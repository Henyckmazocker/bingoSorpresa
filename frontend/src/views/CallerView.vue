<template>
  <!-- Con `:id` (o `/b/:token`) el bingo llega del backend (bingo/apiBingo.js); sin ellos, se
       canta lo que haya en el store (la APK). Mientras carga no se enseña la partida anterior. -->
  <BingoGone
    v-if="loadError"
    :title="loadError.title"
    :detail="loadError.detail"
    :back-to="exitTo"
    :back-label="exitLabel"
  />
  <div v-else-if="loading" class="page tuning">
    <p class="tuning__text">📡 Sintonizando…</p>
  </div>
  <div v-else-if="store.config" class="caller" :style="{ '--mode-color': modeColor }">
    <!-- Cabecera discreta: modo actual + bolas restantes -->
    <header class="caller__top">
      <span class="caller__mode">{{ modeLabel }}</span>
      <span class="caller__remaining">{{ store.remaining }} restantes</span>
    </header>

    <!-- Zona central: cambia según el modo -->
    <main class="caller__stage">
      <!-- Cierre: tras «Ver final», no al agotarse la secuencia (la última entrada también se ve). -->
      <section v-if="finale" class="finale">
        <h1 class="finale__title">🎉 ¡Fin de la partida!</h1>
        <ul class="finale__stats">
          <!-- Solo los modos activos del bingo: sin numérico no hay recuento de bolas. -->
          <li
            v-for="kind in store.enabledKinds"
            :key="kind"
            :style="{ '--stat-color': finaleStats[kind].color }"
          >
            <span class="finale__num">{{ store.drawnByKind[kind] }}</span> {{ finaleStats[kind].text }}
          </li>
        </ul>
        <div class="finale__actions">
          <button ref="playAgain" class="btn btn--primary finale__again" @click="restart">
            🎱 Jugar otra
          </button>
          <!-- APK: vuelve a la pantalla del código para recibir otro bingo. -->
          <button v-if="isMobile" class="btn finale__exit" @click="exitToTvHome">🚪 Salir</button>
        </div>
      </section>

      <!-- Espera neutra: sin numérico y sin nada sacado, `store.mode` cae a 'numeric' y se vería el
           tablero vacío con «Bingo». Solo título y aviso, sin tablero ni la palabra «bola». -->
      <section v-else-if="idle" class="finale">
        <h1 class="finale__title">{{ store.config.title }}</h1>
        <p class="idle__hint">Pulsa «Sacar» para empezar</p>
      </section>

      <template v-else-if="store.mode === 'numeric'">
        <div class="numeric">
          <DrawDisplay :value="store.current" />
          <div class="numeric__board">
            <NumberBoard :max="90" :drawn="store.drawnNumbersSet" :last="store.current" />
          </div>
        </div>
      </template>

      <!-- Las canciones no van en esta cadena: las pinta el reproductor persistente de abajo. -->
      <ImageDisplay
        v-else-if="store.mode === 'image'"
        :item="store.current"
        :index="store.currentKindIndex"
        :revealed="store.revealStep >= 1"
      />

      <!-- YouTube: FUERA de la cadena de arriba y con v-show, nunca v-if (tampoco en el cierre ni
           en la espera). Se crea una vez si el bingo tiene canciones de YouTube y vive toda la
           partida: el permiso de autoplay del primer «Sacar» muere con la instancia. Recibe la
           última canción SACADA, no `store.current` (con una bola en pantalla sería un número). -->
      <div v-if="hasYoutube" v-show="showYoutube" class="caller__yt">
        <YoutubePlayer
          ref="yt"
          :item="store.lastMusicItem"
          :index="store.currentKindIndex"
          :reveal-step="store.revealStep"
          @playing-change="songPlaying = $event"
          @error="onYoutubeError"
        />
        <p v-if="ytErrorShown" class="caller__yt-error" role="alert">
          ⚠️ Esta canción no se puede reproducir aquí
          <span v-if="ytError.code != null" class="caller__yt-code">
            ({{ ytError.code }} · {{ ytErrorText }})
          </span>
        </p>
      </div>
    </main>

    <!-- Controles -->
    <!-- En el cierre desaparecen: el foco solo puede ir a «Jugar otra» (y «Salir» en la APK) y a ↺. -->
    <footer v-if="!finale" class="caller__controls">
      <button
        ref="draw"
        class="btn btn--primary caller__draw"
        @click="drawOrFinish"
      >
        {{ drawLabel }}
      </button>

      <!-- Controles propios: el reproductor de YouTube va sin controles y no es navegable con el D-pad.
           En la misma fila que «Sacar» para moverse izquierda-derecha con el mando. -->
      <template v-if="store.mode === 'music'">
        <button class="btn caller__play" @click="togglePlay">
          {{ songPlaying ? '⏸️ Pausa' : '▶️ Play' }}
        </button>
        <button class="btn caller__rewind" title="Volver al principio" @click="restartSong">
          ⏮
        </button>
      </template>

      <button
        v-if="needsReveal"
        class="btn caller__reveal"
        :disabled="store.current == null || store.revealDone"
        @click="store.reveal()"
      >
        👁️ Revelar
      </button>
    </footer>

    <!-- Reinicio discreto (esquina) -->
    <button class="caller__reset" title="Reiniciar partida" @click="restart">↺</button>
    <!-- Salida discreta (web): a la lista de «Jugar» o a la portada del enlace compartido. -->
    <router-link v-if="exitTo" class="caller__exit" :to="exitTo" title="Salir">✕</router-link>

    <!-- Confirmación de reinicio con diálogo propio (navegable con el D-pad), no confirm(). -->
    <ConfirmDialog
      v-if="dialogOpen"
      ref="dialog"
      :message="
        finale
          ? '¿Empezar otra partida? Se rebaraja todo (bolas y sorpresas).'
          : '¿Reiniciar la partida? Se rebaraja todo (bolas y sorpresas).'
      "
      :confirm-label="finale ? '🎱 Jugar otra' : '↺ Reiniciar'"
      cancel-label="Cancelar"
      @confirm="confirmRestart"
      @cancel="dialogOpen = false"
    />

    <!-- Señal breve al caer una sorpresa: sin cortinilla ni «¡SORPRESA!», solo un aviso corto
         para que los invitados sepan que toca mirar el cartón musical / de recuerdos. -->
    <transition name="cue">
      <div v-if="cue" class="cue" :style="{ '--cue-color': cueColor }">
        <span class="cue__icon">{{ cueIcon }}</span>
        <span class="cue__text">{{ cueText }}</span>
      </div>
    </transition>
  </div>
  <!-- Web sin bingo en el store (no debería pasar: el router web no tiene /cantar sin id). -->
  <BingoGone v-else-if="!isMobile" title="No hay ningún bingo cargado" />
