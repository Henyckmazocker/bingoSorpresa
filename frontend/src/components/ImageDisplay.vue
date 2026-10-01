<template>
  <div class="memory">
    <div class="memory__badge">💖 Recuerdo nº {{ index }}</div>

    <!-- La foto sale de primeras (es la pista); lo que se revela con 👁️ es el título. El hueco
         del título se reserva siempre para que la foto no dé un salto al aparecer el texto. -->
    <figure class="memory__reveal">
      <img v-if="item && item.media.url" :src="item.media.url" alt="" class="memory__photo" />
      <figcaption class="memory__caption">
        <transition name="fade">
          <span v-if="revealed">{{ item ? item.label : '' }}</span>
        </transition>
      </figcaption>
    </figure>
  </div>
</template>

<script>
export default {
  name: 'ImageDisplay',
  props: {
    // `BingoItem` de kind `image`; `media.url` ya viene resuelta por la config.
    item: { type: Object, default: null },
    index: { type: Number, default: 0 },
    revealed: { type: Boolean, default: false }
  }
};
</script>

<style scoped lang="scss">
.memory {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1.2rem;
}

.memory__badge {
  font-size: clamp(1.2rem, 2.4vw, 2rem);
  font-weight: 800;
  color: var(--image);
}

.memory__reveal {
  margin: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}

.memory__photo {
  max-width: min(60vw, 640px);
  max-height: 52vh;
  object-fit: contain;
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  border: 6px solid #fff;
}

.memory__caption {
  font-size: clamp(1.6rem, 4vw, 3rem);
  font-weight: 800;
  text-align: center;
  color: var(--image);
  min-height: 1.2em; /* reserva la línea del título aunque aún no se haya revelado */
  max-width: min(80vw, 900px);
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.25s;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
