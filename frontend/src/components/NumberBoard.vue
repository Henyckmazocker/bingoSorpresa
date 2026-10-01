<template>
  <div class="board-fit" :style="{ '--rows': rows }">
    <div class="board" aria-label="Panel de números cantados">
      <span
        v-for="n in max"
        :key="n"
        class="board__cell"
        :class="{ 'board__cell--on': drawn.has(n), 'board__cell--last': n === last }"
      >{{ n }}</span>
    </div>
  </div>
</template>

<script>
const COLS = 10;

export default {
  name: 'NumberBoard',
  props: {
    max: { type: Number, default: 90 },
    drawn: { type: Set, required: true },
    last: { type: [Number, null], default: null }
  },
  computed: {
    rows() {
      return Math.ceil(this.max / COLS);
    }
  }
};
</script>

<style scoped lang="scss">
/* Contenedor de tamaño: permite limitar el ancho del tablero por la altura
   disponible (100cqh), para que las 9 filas nunca se salgan de la pantalla. */
.board-fit {
  container-type: size;
  width: 100%;
  height: 100%;
  min-width: 0;
  min-height: 0;
  display: grid;
  place-items: center;
}

.board {
  display: grid;
  grid-template-columns: repeat(10, 1fr);
  grid-template-rows: repeat(var(--rows), 1fr);
  gap: clamp(3px, 0.5vw, 8px);
  aspect-ratio: 10 / var(--rows);
  width: min(100%, 100cqh * 10 / var(--rows));
}

.board__cell {
  min-width: 0;
  display: grid;
  place-items: center;
  border-radius: 8px;
  background: var(--panel);
  color: var(--ink-dim);
  font-size: clamp(0.7rem, 1.5vw, 1.25rem);
  font-weight: 700;
  transition: background 0.2s, color 0.2s, transform 0.2s;
}

.board__cell--on {
  background: linear-gradient(135deg, var(--accent), var(--accent-2));
  color: #221100;
}

.board__cell--last {
  transform: scale(1.12);
  box-shadow: 0 0 0 3px var(--focus);
}
</style>