</template>

<script>
import { useGameStore, MODE_META } from '@/store/game';
import DrawDisplay from '@/components/DrawDisplay.vue';
import NumberBoard from '@/components/NumberBoard.vue';
import YoutubePlayer from '@/components/YoutubePlayer.vue';
import ImageDisplay from '@/components/ImageDisplay.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import BingoGone from '@/components/BingoGone.vue';
import { loadApiBingo, loadSharedBingo, isGone } from '@/bingo/apiBingo';
import { youtubeErrorText } from '@/utils/youtubeApi';

const CUE_MS = 1600; // duración de la señal breve al caer una sorpresa

// Recuento del cierre por modo: color y texto fijos (los mismos que tenían las tres <li> de antes).
const FINALE_STATS = {
  numeric: { color: 'var(--accent)', text: 'bolas' },
  music: { color: 'var(--music)', text: 'canciones' },
  image: { color: 'var(--image)', text: 'recuerdos' }
};

export default {
  name: 'CallerView',
  components: { DrawDisplay, NumberBoard, YoutubePlayer, ImageDisplay, ConfirmDialog, BingoGone },
  props: {
    // `/cantar/:id` (bingo propio) o `/b/:token/cantar` (enlace compartido). Sin ninguno, el store.
    id: { type: String, default: null },
    token: { type: String, default: null }
  },
  setup() {
    return {
      store: useGameStore(),
      finaleStats: FINALE_STATS,
      isMobile: process.env.VUE_APP_MODE === 'mobile'
    };
  },
  data() {
    return {
      loading: false,
      loadError: null, // { title, detail } → pantalla completa de BingoGone
      cue: false,
      cueKind: 'music',
      songPlaying: false,
      // Fallo de YouTube ({ itemId, code }); el aviso solo vale para esa canción, así que se borra
      // solo al sacar otra.
      ytError: null,
      dialogOpen: false,
      finale: false // pantalla de cierre visible (se entra con «Ver final» tras la última entrada)
    };
  },
  computed: {
    // Pantalla de espera neutra: solo con el numérico apagado y antes de la primera tirada.
    // Con el numérico activo el arranque sigue siendo el tablero vacío de siempre.
    idle() {
      return !this.finale && this.store.drawn === 0 && !this.store.config.modes.numeric.enabled;
    },
    // En el cierre `store.mode` sigue siendo el kind de la última entrada: paleta propia, o el
    // final saldría teñido del color de la última sorpresa.
    modeLabel() {
      if (this.finale) return 'Fin de la partida';
      if (this.idle) return this.store.config.title;
      return MODE_META[this.store.mode].label;
    },
    modeColor() {
      return this.finale || this.idle ? 'var(--accent-2)' : MODE_META[this.store.mode].color;
    },
    // `media.type` de la canción en pantalla ('youtube'), o null si no hay canción.
    songType() {
      const item = this.store.mode === 'music' ? this.store.current : null;
      return item && item.media ? item.media.type : null;
    },
    // El bingo trae canciones de YouTube → se monta su reproductor (una vez, para toda la partida).
    hasYoutube() {
      const music = this.store.config.modes.music;
      return !!(
        music &&
        music.enabled &&
        Array.isArray(music.items) &&
        music.items.some((it) => it.media && it.media.type === 'youtube')
      );
    },
    showYoutube() {
      return !this.finale && !this.idle && this.songType === 'youtube';
    },
    ytErrorShown() {
      const last = this.store.lastMusicItem;
      return !!(this.ytError && last && this.ytError.itemId === last.id);
    },
    ytErrorText() {
      return this.ytError ? youtubeErrorText(this.ytError.code) : '';
    },
    needsReveal() {
      return !this.finale && this.store.mode !== 'numeric';
    },
    drawLabel() {
      // Neutro a propósito: no delata si lo siguiente será bola o sorpresa.
      return this.store.remaining === 0 ? '🏁 Ver final' : '🎱 Sacar';
    },
    cueColor() {
      return MODE_META[this.cueKind].color;
    },
    cueIcon() {
      return this.cueKind === 'music' ? '🎵' : '💖';
    },
    cueText() {
      return this.cueKind === 'music' ? '¡Suena una canción!' : '¡Un recuerdo!';
    },
    // A dónde vuelve el ✕ y el botón de error. En `/b/:token` no hay `/jugar` (pide cuenta).
    exitTo() {
      if (this.token) return `/b/${this.token}`;
      if (this.id) return '/jugar';
      return null;
    },
    exitLabel() {
      return this.token ? '← Volver al enlace' : '🎱 Ir a Jugar';
    }
  },
  watch: {
    // /cantar/1 → /cantar/2 reutiliza la vista: hay que cargar el otro bingo.
    id() {
      this.loadRemote();
    },
    token() {
      this.loadRemote();
    },
    // Precarga la foto que va a salir después, para que aparezca al instante al «Sacar».
    'store.drawn'() {
      this.preloadNext();
    },
    // Al salir del modo musical ▶️ vuelve a «Play» sin esperar al aviso del reproductor de YouTube
    // (lo pausa `drawOrFinish` y avisa él mismo). `surpriseCue` no vale aquí: no se incrementa al
    // salir una bola.
    'store.mode'(mode) {
      if (mode !== 'music') this.songPlaying = false;
    },
    // Cada vez que cae una sorpresa el store incrementa surpriseCue → mostramos la señal breve.
    'store.surpriseCue'() {
      const entry = this.store.currentEntry;
      if (!entry || entry.kind === 'numeric') return;
      this.cueKind = entry.kind;
      this.cue = true;
      clearTimeout(this.cueTimer);
      this.cueTimer = setTimeout(() => (this.cue = false), CUE_MS);
    }
  },
  async created() {
    if (this.id || this.token) {
      await this.loadRemote();
      return;
    }
    // APK: el bingo lo carga TvHomeView (por código) antes de venir aquí. Sin
    // él (p. ej. la webview recargada en `/cantar`), de vuelta a la pantalla del código.
    if (!this.store.config && this.isMobile) this.$router.replace('/');
  },
  mounted() {
    window.addEventListener('keydown', this.onKey);
    // Vigilante de foco (M4): el iframe de YouTube lleva tabindex=-1, pero si aun así el foco se
    // mete dentro, el D-pad ya no sale de él. `focusin` por si el navegador lo avisa en el <iframe>;
    // `blur` de la ventana porque al entrar en un iframe la página pierde el foco y no siempre llega
    // `focusin` (activeElement se actualiza después: se mira en el siguiente tick).
    window.addEventListener('focusin', this.guardFocus);
    window.addEventListener('blur', this.onWindowBlur);
  },
  beforeUnmount() {
    window.removeEventListener('keydown', this.onKey);
    window.removeEventListener('focusin', this.guardFocus);
    window.removeEventListener('blur', this.onWindowBlur);
    clearTimeout(this.cueTimer);
    clearTimeout(this.blurTimer);
  },
  methods: {
    // Carga el bingo del backend y empieza una partida nueva con él. Siempre se vuelve a pedir
    // (aunque el store ya tenga ese bingo): así las URLs firmadas de las fotos son frescas (12 h).
    async loadRemote() {
      if (!this.id && !this.token) return;
      const seq = (this.loadSeq = (this.loadSeq || 0) + 1);
      const stale = () => seq !== this.loadSeq;
      this.loading = true;
      this.loadError = null;
      this.finale = false;
      this.dialogOpen = false;
      try {
        const config = this.id ? await loadApiBingo(this.id) : await loadSharedBingo(this.token);
        if (stale()) return;
        try {
          this.store.loadBingo(config);
        } catch (e) {
          // No pasa validateConfig (E1–E4): el bingo existe pero aún no se puede cantar.
          this.loadError = {
            title: 'Este bingo aún no se puede cantar',
            detail: e.message.split('; ').join(' ')
          };
          return;
        }
        this.preloadNext();
      } catch (e) {
        if (stale()) return;
        this.loadError = isGone(e)
          ? { title: 'Este bingo ya no existe', detail: '' }
          : { title: 'No se pudo cargar el bingo', detail: e.message || '' };
      } finally {
        if (!stale()) this.loading = false;
      }
    },
    // Pide al navegador la próxima foto de la secuencia (solo la siguiente: no 300 de golpe).
    preloadNext() {
      const next = this.store.sequence[this.store.drawn];
      const url = next && next.kind === 'image' && next.item.media && next.item.media.url;
      if (url && typeof Image !== 'undefined') {
        const img = new Image();
        img.src = url;
      }
    },
    onKey(e) {
      // Con un diálogo abierto los atajos no tocan la partida (n/r/p/Espacio/Enter): el diálogo
      // gestiona su propio teclado y Enter/Espacio solo deben activar el botón enfocado.
      if (this.dialogOpen) return;
      // Sin partida en pantalla (cargando, error o sin bingo) los atajos no hacen nada.
      if (this.loading || this.loadError || !this.store.config) return;
      switch (e.key) {
        case ' ':
        case 'Enter':
        case 'n':
          // Space/Enter también activan el botón enfocado; solo actuamos si no hay foco en botón.
          if (document.activeElement && document.activeElement.tagName === 'BUTTON') return;
          if (this.finale) return;
          e.preventDefault();
          this.drawOrFinish();
          break;
        case 'r':
          if (this.needsReveal) this.store.reveal();
          break;
        case 'p':
          this.togglePlay();
          break;
        default:
          break;
      }
    },
    // Si el foco está en un iframe (el player de YouTube), lo devuelve a la fila de botones: a
    // «Sacar»; en el cierre, que no tiene controles, a «Jugar otra»; con el diálogo abierto, a su
    // botón seguro (no a «Sacar», que está debajo). Cualquier otro foco no se toca.
    guardFocus() {
      const el = document.activeElement;
      if (!el || el.tagName !== 'IFRAME') return;
      const dialog = this.dialogOpen && this.$refs.dialog;
      const target = (dialog && dialog.$refs.cancel) || this.$refs.draw || this.$refs.playAgain;
      if (target) target.focus();
      else el.blur();
    },
    onWindowBlur() {
      clearTimeout(this.blurTimer);
      this.blurTimer = setTimeout(this.guardFocus, 0);
    },
    // «Sacar» mientras quede algo; con la secuencia agotada el mismo botón abre el cierre.
    drawOrFinish() {
      // Cualquier entrada nueva (o el cierre) calla la canción de YouTube: su reproductor no se
      // desmonta, así que hay que pausarlo de verdad (antes lo hacía el desmontaje).
      this.pauseYoutube();
      if (this.store.canDraw) {
        this.store.draw();
        return;
      }
      this.finale = true;
      // `store.mode` no cambia al entrar en el cierre: el watch no lo pilla.
      this.songPlaying = false;
      this.$nextTick(() => this.$refs.playAgain && this.$refs.playAgain.focus());
    },
    // Reproductor de la canción en pantalla (el de YouTube). Con una bola, una foto o el cierre,
    // ninguno (si no, «p» reanudaría una canción ya pasada).
    songPlayer() {
      if (this.finale) return null;
      if (this.songType === 'youtube') return this.$refs.yt || null;
      return null;
    },
    togglePlay() {
      const player = this.songPlayer();
      if (player) player.toggle();
    },
    restartSong() {
      const player = this.songPlayer();
      if (player) player.restart();
    },
    pauseYoutube() {
      if (this.$refs.yt) this.$refs.yt.pause();
    },
    // La canción no se puede reproducir (no incrustable, borrada…): no se avanza sola. Se enseña
    // entera (título visible) y el aviso; el siguiente «Sacar» sigue como siempre.
    onYoutubeError({ code }) {
      const last = this.store.lastMusicItem;
      if (!last) return;
      this.ytError = { itemId: last.id, code };
      if (this.store.current === last) this.store.revealAll();
    },
    restart() {
      this.dialogOpen = true;
    },
    // «Salir» del cierre (APK). Si se llegó desde la pantalla del código, se vuelve atrás en el
    // historial (así «Atrás» del mando no reabre el cantor); si no, se sustituye la entrada.
    exitToTvHome() {
      const back = window.history.state && window.history.state.back;
      if (back === '/') this.$router.back();
      else this.$router.replace('/');
    },
    confirmRestart() {
      this.pauseYoutube();
      this.ytError = null;
      this.dialogOpen = false;
      this.finale = false;
      this.store.initGame();
      // Partida nueva: el foco va a «Sacar», que es lo siguiente que se va a pulsar.
      this.$nextTick(() => this.$refs.draw && this.$refs.draw.focus());
    }
  }
};
</script>

