/**
 * Texto de la lista «orden de premios» (`PrizeOrderHint.vue`) a partir de `simulatePrizes`.
 * Puro, sin Vue: el editor del backoffice lo reutiliza con el mismo componente.
 */

/** @typedef {import('./types').BingoConfig} BingoConfig */

// Nombre de cada premio; el modo se añade con su `label` («Línea · Bingo Musical»).
export const PRIZE_NAMES = { line: 'Línea', bingo: 'BINGO' };

/**
 * Etiqueta legible de un premio: «Línea · Bingo Musical», «BINGO · Bingo de Recuerdos».
 * @param {string} kind   ModeKind
 * @param {'line'|'bingo'} prize
 * @param {BingoConfig} config
 */
export function prizeLabel(kind, prize, config) {
  return `${PRIZE_NAMES[prize]} · ${config.modes[kind].label}`;
}

/**
 * Filas de la lista, en el orden de `result.prizes` (por mediana). `warn` marca los dos premios de
 * cada aviso de `result.warnings` (premios consecutivos demasiado juntos).
 *
 * @param {ReturnType<typeof import('./prizes').simulatePrizes>} result
 * @param {BingoConfig} config
 * @returns {Array<{ key: string, text: string, warn: boolean }>}
 */
export function prizeHintRows(result, config) {
  const key = (p) => `${p.kind}:${p.prize}`;
  const warned = new Set();
  for (const w of result.warnings) {
    warned.add(key(w.a));
    warned.add(key(w.b));
  }
  return result.prizes.map((p) => ({
    key: key(p),
    text: `${prizeLabel(p.kind, p.prize, config)} ≈ entrada ${p.median} de ${result.total}`,
    warn: warned.has(key(p))
  }));
}
