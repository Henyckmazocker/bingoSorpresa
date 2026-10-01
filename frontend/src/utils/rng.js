/**
 * RNG determinista con semilla. Sirve para que los cartones impresos sean reproducibles:
 * la misma semilla → los mismos cartones, siempre. Así puedes reimprimir un cartón perdido
 * y sigue casando con lo que canta la tele.
 */

// Hash de string → entero de 32 bits (para aceptar semillas de texto).
export function hashSeed(str) {
  let h = 1779033703 ^ str.length;
  for (let i = 0; i < str.length; i++) {
    h = Math.imul(h ^ str.charCodeAt(i), 3432918353);
    h = (h << 13) | (h >>> 19);
  }
  h = Math.imul(h ^ (h >>> 16), 2246822507);
  h = Math.imul(h ^ (h >>> 13), 3266489909);
  return (h ^= h >>> 16) >>> 0;
}

// mulberry32: PRNG pequeño y de calidad suficiente para barajar cartones.
export function mulberry32(seed) {
  let a = typeof seed === 'string' ? hashSeed(seed) : seed >>> 0;
  return function () {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

// Baraja (Fisher-Yates) una copia del array usando el rng dado. No muta el original.
export function shuffle(array, rng = Math.random) {
  const a = array.slice();
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(rng() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

// Coge n elementos al azar (sin repetir) usando el rng dado.
export function sample(array, n, rng = Math.random) {
  return shuffle(array, rng).slice(0, n);
}
