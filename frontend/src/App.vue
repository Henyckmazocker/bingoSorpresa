<template>
  <RetroBackground />
  <router-view />
</template>

<script>
import RetroBackground from '@/components/RetroBackground.vue';

export default {
  name: 'App',
  components: { RetroBackground }
};
</script>
