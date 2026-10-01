<template>
  <!-- Presentacional: recibe la config por v-model y emite una config NUEVA en cada cambio (no muta
       la prop). El editor del backoffice lo reutiliza tal cual. -->
  <div class="mode-settings">
    <div v-for="kind in kinds" :key="kind" class="controls__row mode-settings__mode">
      <label class="controls__check">
        <input
          type="checkbox"
          :checked="modelValue.modes[kind].enabled"
          @change="patch(kind, { enabled: $event.target.checked })"
        />
        {{ modelValue.modes[kind].label }}
      </label>

      <!-- El cartón numérico no tiene tamaño (siempre 3×9). Al apagar un modo sorpresa se ocultan
           sus selectores pero su `card` se conserva. -->
      <template v-if="kind !== 'numeric' && modelValue.modes[kind].enabled">
        <label>Filas
          <select :value="modelValue.modes[kind].card[0]" @change="setSide(kind, 0, $event.target.value)">
            <option v-for="n in sides" :key="n" :value="n">{{ n }}</option>
          </select>
        </label>
        <label>Columnas
          <select :value="modelValue.modes[kind].card[1]" @change="setSide(kind, 1, $event.target.value)">
            <option v-for="n in sides" :key="n" :value="n">{{ n }}</option>
          </select>
        </label>
      </template>
    </div>

    <ul v-if="errors.length" class="mode-settings__errors">
      <li v-for="(e, i) in errors" :key="i">{{ e }}</li>
    </ul>
  </div>
</template>

<script>
import { MODE_KINDS } from '@/bingo/types';
import { validateConfig, CARD_LIMITS } from '@/bingo/validateConfig';

// Lados que se ofrecen en los selectores: de CARD_LIMITS.minSide a maxSide (2..6).
const SIDES = Array.from(
  { length: CARD_LIMITS.maxSide - CARD_LIMITS.minSide + 1 },
  (_, i) => CARD_LIMITS.minSide + i
);

export default {
  name: 'ModeSettings',
  props: {
    modelValue: { type: Object, required: true }, // BingoConfig
    // Modos que se pintan. Por defecto los tres (PrintView y el editor del backoffice). Los ocultos
    // se conservan tal cual.
    kinds: { type: Array, default: () => MODE_KINDS }
  },
  emits: ['update:modelValue'],
  data() {
    return { sides: SIDES };
  },
  computed: {
    errors() {
      return validateConfig(this.modelValue).errors;
    }
  },
  methods: {
    // Copia superficial con el modo cambiado: los arrays de items se comparten (no se mutan).
    patch(kind, changes) {
      const config = this.modelValue;
      this.$emit('update:modelValue', {
        ...config,
        modes: { ...config.modes, [kind]: { ...config.modes[kind], ...changes } }
      });
    },
    setSide(kind, index, value) {
      const card = [...this.modelValue.modes[kind].card];
      card[index] = Number(value);
      this.patch(kind, { card });
    }
  }
};
</script>

<style scoped lang="scss">
.mode-settings__mode {
  margin-top: 0.6rem;
}
.mode-settings__mode .controls__check {
  min-width: 13rem;
}
.mode-settings select {
  padding: 0.4rem 0.6rem;
  border-radius: 10px;
  border: 1px solid #3a3a63;
  background: var(--bg-2);
  color: var(--ink);
  font-size: 1rem;
}
/* Errores de validación (E1–E4) en línea, en rojo. */
.mode-settings__errors {
  margin: 1rem 0 0;
  padding-left: 1.2rem;
  color: #ff5c5c;
}
</style>
