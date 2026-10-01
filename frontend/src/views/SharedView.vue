<template>
  <!-- Enlace compartido (`/b/:token`), sin cuenta. Sin botón a /jugar: pide login. -->
  <BingoGone v-if="loadError" :title="loadError.title" :detail="loadError.detail" :back-to="null" />
  <div v-else class="page">
    <main class="page__panel shared">
      <p class="shared__eyebrow">📺 Te han pasado un bingo</p>
      <p v-if="!config" class="shared__tuning">📡 Sintonizando…</p>
      <template v-else>
        <h1 class="shared__title">{{ config.title }}</h1>
        <p class="shared__meta">
          <span v-if="config.modes.numeric.enabled">🔢 Números del 1 al 90</span>
          <span v-if="config.modes.image.enabled">
            🖼️ {{ config.modes.image.items.length }} fotos · cartón {{ config.modes.image.card[0] }}×{{ config.modes.image.card[1] }}
          </span>
        </p>

        <ul v-if="errors.length" class="shared__errors">
          <li v-for="e in errors" :key="e">{{ e }}</li>
        </ul>

        <div class="shared__actions">
          <router-link
            class="btn btn--primary shared__btn"
            :class="{ 'shared__btn--off': errors.length }"
            :to="`/b/${token}/cantar`"
          >
            ▶ Cantar
          </router-link>
          <router-link
            class="btn shared__btn"
            :class="{ 'shared__btn--off': errors.length }"
            :to="`/b/${token}/imprimir`"
          >
            🖨 Imprimir cartones
          </router-link>
        </div>
        <p class="shared__hint">
          «Cantar» saca las bolas y las fotos en esta pantalla (mejor en la tele). «Imprimir» genera
          un A4 por jugador.
        </p>
      </template>

      <footer class="shared__footer">
        <router-link to="/">Bingo Sorpresa</router-link> ·
        <router-link to="/privacidad">Privacidad</router-link>
      </footer>
    </main>
  </div>
</template>

<script>
import BingoGone from '@/components/BingoGone.vue';
import { loadSharedBingo, isGone } from '@/bingo/apiBingo';
import { validateConfig } from '@/bingo/validateConfig';

export default {
  name: 'SharedView',
  components: { BingoGone },
  props: {
    token: { type: String, required: true }
  },
  data() {
    return {
      config: null,
      loadError: null
    };
  },
  computed: {
    errors() {
      return this.config ? validateConfig(this.config).errors : [];
    }
  },
  watch: {
    token() {
      this.load();
    }
  },
  created() {
    this.load();
  },
  methods: {
    async load() {
      this.config = null;
      this.loadError = null;
      try {
        this.config = await loadSharedBingo(this.token);
      } catch (e) {
        this.loadError = isGone(e)
          ? { title: 'Este bingo ya no existe', detail: 'El enlace se ha desactivado o el bingo se ha borrado.' }
          : { title: 'No se pudo cargar el bingo', detail: e.message || '' };
      }
    }
  }
};
</script>

<style scoped lang="scss">
.shared {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  text-align: center;
}

.shared__eyebrow {
  margin: 0;
  color: var(--accent-2);
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.shared__tuning {
  color: var(--ink-dim);
  font-size: 1.3rem;
}

.shared__title {
  margin: 0;
  font-size: clamp(2rem, 6vw, 3.4rem);
  font-weight: 900;
  overflow-wrap: anywhere;
}

.shared__meta {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.3rem 1.2rem;
  margin: 0;
  color: var(--ink-dim);
}

.shared__errors {
  margin: 0;
  padding: 0;
  list-style: none;
  color: var(--accent-2);
}

.shared__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.8rem;
  margin-top: 0.6rem;
}

.shared__btn {
  text-decoration: none;
  color: var(--ink);
}

.shared__btn.btn--primary {
  color: #221100;
}

.shared__btn--off {
  opacity: 0.5;
  pointer-events: none;
}

.shared__hint {
  margin: 0;
  color: var(--ink-dim);
  font-size: 0.95rem;
  line-height: 1.5;
}

.shared__footer {
  margin-top: 1rem;
  color: var(--ink-dim);
  font-size: 0.9rem;
}

.shared__footer a {
  color: var(--ink-dim);
}
</style>
