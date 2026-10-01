import { describe, it, expect } from 'vitest';
import { mulberry32, shuffle } from '@/utils/rng';

describe('rng', () => {
  it('mulberry32 con la misma semilla da la misma secuencia', () => {
    const a = mulberry32('x');
    const b = mulberry32('x');
    const seqA = Array.from({ length: 20 }, () => a());
    const seqB = Array.from({ length: 20 }, () => b());
    expect(seqA).toEqual(seqB);
  });

  it('shuffle no muta el array original', () => {
    const original = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
    const copia = original.slice();
    const barajado = shuffle(original, mulberry32('x'));
    expect(original).toEqual(copia);
    expect(barajado).not.toBe(original);
    expect([...barajado].sort((x, y) => x - y)).toEqual(copia);
  });
});
