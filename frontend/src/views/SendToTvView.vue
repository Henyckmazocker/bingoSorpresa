<template>
  <div class="page">
    <main class="page__panel send">
      <header>
        <h1 class="send__title">📺 Enviar a una tele</h1>
        <p class="send__lead">
          Abre Bingo Sorpresa en la tele: enseña un código de 6 cifras. Elige el bingo y en unos
          segundos la tele se pone a cantarlo.
        </p>
      </header>

      <!-- Hecho: la tele ya lo tiene. -->
      <section v-if="sent" class="send__done" role="status">
        <p class="send__done-icon" aria-hidden="true">✅</p>
        <p class="send__done-text">
          ¡Enviado! <strong>{{ sent }}</strong> se está sintonizando en la tele.
        </p>
        <div class="send__done-actions">
          <router-link class="btn btn--primary send__btn" to="/jugar">🎱 Volver a Jugar</router-link>
          <button class="btn send__btn" @click="again">📺 Enviar a otra tele</button>
        </div>
      </section>

      <form v-else class="send__form" @submit.prevent="send">
        <label class="send__field">
          <span class="send__label">Código de la tele</span>
          <input
            v-model="code"
            class="send__code"
            :class="{ 'send__code--ok': validCode }"
            inputmode="numeric"
            autocomplete="off"
            maxlength="7"
            placeholder="123 456"
            aria-describedby="send-code-hint"
          />
          <span id="send-code-hint" class="send__hint">
            {{ fromQr ? 'Viene del QR de la tele.' : 'El que se ve en grande en la tele.' }}
          </span>
        </label>

        <fieldset class="send__bingos">
          <legend class="send__label">Bingo</legend>
          <p v-if="!loaded" class="send__empty">📡 Sintonizando…</p>
          <p v-else-if="loadError" class="send__error" role="alert">{{ loadError }}</p>
          <p v-else-if="!bingos.length" class="send__empty">
            Aún no tienes bingos.
            <router-link to="/construir">Monta el primero en Construir</router-link>.
          </p>
          <template v-else>
            <label
              v-for="b in bingos"
              :key="b.id"
              class="channel"
              :class="{ 'channel--on': selectedId === b.id, 'channel--off': !playable(b) }"
            >
              <input v-model="selectedId" class="sr-only" type="radio" name="bingo" :value="b.id" />
              <span class="channel__dot" aria-hidden="true"></span>
              <span class="channel__main">
                <span class="channel__title">{{ b.title }}</span>
                <span class="channel__meta">
                  <span v-if="b.numericEnabled">🔢 Números</span>
                  <span v-if="b.imageEnabled">🖼️ {{ b.imageCount }} {{ b.imageCount === 1 ? 'foto' : 'fotos' }}</span>
                  <span v-if="!playable(b)">Sin modos activos</span>
                </span>
              </span>
            </label>
          </template>
        </fieldset>

        <p v-if="error" class="send__error" role="alert">
          {{ error }}
          <router-link v-if="fixLink" :to="fixLink">Arreglarlo en Construir</router-link>
        </p>

        <button class="btn btn--primary send__submit" type="submit" :disabled="!canSend">
          {{ sending ? '📡 Enviando…' : '📺 Enviar a la tele' }}
        </button>
      </form>

      <nav class="send__nav">
        <router-link class="page__back" to="/jugar">← Volver a Jugar</router-link>
      </nav>
    </main>
  </div>
</template>

<script>
import { apiCall } from '@/services/api';
import { loadApiBingo } from '@/bingo/apiBingo';
import { validateConfig } from '@/bingo/validateConfig';
import { normalizeCode, claimPairing } from '@/bingo/tvPairing';

