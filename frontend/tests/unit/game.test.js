/**
 * Store de la partida sobre la config: una partida entera con una config como la de casa, sin vista.
 * Sustituye a medias al humo de `npm run serve` (lo visual se sigue mirando a ojo).
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useGameStore, MODE_META, maxStep } from '@/store/game';
import { houseBingo } from './fixtures';

describe('store game', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('initGame() sin config lanza un error claro', () => {
    const store = useGameStore();
    expect(() => store.initGame()).toThrow(/loadBingo/);
  });

  it('MODE_META usa las claves numeric|music|image', () => {
    expect(Object.keys(MODE_META)).toEqual(['numeric', 'music', 'image']);
  });

  it('juega una partida entera con la config de casa', () => {
    const store = useGameStore();
    store.loadBingo(houseBingo());
    expect(store.config).not.toBeNull();
    expect(store.remaining).toBe(90 + 58 + 86);

    const modes = new Set();
    let checkedReveal = false;
    while (store.canDraw) {
      store.draw();
      modes.add(store.mode);
      if (store.isSurprise && !checkedReveal) {
        // Una sorpresa (canción o foto) sale oculta y 👁️ la revela en `maxStep` pasos.
        const steps = maxStep(store.currentEntry);
        expect(steps).toBe(store.mode === 'music' ? 2 : 1);
        expect(store.revealStep).toBe(0);
        expect(store.revealDone).toBe(false);
        for (let k = 0; k < steps; k++) store.reveal();
        expect(store.revealStep).toBe(steps);
        expect(store.revealDone).toBe(true);
        checkedReveal = true;
      }
    }

    expect(checkedReveal).toBe(true);
    expect(modes).toEqual(new Set(['numeric', 'music', 'image']));
    expect(store.drawnByKind).toEqual({ numeric: 90, music: 58, image: 86 });
    expect(store.remaining).toBe(0);
  });

  it('initGame() rebaraja la misma config', () => {
    const store = useGameStore();
    const config = houseBingo();
    store.loadBingo(config);
    store.draw();
    store.initGame();
    expect(store.config).toEqual(config); // Pinia la envuelve en un proxy reactivo
    expect(store.drawn).toBe(0);
    expect(store.sequence).toHaveLength(90 + 58 + 86);
  });

  it('sin numérico: solo canciones y fotos, y el cierre solo enseña esos dos recuentos', () => {
    const store = useGameStore();
    const config = houseBingo();
    config.modes.numeric.enabled = false;
    store.loadBingo(config);
    expect(store.enabledKinds).toEqual(['music', 'image']);
    while (store.canDraw) {
      store.draw();
      expect(store.mode).not.toBe('numeric');
    }
    expect(store.drawnByKind).toEqual({ numeric: 0, music: 58, image: 86 });
  });

  it('enabledKinds con la config de casa son los tres modos', () => {
    const store = useGameStore();
    store.loadBingo(houseBingo());
    expect(store.enabledKinds).toEqual(['numeric', 'music', 'image']);
  });

  it('loadBingo con todos los modos apagados lanza el error de E1', () => {
    const store = useGameStore();
    const config = houseBingo();
    config.modes.numeric.enabled = false;
    config.modes.music.enabled = false;
    config.modes.image.enabled = false;
    expect(() => store.loadBingo(config)).toThrow(new Error('Activa al menos un modo de juego.'));
    expect(store.config).toBeNull();
  });

  it('sin numérico y sin nada sacado, mode cae a numeric (por eso CallerView tiene `idle`)', () => {
    // CallerView no puede fiarse de `store.mode` antes de la primera tirada: sin numérico pintaría
    // el tablero vacío. Por eso muestra su pantalla de espera neutra (`idle`) en ese caso.
    const store = useGameStore();
    const config = houseBingo();
    config.modes.numeric.enabled = false;
    store.loadBingo(config);
    expect(store.drawn).toBe(0);
    expect(store.currentEntry).toBeNull();
    expect(store.mode).toBe('numeric');
  });
});

// Entradas sueltas para el revelado en dos pasos y el reproductor persistente (M3).
const song = (n) => ({
  kind: 'music',
  item: {
    id: `i${n}`,
    kind: 'music',
    label: `Canción ${n}`,
    sublabel: 'Artista',
    media: { type: 'youtube', url: null, thumbUrl: null, videoId: `vid${n}`.padEnd(11, 'x'), startSeconds: null, endSeconds: null }
  }
});
const photo = { kind: 'image', item: { id: 'r1', kind: 'image', label: 'Foto', sublabel: null, media: { type: 'image', url: '/f.jpg' } } };
const ball = (n) => ({ kind: 'numeric', item: n });

describe('revelado en dos pasos y última canción', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  // Store con una secuencia fija (sin barajar): config de casa para pasar loadBingo y luego a mano.
  function storeWith(sequence) {
    const store = useGameStore();
    store.loadBingo(houseBingo());
    store.sequence = sequence;
    return store;
  }

  it('maxStep: youtube 2 · image 1 · numeric 0 · nada 0', () => {
    expect(maxStep(song(1))).toBe(2);
    expect(maxStep(photo)).toBe(1);
    expect(maxStep(ball(7))).toBe(0);
    expect(maxStep(null)).toBe(0);
  });

  it('un número sale ya revelado (paso 0 = máximo)', () => {
    const store = storeWith([ball(7)]);
    store.draw();
    expect(store.revealStep).toBe(0);
    expect(store.revealDone).toBe(true);
    store.reveal();
    expect(store.revealStep).toBe(0);
  });

  it('una canción de YouTube: tapa → título → vídeo, y ahí se queda', () => {
    const store = storeWith([song(1)]);
    store.draw();
    expect([store.revealStep, store.revealDone]).toEqual([0, false]);
    store.reveal();
    expect([store.revealStep, store.revealDone]).toEqual([1, false]);
    store.reveal();
    expect([store.revealStep, store.revealDone]).toEqual([2, true]);
    store.reveal();
    expect(store.revealStep).toBe(2);
  });

  it('una foto: un solo paso (el título; la foto se ve desde el principio)', () => {
    const store = storeWith([photo]);
    store.draw();
    expect(store.revealDone).toBe(false);
    store.reveal();
    expect([store.revealStep, store.revealDone]).toEqual([1, true]);
  });

  it('draw() vuelve al paso 0 aunque la anterior estuviera destapada', () => {
    const store = storeWith([song(1), song(2), ball(3)]);
    store.draw();
    store.reveal();
    store.reveal();
    store.draw();
    expect([store.revealStep, store.revealDone]).toEqual([0, false]);
    store.reveal();
    store.draw();
    expect([store.revealStep, store.revealDone]).toEqual([0, true]);
  });

  it('revealAll() (onError) salta al máximo de la entrada actual', () => {
    const store = storeWith([song(1)]);
    store.draw();
    store.revealAll();
    expect([store.revealStep, store.revealDone]).toEqual([2, true]);
  });

  it('lastMusicItem es la última canción SACADA, no la entrada actual', () => {
    const store = storeWith([ball(1), song(1), ball(2), photo, ball(3), song(2)]);
    expect(store.lastMusicItem).toBeNull();
    store.draw(); // bola
    expect(store.lastMusicItem).toBeNull();
    store.draw(); // canción 1
    expect(store.lastMusicItem.id).toBe('i1');
    store.draw(); // bola: el player sigue con la canción 1
    store.draw(); // foto
    store.draw(); // bola
    expect(store.current).toBe(3);
    expect(store.lastMusicItem.id).toBe('i1');
    store.draw(); // canción 2
    expect(store.lastMusicItem.id).toBe('i2');
  });

  it('initGame() olvida la última canción y el revelado', () => {
    const store = storeWith([song(1)]);
    store.draw();
    store.reveal();
    store.initGame();
    expect(store.lastMusicItem).toBeNull();
    expect(store.revealStep).toBe(0);
  });
});
