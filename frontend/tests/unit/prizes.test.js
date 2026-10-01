import { describe, it, expect } from 'vitest';
import { simulatePrizes } from '@/bingo/prizes';
import { houseBingo } from './fixtures';

const key = (p) => `${p.kind}:${p.prize}`;

describe('simulatePrizes', () => {
  it('reproduce el orden y las medianas históricas con la config de casa', () => {
    const t0 = performance.now();
    const res = simulatePrizes(houseBingo(), { seed: 'm2' });
    const ms = performance.now() - t0;
    // Se apunta en la bitácora del plan (M2).
    console.log(`simulatePrizes(houseBingo()): ${ms.toFixed(0)} ms ·`,
      res.prizes.map((p) => `${key(p)}=${p.median}`).join(' '));

    expect(res.total).toBe(234);
    // Orden de las medianas de la fiesta de casa (3×3 / números / 4×5).
    expect(res.prizes.map(key)).toEqual([
      'music:line', 'numeric:line', 'image:line',
      'music:bingo', 'numeric:bingo', 'image:bingo'
    ]);
    const historic = [84, 105, 119, 185, 199, 211];
    res.prizes.forEach((p, j) => {
      expect(Math.abs(p.median - historic[j])).toBeLessThanOrEqual(10);
      expect(p.p10).toBeLessThanOrEqual(p.median);
      expect(p.p90).toBeGreaterThanOrEqual(p.median);
    });
    expect(res.warnings).toEqual([]);
  });

  it('es reproducible con el mismo seed', () => {
    const opts = { games: 50, seed: 'r' };
    expect(simulatePrizes(houseBingo(), opts)).toEqual(simulatePrizes(houseBingo(), opts));
  });

  it('avisa entre los dos bingos sorpresa si música usa el mismo cartón que fotos (4×5)', () => {
    const config = houseBingo();
    config.modes.music.card = [4, 5];
    const { warnings } = simulatePrizes(config, { seed: 'm2' });
    const pair = (w) => [key(w.a), key(w.b)].sort().join(' ');
    expect(warnings.map(pair)).toContain('image:bingo music:bingo');
  });

  it('los modos desactivados no generan premios', () => {
    const config = houseBingo();
    config.modes.numeric.enabled = false;
    const res = simulatePrizes(config, { games: 50 });
    expect(res.total).toBe(58 + 86);
    expect(res.prizes.every((p) => p.kind !== 'numeric')).toBe(true);
    expect(res.prizes).toHaveLength(4);
  });
});
