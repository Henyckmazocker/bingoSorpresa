<template>
  <div class="page">
    <main class="page__panel editor">
      <router-link class="editor__back" to="/construir">← Tus bingos</router-link>

      <p v-if="loading" class="editor__empty">Sintonizando…</p>

      <section v-else-if="loadError" class="editor__empty" role="alert">
        <p>{{ loadError }}</p>
        <router-link class="btn btn--primary" to="/construir">Volver a tus bingos</router-link>
      </section>

      <template v-else-if="dto">
        <header class="editor__head">
          <p class="editor__eyebrow">🛠️ Construir</p>
          <h1 class="editor__title">{{ dto.title }}</h1>
        </header>

        <!-- Fijo en todas las pestañas: el aviso importa tanto al tocar tamaños como items. -->
        <p v-if="dto.printsCount" class="editor__printed" role="note">
          🖨️ Ya imprimiste cartones de este bingo; si cambias canciones, fotos o tamaños, los cartones
          impresos no casarán con la partida
        </p>

        <nav class="tabs" role="tablist" aria-label="Secciones del editor">
          <button
            v-for="t in tabs"
            :key="t.key"
            class="tabs__tab"
            :class="{ 'tabs__tab--on': tab === t.key }"
            role="tab"
            :aria-selected="tab === t.key"
            @click="tab = t.key"
          >
            {{ t.label }}
          </button>
        </nav>

        <!-- ============ AJUSTES ============ -->
        <section v-show="tab === 'settings'" class="pane controls" role="tabpanel">
          <div class="controls__row">
            <label class="pane__field pane__field--grow">Título
              <input v-model="settings.title" class="pane__input" maxlength="120" />
            </label>
            <label class="pane__field">Nombre del bingo musical
              <input v-model="settings.modes.music.label" class="pane__input" maxlength="40" />
            </label>
            <label class="pane__field">Nombre del bingo de fotos
              <input v-model="settings.modes.image.label" class="pane__input" maxlength="40" />
            </label>
          </div>

          <ModeSettings :model-value="config" :kinds="EDITOR_KINDS" @update:model-value="onModes" />
          <PrizeOrderHint :config="config" />

          <ul v-if="saveErrors.length" class="pane__errors" role="alert">
            <li v-for="(e, i) in saveErrors" :key="i">{{ e }}</li>
          </ul>

          <div class="pane__actions">
            <button class="btn btn--primary" :disabled="!dirty || saving" @click="save">
              {{ saving ? 'Guardando…' : 'Guardar' }}
            </button>
            <span v-if="dirty" class="pane__status pane__status--warn">Cambios sin guardar</span>
            <span v-else-if="savedOnce" class="pane__status">Guardado ✓</span>
          </div>

          <div class="share">
            <h2 class="share__title">🔗 Enlace compartido</h2>
            <p class="share__hint">
              Quien tenga el enlace puede cantar e imprimir este bingo sin cuenta. Al desactivarlo y
              volver a activarlo, el enlace cambia y el viejo deja de funcionar.
            </p>
            <label class="controls__check share__toggle">
              <input type="checkbox" :checked="dto.shared" :disabled="sharing" @change="setShare($event.target.checked)" />
              {{ dto.shared ? 'Activado' : 'Desactivado' }}
            </label>
            <div v-if="dto.shared && shareLink" class="share__link">
              <input class="pane__input share__url" :value="shareLink" readonly @focus="$event.target.select()" />
              <button class="btn share__copy" @click="copyShare">{{ copied ? 'Copiado ✓' : 'Copiar' }}</button>
            </div>
          </div>
        </section>

        <!-- ============ CANCIONES ============ -->
        <section v-show="tab === 'songs'" class="pane" role="tabpanel">
          <div class="paste">
            <label class="paste__field">Enlaces de YouTube, uno por línea
              <textarea
                v-model="urlsText"
                class="pane__input paste__text"
                rows="5"
                spellcheck="false"
                placeholder="https://www.youtube.com/watch?v=…&#10;https://youtu.be/…"
                :disabled="adding"
              ></textarea>
            </label>
            <div class="pane__actions">
              <button class="btn btn--primary" :disabled="adding || !pastedUrls.length" @click="addSongs(pastedUrls)">
                {{ adding ? 'Buscando en YouTube…' : '＋ Añadir' }}
              </button>
              <span class="upload__hint">YouTube, YouTube Music, youtu.be o Shorts · el título y el artista salen solos</span>
            </div>
          </div>

          <p v-if="songsAdded" class="progress__text">
            ✓ {{ songsAdded }} {{ songsAdded === 1 ? 'canción añadida' : 'canciones añadidas' }}
          </p>
          <div v-if="songFailures.length" class="pane__errors song-failures" role="alert">
            <ul class="song-failures__list">
              <li v-for="(f, i) in songFailures" :key="i">
                <strong class="song-failures__url">{{ f.url || '(vacío)' }}</strong>: {{ failureMessage(f.reason) }}
              </li>
            </ul>
            <button v-if="retryUrls.length" class="btn song-failures__retry" :disabled="adding" @click="addSongs(retryUrls)">
              ↻ Reintentar {{ retryUrls.length === 1 ? 'la que falló' : `las ${retryUrls.length} que fallaron` }}
            </button>
          </div>
          <p v-if="songError" class="pane__errors" role="alert">{{ songError }}</p>

          <p v-if="!songs.length" class="editor__empty">🎵 Aún no hay canciones. Pega enlaces de YouTube: cada canción será una casilla del cartón.</p>
          <ul v-else class="songs">
            <li v-for="item in songs" :key="item.id" class="song">
              <img class="song__thumb" :src="item.thumbUrl" alt="" loading="lazy" />
              <div class="song__fields">
                <input
                  class="photo__label song__title"
                  :value="item.label"
                  maxlength="120"
                  aria-label="Título de la canción"
                  @change="saveSong(item, 'label', $event)"
                  @keydown.enter="$event.target.blur()"
                />
                <input
                  class="photo__label song__artist"
                  :value="item.sublabel || ''"
                  maxlength="120"
                  placeholder="Artista"
                  aria-label="Artista"
                  @change="saveSong(item, 'sublabel', $event)"
                  @keydown.enter="$event.target.blur()"
                />
              </div>
              <label class="song__start" title="Para saltar la intro: 90 o 1:30">Empieza en
                <input
                  class="photo__label song__start-input"
                  :value="formatStart(item.startSeconds)"
                  placeholder="0:00"
                  inputmode="numeric"
                  @change="saveSong(item, 'start', $event)"
                  @keydown.enter="$event.target.blur()"
                />
              </label>
              <button class="photo__delete song__delete" title="Quitar canción" aria-label="Quitar canción" @click="itemToDelete = item">✕</button>
            </li>
          </ul>

          <RehearsalPanel v-if="songs.length" :items="songItems" />
        </section>

        <!-- ============ FOTOS ============ -->
        <section v-show="tab === 'photos'" class="pane" role="tabpanel">
          <div class="quota" :title="`${usedLabel} de ${quotaLabel}`">
            <div class="quota__bar"><div class="quota__fill" :style="{ width: quotaPercent + '%' }"></div></div>
            <span class="quota__text">{{ usedLabel }} de {{ quotaLabel }}</span>
          </div>

          <div class="upload">
            <label class="btn btn--primary upload__btn" :class="{ 'upload__btn--off': uploading }">
              ＋ Subir fotos
              <input
                ref="fileInput"
                class="sr-only"
                type="file"
                multiple
                accept="image/jpeg,image/png,image/webp,image/gif"
                :disabled="uploading"
                @change="onFiles"
              />
            </label>
            <span class="upload__hint">JPEG, PNG, WebP o GIF · hasta 10 MB cada una</span>
          </div>

          <div v-if="uploading" class="progress" aria-live="polite">
            <p class="progress__text">
              Subiendo {{ upload.index + 1 }} de {{ upload.total }} · <span class="progress__name">{{ upload.name }}</span>
            </p>
            <div class="quota__bar"><div class="progress__fill" :style="{ width: uploadPercent + '%' }"></div></div>
          </div>
          <p v-else-if="upload.done" class="progress__text">
            ✓ {{ upload.done }} {{ upload.done === 1 ? 'foto subida' : 'fotos subidas' }}<span v-if="upload.deduplicated">
              ({{ upload.deduplicated }} ya las tenías: no ocupan más)</span>
          </p>

          <ul v-if="failures.length" class="pane__errors" role="alert">
            <li v-for="(f, i) in failures" :key="i"><strong>{{ f.name }}</strong>: {{ f.message }}</li>
          </ul>
          <p v-if="itemError" class="pane__errors" role="alert">{{ itemError }}</p>

          <p v-if="!photos.length" class="editor__empty">📷 Aún no hay fotos. Sube unas cuantas: cada una será una casilla del cartón.</p>
          <ul v-else class="grid">
            <li v-for="item in photos" :key="item.id" class="photo">
              <div class="photo__frame">
                <img class="photo__img" :src="resolveUrl(item.thumbUrl)" :alt="item.label" loading="lazy" />
              </div>
              <input
                class="photo__label"
                :value="item.label"
                maxlength="120"
                aria-label="Texto de la foto"
                @change="renameItem(item, $event)"
                @keydown.enter="$event.target.blur()"
              />
              <button class="photo__delete" title="Quitar foto" aria-label="Quitar foto" @click="itemToDelete = item">✕</button>
            </li>
          </ul>
        </section>
      </template>
    </main>

    <ConfirmDialog
      v-if="itemToDelete"
      :message="`¿Quitar «${itemToDelete.label}» de este bingo?`"
      confirm-label="Quitar"
      @confirm="deleteItem"
      @cancel="itemToDelete = null"
    />
  </div>
