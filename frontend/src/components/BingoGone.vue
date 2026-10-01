<template>
  <!-- Pantalla completa de «sin señal»: el bingo pedido no existe (borrado, ajeno o enlace
       desactivado) o no se pudo cargar. Sustituye a la vista entera (cantor o cartones). -->
  <div class="page gone">
    <main class="page__panel gone__panel" role="alert">
      <p class="gone__static" aria-hidden="true">📺</p>
      <h1 class="gone__title">{{ title }}</h1>
      <p v-if="detail" class="gone__detail">{{ detail }}</p>
      <div class="gone__actions">
        <slot />
        <router-link v-if="backTo" class="btn btn--primary" :to="backTo">{{ backLabel }}</router-link>
      </div>
    </main>
  </div>
</template>

<script>
export default {
  name: 'BingoGone',
  props: {
    title: { type: String, default: 'Este bingo ya no existe' },
    detail: { type: String, default: '' },
    // Ruta del botón; null = sin botón (en `/b/:token` no hay a dónde volver sin cuenta).
    backTo: { type: [String, Object], default: '/jugar' },
    backLabel: { type: String, default: '🎱 Ir a Jugar' }
  }
};
</script>

<style scoped lang="scss">
.gone {
  display: grid;
  place-items: center;
}

.gone__panel {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}

/* Tele sin señal: el icono parpadea como la nieve de un canal vacío. */
.gone__static {
  margin: 0;
  font-size: clamp(3rem, 10vw, 5.5rem);
  animation: gone-flicker 2.4s steps(2, end) infinite;
}

@keyframes gone-flicker {
  0%, 92% { opacity: 1; }
  94% { opacity: 0.3; }
  96% { opacity: 0.9; }
  98% { opacity: 0.4; }
}

.gone__title {
  margin: 0;
  font-size: clamp(1.8rem, 5vw, 3rem);
  font-weight: 900;
  color: var(--accent-2);
}

.gone__detail {
  margin: 0;
  color: var(--ink-dim);
  max-width: 34rem;
  line-height: 1.5;
}

.gone__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.8rem;
  margin-top: 0.6rem;
}

.gone__actions .btn {
  text-decoration: none;
}

@media (prefers-reduced-motion: reduce) {
  .gone__static {
    animation: none;
  }
}
</style>
