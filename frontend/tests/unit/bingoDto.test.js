import { describe, it, expect } from 'vitest';
import {
  itemToBingoItem,
  settingsFromDto,
  buildConfig,
  settingsFromConfig,
  settingsPatch,
  settingsDirty,
  formatMegabytes,
  EDITOR_KINDS,
  parseUrlList,
  chunk,
  musicFailureMessage,
  isRetryable,
  formatStart,
  parseStart
} from '@/bingo/bingoDto';
import { validateConfig } from '@/bingo/validateConfig';

// BingoDTO como lo devuelve get_bingo, con `n` fotos.
function dto(n = 25, image = { enabled: true, rows: 4, cols: 5 }) {
  return {
    id: 7,
    title: 'Cumple de la abuela',
    shared: false,
    shareUrl: null,
    printsCount: 0,
    storageBytes: 1234,
    storageQuota: 209715200,
    modes: {
      numeric: { enabled: true, label: 'Bingo' },
      music: { enabled: false, label: 'Bingo Musical', rows: 3, cols: 3 },
      image: { label: 'Bingo de Fotos', ...image }
    },
    plan: { leadIn: 15, minGap: 4, spreadOver: 55 },
    items: Array.from({ length: n }, (_, i) => ({
      id: 100 + i,
      kind: 'image',
      label: `Foto ${i + 1}`,
      sublabel: null,
      uploadId: 50 + i,
      width: 1600,
      height: 1200,
      url: `/img.php?t=tok${i}`,
      thumbUrl: `/img.php?t=tok${i}&thumb=1`
    }))
  };
}