</template>

<script>
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ModeSettings from '@/components/ModeSettings.vue';
import PrizeOrderHint from '@/components/PrizeOrderHint.vue';
import RehearsalPanel from '@/components/RehearsalPanel.vue';
import { apiCall, apiUpload, resolveUrl } from '@/services/api';
import {
  EDITOR_KINDS,
  settingsFromDto,
  buildConfig,
  settingsFromConfig,
  settingsPatch,
  settingsDirty,
  formatMegabytes,
  itemToBingoItem,
  parseUrlList,
  chunk,
  musicFailureMessage,
  isRetryable,
  formatStart,
  parseStart
} from '@/bingo/bingoDto';

// Igual que ImageProcessor::MAX_BYTES: lo que pase de aquí ni se intenta subir.
const MAX_FILE_BYTES = 10 * 1024 * 1024;

export default {
  name: 'BingoEditorView',
  components: { ConfirmDialog, ModeSettings, PrizeOrderHint, RehearsalPanel },
  props: {
    id: { type: String, required: true } // de la ruta /construir/:id
  },
  setup() {
    return { EDITOR_KINDS, resolveUrl, formatStart, failureMessage: musicFailureMessage };
  },
  data() {
    return {
      dto: null, // BingoDTO tal cual lo guardó el backend
      settings: null, // título y modos, guardados o no
      loading: true,
      loadError: '',
      tab: 'settings',
      saving: false,
      savedOnce: false,
      saveErrors: [],
      sharing: false,
      copied: false,
      uploading: false,
      upload: { index: 0, total: 0, name: '', progress: 0, done: 0, deduplicated: 0 },
      failures: [],
      itemError: '',
      itemToDelete: null,
      urlsText: '', // textarea de «Añadir»
      adding: false,
      songsAdded: 0,
      songFailures: [], // [{url, reason}] de la última tanda
      songError: ''
    };
  },
  computed: {
    // La config que ven ModeSettings y PrizeOrderHint: items guardados + ajustes sin guardar.
    config() {
      return buildConfig(this.dto, this.settings);
    },
    dirty() {
      return settingsDirty(this.dto, this.settings);
    },
    photos() {
      return this.dto.items.filter((i) => i.kind === 'image');
    },
    songs() {
      return this.dto.items.filter((i) => i.kind === 'music');
    },
    // Forma del juego (con videoId/startSeconds): lo que ensaya RehearsalPanel.
    songItems() {
      return this.songs.map(itemToBingoItem);
    },
    pastedUrls() {
      return parseUrlList(this.urlsText);
    },
    // Las que fallaron por YouTube (no por el enlace): se pueden reintentar tal cual.
    retryUrls() {
      return this.songFailures.filter((f) => isRetryable(f.reason)).map((f) => f.url);
    },
    tabs() {
      return [
        { key: 'settings', label: 'Ajustes' },
        { key: 'songs', label: `Canciones (${this.songs.length})` },
        { key: 'photos', label: `Fotos (${this.photos.length})` }
      ];
    },
    shareLink() {
      if (!this.dto.shareUrl) return '';
      return `${window.location.origin}${window.location.pathname}${this.dto.shareUrl.replace(/^\//, '')}`;
    },
    usedLabel() {
      return formatMegabytes(this.dto.storageBytes);
    },
    quotaLabel() {
      return formatMegabytes(this.dto.storageQuota);
    },
    quotaPercent() {
      return Math.min(100, (100 * this.dto.storageBytes) / (this.dto.storageQuota || 1));
    },
    uploadPercent() {
      return Math.round(this.upload.progress * 100);
    }
  },
  watch: {
    id: 'load'
  },
  created() {
    this.load();
  },
  methods: {
    /** El backend no sabe de qué tipo era un item que ya no existe (404); el editor sí. */
    itemErrorMessage(e, item) {
      if (e.status === 404) return item.kind === 'music' ? 'Esta canción ya no existe.' : 'Esta foto ya no existe.';
      return e.message;
    },
    async load() {
      this.loading = true;
      this.loadError = '';
      try {
        this.setDto(await apiCall('get_bingo', { bingo_id: Number(this.id) }));
      } catch (e) {
        this.loadError = e.status === 404 ? 'Este bingo ya no existe.' : e.message || 'No se pudo cargar el bingo.';
      } finally {
        this.loading = false;
      }
    },
    setDto(dto) {
      this.dto = dto;
      this.settings = settingsFromDto(dto);
    },

    // --- Ajustes ---
    onModes(config) {
      this.settings = settingsFromConfig(config, this.settings.title);
    },
    async save() {
      if (this.saving) return;
      this.saving = true;
      this.saveErrors = [];
      try {
        const dto = await apiCall('update_bingo', { bingo_id: this.dto.id, ...settingsPatch(this.settings) });
        this.setDto(dto);
        this.savedOnce = true;
      } catch (e) {
        const errors = e.response?.data?.errors;
        this.saveErrors = Array.isArray(errors) && errors.length ? errors : [e.message];
      } finally {
        this.saving = false;
      }
    },
    async setShare(enabled) {
      this.sharing = true;
      this.saveErrors = [];
      try {
        const { shareUrl } = await apiCall('set_share', { bingo_id: this.dto.id, enabled });
        this.dto = { ...this.dto, shared: !!shareUrl, shareUrl };
        this.copied = false;
      } catch (e) {
        this.saveErrors = [e.message];
      } finally {
        this.sharing = false;
      }
    },
    async copyShare() {
      try {
        await navigator.clipboard.writeText(this.shareLink);
        this.copied = true;
      } catch (e) {
        // Sin portapapeles (http, permisos): el campo ya está seleccionable a mano.
        this.copied = false;
      }
    },

    // --- Fotos ---
    async onFiles(event) {
      const files = Array.from(event.target.files || []);
      event.target.value = ''; // volver a elegir el mismo fichero también dispara change
      if (!files.length || this.uploading) return;

      this.failures = [];
      this.uploading = true;
      this.upload = { index: 0, total: files.length, name: '', progress: 0, done: 0, deduplicated: 0 };
      // En serie: una petición cada vez (menos memoria en el servidor y progreso legible).
      for (let i = 0; i < files.length; i++) {
        const file = files[i];
        this.upload.index = i;
        this.upload.name = file.name;
        this.upload.progress = 0;
        if (file.size > MAX_FILE_BYTES) {
          this.failures.push({ name: file.name, message: 'pesa más de 10 MB.' });
          continue;
        }
        try {
          const data = await apiUpload('upload_image', { bingo_id: this.dto.id }, file, (p) => {
            this.upload.progress = p;
          });
          this.dto.items.push(data.item);
          this.dto.storageBytes = data.storageBytes;
          this.upload.done++;
          if (data.deduplicated) this.upload.deduplicated++;
        } catch (e) {
          this.failures.push({ name: file.name, message: e.message });
          // Sin cuota o sin sesión, las siguientes fallarían igual.
          if (e.status === 507 || e.status === 401 || e.status === 403) break;
        }
      }
      this.uploading = false;
    },
    async renameItem(item, event) {
      const label = event.target.value.trim();
      if (!label || label === item.label) {
        event.target.value = item.label;
        return;
      }
      this.itemError = '';
      try {
        const updated = await apiCall('update_item', { item_id: item.id, label });
        Object.assign(item, updated);
      } catch (e) {
        event.target.value = item.label;
        this.itemError = this.itemErrorMessage(e, item);
      }
    },
    async deleteItem() {
      const item = this.itemToDelete;
      this.itemToDelete = null;
      if (!item) return;
      // Cada pestaña enseña su propio error.
      const errorKey = item.kind === 'music' ? 'songError' : 'itemError';
      this[errorKey] = '';
      try {
        const { storageBytes } = await apiCall('delete_item', { item_id: item.id });
        this.dto.items = this.dto.items.filter((i) => i.id !== item.id);
        this.dto.storageBytes = storageBytes;
      } catch (e) {
        this[errorKey] = this.itemErrorMessage(e, item);
      }
    },

    // --- Canciones ---
    async addSongs(urls) {
      if (this.adding || !urls.length) return;
      urls = [...urls]; // `retryUrls` es un computed que cambia en cuanto se tocan los fallos
      this.adding = true;
      this.songsAdded = 0;
      this.songFailures = [];
      this.songError = '';
      // Tandas de 50 (el máximo por petición), en serie: cada enlace es una consulta a YouTube.
      let sent = 0;
      for (const batch of chunk(urls)) {
        try {
          const { added, failed } = await apiCall('add_music_items', { bingo_id: this.dto.id, urls: batch });
          this.dto.items.push(...added);
          this.songsAdded += added.length;
          this.songFailures.push(...failed);
          sent += batch.length;
        } catch (e) {
          // 409: el lote no cabe en las 300 canciones del bingo (se rechaza entero). 429, 401…:
          // las siguientes tandas fallarían igual.
          this.songError = e.message;
          break;
        }
      }
      // En el textarea se quedan las que fallaron (para corregirlas) y las que no se llegaron a mandar.
      this.urlsText = [...this.songFailures.map((f) => f.url), ...urls.slice(sent)].join('\n');
      this.adding = false;
    },
    /** Guarda un campo de una canción. `update_item` pide título, artista e inicio juntos. */
    async saveSong(item, field, event) {
      const input = event.target;
      const revert = () => {
        input.value = field === 'start' ? formatStart(item.startSeconds) : (item[field] ?? '');
      };
      const value = input.value.trim();
      const next = { label: item.label, sublabel: item.sublabel ?? null, startSeconds: item.startSeconds ?? null };
      if (field === 'label') {
        if (!value) return revert(); // el título es obligatorio
        next.label = value;
      } else if (field === 'sublabel') {
        next.sublabel = value || null;
      } else {
        const seconds = parseStart(value);
        if (seconds === undefined) {
          revert();
          this.songError = 'El inicio se escribe en segundos (90) o en minutos y segundos (1:30).';
          return;
        }
        next.startSeconds = seconds;
      }
      if (next.label === item.label && next.sublabel === (item.sublabel ?? null) && next.startSeconds === (item.startSeconds ?? null)) {
        return revert(); // nada que guardar (p. ej. «90» en un campo que ya decía «1:30»)
      }
      this.songError = '';
      try {
        const updated = await apiCall('update_item', {
          item_id: item.id,
          label: next.label,
          sublabel: next.sublabel ?? '',
          start_seconds: next.startSeconds
        });
        Object.assign(item, updated);
        revert(); // normaliza lo escrito («90» → «1:30»)
      } catch (e) {
        revert();
        this.songError = this.itemErrorMessage(e, item);
      }
    }
  }
};
</script>

