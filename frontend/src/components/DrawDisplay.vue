<template>
  <div class="draw">
    <transition name="pop" mode="out-in">
      <div class="ball" :key="value" v-if="value != null">{{ value }}</div>
      <div class="ball ball--empty" key="empty" v-else>?</div>
    </transition>
    <p class="draw__caption">{{ value != null ? '' : 'Pulsa «Sacar bola»' }}</p>
  </div>
</template>

<script>
export default {
  name: 'DrawDisplay',
  props: {
    value: { type: [Number, null], default: null }
  }
};
</script>

<style scoped lang="scss">
.draw {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1.2rem;
}

.ball {
  width: clamp(180px, 26vw, 340px);
  height: clamp(180px, 26vw, 340px);
  border-radius: 50%;
  display: grid;
  place-items: center;
  font-size: clamp(5rem, 14vw, 12rem);
  font-weight: 900;
  color: #221100;
  background: radial-gradient(circle at 35% 30%, #fff, var(--accent-2) 55%, var(--accent));
  box-shadow: inset -10px -14px 30px rgba(0, 0, 0, 0.25), var(--shadow);
}

.ball--empty {
  color: var(--ink-dim);
  background: radial-gradient(circle at 35% 30%, #2a2a52, #14142b);
  box-shadow: var(--shadow);
}

.draw__caption {
  margin: 0;
  color: var(--ink-dim);
  font-size: clamp(1rem, 2vw, 1.5rem);
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.pop-enter-active {
  animation: pop 0.35s cubic-bezier(0.22, 1.4, 0.4, 1);
}
@keyframes pop {
  0% {
    transform: scale(0.2) rotate(-25deg);
    opacity: 0;
  }
  100% {
    transform: scale(1) rotate(0);
    opacity: 1;
  }
}
</style>