// «/enviar»: elegir el bingo que canta una tele emparejada por código. Llega del QR de la APK
// (`?code=123456`) o de «Enviar a una tele» en /jugar (`?bingo=<id>`, y el código se teclea).
export default {
  name: 'SendToTvView',
  data() {
    const q = this.$route.query;
    const code = typeof q.code === 'string' ? q.code : '';
    const bingo = Number(q.bingo);
    return {
      code,
      fromQr: !!normalizeCode(code),
      bingos: [],
      loaded: false,
      loadError: '',
      selectedId: Number.isInteger(bingo) && bingo > 0 ? bingo : null,
      sending: false,
      error: '',
      fixLink: null,
      sent: '' // título del bingo enviado
    };
  },
  computed: {
    validCode() {
      return !!normalizeCode(this.code);
    },
    canSend() {
      return this.validCode && this.selectedId != null && !this.sending;
    }
  },
  watch: {
    code() {
      this.error = '';
    },
    selectedId() {
      this.error = '';
      this.fixLink = null;
    }
  },
  created() {
    this.load();
  },
  methods: {
    async load() {
      try {
        this.bingos = await apiCall('list_bingos');
        // Un `?bingo=` que no es suyo no se queda elegido; con un solo bingo, ese.
        if (!this.bingos.some((b) => b.id === this.selectedId)) this.selectedId = null;
        if (this.selectedId == null && this.bingos.length === 1) this.selectedId = this.bingos[0].id;
      } catch (e) {
        this.loadError = e.message || 'No se pudieron cargar tus bingos.';
      } finally {
        this.loaded = true;
      }
    },
    playable(b) {
      return b.numericEnabled || b.imageEnabled || b.musicEnabled;
    },
    async send() {
      const code = normalizeCode(this.code);
      if (!code || this.selectedId == null || this.sending) return;
      const id = this.selectedId;
      this.sending = true;
      this.error = '';
      this.fixLink = null;
      try {
        // Se comprueba aquí que se puede cantar (E1–E4): mejor el aviso en el móvil que en la tele.
        const { ok, errors } = validateConfig(await loadApiBingo(id));
        if (!ok) {
          this.error = `Este bingo aún no se puede cantar: ${errors.join(' ')}`;
          this.fixLink = `/construir/${id}`;
          return;
        }
        await claimPairing(code, id);
        const b = this.bingos.find((x) => x.id === id);
        this.sent = b ? b.title : 'El bingo';
      } catch (e) {
        this.error = e.message || 'No se pudo enviar el bingo a la tele.';
      } finally {
        this.sending = false;
      }
    },
    // Otra tele: mismo bingo, código nuevo.
    again() {
      this.sent = '';
      this.code = '';
      this.fromQr = false;
      if (this.$route.query.code) this.$router.replace({ query: { bingo: this.selectedId } });
    }
  }
};
</script>

<style scoped lang="scss">
.send {
  display: flex;
  flex-direction: column;
  gap: 1.4rem;
}

.send__title {
  margin: 0;
  font-size: clamp(1.8rem, 5vw, 2.6rem);
  font-weight: 900;
}

.send__lead {
  margin: 0.4rem 0 0;
  color: var(--ink-dim);
  line-height: 1.5;
}

.send__form {
  display: flex;
  flex-direction: column;
  gap: 1.2rem;
}

.send__field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.send__label {
  padding: 0;
  font-weight: 800;
  color: var(--ink-dim);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.85rem;
}

/* El código, grande y en cifras de ancho fijo, como en la tele. */
.send__code {
  width: min(100%, 12ch);
  padding: 0.3em 0.5em;
  border: 2px solid color-mix(in srgb, var(--ink-dim) 50%, transparent);
  border-radius: 14px;
  background: var(--bg-2);
  color: var(--accent-2);
  font: inherit;
  font-size: clamp(1.8rem, 7vw, 2.6rem);
  font-weight: 900;
  letter-spacing: 0.12em;
  font-variant-numeric: tabular-nums;
}
.send__code--ok {
  border-color: var(--accent-2);
}

.send__hint {
  color: var(--ink-dim);
  font-size: 0.9rem;
}

.send__bingos {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin: 0;
  padding: 0;
  border: 0;
  min-width: 0;
}

.send__empty {
  margin: 0;
  padding: 1.2rem;
  border: 2px dashed color-mix(in srgb, var(--ink-dim) 50%, transparent);
  border-radius: var(--radius);
  color: var(--ink-dim);
  text-align: center;
}
.send__empty a {
  color: var(--accent-2);
  font-weight: 700;
}

/* Cada bingo es un «canal» que se sintoniza (radio): el elegido se ilumina. */
.channel {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.8rem 0.9rem;
  border-left: 6px solid color-mix(in srgb, var(--accent) 45%, transparent);
  border-radius: 14px;
  background: var(--bg-2);
  cursor: pointer;
}
.channel--on {
  border-left-color: var(--accent-2);
  background: color-mix(in srgb, var(--accent-2) 14%, var(--bg-2));
}
.channel--off {
  opacity: 0.6;
}
.channel:focus-within {
  outline: 4px solid var(--focus);
  outline-offset: 3px;
}

.channel__dot {
  flex: none;
  width: 1.1rem;
  height: 1.1rem;
  border-radius: 50%;
  border: 2px solid var(--ink-dim);
}
.channel--on .channel__dot {
  border-color: var(--accent-2);
  background: radial-gradient(circle, var(--accent-2) 45%, transparent 50%);
}

.channel__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}
.channel__title {
  font-size: 1.15rem;
  font-weight: 800;
  overflow-wrap: anywhere;
}
.channel__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem 0.9rem;
  color: var(--ink-dim);
  font-size: 0.9rem;
}

.send__error {
  margin: 0;
  color: var(--accent);
  font-weight: 700;
}
.send__error a {
  color: var(--accent-2);
  margin-left: 0.4em;
}

.send__submit {
  align-self: flex-start;
}

.send__done {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  text-align: center;
}
.send__done-icon {
  margin: 0;
  font-size: 3rem;
}
.send__done-text {
  margin: 0;
  font-size: 1.2rem;
  line-height: 1.5;
}
.send__done-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.8rem;
}

.send__btn {
  font-size: 1rem;
  padding: 0.55em 1em;
  text-decoration: none;
  color: var(--ink);
}
.send__btn.btn--primary {
  color: #221100;
}

.send__nav .page__back {
  margin-top: 0;
}
</style>
