<template>
  <div class="page">
    <main class="page__panel build">
      <header class="build__user">
        <img
          v-if="user.picture"
          class="build__avatar"
          :src="user.picture"
          alt=""
          referrerpolicy="no-referrer"
        />
        <div class="build__who">
          <h1 class="build__name">{{ user.name || 'Tu cuenta' }}</h1>
          <p v-if="user.email" class="build__email">{{ user.email }}</p>
        </div>
      </header>

      <section class="bingos" aria-labelledby="bingos-title">
        <div class="bingos__head">
          <h2 id="bingos-title" class="bingos__title">📺 Tus bingos</h2>
          <span v-if="loaded" class="bingos__count">{{ bingos.length }} / {{ MAX_BINGOS }}</span>
        </div>

        <form class="bingos__create" @submit.prevent="createBingo">
          <input
            v-model="newTitle"
            class="bingos__input"
            maxlength="120"
            placeholder="Título del bingo nuevo"
            aria-label="Título del bingo nuevo"
            :disabled="busy || atLimit"
          />
          <button class="btn btn--primary" type="submit" :disabled="busy || atLimit || !newTitle.trim()">
            ＋ Crear
          </button>
        </form>
        <p v-if="atLimit" class="bingos__hint">Has llegado a {{ MAX_BINGOS }} bingos: borra alguno para crear otro.</p>

        <p v-if="!loaded" class="build__soon">Sintonizando…</p>
        <p v-else-if="!bingos.length" class="build__soon">🛠️ Aún no tienes bingos. Ponle título al primero y dale a «Crear».</p>

        <ul v-else class="bingos__list">
          <li v-for="b in bingos" :key="b.id" class="bingo-row">
            <router-link class="bingo-row__main" :to="`/construir/${b.id}`">
              <span class="bingo-row__title">{{ b.title }}</span>
              <span class="bingo-row__meta">
                <span v-if="b.numericEnabled">🔢 Números</span>
                <span :class="{ 'bingo-row__off': !b.imageEnabled }">🖼️ {{ b.imageCount }} {{ b.imageCount === 1 ? 'foto' : 'fotos' }}</span>
                <span v-if="b.shared">🔗 Compartido</span>
                <span v-if="b.printsCount">🖨️ {{ b.printsCount }} {{ b.printsCount === 1 ? 'tirada' : 'tiradas' }}</span>
              </span>
            </router-link>
            <div class="bingo-row__actions">
              <button class="btn bingo-row__btn" :disabled="busy || atLimit" title="Duplicar" @click="duplicateBingo(b)">
                ⧉ <span class="bingo-row__label">Duplicar</span>
              </button>
              <button class="btn bingo-row__btn build__danger" :disabled="busy" title="Borrar" @click="askDelete(b)">
                🗑 <span class="bingo-row__label">Borrar</span>
              </button>
            </div>
          </li>
        </ul>
      </section>

      <div v-if="error" class="build__error" role="alert">{{ error }}</div>

      <div class="build__actions">
        <button class="btn" :disabled="busy" @click="logout">Cerrar sesión</button>
        <button class="btn build__danger" :disabled="busy" @click="openDelete">Borrar cuenta</button>
      </div>

      <router-link class="page__back" to="/">← Volver a la portada</router-link>
    </main>

    <ConfirmDialog
      v-if="bingoToDelete"
      :message="`¿Borrar «${bingoToDelete.title}»? Se borran sus fotos (las que no uses en otro bingo) y no se puede deshacer.`"
      confirm-label="Borrar bingo"
      @confirm="deleteBingo"
      @cancel="bingoToDelete = null"
    />

    <ConfirmDialog
      v-if="confirmOpen"
      ref="dialog"
      message="¿Borrar tu cuenta? Se borran tus bingos y todas tus fotos, y no se puede deshacer."
      confirm-label="Borrar cuenta"
      :confirm-disabled="!confirmTyped || busy"
      @confirm="deleteAccount"
      @cancel="closeDelete"
    >
      <label class="build__confirm">
        <span>Escribe <strong>{{ CONFIRM_WORD }}</strong> para confirmar</span>
        <input
          ref="confirmInput"
          v-model="confirmText"
          class="build__confirm-input"
          data-confirm-focus
          autocomplete="off"
          autocapitalize="characters"
          spellcheck="false"
          @keydown.enter.prevent="confirmTyped && $refs.dialog.accept()"
        />
      </label>
    </ConfirmDialog>
  </div>
