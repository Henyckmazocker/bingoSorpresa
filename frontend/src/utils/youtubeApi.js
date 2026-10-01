/**
 * Cargador de la IFrame Player API de YouTube, compartido por el ensayo (`RehearsalPanel`) y el
 * reproductor de la partida (`YoutubePlayer`, M3).
 *
 * El script `https://www.youtube.com/iframe_api` se inyecta UNA sola vez: la promesa vive en
 * `window` para que la compartan todos los componentes (y sobreviva a un recambio en caliente de
 * este módulo). Si el script no carga (sin red, CSP), la promesa se olvida y se puede reintentar.
 */

const API_SRC = 'https://www.youtube.com/iframe_api';
const PROMISE_KEY = '__bingoYoutubeApi';

/** @returns {Promise<any>} el objeto global `YT`, ya listo. */
export function loadYoutubeApi() {
  if (window.YT && window.YT.Player) return Promise.resolve(window.YT);
  if (!window[PROMISE_KEY]) {
    window[PROMISE_KEY] = new Promise((resolve, reject) => {
      // La API avisa llamando a este global; se encadena por si alguien más lo había puesto.
      const previous = window.onYouTubeIframeAPIReady;
      window.onYouTubeIframeAPIReady = () => {
        if (typeof previous === 'function') previous();
        resolve(window.YT);
      };
      const script = document.createElement('script');
      script.src = API_SRC;
      script.async = true;
      script.onerror = () => {
        window[PROMISE_KEY] = null;
        script.remove();
        reject(new Error('No se pudo cargar el reproductor de YouTube.'));
      };
      document.head.appendChild(script);
    });
  }
  return window[PROMISE_KEY];
}

/**
 * `new YT.Player` con lo que el plan fija para todos: dominio sin cookies y `origin` de la página
 * (sin él, el postMessage entre el iframe y la página falla en silencio; en la APK es
 * `https://localhost`). `playerVars` se suma a los de por defecto.
 */
export function createPlayer(YT, el, { playerVars = {}, events = {}, ...rest } = {}) {
  const player = new YT.Player(el, {
    host: 'https://www.youtube-nocookie.com',
    width: '100%',
    height: '100%',
    ...rest,
    playerVars: {
      controls: 0,
      disablekb: 1,
      playsinline: 1,
      rel: 0,
      fs: 0,
      iv_load_policy: 3,
      origin: window.location.origin,
      ...playerVars
    },
    events: {
      ...events,
      // El iframe fuera del orden de foco (M4): el D-pad de la tele no debe poder meterse dentro,
      // porque desde allí no hay forma de volver a los botones. Vale para los dos players (partida
      // y ensayo). El vigilante de foco de CallerView es la segunda red.
      onReady: (e) => {
        const iframe = player.getIframe && player.getIframe();
        if (iframe) iframe.setAttribute('tabindex', '-1');
        if (typeof events.onReady === 'function') events.onReady(e);
      }
    }
  });
  return player;
}

// Códigos de `onError` de la IFrame API → texto corto.
const ERROR_TEXT = {
  2: 'enlace no válido',
  5: 'el navegador no puede reproducirlo',
  100: 'no existe o es privado',
  101: 'no se deja incrustar',
  150: 'no se deja incrustar',
  153: 'falta el origen de la página'
};

export function youtubeErrorText(code) {
  return ERROR_TEXT[code] || 'error desconocido';
}