<style scoped lang="scss">
.caller {
  height: 100%;
  display: flex;
  flex-direction: column;
  padding: clamp(0.8rem, 2vw, 2rem);
  gap: clamp(0.6rem, 1.5vw, 1.4rem);
}

.caller__top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: clamp(1rem, 2vw, 1.6rem);
}
.caller__mode {
  font-weight: 900;
  color: var(--mode-color);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.caller__remaining {
  color: var(--ink-dim);
}

.caller__stage {
  flex: 1;
  display: grid;
  place-items: center;
  min-height: 0;
}

.numeric {
  display: grid;
  grid-template-columns: minmax(280px, 40%) 1fr;
  align-items: center;
  gap: clamp(1rem, 3vw, 3rem);
  width: 100%;
  height: 100%;
}
.numeric__board {
  /* El tablero se centra solo; aquí solo hace falta darle una altura definida
     para que pueda limitarse a ella. */
  align-self: stretch;
  min-width: 0;
  min-height: 0;
}

/* Cierre de partida: recuento por tipo (ya no delata nada) y «Jugar otra». */
.finale {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: clamp(1rem, 3vh, 2.4rem);
  text-align: center;
}
.finale__title {
  margin: 0;
  font-size: clamp(2rem, 6vw, 5rem);
  font-weight: 900;
  color: var(--mode-color);
}
.finale__stats {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: clamp(1rem, 4vw, 4rem);
  font-size: clamp(1.1rem, 2.4vw, 2rem);
  color: var(--ink-dim);
}
.finale__num {
  display: block;
  font-size: 2.2em;
  font-weight: 900;
  color: var(--stat-color);
}
.idle__hint {
  margin: 0;
  font-size: clamp(1.1rem, 2.4vw, 2rem);
  color: var(--ink-dim);
}
.finale__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 1rem;
}
.finale__again {
  min-width: clamp(240px, 34vw, 420px);
}

