/**
 * Disposición de la maqueta A4 según los modos activos de un `BingoConfig` (puro, sin store).
 *
 *  fold           numérico + ≥1 sorpresa → cara de números con solapas + reverso con 1 o 2
 *                 cartones sorpresa (la de casa; se imprime a doble cara).
 *  numeric-only   una página por jugador: la banda central con solapas, sin reverso.
 *  surprises-only una página por jugador, a una cara, con 1 o 2 cartones apilados; sin solapas.
 */

/** @typedef {import('./types').BingoConfig} BingoConfig */
/** @typedef {'fold'|'numeric-only'|'surprises-only'} PrintLayout */

// Modos sorpresa, en el orden en que se apilan en la hoja (musical arriba, recuerdos abajo).
export const SURPRISE_KINDS = ['music', 'image'];

// Cartones de la fiesta de casa (musical 3×3, recuerdos 4×5). Con estas medidas la
// letra de las casillas es la de siempre (4,6 mm / 3,2 mm en PrintView); con cualquier otra se usa
// la regla `--cell-font`. Así la combinación de casa se imprime idéntica a antes del plan.
const HOUSE_CARDS = { music: [3, 3], image: [4, 5] };

function isOn(config, kind) {
  const mode = config && config.modes && config.modes[kind];
  return Boolean(mode && mode.enabled);
}

/**
 * Modos sorpresa activos, en orden de apilado.
 * @param {BingoConfig} config
 * @returns {('music'|'image')[]}
 */
export function activeSurprises(config) {
  return SURPRISE_KINDS.filter((kind) => isOn(config, kind));
}

/**
 * @param {BingoConfig} config
 * @returns {PrintLayout}
 */
export function printLayout(config) {
  const numeric = isOn(config, 'numeric');
  const surprises = activeSurprises(config).length > 0;
  if (numeric && surprises) return 'fold';
  if (numeric) return 'numeric-only';
  if (surprises) return 'surprises-only';
  // Sin ningún modo activo (inválida: E1 de validateConfig ya bloquea «Generar»). Se devuelve
  // 'fold', la disposición de casa, para que el panel no cambie de texto mientras se corrige.
  return 'fold';
}

/**
 * ¿Este cartón sorpresa conserva la letra fija de la fiesta de casa? Solo con el modo y las
 * medidas exactas de casa; cualquier otro tamaño usa `--cell-font`.
 * @param {'music'|'image'} kind
 * @param {[number, number]} card
 */
export function usesHouseFont(kind, card) {
  const house = HOUSE_CARDS[kind];
  return Boolean(house && Array.isArray(card) && card[0] === house[0] && card[1] === house[1]);
}