</template>

<script>
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { useAuthStore } from '@/store/auth';
import { apiCall } from '@/services/api';

// Lo que exige el backend en `delete_account` (AuthController::DELETE_CONFIRMATION).
const CONFIRM_WORD = 'BORRAR';
// Límite de bingos por usuario (BingoValidator::MAX_BINGOS_PER_USER).
const MAX_BINGOS = 30;

export default {
  name: 'BingoListView',
  components: { ConfirmDialog },
  setup() {
    return { auth: useAuthStore(), CONFIRM_WORD, MAX_BINGOS };
  },
  data() {
    return {
      confirmOpen: false,
      confirmText: '',
      busy: false,
      error: '',
      bingos: [],
      loaded: false,
      newTitle: '',
      bingoToDelete: null
    };
  },
  created() {
    this.load();
  },
  computed: {
    user() {
      return this.auth.user || {};
    },
    // Sin distinguir mayúsculas al teclear, pero al backend va siempre la palabra exacta.
    confirmTyped() {
      return this.confirmText.trim().toUpperCase() === CONFIRM_WORD;
    },
    atLimit() {
      return this.bingos.length >= MAX_BINGOS;
    }
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
    // Envuelve una acción: un botón a la vez y el error del backend en pantalla.
    async run(fn) {
      if (this.busy) return;
      this.busy = true;
      this.error = '';
      try {
        await fn();
      } catch (e) {
        this.error = e.message || 'Algo ha fallado.';
      } finally {
        this.busy = false;
      }
    },
    createBingo() {
      const title = this.newTitle.trim();
      if (!title) return;
      return this.run(async () => {
        const bingo = await apiCall('create_bingo', { title });
        this.newTitle = '';
        this.$router.push(`/construir/${bingo.id}`);
      });
    },
    duplicateBingo(b) {
      return this.run(async () => {
        await apiCall('duplicate_bingo', { bingo_id: b.id });
        this.bingos = await apiCall('list_bingos');
      });
    },
    askDelete(b) {
      this.error = '';
      this.bingoToDelete = b;
    },
    deleteBingo() {
      const b = this.bingoToDelete;
      this.bingoToDelete = null;
      if (!b) return;
      return this.run(async () => {
        await apiCall('delete_bingo', { bingo_id: b.id });
        this.bingos = this.bingos.filter((x) => x.id !== b.id);
      });
    },
    async logout() {
      this.busy = true;
      await this.auth.logout();
      this.busy = false;
      this.$router.push('/');
    },
    openDelete() {
      this.error = '';
      this.confirmText = '';
      this.confirmOpen = true;
      // El diálogo pone el foco en «Cancelar» al montarse; aquí lo pasamos al campo.
      this.$nextTick(() => this.$refs.confirmInput?.focus());
    },
    closeDelete() {
      this.confirmOpen = false;
    },
    // Llega por el @confirm del diálogo (botón o Enter en el campo, que pasa por su accept()):
    // así el diálogo deshace su entrada de historial antes de que naveguemos a la portada.
    async deleteAccount() {
      this.confirmOpen = false;
      if (!this.confirmTyped || this.busy) return;
      this.busy = true;
      try {
        await this.auth.deleteAccount(CONFIRM_WORD);
        this.$router.push('/');
      } catch (e) {
        this.error = e.message || 'No se pudo borrar la cuenta.';
      } finally {
        this.busy = false;
      }
    }
  }
};
</script>

