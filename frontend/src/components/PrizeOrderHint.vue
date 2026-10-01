<template>
  <!-- Orden esperado de los premios con la config actual. Si la config no es válida, nada. -->
  <div v-if="rows.length" class="prize-hint">
    <p class="prize-hint__title">Orden esperado de los premios (medianas simuladas)</p>
    <ol class="prize-hint__list">
      <li v-for="r in rows" :key="r.key" :class="{ 'prize-hint__warn': r.warn }">
        <span v-if="r.warn">⚠️ </span>{{ r.text }}
      </li>
    </ol>
    <p v-if="hasWarnings" class="controls__warn">⚠️ Hay premios demasiado juntos: cambia el tamaño de algún cartón.</p>
  </div>
</template>

<script>
import { simulatePrizes } from '@/bingo/prizes';
import { validateConfig } from '@/bingo/validateConfig';
import { prizeHintRows } from '@/bingo/prizeHint';

// Espera tras el último cambio antes de simular (simulatePrizes es síncrono, ~140 ms).
const DEBOUNCE_MS = 300;

export default {
  name: 'PrizeOrderHint',
  props: {
    config: { type: Object, required: true } // BingoConfig
  },
  data() {
    return { rows: [], hasWarnings: false };
  },
  watch: {
    // ModeSettings emite una config nueva en cada cambio: basta con vigilar la referencia.
    config: { handler: 'schedule', immediate: true }
  },
  beforeUnmount() {
    clearTimeout(this.timer);
  },
  methods: {
    schedule() {
      clearTimeout(this.timer);
      if (!validateConfig(this.config).ok) {
        this.rows = [];
        this.hasWarnings = false;
        return;
      }
      this.timer = setTimeout(() => {
        const result = simulatePrizes(this.config);
        this.rows = prizeHintRows(result, this.config);
        this.hasWarnings = result.warnings.length > 0;
      }, DEBOUNCE_MS);
    }
  }
};
</script>

<style scoped lang="scss">
.prize-hint {
  margin-top: 1rem;
}
.prize-hint__title {
  margin: 0 0 0.4rem;
  color: var(--ink-dim);
  font-size: 0.9rem;
}
.prize-hint__list {
  margin: 0;
  padding-left: 1.4rem;
  line-height: 1.6;
}
.prize-hint__warn {
  color: var(--accent-2);
}
</style>
