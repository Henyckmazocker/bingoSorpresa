<template>
  <div class="page">
    <main class="page__panel play">
      <header class="play__head">
        <h1 class="play__title">🎱 Jugar</h1>
        <p class="play__lead">
          Elige un bingo: cántalo aquí, en el navegador (conecta el portátil a la tele), mándalo a
          la tele con el código que enseña la app, o imprime sus cartones.
        </p>
      </header>

      <p v-if="!loaded" class="play__empty">📡 Sintonizando…</p>
      <p v-else-if="error" class="play__error" role="alert">{{ error }}</p>
      <p v-else-if="!bingos.length" class="play__empty">
        Aún no tienes bingos.
        <router-link to="/construir">Monta el primero en Construir</router-link>.
      </p>

      <ul v-else class="play__list">
        <li v-for="b in bingos" :key="b.id" class="channel">
          <div class="channel__main">
            <span class="channel__title">{{ b.title }}</span>
            <span class="channel__meta">
              <span v-if="b.numericEnabled">🔢 Números</span>
              <span v-if="b.imageEnabled">🖼️ {{ b.imageCount }} {{ b.imageCount === 1 ? 'foto' : 'fotos' }}</span>
              <span v-if="b.printsCount">🖨️ {{ b.printsCount }} {{ b.printsCount === 1 ? 'tirada' : 'tiradas' }}</span>
              <span v-if="!playable(b)" class="channel__off">Sin modos activos</span>
            </span>
          </div>
          <div class="channel__actions">
            <router-link
              class="btn btn--primary channel__btn"
              :class="{ 'channel__btn--off': !playable(b) }"
              :to="`/cantar/${b.id}`"
            >
              ▶ Jugar aquí
            </router-link>
            <router-link
              class="btn channel__btn"
              :class="{ 'channel__btn--off': !playable(b) }"
              :to="`/imprimir/${b.id}`"
            >
              🖨 Imprimir
            </router-link>
            <router-link
              class="btn channel__btn"
              :class="{ 'channel__btn--off': !playable(b) }"
              :to="{ path: '/enviar', query: { bingo: b.id } }"
            >
              📺 Enviar a una tele
            </router-link>
          </div>
        </li>
      </ul>

      <nav class="play__nav">
        <router-link class="page__back" to="/construir">🛠️ Construir</router-link>
        <router-link class="page__back" to="/">← Volver a la portada</router-link>
      </nav>
    </main>
  </div>
</template>

<script>
import { apiCall } from '@/services/api';

// «/jugar»: la lista de bingos del usuario con sus tres salidas. «Enviar a una tele» lleva a
// `/enviar?bingo=<id>`, que pide el código que enseña la APK.
export default {
  name: 'PlayListView',
  data() {
    return {
      bingos: [],
      loaded: false,
      error: ''
    };
  },
  created() {
    this.load();
  },
  methods: {
    async load() {
      try {
        this.bingos = await apiCall('list_bingos');
      } catch (e) {
        this.error = e.message || 'No se pudieron cargar tus bingos.';
      } finally {
        this.loaded = true;
      }
    },
    // Aviso rápido con el resumen de la lista; las reglas completas (E1–E4) las aplica la vista
    // del cantor o de los cartones al cargar el bingo.
    playable(b) {
      return b.numericEnabled || b.imageEnabled || b.musicEnabled;
    }
  }
};
</script>

<style scoped lang="scss">
.play {
  display: flex;
  flex-direction: column;
  gap: 1.4rem;
}

.play__title {
  margin: 0;
  font-size: clamp(1.8rem, 5vw, 2.6rem);
  font-weight: 900;
}

.play__lead {
  margin: 0.4rem 0 0;
  color: var(--ink-dim);
  line-height: 1.5;
}

.play__empty {
  margin: 0;
  padding: 1.2rem;
  border: 2px dashed color-mix(in srgb, var(--ink-dim) 50%, transparent);
  border-radius: var(--radius);
  color: var(--ink-dim);
  text-align: center;
}

.play__empty a {
  color: var(--accent-2);
  font-weight: 700;
}

.play__error {
  margin: 0;
  color: var(--accent);
}

.play__list {
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

/* Cada bingo es un «canal» (como en Construir), con sus dos botones a la derecha. */
.channel {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.8rem;
  padding: 0.8rem 0.9rem;
  border-left: 6px solid var(--accent);
  border-radius: 14px;
  background: var(--bg-2);
}

.channel__main {
  flex: 1 1 12rem;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.channel__title {
  font-size: 1.2rem;
  font-weight: 800;
  overflow-wrap: anywhere;
}

.channel__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem 0.9rem;
  color: var(--ink-dim);
  font-size: 0.9rem;
}

.channel__off {
  color: var(--accent-2);
}

.channel__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.channel__btn {
  font-size: 1rem;
  padding: 0.55em 1em;
  text-decoration: none;
  color: var(--ink);
}

.channel__btn.btn--primary {
  color: #221100;
}

.channel__btn--off {
  opacity: 0.55;
}

.play__nav {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 1rem;
}

.play__nav .page__back {
  margin-top: 0;
}
</style>
