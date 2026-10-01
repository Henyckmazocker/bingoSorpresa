import { describe, it, expect } from 'vitest';
import { validateConfig, CARD_LIMITS } from '@/bingo/validateConfig';
import { houseBingo } from './fixtures';

describe('validateConfig', () => {
  it('la config de casa es válida', () => {
    expect(validateConfig(houseBingo())).toEqual({ ok: true, errors: [] });
  });

  it('exporta CARD_LIMITS 2..6', () => {
    expect(CARD_LIMITS).toEqual({ minSide: 2, maxSide: 6 });
  });

  it('E1: todos los modos apagados', () => {
    const config = houseBingo();
    config.modes.numeric.enabled = false;
    config.modes.music.enabled = false;
    config.modes.image.enabled = false;
    expect(validateConfig(config)).toEqual({ ok: false, errors: ['Activa al menos un modo de juego.'] });
  });

  it('E2: modo sorpresa activo sin items (y uno apagado sin items es válido)', () => {
    const config = houseBingo();
    config.modes.music.items = [];
    expect(validateConfig(config)).toEqual({
      ok: false,
      errors: ['El modo de canciones está activo y tiene 0 canciones.']
    });
    config.modes.music.enabled = false;
    expect(validateConfig(config).ok).toBe(true);
  });

  it('E3: cartón fuera de 2..6', () => {
    const config = houseBingo();
    config.modes.image.card = [7, 3];
    expect(validateConfig(config)).toEqual({
      ok: false,
      errors: ['El cartón de fotos mide 7×3; filas y columnas deben estar entre 2 y 6.']
    });
  });

  it('E4: más casillas que items', () => {
    const config = houseBingo();
    config.modes.image.items = config.modes.image.items.slice(0, 12);
    expect(validateConfig(config)).toEqual({
      ok: false,
      errors: ['El cartón de fotos tiene 20 casillas y solo hay 12 fotos.']
    });
  });
});
