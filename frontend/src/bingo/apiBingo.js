/**
 * Fuente de bingo del backoffice: el `PlayPayload` del backend → `BingoConfig`.
 *
 * El payload ya llega con la forma de `BingoConfig` (`PlayPresenter.php`);
 * aquí solo se copia (sin compartir nada con la respuesta) y se resuelven las URLs de las fotos.
 *
 * Tres orígenes:
 *  - `loadApiBingo(id)`       → `get_play_payload`   (con cuenta, bingo propio)
 *  - `loadSharedBingo(token)` → `get_shared_payload` (público, enlace `/b/:token`)
 *  - `loadPrint(printId)`     → `get_print`          (el snapshot de una tirada: los items de
 *                                                      ENTONCES, con las URLs re-firmadas; una foto
 *                                                      ya borrada llega con `url`/`thumbUrl` a null)
 */

import { apiCall } from '@/services/api';

/** @typedef {import('./types').BingoConfig} BingoConfig */
/** @typedef {import('./types').BingoItem} BingoItem */

// En la APK las URLs relativas del backend (`/img.php?t=…`) necesitan el origen; en web es vacío
// (mismo origen). Se lee al llamar, no al importar, para poder probarlo.
function apiOrigin() {
  return process.env.VUE_APP_API_ORIGIN || '';
}

/** `/img.php?t=…` → `<origen>/img.php?t=…`. Las absolutas y los null pasan tal cual. */
export function withOrigin(url, origin = apiOrigin()) {
  if (!url || !origin || /^[a-z][a-z0-9+.-]*:/i.test(url)) return url ?? null;
  return origin.replace(/\/+$/, '') + (url.startsWith('/') ? url : `/${url}`);
}

/** @returns {BingoItem} */
function toItem(item, origin) {
  const media = item.media || {};
  return {
    id: String(item.id),
    kind: item.kind,
    label: item.label,
    sublabel: item.sublabel ?? null,
    media: {
      type: media.type || (item.kind === 'image' ? 'image' : 'youtube'),
      url: withOrigin(media.url, origin),
      thumbUrl: withOrigin(media.thumbUrl, origin),
      videoId: media.videoId ?? null,
      startSeconds: media.startSeconds ?? null,
      endSeconds: media.endSeconds ?? null
    }
  };
}

function toSurprise(mode, origin) {
  return {
    enabled: !!mode.enabled,
    label: mode.label,
    card: [Number(mode.card[0]), Number(mode.card[1])],
    // El ORDEN de los items se conserva tal cual: los cartones barajan el pool con semilla.
    items: (mode.items || []).map((i) => toItem(i, origin))
  };
}

/**
 * PlayPayload → BingoConfig. Puro (sin red ni store): lo usan los tres cargadores y, en M5, la tele.
 * @returns {BingoConfig}
 */
export function fromPayload(payload, origin = apiOrigin()) {
  const { modes, plan } = payload;
  return {
    id: String(payload.id),
    title: payload.title,
    modes: {
      numeric: { enabled: !!modes.numeric.enabled, label: modes.numeric.label },
      music: toSurprise(modes.music, origin),
      image: toSurprise(modes.image, origin)
    },
    plan: { leadIn: plan.leadIn, minGap: plan.minGap, spreadOver: plan.spreadOver }
  };
}

/** @returns {Promise<BingoConfig>} */
export async function loadApiBingo(id) {
  return fromPayload(await apiCall('get_play_payload', { bingo_id: Number(id) }));
}

/** @returns {Promise<BingoConfig>} */
export async function loadSharedBingo(token) {
  return fromPayload(await apiCall('get_shared_payload', { share_token: String(token) }));
}

/** @returns {Promise<BingoConfig>} */
export async function loadPrint(printId) {
  return fromPayload(await apiCall('get_print', { print_id: Number(printId) }));
}

/**
 * Guarda la tirada que se va a imprimir. Con `bingoId` (cuenta) o con `shareToken` (enlace público).
 * @returns {Promise<{printId:number}>}
 */
export function savePrint({ bingoId, shareToken, seed, players }) {
  const target = shareToken ? { share_token: shareToken } : { bingo_id: Number(bingoId) };
  return apiCall('save_print', { ...target, seed, players });
}

/** @returns {Promise<Array<{printId:number, seed:string, players:number, createdAt:string}>>} */
export function listPrints(bingoId) {
  return apiCall('list_prints', { bingo_id: Number(bingoId) });
}

/**
 * ¿El error es «este bingo ya no existe»? 404 (borrado, ajeno o compartido apagado) o 403.
 * El 403 de CSRF (`error_code: 'CSRF_INVALID'`) no cuenta: es la sesión caducada y se enseña su
 * mensaje. Lo demás (red, 500, validación) también se enseña con su mensaje.
 */
export function isGone(err) {
  if (!err) return false;
  if (err.response && err.response.error_code === 'CSRF_INVALID') return false;
  return err.status === 404 || err.status === 403;
}
