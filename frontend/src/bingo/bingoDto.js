/**
 * Puente entre el `BingoDTO` del backend (`get_bingo`) y el `BingoConfig` que esperan
 * `ModeSettings`, `PrizeOrderHint` y `validateConfig`. Puro, sin Vue: lo usa el editor del
 * backoffice (`BingoEditorView`).
 *
 * El editor separa dos cosas:
 *  - los AJUSTES sin guardar (título y modos), que se tocan con ModeSettings y se guardan con
 *    «Guardar» (`update_bingo`);
 *  - los ITEMS, que se guardan al momento (subir fotos, añadir canciones, renombrar, borrar).
 * La config que ven ModeSettings/PrizeOrderHint es la mezcla de las dos: así, subir una foto con
 * ajustes a medio editar no los pisa.
 */

/** @typedef {import('./types').BingoConfig} BingoConfig */
/** @typedef {import('./types').BingoItem} BingoItem */

// Modos que ofrece el editor: los tres, en el orden de MODE_KINDS.
export const EDITOR_KINDS = ['numeric', 'music', 'image'];

// Enlaces por petición de `add_music_items` (MUSIC_URLS_MAX del backend). Más se mandan en tandas.
export const MUSIC_BATCH_MAX = 50;
// Tope de `start_seconds` (YoutubeUrlParser::MAX_START_SECONDS).
export const MAX_START_SECONDS = 65535;

/**
 * ItemDTO → BingoItem (forma del juego: id 'i<itemId>').
 * @returns {BingoItem}
 */
export function itemToBingoItem(item) {
  return {
    id: `i${item.id}`,
    kind: item.kind,
    label: item.label,
    sublabel: item.sublabel ?? null,
    media: {
      type: item.kind === 'image' ? 'image' : 'youtube',
      url: item.url ?? null,
      thumbUrl: item.thumbUrl ?? null,
      videoId: item.videoId ?? null,
      startSeconds: item.startSeconds ?? null,
      endSeconds: null
    }
  };
}

/**
 * Ajustes editables de un BingoDTO (copia nueva, sin compartir nada con el DTO).
 * @returns {{ title: string, modes: { numeric: {enabled:boolean, label:string},
 *            music: {enabled:boolean, label:string, card:[number,number]},
 *            image: {enabled:boolean, label:string, card:[number,number]} } }}
 */
export function settingsFromDto(dto) {
  const m = dto.modes;
  return {
    title: dto.title,
    modes: {
      numeric: { enabled: !!m.numeric.enabled, label: m.numeric.label },
      music: { enabled: !!m.music.enabled, label: m.music.label, card: [m.music.rows, m.music.cols] },
      image: { enabled: !!m.image.enabled, label: m.image.label, card: [m.image.rows, m.image.cols] }
    }
  };
}

/**
 * BingoConfig con los items del DTO y los modos de los ajustes (guardados o no).
 * @returns {BingoConfig}
 */
export function buildConfig(dto, settings) {
  const items = (dto.items || []).map(itemToBingoItem);
  const { numeric, music, image } = settings.modes;
  return {
    id: String(dto.id),
    title: settings.title,
    modes: {
      numeric: { enabled: numeric.enabled, label: numeric.label },
      music: { ...music, card: [...music.card], items: items.filter((i) => i.kind === 'music') },
      image: { ...image, card: [...image.card], items: items.filter((i) => i.kind === 'image') }
    },
    plan: { ...dto.plan }
  };
}

/**
 * Lo que ModeSettings emite (un BingoConfig nuevo) → ajustes. Los items no se tocan aquí.
 */
export function settingsFromConfig(config, title) {
  const { numeric, music, image } = config.modes;
  const pick = (m) => ({ enabled: !!m.enabled, label: m.label, card: [...m.card] });
  return {
    title,
    modes: {
      numeric: { enabled: !!numeric.enabled, label: numeric.label },
      music: pick(music),
      image: pick(image)
    }
  };
}

/**
 * Cuerpo de `update_bingo` (sin `bingo_id`).
 */
export function settingsPatch(settings) {
  const { numeric, music, image } = settings.modes;
  const surprise = (m) => ({ enabled: m.enabled, label: m.label.trim(), rows: m.card[0], cols: m.card[1] });
  return {
    title: settings.title.trim(),
    modes: {
      numeric: { enabled: numeric.enabled },
      music: surprise(music),
      image: surprise(image)
    }
  };
}

/** ¿Hay ajustes sin guardar respecto al DTO? (Mismo criterio que lo que se manda al guardar.) */
export function settingsDirty(dto, settings) {
  return JSON.stringify(settingsPatch(settings)) !== JSON.stringify(settingsPatch(settingsFromDto(dto)));
}

/** 12,3 MB (como los mensajes del backend). */
export function formatMegabytes(bytes) {
  return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}

// --- Canciones ---

/** El textarea de «Añadir»: una URL por línea, sin vacías ni repetidas (en el orden pegado). */
export function parseUrlList(text) {
  const seen = new Set();
  const out = [];
  for (const line of String(text || '').split(/\r?\n/)) {
    const url = line.trim();
    if (url && !seen.has(url)) {
      seen.add(url);
      out.push(url);
    }
  }
  return out;
}

/** Trocea una lista en tandas de `size` (para no pasar de MUSIC_BATCH_MAX por petición). */
export function chunk(list, size = MUSIC_BATCH_MAX) {
  const out = [];
  for (let i = 0; i < list.length; i += size) out.push(list.slice(i, i + size));
  return out;
}

// Motivos de `failed` de `add_music_items`, para el usuario. `unavailable` es pasajero: se reintenta.
const MUSIC_FAILURES = {
  invalid_url: 'no es un enlace de YouTube.',
  not_found: 'ese vídeo no existe o es privado.',
  not_embeddable: 'su dueño no deja reproducirlo fuera de YouTube.',
  duplicate: 'esa canción ya está en este bingo.',
  unavailable: 'YouTube no ha contestado. Vuelve a intentarlo.'
};

export function musicFailureMessage(reason) {
  return MUSIC_FAILURES[reason] || 'no se pudo añadir.';
}

/** ¿Merece la pena reintentarlo tal cual? */
export function isRetryable(reason) {
  return reason === 'unavailable';
}

/** 90 → «1:30»; null → '' (empieza en el 0). */
export function formatStart(seconds) {
  if (seconds === null || seconds === undefined) return '';
  const s = Number(seconds);
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/**
 * Lo que se escribe en el campo «Empieza en» → segundos. Acepta «90», «1:30» y vacío (null).
 * Devuelve `undefined` si no se entiende o se sale de 0..MAX_START_SECONDS.
 */
export function parseStart(text) {
  const t = String(text ?? '').trim();
  if (t === '') return null;
  let n;
  if (/^\d+$/.test(t)) {
    n = Number(t);
  } else {
    const m = /^(\d+):([0-5]\d)$/.exec(t);
    if (!m) return undefined;
    n = Number(m[1]) * 60 + Number(m[2]);
  }
  return n <= MAX_START_SECONDS ? n : undefined;
}
