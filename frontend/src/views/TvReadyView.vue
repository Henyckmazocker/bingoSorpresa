<template>
  <!-- Antesala del cantor en la tele (solo APK): aparece cuando el bingo recibido trae canciones de
       YouTube. «▶️ Empezar» va al cantor; «🎵 Ensayar canciones» (el botón del RehearsalPanel)
       prueba cada canción en ESTA tele, que es donde la respuesta puede cambiar. Todo con el mando:
       foco inicial en «Empezar» y los dos botones a la misma altura (izquierda-derecha). -->
  <div v-if="store.config" class="ready">
    <section class="ready__info">
      <p class="ready__eyebrow">📺 Bingo recibido</p>
      <h1 class="ready__title">{{ store.config.title }}</h1>
      <p class="ready__lead">
        Trae {{ songs.length }} {{ songs.length === 1 ? 'canción' : 'canciones' }} de YouTube.
        Si quieres, ensáyalas antes de empezar para ver cuáles suenan en esta tele.
      </p>
      <button ref="start" class="btn btn--primary ready__start" @click="begin">▶️ Empezar</button>
    </section>

    <section class="ready__rehearsal">
      <RehearsalPanel :items="songs" />
    </section>
  </div>
</template>

<script>
import { useGameStore } from '@/store/game';
import { youtubeSongs } from '@/bingo/rehearsal';
import RehearsalPanel from '@/components/RehearsalPanel.vue';

export default {
  name: 'TvReadyView',
  components: { RehearsalPanel },
  setup() {
    return { store: useGameStore() };
  },
  computed: {
    songs() {
      return youtubeSongs(this.store.config);
    }
  },
  created() {
    // Sin bingo (la webview recargada en `/listo`): a la pantalla del código, como el cantor.
    if (!this.store.config) this.$router.replace('/');
    // Sin canciones de YouTube no hay nada que ensayar: directo al cantor.
    else if (!this.songs.length) this.$router.replace('/cantar');
  },
  mounted() {
    if (this.$refs.start) this.$refs.start.focus();
  },
  methods: {
    // `replace`: «Atrás» del mando (y «Salir» del cierre) desde el cantor vuelve a la pantalla del
    // código, no a esta antesala.
    begin() {
      this.$router.replace('/cantar');
    }
  }
};
</script>

<style scoped lang="scss">
/* Pantalla horizontal de tele, como la del código: datos y «Empezar» a la izquierda, ensayo a la
   derecha. El body no hace scroll: la lista del ensayo se desplaza dentro de su columna. */
.ready {
  height: 100%;
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr);
  align-items: center;
  gap: clamp(1.5rem, 5vw, 5rem);
  padding: clamp(1rem, 4vw, 4rem);
}

.ready__info {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: clamp(0.5rem, 2vh, 1.4rem);
  min-width: 0;
}
.ready__eyebrow {
  margin: 0;
  color: var(--ink-dim);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-size: clamp(0.9rem, 1.6vw, 1.3rem);
}
.ready__title {
  margin: 0;
  font-size: clamp(2rem, 5vw, 4rem);
  font-weight: 900;
  line-height: 1.05;
  overflow-wrap: anywhere;
}
.ready__lead {
  margin: 0;
  max-width: 30em;
  color: var(--ink-dim);
  font-size: clamp(1rem, 1.9vw, 1.6rem);
  line-height: 1.4;
}
.ready__start {
  margin-top: clamp(0.4rem, 2vh, 1.2rem);
  min-width: clamp(240px, 30vw, 420px);
}

.ready__rehearsal {
  align-self: stretch;
  min-height: 0;
  max-height: 100%;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  justify-content: center;
}
/* El panel nace para el editor: en la tele, letra de sala de estar. */
.ready__rehearsal :deep(.rehearsal) {
  margin: 0;
  font-size: clamp(1rem, 1.6vw, 1.3rem);
}
.ready__rehearsal :deep(.rehearsal__hint) {
  font-size: 0.9em;
}

/* Probando en el móvil en vertical: apilado. */
@media (max-aspect-ratio: 1/1) {
  .ready {
    grid-template-columns: 1fr;
    overflow-y: auto;
  }
}
</style>
