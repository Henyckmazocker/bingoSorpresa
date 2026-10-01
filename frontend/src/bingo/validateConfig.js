/**
 * Reglas de validación de un `BingoConfig` (puro, sin store). Fuente única de las reglas E1–E4.
 *
 * `BingoValidator.php` del backoffice repetirá estas MISMAS cuatro reglas con los MISMOS mensajes
 * (cada regla de allí apuntará a su gemela de aquí). Si cambias una regla o un mensaje, cambia
 * también la gemela PHP.
 */

import { MODE_KINDS } from './types';

/** @typedef {import('./types').BingoConfig} BingoConfig */

// Lados admitidos para un cartón sorpresa: de 2×2 (4 casillas) a 6×6 (36 casillas).
export const CARD_LIMITS = { minSide: 2, maxSide: 6 };

// Modos sorpresa (los que llevan cartón e items).
const SURPRISE_KINDS = ['music', 'image'];

// Nombre fijo y legible de los items de cada modo sorpresa, para los mensajes. Se usa este nombre y
// no la `label` del modo porque la label es libre (la escribe quien crea el bingo) y no encaja en
// frases como «solo hay 12 fotos».
const ITEM_NOUN = { music: 'canciones', image: 'fotos' };

function isValidSide(n) {
  return Number.isInteger(n) && n >= CARD_LIMITS.minSide && n <= CARD_LIMITS.maxSide;
}

/**
 * Valida un `BingoConfig`.
 * @param {BingoConfig} config
 * @returns {{ ok: boolean, errors: string[] }}
 */
export function validateConfig(config) {
  const errors = [];
  const modes = (config && config.modes) || {};

  // E1 — al menos un modo activo. (Gemela: BingoValidator.php, regla E1.)
  if (!MODE_KINDS.some((kind) => modes[kind] && modes[kind].enabled)) {
    errors.push('Activa al menos un modo de juego.');
  }

  // E2–E4 solo para modos sorpresa ACTIVOS: uno desactivado puede estar vacío.
  for (const kind of SURPRISE_KINDS) {
    const mode = modes[kind];
    if (!mode || !mode.enabled) continue;
    const noun = ITEM_NOUN[kind];
    const count = Array.isArray(mode.items) ? mode.items.length : 0;

    // E2 — modo sorpresa activo → al menos 1 item. (Gemela: BingoValidator.php, regla E2.)
    if (count < 1) {
      errors.push(`El modo de ${noun} está activo y tiene 0 ${noun}.`);
    }

    // E3 — modo sorpresa activo → cartón [filas, columnas] con lados entre 2 y 6.
    // (Gemela: BingoValidator.php, regla E3.)
    const card = mode.card;
    const shapeOk = Array.isArray(card) && card.length === 2 && isValidSide(card[0]) && isValidSide(card[1]);
    if (!shapeOk) {
      const shown = Array.isArray(card) ? card.join('×') : String(card);
      errors.push(
        `El cartón de ${noun} mide ${shown}; filas y columnas deben estar entre ` +
          `${CARD_LIMITS.minSide} y ${CARD_LIMITS.maxSide}.`
      );
    }

    // E4 — modo sorpresa activo → filas×columnas ≤ items (si no, el cartón no se puede rellenar).
    // Solo se comprueba con un cartón válido y algún item: si no, E2/E3 ya lo explican.
    // (Gemela: BingoValidator.php, regla E4.)
    if (shapeOk && count >= 1) {
      const cells = card[0] * card[1];
      if (cells > count) {
        errors.push(`El cartón de ${noun} tiene ${cells} casillas y solo hay ${count} ${noun}.`);
      }
    }
  }

  return { ok: errors.length === 0, errors };
}
