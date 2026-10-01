import { describe, it, expect } from 'vitest';
import { makeNumericCard, interleaveSurprises } from '@/utils/bingo';
import { mulberry32 } from '@/utils/rng';

// Copia de COLUMN_RANGES (bingo.js:15-18), que no se exporta.
const COLUMN_RANGES = [
  [1, 9], [10, 19], [20, 29], [30, 39], [40, 49],
  [50, 59], [60, 69], [70, 79], [80, 90]
];

describe('makeNumericCard', () => {
  it('da 15 números, 5 por fila y cada columna en su decena (200 semillas)', () => {
    for (let s = 0; s < 200; s++) {
      const card = makeNumericCard(`semilla-${s}`);
      expect(card).toHaveLength(3);
      card.forEach((row) => {
        expect(row).toHaveLength(9);
        expect(row.filter((n) => n != null)).toHaveLength(5);
      });
      const nums = card.flat().filter((n) => n != null);
      expect(nums).toHaveLength(15);
      expect(new Set(nums).size).toBe(15);
      for (let col = 0; col < 9; col++) {
        const [from, to] = COLUMN_RANGES[col];
        card.forEach((row) => {
          const n = row[col];
          if (n != null) {
            expect(n).toBeGreaterThanOrEqual(from);
            expect(n).toBeLessThanOrEqual(to);
          }
        });
      }
    }
  });
});

describe('interleaveSurprises', () => {
  const base = Array.from({ length: 90 }, (_, i) => ({ kind: 'numeric', n: i + 1 }));
  const surprises = Array.from({ length: 10 }, (_, i) => ({ kind: 'sorpresa', n: i }));

  it('respeta leadIn y conserva todos los elementos', () => {
    for (let s = 0; s < 50; s++) {
      const leadIn = 15;
      const out = interleaveSurprises(base, surprises, { leadIn, minGap: 4, spreadOver: 55, rng: mulberry32(s) });
      expect(out).toHaveLength(base.length + surprises.length);
      // Las primeras leadIn entradas son siempre bolas normales.
      expect(out.slice(0, leadIn).every((x) => x.kind === 'numeric')).toBe(true);
      // Mismos elementos, y cada grupo en su orden original.
      expect(out.filter((x) => x.kind === 'numeric')).toEqual(base);
      expect(out.filter((x) => x.kind === 'sorpresa')).toEqual(surprises);
    }
  });

  it('usa leadIn = 7 por defecto', () => {
    const out = interleaveSurprises(base, surprises, { rng: mulberry32('x') });
    expect(out.slice(0, 7).every((x) => x.kind === 'numeric')).toBe(true);
    expect(out).toHaveLength(100);
  });
});
