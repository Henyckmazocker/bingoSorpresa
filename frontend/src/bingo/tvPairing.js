/**
 * La tele por código (M5). La APK no tiene cuenta: pide un código de 6 cifras y lo enseña (en
 * grande y en un QR); el dueño de un bingo lo reclama desde la web (`/enviar?code=`) y la tele,
 * que sondea cada 3 s con su `deviceToken`, recibe el `PlayPayload` y se pone a cantar.
 *
 *   pair_create                 → {code, deviceToken, expiresIn}       (tele, público)
 *   pair_poll {deviceToken}     → {status:'waiting'} | {status:'ready', payload} · 410 caducado
 *   pair_claim {code, bingo_id} → {}  (web, con cuenta) · 404 inexistente/caducado · 409 ya reclamado
 */

import { apiCall } from '@/services/api';

/** Cada cuánto sondea la tele (el «Hecho cuando» pide ≤ 5 s de punta a punta). */
export const POLL_MS = 3000;

/**
 * Web pública a la que apunta el QR. Es el mismo origen que la API de la APK
 * (`VUE_APP_API_ORIGIN`): nginx sirve la web y hace de proxy de `/api`.
 */
export const WEB_ORIGIN = 'https://bingo.dcahomelab.com';

function webOrigin() {
  return process.env.VUE_APP_API_ORIGIN || WEB_ORIGIN;
}

/** Lo que codifica el QR: la página de la web que elige el bingo para ese código. */
export function pairUrl(code, origin = webOrigin()) {
  return `${origin.replace(/\/+$/, '')}/#/enviar?code=${encodeURIComponent(code)}`;
}

/** «123456» → «123 456» (se lee mejor desde el sofá). */
export function formatCode(code) {
  const s = String(code || '');
  return s.length === 6 ? `${s.slice(0, 3)} ${s.slice(3)}` : s;
}

/** Lo que el usuario teclee («123 456», «123-456») → «123456», o null si no son 6 cifras. */
export function normalizeCode(value) {
  const digits = String(value ?? '').replace(/[\s-]/g, '');
  return /^\d{6}$/.test(digits) ? digits : null;
}

/** ¿El sondeo dice que el código ha caducado (la tele debe pedir otro)? */
export function isExpired(err) {
  return !!err && err.status === 410;
}

/** @returns {Promise<{code:string, deviceToken:string, expiresIn:number}>} */
export function createPairing() {
  return apiCall('pair_create');
}

/** @returns {Promise<{status:'waiting'} | {status:'ready', payload:object}>} */
export function pollPairing(deviceToken) {
  return apiCall('pair_poll', { deviceToken });
}

/** @returns {Promise<object>} */
export function claimPairing(code, bingoId) {
  return apiCall('pair_claim', { code, bingo_id: Number(bingoId) });
}
