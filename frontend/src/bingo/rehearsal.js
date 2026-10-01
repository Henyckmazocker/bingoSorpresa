/**
 * Lógica del ensayo de canciones, sin Vue ni YouTube (la usa `RehearsalPanel`).
 *
 * Recorre los items de uno en uno: `load(item)` pide al player que cargue el vídeo y el panel avisa
 * con `playing()` (estado PLAYING) o `error(code)` (`onError`). Lo primero que llegue decide; si
 * en `timeoutMs` no llega nada, ⏱️. Los avisos que lleguen sin canción en curso se ignoran (eventos
 * rezagados del vídeo anterior).
 *
 * Resultados: `{ status: 'ok' }` · `{ status: 'error', code }` · `{ status: 'timeout' }`.
 */

export const REHEARSAL_TIMEOUT_MS = 10000;

export function createRehearsal({ items, load, onResult, timeoutMs = REHEARSAL_TIMEOUT_MS }) {
  let settle = null; // resuelve la canción en curso
  let stopped = false;

  function report(result) {
    if (!settle) return;
    const s = settle;
    settle = null;
    s(result);
  }

  async function run() {
    for (let i = 0; i < items.length && !stopped; i++) {
      const result = await new Promise((resolve) => {
        const timer = setTimeout(() => report({ status: 'timeout' }), timeoutMs);
        settle = (r) => {
          clearTimeout(timer);
          resolve(r);
        };
        load(items[i]);
      });
      if (stopped) return;
      onResult(i, result);
    }
  }

  return {
    run,
    playing: () => report({ status: 'ok' }),
    error: (code) => report({ status: 'error', code }),
    stop() {
      stopped = true;
      report({ status: 'stopped' });
    }
  };
}

/**
 * Canciones de YouTube de un bingo (`BingoConfig`) que se pueden ensayar: las del modo musical
 * activo con `media.type === 'youtube'`. Vacío si el modo está apagado o no tiene canciones. La
 * APK lo usa para decidir si pasa por `TvReadyView` antes del cantor.
 */
export function youtubeSongs(config) {
  const music = config && config.modes && config.modes.music;
  if (!music || !music.enabled || !Array.isArray(music.items)) return [];
  return music.items.filter((it) => it && it.media && it.media.type === 'youtube');
}