<style scoped lang="scss">
.build {
  display: flex;
  flex-direction: column;
  gap: 1.4rem;
}

.build__user {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.build__avatar {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  border: 3px solid var(--accent-2);
}

.build__who {
  min-width: 0;
}

.build__name {
  margin: 0;
  font-size: clamp(1.5rem, 4vw, 2.2rem);
  font-weight: 900;
}

.build__email {
  margin: 0.2rem 0 0;
  color: var(--ink-dim);
  overflow-wrap: anywhere;
}

.build__soon {
  margin: 0;
  padding: 1.2rem;
  border: 2px dashed color-mix(in srgb, var(--ink-dim) 50%, transparent);
  border-radius: var(--radius);
  color: var(--ink-dim);
  text-align: center;
}

.bingos {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.bingos__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
}

.bingos__title {
  margin: 0;
  font-size: clamp(1.3rem, 3vw, 1.8rem);
  font-weight: 900;
  letter-spacing: 0.02em;
}

.bingos__count {
  color: var(--ink-dim);
  font-variant-numeric: tabular-nums;
}

.bingos__create {
  display: flex;
  flex-wrap: wrap;
  gap: 0.8rem;
}

.bingos__input {
  flex: 1 1 14rem;
  min-width: 0;
  padding: 0.6em 0.9em;
  border: 2px solid #3a3a63;
  border-radius: 12px;
  background: var(--bg-2);
  color: var(--ink);
  font: inherit;
  font-size: 1.1rem;
}

.bingos__input:focus-visible {
  border-color: var(--accent-2);
}

.bingos__create .btn {
  font-size: 1.1rem;
}

.bingos__hint {
  margin: 0;
  color: var(--accent-2);
}

.bingos__list {
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

/* Cada bingo es un «canal»: franja con borde de color y acciones a la derecha. */
.bingo-row {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.8rem 0.9rem;
  border-left: 6px solid var(--image);
  border-radius: 14px;
  background: var(--bg-2);
}

.bingo-row__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  color: var(--ink);
  text-decoration: none;
}

.bingo-row__title {
  font-size: 1.2rem;
  font-weight: 800;
  overflow-wrap: anywhere;
}

.bingo-row__main:hover .bingo-row__title,
.bingo-row__main:focus-visible .bingo-row__title {
  color: var(--accent-2);
}

.bingo-row__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem 0.9rem;
  color: var(--ink-dim);
  font-size: 0.9rem;
}

.bingo-row__off {
  opacity: 0.55;
}

.bingo-row__actions {
  display: flex;
  gap: 0.5rem;
}

.bingo-row__btn {
  font-size: 0.95rem;
  padding: 0.5em 0.8em;
  background: color-mix(in srgb, var(--ink) 12%, var(--panel));
}

.bingo-row__btn.build__danger {
  background: color-mix(in srgb, var(--accent) 35%, var(--panel));
}

@media (max-width: 560px) {
  .bingo-row__label {
    display: none;
  }
}

.build__error {
  padding: 0.6em 1em;
  border-radius: 12px;
  background: color-mix(in srgb, var(--accent) 22%, var(--panel));
  font-weight: 700;
}

.build__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.build__actions .btn {
  background: color-mix(in srgb, var(--ink) 12%, var(--panel));
}

.build__actions .build__danger {
  background: color-mix(in srgb, var(--accent) 35%, var(--panel));
}

.build__confirm {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.6rem;
  font-size: clamp(1rem, 2vw, 1.3rem);
  color: var(--ink-dim);
}

.build__confirm-input {
  width: min(320px, 100%);
  padding: 0.5em 0.8em;
  border: 2px solid var(--ink-dim);
  border-radius: 12px;
  background: var(--bg);
  color: var(--ink);
  font: inherit;
  font-weight: 900;
  letter-spacing: 0.2em;
  text-align: center;
  text-transform: uppercase;
}

.build__confirm-input:focus-visible {
  border-color: var(--accent-2);
}
</style>
