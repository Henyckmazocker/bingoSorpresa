<template>
  <!-- «Ensayar canciones»: carga cada canción en un player de YouTube oculto y SILENCIADO y apunta
       si arranca (✅), si YouTube la rechaza (❌ con su código) o si no arranca en 10 s (⏱️). Es el
       mismo player que la partida, en el mismo aparato: lo que pasa aquí es lo que pasará allí. -->
  <section class="rehearsal">
    <div class="rehearsal__head">
      <h2 class="rehearsal__title">🎧 Ensayo</h2>
      <p class="rehearsal__hint">
        Prueba cada canción en este aparato, sin sonido, antes de la fiesta. Las que salgan con ❌ no
        se podrán reproducir: cámbialas por otra versión.
      </p>
      <div class="rehearsal__actions">
        <button ref="startBtn" class="btn btn--primary" :disabled="running || !items.length" @click="start">
          {{ running ? `Ensayando ${doneCount + 1} de ${rows.length}…` : '🎵 Ensayar canciones' }}
        </button>
        <button v-if="running" ref="stopBtn" class="btn rehearsal__stop" @click="stop">Parar</button>
        <span v-if="finished" class="rehearsal__summary" aria-live="polite">
          ✅ {{ count.ok }} · ❌ {{ count.error }} · ⏱️ {{ count.timeout }}
        </span>
      </div>
    </div>

    <p v-if="loadError" class="rehearsal__error" role="alert">{{ loadError }}</p>

    <ol v-if="rows.length" class="rehearsal__list">
      <li v-for="(row, i) in rows" :key="row.item.id" class="rehearsal__row" :class="`rehearsal__row--${row.status}`">
        <span class="rehearsal__icon" aria-hidden="true">{{ icon(row, i) }}</span>
        <span class="rehearsal__name">
          {{ row.item.label }}<span v-if="row.item.sublabel" class="rehearsal__artist"> · {{ row.item.sublabel }}</span>
        </span>
        <span class="rehearsal__detail">{{ detail(row) }}</span>
      </li>
    </ol>

    <!-- Fuera de la pantalla pero con tamaño real: un iframe con display:none no siempre arranca.
         Su iframe va con tabindex=-1 (createPlayer): el mando no debe poder meterse dentro. -->
    <div ref="stage" class="rehearsal__stage" aria-hidden="true"></div>
  </section>
</template>

<script>
import { loadYoutubeApi, createPlayer, youtubeErrorText } from '@/utils/youtubeApi';
import { createRehearsal, REHEARSAL_TIMEOUT_MS } from '@/bingo/rehearsal';

