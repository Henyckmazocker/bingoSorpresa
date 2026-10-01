import { describe, it, expect } from 'vitest';
import { prizeLabel, prizeHintRows } from '@/bingo/prizeHint';
import { houseBingo } from './fixtures';

const config = houseBingo();

describe('prizeHint', () => {
  it('etiqueta cada premio con el nombre del premio y la label del modo', () => {
    expect(prizeLabel('music', 'line', config)).toBe('Línea · Bingo Musical');
    expect(prizeLabel('image', 'bingo', config)).toBe('BINGO · Bingo de Recuerdos');
    expect(prizeLabel('numeric', 'line', config)).toBe('Línea · Bingo');
  });

  it('formatea «≈ entrada N de T» en orden y marca los dos premios de cada aviso', () => {
    const result = {
      total: 234,
      prizes: [
        { kind: 'music', prize: 'line', median: 84, p10: 70, p90: 100 },
        { kind: 'music', prize: 'bingo', median: 183, p10: 170, p90: 200 },
        { kind: 'image', prize: 'bingo', median: 185, p10: 172, p90: 205 }
      ],
      warnings: [{ a: { kind: 'music', prize: 'bingo' }, b: { kind: 'image', prize: 'bingo' }, gap: 2 }]
    };
    expect(prizeHintRows(result, config)).toEqual([
      { key: 'music:line', text: 'Línea · Bingo Musical ≈ entrada 84 de 234', warn: false },
      { key: 'music:bingo', text: 'BINGO · Bingo Musical ≈ entrada 183 de 234', warn: true },
      { key: 'image:bingo', text: 'BINGO · Bingo de Recuerdos ≈ entrada 185 de 234', warn: true }
    ]);
  });
});
