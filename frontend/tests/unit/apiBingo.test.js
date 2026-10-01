import { describe, it, expect } from 'vitest';
import { fromPayload, withOrigin, isGone } from '@/bingo/apiBingo';
import { validateConfig } from '@/bingo/validateConfig';
import { makePlayerCards } from '@/bingo/cards';
import fixture from '../fixtures/print-reprint.json';

// PlayPayload de ejemplo, con la forma de PlayPresenter.php.
function payload(n = 25) {
  return {
    id: '42',
    title: 'Cumple de la abuela',
    modes: {
      numeric: { enabled: true, label: 'Bingo' },
      music: { enabled: false, label: 'Bingo Musical', card: [3, 3], items: [] },
      image: {
        enabled: true,
        label: 'Bingo de Fotos',
        card: [4, 5],
        items: Array.from({ length: n }, (_, i) => ({
          id: `i${100 + i}`,
          kind: 'image',
          label: `Foto ${i + 1}`,
          sublabel: null,
          media: {
            type: 'image',
            url: `/img.php?t=tok${i}`,
            thumbUrl: `/img.php?t=tok${i}&thumb=1`,
            uploadId: 500 + i,
            videoId: null,
            startSeconds: null,
            endSeconds: null
          }
        }))
      }
    },
    plan: { leadIn: 15, minGap: 4, spreadOver: 55 }
  };
}

// Lo que imprime BingoCard: el texto de cada casilla (y el id, que es lo que se tacha).
const printed = (cards) => ({
  numeric: cards.numeric,
  image: cards.image && cards.image.map((it) => [it.id, it.label])
});

describe('apiBingo.fromPayload', () => {
  it('un payload de ejemplo da un BingoConfig que pasa validateConfig', () => {
    const config = fromPayload(payload());
    expect(validateConfig(config)).toEqual({ ok: true, errors: [] });
    expect(config.id).toBe('42');
    expect(config.modes.image.card).toEqual([4, 5]);
    expect(config.modes.image.items).toHaveLength(25);
    expect(config.modes.image.items[0]).toEqual({
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
    expect(config.plan).toEqual({ leadIn: 15, minGap: 4, spreadOver: 55 });
  });

  it('no comparte objetos con el payload', () => {
    const p = payload(20);
    const config = fromPayload(p);
    config.modes.image.card[0] = 9;
    config.modes.image.items[0].label = 'otro';
    expect(p.modes.image.card[0]).toBe(4);
    expect(p.modes.image.items[0].label).toBe('Foto 1');
  });

  it('con un payload de pocas fotos, validateConfig lo dice (E4)', () => {
    expect(validateConfig(fromPayload(payload(10))).errors).toEqual([
      'El cartón de fotos tiene 20 casillas y solo hay 10 fotos.'
    ]);
  });

  it('en la APK antepone VUE_APP_API_ORIGIN a las URLs relativas', () => {
    const config = fromPayload(payload(), 'https://bingo.dcahomelab.com/');
    expect(config.modes.image.items[0].media.url).toBe('https://bingo.dcahomelab.com/img.php?t=tok0');
    expect(withOrigin('https://x.test/a.webp', 'https://bingo.dcahomelab.com')).toBe('https://x.test/a.webp');
    expect(withOrigin(null, 'https://bingo.dcahomelab.com')).toBeNull();
    expect(withOrigin('/img.php?t=a', '')).toBe('/img.php?t=a');
  });

  it('una foto ya borrada (url null en get_print) sigue siendo un item válido', () => {
    const p = payload();
    p.modes.image.items[3].media.url = null;
    p.modes.image.items[3].media.thumbUrl = null;
    const config = fromPayload(p);
    expect(config.modes.image.items[3].media.url).toBeNull();
    expect(validateConfig(config).ok).toBe(true);
  });

  it('isGone: 404 y 403 son «ya no existe»; el resto no', () => {
    expect(isGone({ status: 404 })).toBe(true);
    expect(isGone({ status: 403 })).toBe(true);
    expect(isGone({ status: 500 })).toBe(false);
    expect(isGone(null)).toBe(false);
  });

  it('isGone: el 403 de CSRF_INVALID no es «ya no existe» (sesión caducada)', () => {
    expect(isGone({ status: 403, response: { status: 'error', error_code: 'CSRF_INVALID' } })).toBe(false);
  });

  it('isGone: un 403 normal (con respuesta, sin error_code) sí lo es', () => {
    expect(isGone({ status: 403, response: { status: 'error', message: 'No es tuyo' } })).toBe(true);
  });
});

// La prueba que importa del plan (M4): reimprimir una tirada tras borrar una foto da los MISMOS
// cartones. Fixture capturado de dev: get_play_payload al imprimir la tirada #1, get_print de esa
// tirada tras `delete_item` de la 3.ª foto, y get_play_payload del bingo ya sin ella.
describe('reimpresión de una tirada tras borrar una foto (fixture de dev)', () => {
  const { seed, players } = fixture;
  const deal = (p) => {
    const config = fromPayload(p);
    return Array.from({ length: players }, (_, i) => printed(makePlayerCards(config, seed, i)));
  };

  it('el snapshot conserva las 25 fotos aunque el bingo ya tenga 24', () => {
    expect(fixture.printedPayload.modes.image.items).toHaveLength(25);
    expect(fixture.printSnapshotAfterDelete.modes.image.items).toHaveLength(25);
    expect(fixture.currentPayload.modes.image.items).toHaveLength(24);
    const deleted = fixture.printSnapshotAfterDelete.modes.image.items.filter((i) => i.media.url === null);
    expect(deleted.map((i) => i.id)).toEqual(['i3']);
    expect(validateConfig(fromPayload(fixture.printSnapshotAfterDelete)).ok).toBe(true);
  });

  it('con el snapshot y la misma semilla salen cartones idénticos a los impresos', () => {
    expect(deal(fixture.printSnapshotAfterDelete)).toEqual(deal(fixture.printedPayload));
  });

  it('con el bingo actual (sin el snapshot) los cartones de fotos ya no casan', () => {
    const now = deal(fixture.currentPayload);
    const then = deal(fixture.printedPayload);
    // Los números no dependen de las fotos; las fotos sí.
    expect(now.map((c) => c.numeric)).toEqual(then.map((c) => c.numeric));
    expect(now.map((c) => c.image)).not.toEqual(then.map((c) => c.image));
  });
});
