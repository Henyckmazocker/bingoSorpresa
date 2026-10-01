/**
 * Lógica de bingo: pools, sacar bolas y generación de cartones.
 *
 * El cantor (CallerView) y el generador de cartones (PrintView) comparten estas funciones y los
 * mismos pools de datos, de modo que los cartones impresos SIEMPRE casan con lo que canta la tele.
 */

import { mulberry32, shuffle, sample } from './rng';

// --- Modo numérico (bingo español de 90 bolas) ---

export const NUMERIC_MAX = 90;

// Rango de cada una de las 9 columnas del cartón español (por decenas).
const COLUMN_RANGES = [
  [1, 9], [10, 19], [20, 29], [30, 39], [40, 49],
  [50, 59], [60, 69], [70, 79], [80, 90]
];

function rangeArray(from, to) {
  const out = [];
  for (let n = from; n <= to; n++) out.push(n);
  return out;
}

// El pool completo de bolas 1..90.
export function numberPool() {
  return rangeArray(1, NUMERIC_MAX);
}

// Reparte 15 números en 9 columnas: cada columna 1..3, total 15.
function distributeCounts(rng) {
  const counts = new Array(9).fill(1); // cada columna al menos 1 (regla del cartón español)
  let remaining = 15 - 9; // 6 números extra por repartir
  while (remaining > 0) {
    const col = Math.floor(rng() * 9);
    if (counts[col] < 3) {
      counts[col]++;
      remaining--;
    }
  }
  return counts;
}

// Dada la cuenta por columna, decide en qué filas (0..2) va cada número, con 5 por fila.
function assignRows(counts, rng) {
  for (let attempt = 0; attempt < 500; attempt++) {
    const grid = [new Array(9).fill(false), new Array(9).fill(false), new Array(9).fill(false)];
    for (let col = 0; col < 9; col++) {
      const rows = shuffle([0, 1, 2], rng).slice(0, counts[col]);
      rows.forEach((r) => (grid[r][col] = true));
    }
    if (grid.every((row) => row.filter(Boolean).length === 5)) return grid;
  }
  return null; // extremadamente raro; el llamador reintenta con otra cuenta
}

/**
 * Genera un cartón español 3×9 (15 números). Devuelve una matriz 3×9 donde cada celda es
 * un número o null (hueco). `seed` lo hace reproducible.
 */
export function makeNumericCard(seed) {
  const rng = mulberry32(seed);
  let counts;
  let rowMask = null;
  while (!rowMask) {
    counts = distributeCounts(rng);
    rowMask = assignRows(counts, rng);
  }
  // Números elegidos por columna, ordenados de arriba a abajo.
  const colNumbers = COLUMN_RANGES.map((r, i) =>
    sample(rangeArray(r[0], r[1]), counts[i], rng).sort((a, b) => a - b)
  );
  const cursor = new Array(9).fill(0);
  return rowMask.map((row) =>
    row.map((filled, col) => (filled ? colNumbers[col][cursor[col]++] : null))
  );
}

// --- Modos con lista de items (musical / recuerdos): cartón = rejilla de items ---

/**
 * Genera un cartón de items: una rejilla rows×cols con items únicos del pool.
 * Devuelve un array plano de items (longitud rows*cols). `seed` lo hace reproducible.
 */
export function makeItemCard(pool, rows, cols, seed) {
  const rng = mulberry32(seed);
  return sample(pool, rows * cols, rng);
}

// --- Sacar bolas / items (orden aleatorio sin repetir) ---

// Devuelve el orden de canto de un pool. Con semilla es reproducible; sin ella, aleatorio real.
export function drawOrder(pool, seed) {
  const rng = seed != null ? mulberry32(seed) : Math.random;
  return shuffle(pool, rng);
}

/**
 * Intercala unas cuantas "sorpresas" (canciones/recuerdos) dentro de la tanda de bolas normales,
 * en posiciones imprevisibles pero repartidas. Así el juego es un bingo numérico corriente y, de
 * vez en cuando, en lugar de una bola sale una sorpresa (sin cortinilla, sin avisar).
 *
 * @param {Array} base        secuencia de items normales (las bolas ya barajadas)
 * @param {Array} surprises   items sorpresa ya elegidos y en el orden en que aparecerán
 * @param {Object} opts
 *   - leadIn:     cuántas bolas normales caen SIEMPRE antes de la primera sorpresa (para que el
 *                 juego se asiente como un bingo normal y nadie se la espere).
 *   - minGap:     mínimo de bolas normales entre dos sorpresas (evita que caigan seguidas).
 *   - spreadOver: reparte las sorpresas dentro de las primeras N bolas. Las partidas suelen
 *                 acabar antes de cantar las 90, así que conviene que las sorpresas salgan pronto.
 *   - rng:        fuente de azar (por defecto Math.random → distinto cada partida).
 * @returns {Array} secuencia combinada [...items normales y sorpresa entremezclados].
 */
export function interleaveSurprises(base, surprises, opts = {}) {
  const { leadIn = 7, minGap = 4, spreadOver = 55, rng = Math.random } = opts;
  const n = base.length;
  const k = surprises.length;
  if (!k) return base.slice();

  // Elige una posición de inserción por sorpresa: repartidas en buckets dentro de la ventana,
  // con jitter, respetando el arranque (leadIn) y la separación mínima (minGap).
  const window = Math.min(Math.max(spreadOver, leadIn + minGap * k), n);
  const start = Math.min(leadIn, window);
  // El bucket puede ser < 1: si hay tantas sorpresas como bolas (p. ej. salen TODOS los recuerdos)
  // no caben minGap bolas entre cada dos, así que la separación se relaja a lo que dé de sí la
  // ventana. Sin esto las posiciones chocarían contra el tope y las sorpresas sobrantes se
  // amontonarían todas al final de la partida.
  const bucket = (window - start) / k;
  const gap = Math.min(minGap, Math.floor(bucket));
  const positions = [];
  let last = start - gap;
  for (let i = 0; i < k; i++) {
    let p = Math.floor(start + i * bucket + rng() * bucket);
    if (p < last + gap) p = last + gap;
    if (p > n) p = n;
    positions.push(p);
    last = p;
  }

  // Reconstruye la secuencia insertando cada sorpresa "después de p bolas normales".
  const out = [];
  let si = 0;
  for (let i = 0; i <= n; i++) {
    while (si < k && positions[si] === i) out.push(surprises[si++]);
    if (i < n) out.push(base[i]);
  }
  while (si < k) out.push(surprises[si++]); // por si alguna cayó justo al final
  return out;
}
