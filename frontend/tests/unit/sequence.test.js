import { describe, it, expect } from 'vitest';
import { buildSequence } from '@/bingo/sequence';
import { houseBingo } from './fixtures';
import { mulberry32, shuffle } from '@/utils/rng';

const ids = (seq) => seq.map((e) => (e.kind === 'numeric' ? e.item : e.item.id));

describe('buildSequence', () => {
  it('saca 90 bolas y todos los items, con rng reproducible', () => {
    const config = houseBingo();
    const a = buildSequence(config, mulberry32('x'));
    const b = buildSequence(config, mulberry32('x'));
    expect(a).toHaveLength(90 + 58 + 86);
    expect(ids(a)).toEqual(ids(b));
    expect(a.slice(0, 15).every((e) => e.kind === 'numeric')).toBe(true);
    expect(buildSequence(config)).toHaveLength(234);
  });

  it('respeta modes.*.enabled', () => {
    const config = houseBingo();
    config.modes.numeric.enabled = false;
    config.modes.image.enabled = false;
    const seq = buildSequence(config, mulberry32('y'));
    expect(seq).toHaveLength(58);
    expect(seq.every((e) => e.kind === 'music')).toBe(true);
  });

  it('sin numérico la base es [] y las sorpresas salen en el orden barajado', () => {
    const config = houseBingo();
    config.modes.numeric.enabled = false;
    const seq = buildSequence(config, mulberry32('z'));
    expect(seq).toHaveLength(58 + 86);
    expect(seq.some((e) => e.kind === 'numeric')).toBe(false);
    // Con n=0 todas las posiciones de interleaveSurprises son 0: la secuencia es el mazo barajado.
    const pool = [...config.modes.music.items, ...config.modes.image.items];
    const expected = shuffle(pool, mulberry32('z')).map((it) => it.id);
    expect(ids(seq)).toEqual(expected);
  });
});