export default {
  name: 'RehearsalPanel',
  props: {
    items: { type: Array, required: true } // BingoItem[] con media.type 'youtube'
  },
  data() {
    return {
      rows: [], // { item, status: 'pending'|'ok'|'error'|'timeout', code }
      running: false,
      finished: false,
      current: -1,
      loadError: ''
    };
  },
  computed: {
    doneCount() {
      return this.rows.filter((r) => r.status !== 'pending').length;
    },
    count() {
      const c = { ok: 0, error: 0, timeout: 0 };
      for (const r of this.rows) if (c[r.status] !== undefined) c[r.status]++;
      return c;
    }
  },
  created() {
    // Fuera de `data`: ni el player ni el ensayo tienen que ser reactivos.
    this.player = null;
    this.rehearsal = null;
  },
  beforeUnmount() {
    if (this.rehearsal) this.rehearsal.stop();
    if (this.player) this.player.destroy();
    this.player = null;
  },
  methods: {
    async start() {
      if (this.running || !this.items.length) return;
      this.loadError = '';
      this.finished = false;
      // Foto fija de la lista: si se añaden o borran canciones a mitad, el ensayo sigue con estas.
      this.rows = this.items.map((item) => ({ item, status: 'pending', code: null }));
      this.running = true;
      this.keepFocus('stopBtn'); // el botón pulsado se deshabilita: el mando pasa a «Parar»
      try {
        await this.ensurePlayer();
      } catch (e) {
        this.loadError = e.message;
        this.running = false;
        this.keepFocus('startBtn');
        return;
      }
      const rehearsal = createRehearsal({
        items: this.rows.map((r) => r.item),
        load: (item) => this.load(item),
        onResult: (i, result) => {
          this.rows[i].status = result.status;
          this.rows[i].code = result.code ?? null;
        }
      });
      this.rehearsal = rehearsal;
      await rehearsal.run();
      if (this.rehearsal !== rehearsal) return; // parado y vuelto a empezar
      this.rehearsal = null;
      this.player?.stopVideo();
      this.running = false;
      this.finished = true;
      this.keepFocus('startBtn'); // «Parar» desaparece
    },
    stop() {
      if (this.rehearsal) this.rehearsal.stop();
      this.rehearsal = null;
      this.player?.stopVideo();
      this.running = false;
      this.finished = this.doneCount > 0;
      this.keepFocus('startBtn');
    },
    // Con el mando no hay ratón: si el botón con foco se deshabilita o desaparece, el foco se
    // perdería en el body. Solo se mueve si se ha perdido (en la web no roba nada).
    keepFocus(ref) {
      this.$nextTick(() => {
        const a = document.activeElement;
        const lost = !a || a === document.body || !a.isConnected || a.disabled;
        const el = this.$refs[ref];
        if (lost && el && !el.disabled) el.focus();
      });
    },
    load(item) {
      this.current = this.rows.findIndex((r) => r.item === item);
      const videoId = item.media?.videoId;
      if (!videoId) {
        this.rehearsal.error(2); // sin vídeo que cargar: como un enlace no válido
        return;
      }
      // Silencio SIEMPRE antes de cargar: si no, sonarían todas las entradillas seguidas.
      this.player.mute();
      this.player.loadVideoById({ videoId, startSeconds: item.media.startSeconds || 0 });
    },
    /** Crea el player oculto una sola vez y espera a su `onReady`. */
    async ensurePlayer() {
      if (this.player) return;
      const YT = await loadYoutubeApi();
      const el = document.createElement('div'); // YT lo sustituye por el iframe: fuera del DOM de Vue
      this.$refs.stage.appendChild(el);
      await new Promise((resolve, reject) => {
        // Sin `onReady` no hay nada que ensayar: mejor decirlo que dejar el botón colgado.
        const timer = setTimeout(() => {
          this.player?.destroy();
          this.player = null;
          reject(new Error('El reproductor de YouTube no responde. Vuelve a intentarlo.'));
        }, REHEARSAL_TIMEOUT_MS);
        this.player = createPlayer(YT, el, {
          playerVars: { autoplay: 0, mute: 1 },
          events: {
            onReady: () => {
              clearTimeout(timer);
              resolve();
            },
            onStateChange: (e) => {
              if (e.data === YT.PlayerState.PLAYING) this.rehearsal?.playing();
            },
            onError: (e) => this.rehearsal?.error(e.data)
          }
        });
      });
    },
    icon(row, i) {
      if (row.status === 'ok') return '✅';
      if (row.status === 'error') return '❌';
      if (row.status === 'timeout') return '⏱️';
      return this.running && i === this.current ? '⏳' : '·';
    },
    detail(row) {
      if (row.status === 'ok') return 'se reproduce';
      if (row.status === 'error') return `${row.code} · ${youtubeErrorText(row.code)}`;
      if (row.status === 'timeout') return `no arrancó en ${REHEARSAL_TIMEOUT_MS / 1000} s`;
      return '';
    }
  }
};
</script>

<style scoped lang="scss">
.rehearsal {
  margin-top: 0.5rem;
  padding: 1rem;
  border-radius: 14px;
  background: var(--bg-2);
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
}

.rehearsal__head {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.rehearsal__title {
  margin: 0;
  font-size: 1.2rem;
}

.rehearsal__hint {
  margin: 0;
  color: var(--ink-dim);
  font-size: 0.9rem;
  line-height: 1.5;
}

.rehearsal__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
}

.rehearsal__stop {
  font-size: 1rem;
  padding: 0.5em 1em;
  background: color-mix(in srgb, var(--ink) 12%, var(--panel));
}

.rehearsal__summary {
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.rehearsal__error {
  margin: 0;
  padding: 0.6em 1em;
  border-radius: 12px;
  background: color-mix(in srgb, var(--accent) 18%, var(--panel));
  color: #ffd6dd;
}

.rehearsal__list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.rehearsal__row {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
  padding: 0.3rem 0.5rem;
  border-radius: 8px;
}

.rehearsal__row--error {
  background: color-mix(in srgb, var(--accent) 14%, transparent);
}

.rehearsal__row--timeout {
  background: color-mix(in srgb, var(--accent-2) 12%, transparent);
}

.rehearsal__icon {
  flex: none;
  width: 1.5em;
  text-align: center;
}

.rehearsal__name {
  flex: 1;
  min-width: 0;
  overflow-wrap: anywhere;
}

.rehearsal__artist {
  color: var(--ink-dim);
}

.rehearsal__detail {
  flex: none;
  color: var(--ink-dim);
  font-size: 0.85rem;
}

/* Tamaño de verdad (YouTube no arranca en players diminutos) pero fuera de la vista. */
.rehearsal__stage {
  position: fixed;
  left: -10000px;
  top: 0;
  width: 320px;
  height: 180px;
  pointer-events: none;
}
</style>
