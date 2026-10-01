import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { createRehearsal, REHEARSAL_TIMEOUT_MS, youtubeSongs } from '@/bingo/rehearsal';

// Un «player» de mentira: apunta lo que se carga y deja que el test conteste.
function setup(items, timeoutMs) {
  const loaded = [];
  const results = [];
  const r = createRehearsal({
    items,
    timeoutMs,
    load: (item) => loaded.push(item),
    onResult: (i, result) => results.push([i, result])
  });
  return { r, loaded, results };
}

describe('rehearsal', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('recorre en orden: PLAYING → ok, onError → error con código, sin nada → timeout a los 10 s', async () => {
    const { r, loaded, results } = setup(['a', 'b', 'c']);
    const done = r.run();
    expect(loaded).toEqual(['a']); // de una en una
    r.playing();
    await vi.advanceTimersByTimeAsync(0);
    expect(loaded).toEqual(['a', 'b']);
    r.error(150);
    await vi.advanceTimersByTimeAsync(0);
    expect(loaded).toEqual(['a', 'b', 'c']);
    await vi.advanceTimersByTimeAsync(REHEARSAL_TIMEOUT_MS - 1);
    expect(results).toHaveLength(2);
    await vi.advanceTimersByTimeAsync(1);
    await done;
    expect(results).toEqual([
      [0, { status: 'ok' }],
      [1, { status: 'error', code: 150 }],
      [2, { status: 'timeout' }]
    ]);
  });

  it('se queda con lo primero que llega e ignora los eventos rezagados', async () => {
    const { r, loaded, results } = setup(['a', 'b'], 1000);
    const done = r.run();
    r.playing();
    r.error(101); // del mismo vídeo, tarde: no cuenta para «a» ni para «b»
    await vi.advanceTimersByTimeAsync(0);
    expect(loaded).toEqual(['a', 'b']);
    r.playing();
    await done;
    expect(results).toEqual([
      [0, { status: 'ok' }],
      [1, { status: 'ok' }]
    ]);
  });

  it('stop corta el recorrido sin apuntar la canción en curso', async () => {
    const { r, loaded, results } = setup(['a', 'b', 'c'], 1000);
    const done = r.run();
    r.playing();
    await vi.advanceTimersByTimeAsync(0);
    r.stop();
    await done;
    expect(loaded).toEqual(['a', 'b']);
    expect(results).toEqual([[0, { status: 'ok' }]]);
    await vi.advanceTimersByTimeAsync(5000); // el temporizador de «b» ya no hace nada
    expect(results).toHaveLength(1);
  });
});

describe('youtubeSongs', () => {
  const yt = (id) => ({ id, label: id, media: { type: 'youtube', videoId: 'dQw4w9WgXcQ' } });
  const photo = (id) => ({ id, label: id, media: { type: 'image', url: 'x.jpg' } });
  const config = (music) => ({ modes: { numeric: { enabled: true }, music } });

  it('devuelve solo las canciones de YouTube del modo musical activo', () => {
    const items = [yt('a'), photo('b'), yt('c')];
    expect(youtubeSongs(config({ enabled: true, items })).map((i) => i.id)).toEqual(['a', 'c']);
  });

  it('vacío con el modo musical apagado, ausente, sin items o sin ninguna de YouTube', () => {
    expect(youtubeSongs(config({ enabled: false, items: [yt('a')] }))).toEqual([]);
    expect(youtubeSongs(config(undefined))).toEqual([]);
    expect(youtubeSongs(config({ enabled: true }))).toEqual([]);
    expect(youtubeSongs(config({ enabled: true, items: [photo('a')] }))).toEqual([]);
    expect(youtubeSongs(null)).toEqual([]);
  });
});
