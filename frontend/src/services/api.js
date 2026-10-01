// Cliente del backend (endpoint único: POST {action, …}),
// con fetch en vez de axios para no añadir una dependencia por una sola llamada.
//
// Web: `/api` en el mismo origen (nginx lo reescribe a /index.php del backend).
// APK: `VUE_APP_API_ORIGIN` (https://bingo.dcahomelab.com) + `/api`.
const API_URL = `${process.env.VUE_APP_API_ORIGIN || ''}/api`;
const JWT_KEY = 'bingosorpresa_jwt';

// localStorage puede lanzar (navegación privada, almacenamiento bloqueado): sin él no hay sesión
// persistente, pero la app no debe romperse.
function storageGet(key) {
  try { return localStorage.getItem(key); } catch (e) { return null; }
}
function storageSet(key, value) {
  try {
    if (value) localStorage.setItem(key, value);
    else localStorage.removeItem(key);
  } catch (e) { /* sin almacenamiento: la sesión dura lo que la pestaña */ }
}

let csrfToken = null;
export const setCsrfToken = (t) => { csrfToken = t; };
export const getJwt = () => storageGet(JWT_KEY);
export const setJwt = (t) => storageSet(JWT_KEY, t);

/**
 * Resuelve una URL relativa del backend (p. ej. "/img.php?t=…") a una absoluta.
 * En web la API es del mismo origen y la ruta relativa vale tal cual; en la APK se antepone el origen.
 */
export function resolveUrl(path) {
  if (!path) return path;
  if (/^https?:\/\//i.test(path)) return path;
  const origin = process.env.VUE_APP_API_ORIGIN;
  if (origin) {
    try { return new URL(path, origin).href; } catch (e) { return path; }
  }
  return path;
}

/**
 * Llama a una acción del backend. Devuelve `data` si va bien; si no, lanza un Error con el
 * `message` del backend y `err.status` con el código HTTP.
 */
export async function apiCall(action, payload = {}) {
  const body = { action, ...payload };
  if (csrfToken) body.csrf_token = csrfToken;

  const headers = { 'Content-Type': 'application/json' };
  const token = getJwt();
  if (token) headers.Authorization = `Bearer ${token}`;

  const res = await fetch(API_URL, {
    method: 'POST',
    credentials: 'include', // cookie de sesión en web
    headers,
    body: JSON.stringify(body)
  });

  let data = null;
  try { data = await res.json(); } catch (e) { /* respuesta no JSON (proxy caído, 502…) */ }
  if (!data || data.status !== 'success') {
    const err = new Error((data && data.message) || `Error en la petición (${res.status})`);
    err.status = res.status;
    err.response = data;
    throw err;
  }
  return data.data;
}

/**
 * Sube un fichero a una acción multipart (`upload_image`): campos de texto + `file`. Con
 * XMLHttpRequest y no fetch porque fetch no da progreso de subida. `onProgress(0..1)`.
 * Devuelve `data` o lanza un Error con `message` y `status`, igual que `apiCall`.
 */
export function apiUpload(action, fields, file, onProgress) {
  const form = new FormData();
  form.append('action', action);
  for (const [k, v] of Object.entries(fields)) form.append(k, String(v));
  if (csrfToken) form.append('csrf_token', csrfToken);
  form.append('file', file, file.name);

  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', API_URL);
    xhr.withCredentials = true; // cookie de sesión en web
    const token = getJwt();
    if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`);
    // Sin Content-Type a mano: el navegador pone el boundary del multipart.
    if (onProgress) {
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) onProgress(e.loaded / e.total);
      };
    }
    xhr.onload = () => {
      let data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) { /* no JSON (502, 413 de nginx…) */ }
      if (data && data.status === 'success') {
        resolve(data.data);
        return;
      }
      const fallback = xhr.status === 413 ? 'La foto pesa más de 10 MB.' : `Error en la subida (${xhr.status})`;
      const err = new Error((data && data.message) || fallback);
      err.status = xhr.status;
      err.response = data;
      reject(err);
    };
    xhr.onerror = () => {
      const err = new Error('No hay conexión con el servidor.');
      err.status = 0;
      reject(err);
    };
    xhr.send(form);
  });
}

export default { apiCall, apiUpload, setCsrfToken, setJwt, getJwt, resolveUrl };