<style scoped lang="scss">
.editor {
  width: min(1100px, 100%);
  display: flex;
  flex-direction: column;
  gap: 1.2rem;
}

.editor__back {
  align-self: flex-start;
  color: var(--accent-2);
  font-weight: 700;
}

.editor__empty {
  margin: 0;
  padding: 1.2rem;
  border: 2px dashed color-mix(in srgb, var(--ink-dim) 50%, transparent);
  border-radius: var(--radius);
  color: var(--ink-dim);
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}

.editor__eyebrow {
  margin: 0;
  color: var(--accent-2);
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  font-size: 0.85rem;
}

.editor__title {
  margin: 0.2rem 0 0;
  font-size: clamp(1.6rem, 4vw, 2.4rem);
  font-weight: 900;
  overflow-wrap: anywhere;
}

/* Aviso fijo de tiradas impresas: franja dorada, como un rótulo de «última hora». */
.editor__printed {
  position: sticky;
  top: 0;
  z-index: 5;
  margin: 0;
  padding: 0.8em 1em;
  border-radius: 12px;
  background: color-mix(in srgb, var(--accent-2) 22%, var(--panel));
  border-left: 6px solid var(--accent-2);
  font-weight: 700;
}

/* --- Pestañas: botones de canal --- */
.tabs {
  display: flex;
  gap: 0.5rem;
  border-bottom: 3px solid var(--bg-2);
}

