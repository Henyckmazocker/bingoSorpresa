<template>
  <!-- Portada de la tele (solo APK, sin cuenta): código + QR para mandarle un bingo desde el
       móvil. -->
  <div class="tv">
    <section class="tv__qr" :class="{ 'tv__qr--off': !code }" aria-hidden="true">
      <canvas ref="qr" class="tv__canvas"></canvas>
      <p v-if="!code" class="tv__static">📡</p>
    </section>

    <section class="tv__info">
      <p class="tv__eyebrow">📺 Canal libre</p>
      <h1 class="tv__title">Bingo Sorpresa</h1>

      <template v-if="code">
        <p class="tv__lead">
          Escanea el QR con el móvil, o entra en <strong>{{ webHost }}</strong> › Jugar ›
          «Enviar a una tele» y escribe:
        </p>
        <p class="tv__code" :aria-label="`Código ${code.split('').join(' ')}`">{{ prettyCode }}</p>
      </template>
      <p v-else class="tv__lead">{{ offline ? 'Sin conexión con el servidor.' : 'Sintonizando…' }}</p>

      <p class="tv__status" role="status">
        <span v-if="notice" class="tv__notice">{{ notice }}</span>
        <span v-else-if="offline">⚠️ Sin señal. Reintentando…</span>
        <span v-else-if="code">Esperando un bingo… El código cambia cada 10 minutos.</span>
      </p>
    </section>
  </div>
</template>

<script>
import QRCode from 'qrcode';
import { useGameStore } from '@/store/game';
import { fromPayload } from '@/bingo/apiBingo';
import { youtubeSongs } from '@/bingo/rehearsal';
import {
  POLL_MS,
  pairUrl,
  formatCode,
  isExpired,
  createPairing,
  pollPairing
} from '@/bingo/tvPairing';

const RETRY_MS = 5000; // sin red: cada cuánto se vuelve a pedir código

