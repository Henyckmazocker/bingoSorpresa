/**
 * Orden esperado de los premios de una partida, por simulación (no por fórmula).
 *
 * Las sorpresas no se reparten uniformes por la partida (`interleaveSurprises` las concentra en la
 * ventana `spreadOver`), así que la posición de cada premio se mide jugando muchas partidas con la
 * secuencia y los cartones de verdad: `buildSequence` (bingo/sequence.js) y `makePlayerCards`
 * (bingo/cards.js), no copias. Puro y reproducible: el mismo `seed` da el mismo resultado.
 */

import { buildSequence } from './sequence';
import { makePlayerCards } from './cards';
import { mulberry32 } from '@/utils/rng';

/** @typedef {import('./types').BingoConfig} BingoConfig */

const KINDS = ['numeric', 'music', 'image'];

// Cuantil por rango más cercano sobre un array YA ordenado (q=0.5 → mediana).
function quantile(sorted, q) {
  return sorted[Math.min(sorted.length - 1, Math.floor(q * sorted.length))];
}

// Posición (1-based) en la que se completa un conjunto de claves: la última en salir.
function doneAt(keys, pos) {
  let max = 0;
  for (const k of keys) {
    const p = pos.get(k);
    if (p > max) max = p;
  }
  return max;
}

// Filas de un cartón como listas de claves. Numérico: 3×9 con huecos `null` → 5 números por fila.
// Sorpresa: array plano rows*cols → fila r = slice(r*cols, (r+1)*cols), claves = `id`.
function cardRows(kind, card, config) {
  if (kind === 'numeric') return card.map((row) => row.filter((n) => n != null));
  const cols = config.modes[kind].card[1];
  const rows = [];
  for (let r = 0; r * cols < card.length; r++) {
    rows.push(card.slice(r * cols, (r + 1) * cols).map((item) => item.id));
  }
  return rows;
}

/**
 * Simula `games` partidas con `players` jugadores y devuelve en qué entrada cae cada premio.
 *
 * Premio = primera entrada en la que ALGÚN jugador completa: línea numérica = una fila de 5 ·
 * línea sorpresa = una fila de `cols` · bingo = cartón entero. Las posiciones son 1-based (entrada
 * nº N de la secuencia, como en «entrada 84 de 234»). Los modos desactivados no generan premios.
 *
 * Aviso entre premios consecutivos (por mediana) si median(b) - median(a) < max(6, round(0.03*total)).
 *
 * @param {BingoConfig} config  config válida (ver validateConfig)
 * @param {{ players?: number, games?: number, seed?: string }} [opts]
 * @returns {{ prizes: Array<{kind: string, prize: 'line'|'bingo', median: number, p10: number, p90: number}>,
 *             total: number,
 *             warnings: Array<{a: {kind: string, prize: string}, b: {kind: string, prize: string}, gap: number}> }}
 */
export function simulatePrizes(config, { players = 8, games = 1000, seed = 'prizes' } = {}) {
  const kinds = KINDS.filter((k) => config.modes[k].enabled);
  // samples['music:line'] = [posición de la partida 0, de la 1, …]
  const samples = {};
  for (const k of kinds) {
    samples[`${k}:line`] = [];
    samples[`${k}:bingo`] = [];
  }
  let total = 0;

  for (let g = 0; g < games; g++) {
    const seq = buildSequence(config, mulberry32(`${seed}::seq::g${g}`));
    total = seq.length;

    // Índice de aparición de cada número / id. Un Map por modo: un número y un id no chocan.
    const pos = { numeric: new Map(), music: new Map(), image: new Map() };
    seq.forEach((e, idx) => {
      pos[e.kind].set(e.kind === 'numeric' ? e.item : e.item.id, idx + 1);
    });

    const best = {};
    for (const k of kinds) best[k] = { line: Infinity, bingo: Infinity };

    // Cartones distintos en cada partida: la semilla de cartones depende de la partida.
    const cardSeed = `${seed}::g${g}`;
    for (let i = 0; i < players; i++) {
      const cards = makePlayerCards(config, cardSeed, i);
      for (const k of kinds) {
        const rowsDone = cardRows(k, cards[k], config).map((row) => doneAt(row, pos[k]));
        const line = Math.min(...rowsDone);
        const bingo = Math.max(...rowsDone);
        if (line < best[k].line) best[k].line = line;
        if (bingo < best[k].bingo) best[k].bingo = bingo;
      }
    }

    for (const k of kinds) {
      samples[`${k}:line`].push(best[k].line);
      samples[`${k}:bingo`].push(best[k].bingo);
    }
  }

  const prizes = Object.entries(samples)
    .map(([key, values]) => {
      const [kind, prize] = key.split(':');
      const sorted = values.slice().sort((x, y) => x - y);
      return {
        kind,
        prize,
        median: quantile(sorted, 0.5),
        p10: quantile(sorted, 0.1),
        p90: quantile(sorted, 0.9)
      };
    })
    .sort((x, y) => x.median - y.median);

  const minGap = Math.max(6, Math.round(0.03 * total));
  const warnings = [];
  for (let j = 1; j < prizes.length; j++) {
    const a = prizes[j - 1];
    const b = prizes[j];
    const gap = b.median - a.median;
    if (gap < minGap) {
      warnings.push({ a: { kind: a.kind, prize: a.prize }, b: { kind: b.kind, prize: b.prize }, gap });
    }
  }

  return { prizes, total, warnings };
}
