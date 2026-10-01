/**
 * Config de prueba con la forma de la fiesta de casa (sin depender de ningún bingo real): números,
 * 58 canciones de YouTube en cartón 3×3, 86 fotos en cartón 4×5 y plan 15/4/55. Las medidas y los
 * recuentos son los que fijan las medianas de los premios (ver prizes.test.js); los ids siguen el
 * formato de la API (`i<n>`).
 */

export const MUSIC_COUNT = 58;
export const IMAGE_COUNT = 86;

function song(n) {
  return {
    id: `i${n}`,
    kind: 'music',
    label: `Canción ${n}`,
    sublabel: 'Artista',
    media: {
      type: 'youtube',
      url: null,
      thumbUrl: null,
      videoId: `v${n}`.padEnd(11, 'x'),
      startSeconds: null,
      endSeconds: null
    }
  };
}

function photo(n) {
  return {
    id: `i${n}`,
    kind: 'image',
    label: `Recuerdo ${n}`,
    sublabel: null,
    media: { type: 'image', url: `/f${n}.jpg`, thumbUrl: null, videoId: null, startSeconds: null, endSeconds: null }
  };
}

/** @returns {import('@/bingo/types').BingoConfig} una copia nueva en cada llamada */
export function houseBingo() {
  const range = (from, count) => Array.from({ length: count }, (_, k) => from + k);
  return {
    id: '1',
    title: 'Bingo Sorpresa',
    modes: {
      numeric: { enabled: true, label: 'Bingo' },
      music: { enabled: true, label: 'Bingo Musical', card: [3, 3], items: range(1, MUSIC_COUNT).map(song) },
      image: {
        enabled: true,
        label: 'Bingo de Recuerdos',
        card: [4, 5],
        items: range(MUSIC_COUNT + 1, IMAGE_COUNT).map(photo)
      }
    },
    plan: { leadIn: 15, minGap: 4, spreadOver: 55 }
  };
}