describe('bingoDto', () => {
  it('ItemDTO → BingoItem con id «i<itemId>» y media de imagen', () => {
    expect(itemToBingoItem(dto(1).items[0])).toEqual({
      id: 'i100',
      kind: 'image',
      label: 'Foto 1',
      sublabel: null,
      media: {
        type: 'image',
        url: '/img.php?t=tok0',
        thumbUrl: '/img.php?t=tok0&thumb=1',
        videoId: null,
        startSeconds: null,
        endSeconds: null
      }
    });
  });

  it('un DTO con 25 fotos en 4×5 da una config válida, con las fotos en el modo imagen', () => {
    const d = dto();
    const config = buildConfig(d, settingsFromDto(d));
    expect(config.id).toBe('7');
    expect(config.modes.image.card).toEqual([4, 5]);
    expect(config.modes.image.items).toHaveLength(25);
    expect(config.modes.music.items).toEqual([]);
    expect(config.plan).toEqual({ leadIn: 15, minGap: 4, spreadOver: 55 });
    expect(validateConfig(config)).toEqual({ ok: true, errors: [] });
  });

  it('6×6 con 25 fotos da E4, como el backend', () => {
    const d = dto(25, { enabled: true, rows: 6, cols: 6 });
    expect(validateConfig(buildConfig(d, settingsFromDto(d))).errors).toEqual([
      'El cartón de fotos tiene 36 casillas y solo hay 25 fotos.'
    ]);
  });

  it('ida y vuelta: lo que emite ModeSettings vuelve a ajustes y al patch de update_bingo', () => {
    const d = dto();
    const settings = settingsFromDto(d);
    const config = buildConfig(d, settings);
    // Lo que haría ModeSettings al cambiar las columnas a 3.
    const emitted = { ...config, modes: { ...config.modes, image: { ...config.modes.image, card: [4, 3] } } };
    const next = settingsFromConfig(emitted, 'Nuevo título ');
    expect(settingsPatch(next)).toEqual({
      title: 'Nuevo título',
      modes: {
        numeric: { enabled: true },
        music: { enabled: false, label: 'Bingo Musical', rows: 3, cols: 3 },
        image: { enabled: true, label: 'Bingo de Fotos', rows: 4, cols: 3 }
      }
    });
    expect(settingsDirty(d, next)).toBe(true);
    expect(settingsDirty(d, settings)).toBe(false);
  });

  it('el editor ofrece los tres modos y el patch manda el musical', () => {
    expect(EDITOR_KINDS).toEqual(['numeric', 'music', 'image']);
    const d = dto();
    const settings = settingsFromDto(d);
    settings.modes.music = { enabled: true, label: ' Canciones ', card: [3, 4] };
    expect(settingsPatch(settings).modes.music).toEqual({ enabled: true, label: 'Canciones', rows: 3, cols: 4 });
    expect(settingsDirty(d, settings)).toBe(true);
  });

  it('una canción del DTO propaga videoId y startSeconds al BingoItem', () => {
    const song = {
      id: 300,
      kind: 'music',
      label: 'Never Gonna Give You Up',
      sublabel: 'Rick Astley',
      uploadId: null,
      width: null,
      height: null,
      url: null,
      thumbUrl: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
      videoId: 'dQw4w9WgXcQ',
      startSeconds: 43
    };
    expect(itemToBingoItem(song)).toEqual({
      id: 'i300',
      kind: 'music',
      label: 'Never Gonna Give You Up',
      sublabel: 'Rick Astley',
      media: {
        type: 'youtube',
        url: null,
        thumbUrl: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
        videoId: 'dQw4w9WgXcQ',
        startSeconds: 43,
        endSeconds: null
      }
    });
  });

  it('las canciones del DTO van al modo musical y cuentan para E4', () => {
    const d = dto();
    d.items.push(...Array.from({ length: 8 }, (_, i) => ({
      id: 400 + i, kind: 'music', label: `Canción ${i}`, sublabel: 'X', uploadId: null,
      url: null, thumbUrl: null, videoId: `abcdefghij${i}`, startSeconds: null
    })));
    const settings = settingsFromDto(d);
    settings.modes.music.enabled = true;
    const config = buildConfig(d, settings);
    expect(config.modes.music.items).toHaveLength(8);
    expect(validateConfig(config).errors).toEqual([
      'El cartón de canciones tiene 9 casillas y solo hay 8 canciones.'
    ]);
  });

  it('textarea de enlaces: una por línea, sin vacías ni repetidas', () => {
    expect(parseUrlList(' https://youtu.be/a \n\n\r\nhttps://youtu.be/b\nhttps://youtu.be/a\n')).toEqual([
      'https://youtu.be/a',
      'https://youtu.be/b'
    ]);
    expect(parseUrlList('')).toEqual([]);
  });

  it('trocea en tandas de 50', () => {
    const urls = Array.from({ length: 58 }, (_, i) => `u${i}`);
    const batches = chunk(urls);
    expect(batches.map((b) => b.length)).toEqual([50, 8]);
    expect(batches.flat()).toEqual(urls);
  });

  it('motivos de fallo en español; solo «unavailable» se reintenta', () => {
    for (const r of ['invalid_url', 'not_found', 'not_embeddable', 'duplicate', 'unavailable']) {
      expect(musicFailureMessage(r)).not.toBe(musicFailureMessage('otro'));
    }
    expect(musicFailureMessage('unavailable')).toMatch(/intent/i);
    expect(isRetryable('unavailable')).toBe(true);
    expect(isRetryable('not_found')).toBe(false);
  });

  it('inicio: formatea m:ss y lee segundos o m:ss', () => {
    expect(formatStart(null)).toBe('');
    expect(formatStart(0)).toBe('0:00');
    expect(formatStart(90)).toBe('1:30');
    expect(parseStart('')).toBeNull();
    expect(parseStart(' 90 ')).toBe(90);
    expect(parseStart('1:30')).toBe(90);
    expect(parseStart('1:75')).toBeUndefined();
    expect(parseStart('-3')).toBeUndefined();
    expect(parseStart('abc')).toBeUndefined();
    expect(parseStart('65536')).toBeUndefined();
  });

  it('los ajustes no comparten arrays con el DTO ni con la config', () => {
    const d = dto();
    const settings = settingsFromDto(d);
    const config = buildConfig(d, settings);
    config.modes.image.card[0] = 6;
    expect(settings.modes.image.card).toEqual([4, 5]);
    expect(d.modes.image.rows).toBe(4);
  });

  it('formatea megas con coma', () => {
    expect(formatMegabytes(0)).toBe('0,0 MB');
    expect(formatMegabytes(209715200)).toBe('200,0 MB');
  });
});