.caller__controls {
  display: flex;
  justify-content: center;
  gap: 1rem;
  padding-bottom: 0.4rem;
}
.caller__draw {
  min-width: clamp(240px, 34vw, 420px);
}
.caller__play {
  min-width: clamp(140px, 16vw, 220px); /* ancho fijo: no baila al cambiar Play ⇄ Pausa */
  background: color-mix(in srgb, var(--music) 35%, var(--panel));
}
.caller__rewind {
  padding-left: 0.8em;
  padding-right: 0.8em;
}

/* Reproductor de YouTube persistente y su aviso de error. */
.caller__yt {
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.8rem;
}
.caller__yt-error {
  margin: 0;
  padding: 0.5rem 1.2rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--accent-2) 18%, var(--bg));
  border: 2px solid var(--accent-2);
  font-weight: 800;
  font-size: clamp(1rem, 2vw, 1.5rem);
  text-align: center;
}
.caller__yt-code {
  font-weight: 600;
  color: var(--ink-dim);
}

/* Reinicio discreto: enfocable con el mando pero sin llamar la atención. */
.caller__reset {
  position: fixed;
  bottom: 14px;
  left: 14px;
  width: 46px;
  height: 46px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.06);
  color: rgba(255, 255, 255, 0.35);
  font-size: 1.4rem;
  line-height: 1;
}
.caller__reset:focus-visible,
.caller__reset:hover {
  background: rgba(255, 255, 255, 0.18);
  color: var(--ink);
}