.tabs__tab {
  padding: 0.6em 1.2em;
  border-radius: 12px 12px 0 0;
  background: transparent;
  color: var(--ink-dim);
  font-size: 1.1rem;
  font-weight: 800;
}

.tabs__tab--on {
  background: var(--bg-2);
  color: var(--ink);
  box-shadow: inset 0 3px 0 var(--accent);
}

.pane {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

/* Mismas reglas que PrintView para que ModeSettings y PrizeOrderHint se vean igual. */
.controls :deep(.controls__row) {
  display: flex;
  gap: 1rem;
  align-items: center;
  flex-wrap: wrap;
  margin-top: 1rem;
}
.controls :deep(label) {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: 0.9rem;
}
.controls :deep(.controls__check) {
  flex-direction: row !important;
  align-items: center;
  gap: 0.5rem;
}
.controls :deep(.controls__warn) {
  margin-top: 1rem;
  color: var(--accent-2);
}

.pane__field {
  min-width: 12rem;
}

.pane__field--grow {
  flex: 1 1 18rem;
}

.pane__input {
  padding: 0.5rem 0.7rem;
  border-radius: 10px;
  border: 1px solid #3a3a63;
  background: var(--bg-2);
  color: var(--ink);
  font: inherit;
  font-size: 1rem;
}

.pane__errors {
  margin: 0;
  padding: 0.6em 1em 0.6em 2em;
  border-radius: 12px;
  background: color-mix(in srgb, var(--accent) 18%, var(--panel));
  color: #ffd6dd;
}

p.pane__errors {
  padding-left: 1em;
}

.pane__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
}

