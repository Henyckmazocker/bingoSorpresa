<template>
  <!-- Reproductor de YouTube de TODA la partida: CallerView lo monta una vez y lo esconde con
       v-show. El permiso de autoplay que da el primer «Sacar» vive en esta instancia; si se
       desmontara con cada canción, la segunda ya no sonaría sola. -->
  <div class="yt">
    <div class="yt__frame">
      <!-- YT sustituye por el iframe el div que se le pasa: se crea a mano, fuera del DOM de Vue. -->
      <div ref="stage" class="yt__stage"></div>

      <!-- Tapa: paso 0 = disco y número; paso 1 = título y artista; paso 2 = sin tapa (se ve el
           vídeo). `.yt-cover--solid` (tapa opaca) es el plan B si el desenfoque va a tirones en la
           tele (M4); no está activada. Si la canción falla, CallerView sube el revelado al máximo,
           pero la tapa se queda con el título: debajo solo habría la pantalla de error de YouTube. -->
      <div v-if="revealStep < 2 || errored" class="yt-cover">
        <transition name="fade" mode="out-in">
          <div v-if="revealStep < 1 && !errored" key="mystery" class="yt-cover__content">
            <div class="yt-cover__disc" :class="{ playing }">🎧</div>
            <p class="yt-cover__badge">Canción nº {{ index }}</p>
          </div>
          <div v-else key="title" class="yt-cover__content">
            <p class="yt-cover__badge">Canción nº {{ index }}</p>
            <h2 class="yt-cover__title">{{ item ? item.label : '' }}</h2>
            <p class="yt-cover__artist">{{ item ? item.sublabel : '' }}</p>
          </div>
        </transition>
      </div>
    </div>
  </div>
</template>

<script>
import { loadYoutubeApi, createPlayer } from '@/utils/youtubeApi';

// Lo que suena solo al caer la canción: una entradilla corta para que los invitados adivinen.
// Pasados estos segundos se pausa sola y a partir de ahí manda el host con el play/pausa.
const PREVIEW_SECONDS = 5;
// La IFrame API no tiene `timeupdate`: se sondea `getCurrentTime()` cada tanto.
const POLL_MS = 250;
// Estados de `onStateChange` (los mismos que `YT.PlayerState`, sin depender de que esté cargado).
const STATE = { UNSTARTED: -1, ENDED: 0, PLAYING: 1, PAUSED: 2, BUFFERING: 3, CUED: 5 };