/* Salida discreta (web), en la esquina opuesta a ↺. */
.caller__exit {
  position: fixed;
  bottom: 14px;
  right: 14px;
  width: 46px;
  height: 46px;
  display: grid;
  place-items: center;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.06);
  color: rgba(255, 255, 255, 0.35);
  font-size: 1.2rem;
  text-decoration: none;
}
.caller__exit:focus-visible,
.caller__exit:hover {
  background: rgba(255, 255, 255, 0.18);
  color: var(--ink);
}

/* Cargando el bingo del backend. */
.tuning {
  display: grid;
  place-items: center;
}
.tuning__text {
  font-size: clamp(1.4rem, 3vw, 2.4rem);
  font-weight: 800;
  color: var(--ink-dim);
  letter-spacing: 0.06em;
}

/* Señal breve al caer una sorpresa: chip corto arriba, ni cortinilla ni confeti. */
.cue {
  position: fixed;
  top: clamp(0.8rem, 2.5vw, 2rem);
  left: 50%;
  transform: translateX(-50%);
  z-index: 40;
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 1.2rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--cue-color) 22%, #0b0b1a);
  border: 2px solid var(--cue-color);
  box-shadow: 0 8px 30px color-mix(in srgb, var(--cue-color) 45%, transparent);
  font-weight: 900;
  font-size: clamp(1rem, 2.2vw, 1.6rem);
  color: var(--ink);
  white-space: nowrap;
}
.cue__icon {
  font-size: 1.3em;
  animation: cue-pop 0.5s ease;
}
@keyframes cue-pop {
  0% { transform: scale(0.4); }
  60% { transform: scale(1.25); }
}

/* Entra/sale deslizando desde arriba, rápido y sutil. */
.cue-enter-active {
  transition: opacity 0.25s ease, transform 0.35s cubic-bezier(0.22, 1.3, 0.4, 1);
}
.cue-leave-active {
  transition: opacity 0.3s ease, transform 0.3s ease;
}
.cue-enter-from,
.cue-leave-to {
  opacity: 0;
  transform: translate(-50%, -20px);
}

/* En pantallas muy estrechas (probando en el móvil) apila la zona numérica. */
@media (max-width: 720px) {
  .numeric {
    grid-template-columns: 1fr;
    gap: 1rem;
  }
}
</style>