.pane__status {
  color: var(--image);
  font-weight: 700;
}

.pane__status--warn {
  color: var(--accent-2);
}

/* --- Enlace compartido --- */
.share {
  margin-top: 0.5rem;
  padding: 1rem;
  border-radius: 14px;
  background: var(--bg-2);
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.share__title {
  margin: 0;
  font-size: 1.2rem;
}

.share__hint {
  margin: 0;
  color: var(--ink-dim);
  font-size: 0.9rem;
  line-height: 1.5;
}

.share__toggle {
  font-size: 1rem !important;
  font-weight: 700;
}

.share__link {
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.share__url {
  flex: 1 1 16rem;
  min-width: 0;
  font-family: ui-monospace, monospace;
  font-size: 0.85rem;
}

.share__copy {
  font-size: 1rem;
  padding: 0.5em 1em;
  background: color-mix(in srgb, var(--ink) 12%, var(--panel));
}

/* --- Cuota y progreso: barras de «volumen» de tele --- */
.quota {
  display: flex;
  align-items: center;
  gap: 0.8rem;
}

.quota__bar {
  flex: 1;
  height: 12px;
  border-radius: 6px;
  background: var(--bg-2);
  overflow: hidden;
}

.quota__fill,
.progress__fill {
  height: 100%;
  background: linear-gradient(90deg, var(--image), var(--accent-2));
  transition: width 0.2s ease;
}

.progress__fill {
  background: linear-gradient(90deg, var(--accent), var(--accent-2));
}

.quota__text {
  color: var(--ink-dim);
  font-size: 0.9rem;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.upload {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
}

.upload__btn {
  position: relative;
  font-size: 1.1rem;
}

.upload__btn--off {
  opacity: 0.4;
  pointer-events: none;
}

/* El input va oculto dentro del label: el foco de teclado se ve en el botón. */
.upload__btn:focus-within {
  outline: 4px solid var(--focus);
  outline-offset: 3px;
}

.upload__hint {
  color: var(--ink-dim);
  font-size: 0.9rem;
}

.progress {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.progress__text {
  margin: 0;
  color: var(--ink-dim);
}

.progress__name {
  color: var(--ink);
  overflow-wrap: anywhere;
}

/* --- Rejilla de miniaturas: cada foto, una «pantalla» --- */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 0.9rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.photo {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.5rem;
  border-radius: 14px;
  background: var(--bg-2);
}

.photo__frame {
  aspect-ratio: 4 / 3;
  border-radius: 10px;
  overflow: hidden;
  background: #000;
  box-shadow: inset 0 0 0 3px #2a2a4a;
}

.photo__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.photo__label {
  width: 100%;
  min-width: 0;
  padding: 0.35rem 0.5rem;
  border: 1px solid transparent;
  border-radius: 8px;
  background: transparent;
  color: var(--ink);
  font: inherit;
  font-size: 0.9rem;
}

.photo__label:hover,
.photo__label:focus {
  border-color: #3a3a63;
  background: var(--panel);
}

.photo__delete {
  position: absolute;
  top: 0.8rem;
  right: 0.8rem;
  width: 2rem;
  height: 2rem;
  border-radius: 50%;
  background: rgba(5, 5, 15, 0.75);
  font-weight: 900;
}

.photo__delete:hover,
.photo__delete:focus-visible {
  background: var(--accent);
}

/* --- Canciones: pegar enlaces y la lista, cada una una «ficha» con su carátula --- */
.paste {
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
}

.paste__field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: 0.9rem;
}

.paste__text {
  resize: vertical;
  min-height: 7rem;
  font-family: ui-monospace, monospace;
  font-size: 0.85rem;
}

.song-failures {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.6rem;
  padding-left: 1em;
}

.song-failures__list {
  margin: 0;
  padding-left: 1em;
}

.song-failures__url {
  overflow-wrap: anywhere;
}

.song-failures__retry {
  font-size: 1rem;
  padding: 0.4em 1em;
  background: color-mix(in srgb, var(--ink) 12%, var(--panel));
}

.songs {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.song {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.8rem;
  padding: 0.5rem;
  border-radius: 14px;
  background: var(--bg-2);
}

.song__thumb {
  flex: none;
  width: 96px;
  aspect-ratio: 16 / 9;
  object-fit: cover;
  border-radius: 8px;
  background: #000;
  box-shadow: inset 0 0 0 3px #2a2a4a;
}

.song__fields {
  flex: 1 1 14rem;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.song__title {
  font-weight: 700;
}

.song__artist {
  color: var(--ink-dim);
}

.song__start {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--ink-dim);
  font-size: 0.85rem;
  white-space: nowrap;
}

.song__start-input {
  width: 4.5rem;
  font-variant-numeric: tabular-nums;
}

/* Mismo ✕ redondo que las fotos, pero en la fila en vez de flotando sobre la imagen. */
.song__delete {
  position: static;
  flex: none;
}
</style>
