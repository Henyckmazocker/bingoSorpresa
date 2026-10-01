import { defineStore } from 'pinia';
import { buildSequence } from '@/bingo/sequence';
import { validateConfig } from '@/bingo/validateConfig';
import { MODE_KINDS } from '@/bingo/types';

// Tipos de item que pueden salir. El juego ES un bingo numérico; música/fotos son las
// "sorpresas" que caen de vez en cuando, sin avisar, en lugar de una bola.
export const MODE_META = {
  numeric: { label: 'Bingo', color: 'var(--accent)' },
  music: { label: 'Bingo Musical', color: 'var(--music)' },
  image: { label: 'Bingo de Recuerdos', color: 'var(--image)' }
};

// El plan de sorpresas (cuántas salen y cómo se reparten) vive ahora en la config del bingo:
// ver `plan` en `bingo/types.js` y `bingo/sequence.js`.

// Cuántos pulsos de 👁️ tiene una entrada. Un vídeo va en dos (tapa → título y artista → sin tapa);
// una foto, en uno (la foto se ve desde el principio y el paso 1 es el título); un número sale ya
// revelado.
export function maxStep(entry) {
  if (!entry || entry.kind === 'numeric') return 0;
  return entry.item && entry.item.media && entry.item.media.type === 'youtube' ? 2 : 1;
}

export const useGameStore = defineStore('game', {
  state: () => ({
    // Bingo cargado (`BingoConfig`, ver `bingo/types.js`); null hasta `loadBingo()`.
    config: null,
    // Secuencia única de la partida: cada entrada es { kind, item }.
    sequence: [],
    drawn: 0, // cuántas entradas se han sacado
    // Paso de revelado de la entrada actual: 0 = oculta; sube con 👁️ hasta `maxStep(entrada)`.
    revealStep: 0,
    surpriseCue: 0 // se incrementa al sacar una sorpresa; la vista lo usa para la señal breve
  }),

  getters: {
    // Entrada recién sacada (número, canción o recuerdo), o null si aún no se ha sacado nada.
    currentEntry: (s) => (s.drawn > 0 ? s.sequence[s.drawn - 1] : null),
    // Modo "actual" derivado del item en pantalla (para colores/cabecera y elegir componente).
    mode() {
      return this.currentEntry ? this.currentEntry.kind : 'numeric';
    },
    // El item en sí (número, objeto canción u objeto recuerdo).
    current() {
      return this.currentEntry ? this.currentEntry.item : null;
    },
    // ¿Ya no queda nada que revelar en la entrada actual? (deshabilita 👁️)
    revealDone() {
      return this.revealStep >= maxStep(this.currentEntry);
    },
    // Última canción SACADA (no `current`): el reproductor de YouTube la conserva mientras salen
    // bolas o fotos, en vez de intentar cargar un número. null si aún no ha salido ninguna.
    lastMusicItem: (s) => {
      for (let i = s.drawn - 1; i >= 0; i--) {
        if (s.sequence[i].kind === 'music') return s.sequence[i].item;
      }
      return null;
    },
    // ¿La entrada actual es una sorpresa (necesita revelado)?
    isSurprise() {
      return this.currentEntry != null && this.currentEntry.kind !== 'numeric';
    },
    // Cuántas entradas del mismo tipo llevamos (para "Canción nº 3", "Recuerdo nº 2").
    currentKindIndex: (s) => {
      const entry = s.drawn > 0 ? s.sequence[s.drawn - 1] : null;
      if (!entry) return 0;
      let c = 0;
      for (let i = 0; i < s.drawn; i++) if (s.sequence[i].kind === entry.kind) c++;
      return c;
    },
    // Números ya cantados (para pintar el tablero).
    drawnNumbersSet: (s) => {
      const set = new Set();
      for (let i = 0; i < s.drawn; i++) {
        if (s.sequence[i].kind === 'numeric') set.add(s.sequence[i].item);
      }
      return set;
    },
    // Recuento de lo sacado por tipo (para la pantalla de cierre; durante la partida NO se enseña:
    // delataría cuántas sorpresas quedan).
    drawnByKind: (s) => {
      const counts = { numeric: 0, music: 0, image: 0 };
      for (let i = 0; i < s.drawn; i++) counts[s.sequence[i].kind]++;
      return counts;
    },
    // Modos activos del bingo cargado, en el orden de `MODE_KINDS` (para la pantalla de cierre).
    enabledKinds: (s) => (s.config ? MODE_KINDS.filter((kind) => s.config.modes[kind].enabled) : []),
    remaining: (s) => s.sequence.length - s.drawn,
    canDraw() {
      return this.remaining > 0;
    }
  },

  actions: {
    // Carga un bingo (`BingoConfig`) y prepara una partida nueva con él. Si la config no pasa
    // `validateConfig` lanza un Error con todos los fallos y no toca la partida en curso.
    loadBingo(config) {
      const { ok, errors } = validateConfig(config);
      if (!ok) throw new Error(errors.join('; '));
      this.config = config;
      this.initGame();
    },

    // Prepara (o reinicia) la partida: baraja bolas y sorpresas de la config cargada.
    initGame() {
      if (!this.config) {
        throw new Error('initGame(): no hay bingo cargado; llama antes a loadBingo(config).');
      }
      this.sequence = buildSequence(this.config);
      this.drawn = 0;
      this.revealStep = 0;
      this.surpriseCue = 0;
    },

    // Saca la siguiente entrada de la secuencia.
    draw() {
      if (!this.canDraw) return;
      this.drawn++;
      const entry = this.sequence[this.drawn - 1];
      // Todo empieza en el paso 0: un número tiene maxStep 0, así que ya sale «revelado»; las
      // sorpresas empiezan ocultas (para adivinar).
      this.revealStep = 0;
      // Sorpresa → dispara la señal breve (sin cortinilla).
      if (entry.kind !== 'numeric') this.surpriseCue++;
    },

    // Un paso más de revelado del item actual (título de canción / texto del recuerdo / vídeo).
    reveal() {
      this.revealStep = Math.min(this.revealStep + 1, maxStep(this.currentEntry));
    },

    // Revelado completo de golpe: la canción no se puede reproducir y al menos se ve el título.
    revealAll() {
      this.revealStep = maxStep(this.currentEntry);
    }
  }
});
