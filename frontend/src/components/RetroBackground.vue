<template>
  <div class='bg' aria-hidden='true'>
    <!-- Banda retro ondulada, en un único tile que sube en loop infinito -->
    <div class='bg__pattern' :style='patternStyle'></div>
    <!-- Velo para dejar el fondo tenue y que se lea el bingo por encima -->
    <div class='bg__scrim'></div>
  </div>
</template>

<script>
// Patrón retro propio (diseño original, sin autor ajeno): cuatro bandas paralelas que ondulan en
// vertical. La onda tiene periodo 500 y entra/sale del tile por x=150 con la misma tangente, así que
// el loop vertical no tiene costura. Vectorial, sin dependencias: va en el APK y funciona offline.
const WAVE = 'M150-250C90-170 90-80 150 0S210 170 150 250 90 420 150 500 210 670 150 750';
const BANDS = [
  ['#7b2141', 150], // granate (borde)
  ['#e9693a', 112], // naranja
  ['#f6a04f', 74], // naranja claro
  ['#fdca5c', 36] // amarillo (centro)
];
const SVG =
  "<svg xmlns='http://www.w3.org/2000/svg' width='300' height='500' viewBox='0 0 300 500'><g fill='none'>" +
  BANDS.map(([c, w]) => `<path d='${WAVE}' stroke='${c}' stroke-width='${w}'/>`).join('') +
  '</g></svg>';
const TILE = `data:image/svg+xml,${encodeURIComponent(SVG)}`;

export default {
  name: 'RetroBackground',
  computed: {
    patternStyle() {
      return { backgroundImage: `url("${TILE}")` };
    }
  }
};
</script>

<style scoped lang='scss'>
.bg { position: fixed; inset: 0; z-index: -1; overflow: hidden; background: #00c1ff; }
.bg__pattern, .bg__scrim { position: absolute; inset: 0; }
.bg__pattern {
  background-repeat: repeat-y;
  background-size: 300px 500px;
  background-position: 30% 0;
  animation: bg-rise 30s linear infinite;
}
@keyframes bg-rise { to { background-position: 30% -500px; } }
.bg__scrim { background: rgba(8, 8, 22, 0.62); }
@media (prefers-reduced-motion: reduce) { .bg__pattern { animation: none; } }
</style>
