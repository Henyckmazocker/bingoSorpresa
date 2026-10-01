// Generación de los cartones de un jugador a partir de un BingoConfig. Puro: lo comparten
// PrintView y la regresión dorada (tests/unit/golden.test.js).
import { makeNumericCard, makeItemCard } from '@/utils/bingo';

/**
 * Nombre del modo que va DENTRO de la semilla de cada cartón.
 *
 * NO se puede «limpiar» a `kind`: la semilla es `${seed}::${clave}::${i}`, y los cartones ya
 * impresos se generaron con los nombres viejos de la fiesta ('musical', 'recuerdos'). Cambiar una
 * clave cambia TODOS los cartones de esa semilla, y reimprimir un cartón perdido daría otro
 * distinto al que tiene el resto de jugadores. Lo vigila el fixture dorado.
 */
export const LEGACY_SEED_KEY = Object.freeze({
  numeric: 'numeric',
  music: 'musical',
  image: 'recuerdos'
});

/** Semilla de un cartón: depende solo de la semilla, el modo y el índice del jugador. */
export function seedFor(seed, kind, i) {
  return `${seed}::${LEGACY_SEED_KEY[kind]}::${i}`;
}

/**
 * Cartones del jugador `i` (base 0). Como la semilla depende solo de (seed, kind, i), un cartón
 * concreto no cambia al variar el nº de jugadores: se puede reimprimir uno suelto.
 * Los modos desactivados devuelven `null`.
 *
 * @param {import('./types').BingoConfig} config
 * @param {string} seed
 * @param {number} i
 * @returns {{ numeric: (number|null)[][]|null,
 *             music: import('./types').BingoItem[]|null,
 *             image: import('./types').BingoItem[]|null }}
 */
export function makePlayerCards(config, seed, i) {
  const { numeric, music, image } = config.modes;
  // Los items se pasan tal cual: makeItemCard depende del orden del pool.
  const itemCard = (mode, kind) =>
    mode.enabled ? makeItemCard(mode.items, mode.card[0], mode.card[1], seedFor(seed, kind, i)) : null;
  return {
    numeric: numeric.enabled ? makeNumericCard(seedFor(seed, 'numeric', i)) : null,
    music: itemCard(music, 'music'),
    image: itemCard(image, 'image')
  };
}
