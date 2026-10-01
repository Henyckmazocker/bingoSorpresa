import { describe, it, expect } from 'vitest';
import { printLayout, activeSurprises, usesHouseFont } from '@/bingo/printLayout';
import { houseBingo } from './fixtures';

// Config de casa con los modos indicados encendidos y el resto apagados.
function withModes(numeric, music, image) {
  const config = houseBingo();
  config.modes.numeric.enabled = numeric;
  config.modes.music.enabled = music;
  config.modes.image.enabled = image;
  return config;
}

describe('printLayout', () => {
  it.each([
    // numérico, música, fotos → disposición
    [true, true, true, 'fold'],
    [true, true, false, 'fold'],
    [true, false, true, 'fold'],
    [true, false, false, 'numeric-only'],
    [false, true, true, 'surprises-only'],
    [false, true, false, 'surprises-only'],
    [false, false, true, 'surprises-only']
  ])('numeric=%s music=%s image=%s → %s', (numeric, music, image, expected) => {
    expect(printLayout(withModes(numeric, music, image))).toBe(expected);
  });

  it('sin modos activos (inválida, E1) devuelve la disposición de casa', () => {
    expect(printLayout(withModes(false, false, false))).toBe('fold');
  });

  it('la config de casa es fold', () => {
    expect(printLayout(houseBingo())).toBe('fold');
  });

  it('activeSurprises respeta el orden de apilado', () => {
    expect(activeSurprises(withModes(true, true, true))).toEqual(['music', 'image']);
    expect(activeSurprises(withModes(false, false, true))).toEqual(['image']);
    expect(activeSurprises(withModes(true, false, false))).toEqual([]);
  });

  it('solo los cartones de casa conservan la letra fija', () => {
    expect(usesHouseFont('music', [3, 3])).toBe(true);
    expect(usesHouseFont('image', [4, 5])).toBe(true);
    expect(usesHouseFont('music', [4, 5])).toBe(false);
    expect(usesHouseFont('image', [6, 6])).toBe(false);
    expect(usesHouseFont('image', [2, 2])).toBe(false);
  });
});