export default {
  name: 'TvHomeView',
  setup() {
    return { store: useGameStore() };
  },
  data() {
    return {
      code: null,
      deviceToken: null,
      offline: false,
      notice: '' // aviso puntual (p. ej. el bingo recibido no se puede cantar)
    };
  },
  computed: {
    prettyCode() {
      return formatCode(this.code);
    },
    webHost() {
      return pairUrl('').replace(/^https?:\/\//, '').replace(/\/#.*$/, '');
    }
  },
  mounted() {
    this.active = true;
    this.newCode();
  },
  beforeUnmount() {
    this.active = false;
    clearTimeout(this.timer);
  },
  methods: {
    // Pide un código nuevo y empieza a sondear. Sin red, reintenta cada 5 s.
    async newCode() {
      clearTimeout(this.timer);
      this.code = null;
      this.deviceToken = null;
      try {
        const pairing = await createPairing();
        if (!this.active) return;
        this.offline = false;
        this.code = pairing.code;
        this.deviceToken = pairing.deviceToken;
        await this.drawQr();
        this.schedule(this.poll, POLL_MS);
      } catch (e) {
        if (!this.active) return;
        this.offline = true;
        this.schedule(this.newCode, RETRY_MS);
      }
    },
    async drawQr() {
      await this.$nextTick();
      if (!this.$refs.qr || !this.code) return;
      try {
        await QRCode.toCanvas(this.$refs.qr, pairUrl(this.code), {
          width: 512, // el CSS lo escala al hueco; así no se pixela en la tele
          margin: 2,
          errorCorrectionLevel: 'M',
          // Oscuro sobre claro: algunos lectores no leen QR invertidos.
          color: { dark: '#0b0b1aff', light: '#f5f5ffff' }
        });
      } catch (e) {
        // Sin QR queda el código en grande, que basta.
      }
    },
    schedule(fn, ms) {
      clearTimeout(this.timer);
      if (this.active) this.timer = setTimeout(fn, ms);
    },
    // Un sondeo cada 3 s (encadenado con setTimeout: nunca dos a la vez si la red va lenta).
    async poll() {
      const token = this.deviceToken;
      if (!token) return;
      try {
        const res = await pollPairing(token);
        if (!this.active || token !== this.deviceToken) return;
        this.offline = false;
        if (res.status === 'ready') {
          this.play(res.payload);
          return;
        }
        this.schedule(this.poll, POLL_MS);
      } catch (e) {
        if (!this.active || token !== this.deviceToken) return;
        if (isExpired(e)) {
          // Caducó sin que nadie lo reclamara (10 min): código nuevo, sin tocar nada.
          this.newCode();
          return;
        }
        this.offline = e.status === 0 || e.status === undefined || e.status >= 500;
        this.schedule(this.poll, POLL_MS);
      }
    },
    play(payload) {
      let config;
      try {
        config = fromPayload(payload);
        this.store.loadBingo(config);
      } catch (e) {
        // El bingo no pasa validateConfig (E1–E4): se avisa y se ofrece otro código.
        this.notice = `Ese bingo aún no se puede cantar: ${e.message.split('; ').join(' ')}`;
        this.newCode();
        return;
      }
      this.notice = '';
      // Con canciones de YouTube, antes la antesala con «Ensayar canciones»; si no, al cantor.
      this.$router.push(youtubeSongs(config).length ? '/listo' : '/cantar');
    }
  }
};
</script>

<style scoped lang="scss">
/* Pantalla horizontal de tele: QR a la izquierda, código e instrucciones a la derecha. Todo cabe
   sin scroll (el body no lo tiene). */
.tv {
  height: 100%;
  display: grid;
  grid-template-columns: minmax(0, auto) minmax(0, 1fr);
  align-items: center;
  gap: clamp(1.5rem, 5vw, 5rem);
  padding: clamp(1rem, 4vw, 4rem);
}

/* El QR, como una tele pequeña: marco con brillo y esquinas redondeadas. */
.tv__qr {
  position: relative;
  width: min(42vw, 72vh);
  aspect-ratio: 1;
  display: grid;
  place-items: center;
  padding: clamp(0.6rem, 1.4vw, 1.2rem);
  border-radius: calc(var(--radius) * 1.4);
  background: #f5f5ff;
  box-shadow:
    0 0 0 6px var(--panel),
    0 0 0 10px color-mix(in srgb, var(--accent-2) 55%, transparent),
    var(--shadow);
}
.tv__qr--off {
  background: var(--panel);
}
.tv__qr--off .tv__canvas {
  visibility: hidden;
}
.tv__canvas {
  width: 100% !important;
  height: 100% !important;
  image-rendering: pixelated;
}
.tv__static {
  position: absolute;
  margin: 0;
  font-size: clamp(3rem, 9vw, 7rem);
  animation: tv-blink 1.4s ease-in-out infinite;
}
@keyframes tv-blink {
  50% { opacity: 0.35; }
}

.tv__info {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: clamp(0.5rem, 2vh, 1.4rem);
  min-width: 0;
}
.tv__eyebrow {
  margin: 0;
  color: var(--ink-dim);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-size: clamp(0.9rem, 1.6vw, 1.3rem);
}
.tv__title {
  margin: 0;
  font-size: clamp(2rem, 5vw, 4rem);
  font-weight: 900;
  line-height: 1;
}
.tv__lead {
  margin: 0;
  max-width: 34em;
  color: var(--ink-dim);
  font-size: clamp(1rem, 1.9vw, 1.6rem);
  line-height: 1.4;
}
.tv__lead strong {
  color: var(--ink);
}

/* El código, enorme y en dorado, con cifras de ancho fijo para que no baile. */
.tv__code {
  margin: 0;
  font-size: clamp(3rem, 11vw, 9rem);
  font-weight: 900;
  line-height: 1;
  letter-spacing: 0.08em;
  font-variant-numeric: tabular-nums;
  color: var(--accent-2);
  text-shadow: 0 0 24px color-mix(in srgb, var(--accent-2) 45%, transparent);
  white-space: nowrap;
}

.tv__status {
  margin: 0;
  min-height: 1.4em;
  color: var(--ink-dim);
  font-size: clamp(0.9rem, 1.6vw, 1.3rem);
}
.tv__notice {
  color: var(--accent);
  font-weight: 700;
}

/* Probando en el móvil en vertical: apilado. */
@media (max-aspect-ratio: 1/1) {
  .tv {
    grid-template-columns: 1fr;
    justify-items: center;
    overflow-y: auto;
  }
  .tv__qr {
    width: min(70vw, 45vh);
  }
}
</style>