export default {
  name: 'YoutubePlayer',
  props: {
    // Última canción sacada (`store.lastMusicItem`), con `media.type === 'youtube'`; null al empezar.
    item: { type: Object, default: null },
    revealStep: { type: Number, default: 0 },
    index: { type: Number, default: 0 } // «Canción nº N» en la tapa
  },
  emits: ['playing-change', 'error'], // error: { code } de onError (null si la API no cargó)
  data() {
    // `previewing`: la reproducción en curso es la entradilla automática y hay que cortarla a los
    // PREVIEW_SECONDS. Los plays manuales del host van sin límite.
    // `errored`: la canción actual dio `onError`.
    return { playing: false, previewing: false, errored: false };
  },
  watch: {
    // Al sacar una canción nueva, la carga y la intenta reproducir (el «Sacar» es el gesto).
    'item.id'() {
      this.errored = false;
      // Sin canción (partida reiniciada): se calla y olvida la cargada, que puede volver a salir.
      if (!this.item) {
        this.loadedId = null;
        this.pause();
        return;
      }
      this.$nextTick(() => this.loadItem());
    }
  },
  created() {
    // Fuera de `data`: ni el player ni los temporizadores tienen que ser reactivos.
    this.player = null;
    this.ready = false;
    this.failed = false; // la IFrame API no cargó (sin red, CSP)
    this.loadedId = null; // id del item cargado en el player
    this.pollTimer = null;
    this.unmounted = false;
  },
  async mounted() {
    let YT;
    try {
      YT = await loadYoutubeApi();
    } catch (e) {
      this.failed = true;
      this.loadItem(); // si ya hay canción, avisa del fallo
      return;
    }
    if (this.unmounted) return;
    const el = document.createElement('div');
    this.$refs.stage.appendChild(el);
    this.player = createPlayer(YT, el, {
      playerVars: { autoplay: 0 },
      events: {
        onReady: () => {
          this.ready = true;
          // Si la primera canción salió antes de que el player estuviera listo, se carga ahora.
          this.loadItem();
        },
        onStateChange: (e) => this.setPlaying(e.data === STATE.PLAYING || e.data === STATE.BUFFERING),
        onError: (e) => {
          this.stopPreview();
          this.errored = true;
          this.$emit('error', { code: e.data });
        }
      }
    });
  },
  beforeUnmount() {
    this.unmounted = true;
    this.stopPreview();
    if (this.player) this.player.destroy();
    this.player = null;
  },
  methods: {
    setPlaying(v) {
      if (this.playing === v) return;
      this.playing = v;
      this.$emit('playing-change', v);
    },
    isYoutube(item) {
      return !!(item && item.media && item.media.type === 'youtube' && item.media.videoId);
    },
    startSeconds() {
      return (this.item && this.item.media.startSeconds) || 0;
    },
    loadItem() {
      const item = this.item;
      if (!this.isYoutube(item) || item.id === this.loadedId) return;
      if (this.failed) {
        this.loadedId = item.id;
        this.errored = true;
        this.$emit('error', { code: null });
        return;
      }
      if (!this.ready) return; // onReady la carga
      this.loadedId = item.id;
      this.player.loadVideoById({ videoId: item.media.videoId, startSeconds: this.startSeconds() });
      this.previewing = true;
      this.startPoll(item.media.videoId);
    },
    // Corta la entradilla a los PREVIEW_SECONDS de VÍDEO contados desde `startSeconds` (si empieza
    // en el 0:40, hasta el 0:45). Tiempo de vídeo y no de reloj: si buferea, 5 s de reloj no son 5 s
    // de música. Solo cuenta con el vídeo nuevo ya sonando: justo tras `loadVideoById`,
    // `getCurrentTime()` aún puede dar el tiempo de la canción anterior.
    startPoll(videoId) {
      clearInterval(this.pollTimer);
      this.pollTimer = setInterval(() => {
        const p = this.player;
        if (!this.previewing || !p) return this.stopPreview();
        if (p.getPlayerState() !== STATE.PLAYING) return;
        const data = p.getVideoData && p.getVideoData();
        if (data && data.video_id && data.video_id !== videoId) return;
        if (p.getCurrentTime() - this.startSeconds() >= PREVIEW_SECONDS) {
          this.stopPreview();
          p.pauseVideo();
        }
      }, POLL_MS);
    },
    stopPreview() {
      this.previewing = false;
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    },

    /* API pública para CallerView (los botones del mando viven en su fila de controles). */

    toggle() {
      const p = this.player;
      if (!p || !this.ready || !this.isYoutube(this.item)) return;
      const state = p.getPlayerState();
      if (state === STATE.PLAYING || state === STATE.BUFFERING) {
        this.pause();
      } else {
        // Play manual: sin límite de 5 s, sigue desde donde se cortó la entradilla.
        this.stopPreview();
        p.playVideo();
      }
    },
    restart() {
      const p = this.player;
      if (!p || !this.ready || !this.isYoutube(this.item)) return;
      this.stopPreview();
      p.seekTo(this.startSeconds(), true);
      p.playVideo();
    },
    // Pausa de verdad: al sacar otra entrada o al «Ver final» el player sigue vivo (v-show).
    pause() {
      this.stopPreview();
      if (this.player && this.ready) this.player.pauseVideo();
    }
  }
};
</script>

<style scoped lang="scss">
.yt {
  width: 100%;
  display: flex;
  justify-content: center;
}

/* 16:9 que cabe a lo ancho y a lo alto (cabecera y fila de botones aparte). */
.yt__frame {
  position: relative;
  width: min(100%, calc(62vh * 16 / 9));
  aspect-ratio: 16 / 9;
  border-radius: var(--radius);
  overflow: hidden;
  background: #000;
  box-shadow: var(--shadow);
}

.yt__stage,
.yt__stage :deep(iframe) {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
}

.yt-cover {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  -webkit-backdrop-filter: blur(40px) brightness(0.25);
  backdrop-filter: blur(40px) brightness(0.25);
}
/* Tapa opaca: plan B de la pregunta 5 de M4 (y por si el motor no sabe de backdrop-filter, que
   dejaría el vídeo a la vista). */
.yt-cover--solid {
  -webkit-backdrop-filter: none;
  backdrop-filter: none;
  background: #0b0b1a;
}
@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
  .yt-cover {
    background: #0b0b1a;
  }
}

.yt-cover__content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.6rem;
  padding: 1rem;
  text-align: center;
}

.yt-cover__badge {
  margin: 0;
  font-size: clamp(1.2rem, 2.4vw, 2rem);
  font-weight: 800;
  color: var(--music);
}

.yt-cover__disc {
  font-size: clamp(5rem, 12vw, 10rem);
  filter: drop-shadow(0 8px 20px rgba(0, 0, 0, 0.4));
}
.yt-cover__disc.playing {
  animation: pulse 1.6s ease-in-out infinite;
}
@keyframes pulse {
  50% {
    transform: scale(1.08);
  }
}

.yt-cover__title {
  margin: 0.2rem 0 0;
  font-size: clamp(2rem, 5vw, 4rem);
  color: var(--ink);
}
.yt-cover__artist {
  margin: 0;
  color: var(--ink-dim);
  font-size: clamp(1.2rem, 2.6vw, 2rem);
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.25s;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
