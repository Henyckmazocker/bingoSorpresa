<template>
  <div class="card" :class="`card--${mode}`" :style="mode === 'numeric' ? null : { '--cols': cols }">
    <!-- `--cols` alimenta la regla `--cell-font` (estilos, abajo); el numérico no la usa. -->
    <div class="card__head">
      <span class="card__title">{{ title }}</span>
      <span class="card__num">Nº {{ cardNumber }}</span>
    </div>

    <!-- Cartón numérico: 3×9 con huecos -->
    <div v-if="mode === 'numeric'" class="grid grid--numeric">
      <template v-for="(row, r) in data" :key="r">
        <div
          v-for="(cell, c) in row"
          :key="`${r}-${c}`"
          class="cell"
          :class="{ 'cell--blank': cell == null }"
        >{{ cell }}</div>
      </template>
    </div>

    <!-- Cartón de items (music / image): rejilla de texto. Sin miniatura de foto: en una
         casilla de este tamaño no se reconocía nada y el texto se queda sin sitio. -->
    <div v-else class="grid grid--items" :style="{ gridTemplateColumns: `repeat(${cols}, 1fr)` }">
      <div v-for="item in data" :key="item.id" class="cell cell--item">
        <span class="cell__text">{{ item.label }}</span>
        <span v-if="item.sublabel" class="cell__sub">{{ item.sublabel }}</span>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'BingoCard',
  props: {
    mode: { type: String, required: true }, // ModeKind: 'numeric' | 'music' | 'image'
    data: { type: Array, required: true },   // matriz 3×9 (numeric) o array de BingoItem
    title: { type: String, default: 'Bingo' },
    cardNumber: { type: [Number, String], default: 1 },
    cols: { type: Number, default: 3 } // columnas del cartón de items (el numérico siempre es 9)
  }
};
</script>

<style scoped lang="scss">
.card {
  border: 3px solid #222;
  border-radius: 12px;
  padding: 10px;
  background: #fff;
  color: #111;
  break-inside: avoid;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.card__head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  font-weight: 800;
}
.card__title {
  font-size: 1.1rem;
}
.card__num {
  font-size: 0.85rem;
  color: #666;
}

.grid {
  display: grid;
  gap: 4px;
}
.grid--numeric {
  grid-template-columns: repeat(9, 1fr);
}
.grid--items {
  grid-template-columns: repeat(3, 1fr);
  /* Letra de las casillas según el nº de columnas: 3×3 y 6×6 caben sin desbordar. Aquí solo se
     define; la aplica la hoja impresa (PrintView) a los cartones que no son los de casa, que
     conservan su letra fija de siempre. */
  --cell-font: clamp(7pt, calc(40pt / var(--cols, 3)), 16pt);
}

.cell {
  aspect-ratio: 1;
  display: grid;
  place-items: center;
  border: 2px solid #333;
  border-radius: 6px;
  font-weight: 800;
  font-size: 1.1rem;
}
.cell--blank {
  border-style: dashed;
  border-color: #ccc;
  background: repeating-linear-gradient(45deg, #fafafa, #fafafa 6px, #f0f0f0 6px, #f0f0f0 12px);
}

/* Flex, no grid: `.cell` es `display: grid` y aquí se pedía `flex-direction: column`, que en un
   grid no hace nada — la foto se dimensionaba sola y desbordaba la casilla (la tapaba el
   `overflow: hidden`). Con flex el `flex: 1` de la miniatura reparte el alto como se pretendía. */
.cell--item {
  aspect-ratio: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 3px;
  padding: 5px;
  text-align: center;
  font-size: 0.72rem;
  line-height: 1.1;
  overflow: hidden;
}
.cell__text {
  font-weight: 800;
}
.cell__sub {
  font-weight: 500;
  color: #666;
  font-size: 0.62rem;
}

.card--music .card__title { color: #5a3fd6; }
.card--image .card__title { color: #1f9e77; }
</style>
