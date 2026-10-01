/**
 * Secuencia de la partida a partir de un `BingoConfig` (puro, sin store).
 */

import { numberPool, drawOrder, interleaveSurprises } from '@/utils/bingo';
import { shuffle } from '@/utils/rng';

/** @typedef {import('./types').BingoConfig} BingoConfig */

// Baraja con el rng inyectado; sin él, `drawOrder` sin semilla (Math.random, como hoy).
function order(pool, rng) {
  return rng ? shuffle(pool, rng) : drawOrder(pool);
}

/**
 * Construye la secuencia de la partida: bolas 1..90 barajadas (si el modo numérico está activo) con
 * TODOS los items de los modos sorpresa activos intercalados. Cada entrada es `{ kind, item }`.
 *
 * @param {BingoConfig} config
 * @param {() => number} [rng] fuente de azar; sin ella cae a Math.random (la partida no es
 *   reproducible, como hoy). Existe para simular partidas.
 * @returns {Array<{kind: string, item: any}>}
 */
export function buildSequence(config, rng) {
  const { modes, plan } = config;

  const base = modes.numeric.enabled
    ? order(numberPool(), rng).map((n) => ({ kind: 'numeric', item: n }))
    : [];

  // Música y luego fotos: el mismo orden de concatenación que el store de antes.
  const pool = [
    ...(modes.music.enabled ? modes.music.items : []),
    ...(modes.image.enabled ? modes.image.items : [])
  ];
  const surprises = order(pool.map((item) => ({ kind: item.kind, item })), rng);

  const opts = { leadIn: plan.leadIn, minGap: plan.minGap, spreadOver: plan.spreadOver };
  if (rng) opts.rng = rng;
  return interleaveSurprises(base, surprises, opts);
}
