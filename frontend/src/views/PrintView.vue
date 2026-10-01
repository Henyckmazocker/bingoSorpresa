<template>
  <!-- Con `:id` (o `/b/:token`) el bingo llega del backend (bingo/apiBingo.js) y cada «Imprimir»
       guarda la tirada (`save_print`); con `?print=<id>` se reimprime el snapshot de una tirada.
       Sin ellos, la config del store (la APK). -->
  <BingoGone
    v-if="loadError"
    :title="loadError.title"
    :detail="loadError.detail"
    :back-to="backTo"
    :back-label="backLabel"
  />
  <div v-else-if="loading" class="page tuning">
    <p class="tuning__text">📡 Sintonizando…</p>
  </div>
  <div v-else-if="workingConfig" class="print">
    <!-- Panel de control (solo en pantalla, no se imprime) -->
    <div class="controls no-print">
      <h1>🖨️ Generador de cartones</h1>
      <p v-if="remote" class="controls__bingo">«{{ workingConfig.title }}»</p>

      <!-- Reimpresión: los items, la semilla y los jugadores son los de la tirada, no los de hoy. -->
      <div v-if="reprint" class="controls__reprint">
        <strong>Reimprimiendo la tirada #{{ reprint.n }}</strong> — semilla «{{ reprint.seed }}»,
        {{ reprint.players }} {{ reprint.players === 1 ? 'jugador' : 'jugadores' }}. Salen los mismos
        cartones que entonces, aunque luego hayas cambiado o borrado fotos.
        <router-link :to="`/imprimir/${id}`">Hacer una tirada nueva</router-link>
      </div>
      <!-- El texto de ayuda depende de la disposición (bingo/printLayout.js). El de `fold` es el de
           la fiesta de casa; solo cambia la frase final si el reverso lleva un único cartón. -->
      <template v-if="layout === 'fold'">
        <p class="controls__hint">
          Un <strong>A4 vertical por jugador</strong>, impreso <strong>a doble cara</strong>: cada
          jugador ocupa dos páginas seguidas del PDF, la de números y la de sorpresas, que caen en las
          dos caras de la misma hoja. Los números van en la banda central: doblando hacia atrás las
          solapas de arriba y abajo por las líneas de puntos, el reverso entero queda en sándwich y el
          cartón se queda en 210 × 105 mm.
          <template v-if="surpriseKinds.length === 2">Al desdoblar aparecen los cartones musical y de recuerdos,
          uno encima del otro a página completa.</template>
          <template v-else>Al desdoblar aparece el cartón {{ SURPRISE_NOUN[surpriseKinds[0]] }} a página
          completa.</template>
        </p>
        <p class="controls__hint">
          En el diálogo de impresión: marca <strong>doble cara</strong>, pon márgenes en
          <strong>Ninguno</strong> y <strong>desmarca «Encabezados y pies de página»</strong> — si no,
          el navegador estampa la fecha, la URL y el título en los bordes, y eso no hay CSS que lo
          quite. Si el reverso te sale del revés, cambia el volteo de borde largo a borde corto.
          La <em>semilla</em> hace los cartones reproducibles: con la misma semilla y el mismo número
          de jugador sale el mismo cartón, para reimprimir uno perdido sin rehacer los demás.
        </p>
      </template>
      <template v-else-if="layout === 'numeric-only'">
        <p class="controls__hint">
          Un <strong>A4 vertical por jugador</strong>, a una cara: una página del PDF por jugador, con
          los números en la banda central. Doblando hacia atrás las solapas de arriba y abajo por las
          líneas de puntos, el cartón se queda en 210 × 105 mm.
        </p>
        <p class="controls__hint">
          En el diálogo de impresión: pon márgenes en <strong>Ninguno</strong> (las líneas de plegado
          se miden contra el papel) y <strong>desmarca «Encabezados y pies de página»</strong> — si no,
          el navegador estampa la fecha, la URL y el título en los bordes, y eso no hay CSS que lo
          quite. La <em>semilla</em> hace los cartones reproducibles: con la misma semilla y el mismo
          número de jugador sale el mismo cartón, para reimprimir uno perdido sin rehacer los demás.
        </p>
      </template>
      <template v-else>
        <p class="controls__hint">
          Un <strong>A4 vertical por jugador</strong>, a una cara y sin doblar: una página del PDF por
          jugador<template v-if="surpriseKinds.length === 2">, con los cartones musical y de recuerdos
          uno encima del otro</template><template v-else>, con el cartón {{ SURPRISE_NOUN[surpriseKinds[0]] }}
          a página completa</template>.
        </p>
        <p class="controls__hint">
          En el diálogo de impresión vale tanto márgenes <strong>Ninguno</strong> como
          <strong>Predeterminados</strong>; <strong>desmarca «Encabezados y pies de página»</strong> — si
          no, el navegador estampa la fecha, la URL y el título en los bordes, y eso no hay CSS que lo
          quite. La <em>semilla</em> hace los cartones reproducibles: con la misma semilla y el mismo
          número de jugador sale el mismo cartón, para reimprimir uno perdido sin rehacer los demás.
        </p>
      </template>

      <!-- Copia de trabajo de la config: se pierde al recargar. Solo sin backend: un bingo del
           backoffice se imprime TAL CUAL está guardado (es lo que congela `save_print`); sus modos y
           tamaños se cambian en «Construir». -->
      <ModeSettings v-if="!remote" v-model="workingConfig" />
      <template v-else>
        <p class="controls__hint">
          Modos y tamaños de cartón: los del bingo guardado.
          <router-link v-if="id && !reprint" :to="`/construir/${id}`">Cambiarlos en Construir</router-link>
        </p>
        <ul v-if="validationErrors.length" class="controls__errors">
          <li v-for="e in validationErrors" :key="e" class="controls__warn">{{ e }}</li>
        </ul>
      </template>
      <PrizeOrderHint :config="workingConfig" />

      <div class="controls__row">
        <label>Semilla
          <input v-model="seed" type="text" maxlength="64" :disabled="!!reprint" />
        </label>
        <label>Nº jugadores
          <input v-model.number="count" type="number" min="1" max="60" :disabled="!!reprint" />
        </label>
      </div>

      <div class="controls__row">
        <button class="btn btn--primary" :disabled="!isValid || !!reprint" @click="generate">Generar</button>
        <button class="btn" :disabled="!hasCards || busy" @click="printNow">
          {{ busy ? 'Guardando…' : 'Imprimir / PDF' }}
        </button>
      </div>
      <p v-if="error" class="controls__warn" role="alert">{{ error }}</p>

      <!-- Tiradas anteriores (solo con cuenta: list_prints pide ser el dueño). -->
      <section v-if="id && prints.length" class="prints">
        <h2 class="prints__title">Tiradas anteriores</h2>
        <ul class="prints__list">
          <li v-for="(p, i) in printsNewestFirst" :key="p.printId" class="prints__row">
            <span class="prints__meta">
              #{{ p.n }} · «{{ p.seed }}» · {{ p.players }} {{ p.players === 1 ? 'jugador' : 'jugadores' }} ·
              {{ formatDate(p.createdAt) }}
            </span>
            <button
              class="btn prints__btn"
              :disabled="reprint && reprint.printId === p.printId"
              @click="openPrint(p)"
            >
              Reimprimir tirada #{{ p.n }}
            </button>
            <span v-if="i === 0 && savedPrintId === p.printId" class="prints__new">recién guardada</span>
          </li>
        </ul>
      </section>

      <router-link class="controls__back" :to="backTo">{{ backLabel }}</router-link>
    </div>

    <!-- La maqueta sale de printLayout(workingConfig):
         - fold: dos páginas por jugador, la cara de números y la de sorpresas. Van seguidas para que
           al imprimir a doble cara caigan en las dos caras de la misma hoja. Los números viven en la
           banda central; arriba y abajo quedan las solapas que se doblan hacia atrás.
         - numeric-only: solo la cara de números.
         - surprises-only: una hoja a una cara con los cartones sorpresa apilados, sin solapas.
         Solo se pintan los cartones de los modos activos (los apagados son `null`). -->
    <div v-if="hasCards" class="sheets">
      <template v-for="(p, i) in players" :key="i">
        <section v-if="layout !== 'surprises-only'" class="sheet sheet--front">
          <div class="sheet__flap"></div>
          <div class="sheet__band">
            <BingoCard mode="numeric" :data="p.numeric" :title="modes.numeric.label" :card-number="i + 1" />
          </div>
          <div class="sheet__flap"></div>
        </section>

        <!-- El reverso usa la página entera: al doblar las solapas de la cara de números, sus
             dorsos quedan mirando contra la banda, así que todo el reverso queda en sándwich.
             `sheet--single` reutiliza sus reglas, con margen de seguridad para imprimir sin doblar. -->
        <section
          v-if="layout !== 'numeric-only'"
          class="sheet"
          :class="layout === 'fold' ? 'sheet--back' : 'sheet--single'"
        >
          <BingoCard
            v-for="kind in surpriseKinds"
            :key="kind"
            :class="{ 'card--house-font': houseFont[kind] }"
            :mode="kind"
            :data="p[kind]"
            :cols="modes[kind].card[1]"
            :title="modes[kind].label"
            :card-number="i + 1"
          />
        </section>
      </template>
    </div>
  </div>
  <!-- Web sin bingo (no debería pasar: el router web no tiene /imprimir sin id). -->
  <BingoGone v-else-if="!isMobile" title="No hay ningún bingo cargado" />
</template>

<script>
import { toRaw } from 'vue';
import BingoCard from '@/components/BingoCard.vue';
import ModeSettings from '@/components/ModeSettings.vue';
import PrizeOrderHint from '@/components/PrizeOrderHint.vue';
import BingoGone from '@/components/BingoGone.vue';
import {
  loadApiBingo,
  loadSharedBingo,
  loadPrint,
  listPrints,
  savePrint,
  isGone
} from '@/bingo/apiBingo';
import { useGameStore } from '@/store/game';
import { makePlayerCards } from '@/bingo/cards';
import { validateConfig } from '@/bingo/validateConfig';
import { printLayout, activeSurprises, usesHouseFont } from '@/bingo/printLayout';

// Cómo se nombra cada cartón sorpresa en el texto de ayuda (el de casa dice «musical y de recuerdos»).
const SURPRISE_NOUN = { music: 'musical', image: 'de recuerdos' };

// Tope del «Nº jugadores» (el mismo que valida `save_print`: PlayController::MAX_PLAYERS).
const MAX_PLAYERS = 60;

// Copia de trabajo de la config del store. `structuredClone` sobre el proxy de Pinia lanza
// `DataCloneError`: hay que pasar antes por `toRaw` (loadBingo guarda un objeto plano, así que
// dentro no quedan proxies; comprobado contra el store real).
function cloneConfig(config) {
  return structuredClone(toRaw(config));
}

export default {
  name: 'PrintView',
  components: { BingoCard, ModeSettings, PrizeOrderHint, BingoGone },
  props: {
    // `/imprimir/:id` (bingo propio) o `/b/:token/imprimir` (enlace compartido). Sin ninguno, el store.
    id: { type: String, default: null },
    token: { type: String, default: null }
  },
  setup() {
    return { store: useGameStore(), SURPRISE_NOUN, isMobile: process.env.VUE_APP_MODE === 'mobile' };
  },
  data() {
    return {
      seed: 'cumple-2026',
      count: 8,
      players: null,
      // Config que editan ModeSettings y que usan generate() y la maqueta (se rellena en created).
      workingConfig: null,
      // --- Con backend ---
      loading: false,
      loadError: null, // { title, detail } → pantalla completa de BingoGone
      prints: [], // list_prints, de la más antigua a la más reciente
      reprint: null, // { printId, n, seed, players } con ?print=<id>
      generated: null, // { seed, players } de los cartones en pantalla: es lo que guarda save_print
      savedPrintId: null, // tirada ya guardada para estos cartones (no se guarda dos veces)
      busy: false,
      error: ''
    };
  },
  computed: {
    remote() {
      return !!(this.id || this.token);
    },
    printParam() {
      return this.id ? this.$route.query.print || null : null;
    },
    backTo() {
      if (this.token) return `/b/${this.token}`;
      if (this.id) return '/jugar';
      return '/';
    },
    backLabel() {
      if (this.token) return '← Volver al enlace';
      if (this.id) return '← Volver a Jugar';
      return '← Volver al cantor';
    },
    // Numeradas por orden de impresión (#1 = la primera) y enseñadas de la más reciente a la más vieja.
    printsNewestFirst() {
      return this.prints.map((p, i) => ({ ...p, n: i + 1 })).reverse();
    },
    validationErrors() {
      return validateConfig(this.workingConfig).errors;
    },
    modes() {
      return this.workingConfig.modes;
    },
    layout() {
      return printLayout(this.workingConfig);
    },
    // Cartones sorpresa activos, en el orden en que se apilan.
    surpriseKinds() {
      return activeSurprises(this.workingConfig);
    },
    // Los cartones con las medidas de casa conservan su letra fija; el resto usa `--cell-font`.
    houseFont() {
      return {
        music: usesHouseFont('music', this.modes.music.card),
        image: usesHouseFont('image', this.modes.image.card)
      };
    },
    hasCards() {
      return this.players != null;
    },
    // El viejo aviso de «faltan items» lo cubre ahora E4, que ModeSettings muestra en línea.
    isValid() {
      return validateConfig(this.workingConfig).ok;
    }
  },
  watch: {
    // Los cartones ya generados son de la config anterior: se descartan para no pintarlos con
    // otro tamaño. Se vuelven a generar con «Generar».
    workingConfig() {
      this.players = null;
      this.generated = null;
    },
    id() {
      this.load();
    },
    token() {
      this.load();
    },
    // «Reimprimir tirada #n» y «Hacer una tirada nueva» solo cambian ?print: misma vista.
    printParam() {
      this.load();
    }
  },
  async created() {
    if (this.remote) {
      await this.load();
      return;
    }
    if (this.store.config) this.workingConfig = cloneConfig(this.store.config);
  },
  methods: {
    // Carga el bingo (o el snapshot de una tirada) del backend.
    async load() {
      if (!this.remote) return;
      // Cambiar de :id y de ?print a la vez dispara dos cargas: solo vale la última.
      const seq = (this.loadSeq = (this.loadSeq || 0) + 1);
      const stale = () => seq !== this.loadSeq;
      this.loading = true;
      this.loadError = null;
      this.error = '';
      this.reprint = null;
      this.savedPrintId = null;
      try {
        let config;
        if (this.printParam) {
          // La semilla y los jugadores de la tirada salen de list_prints (get_print da el payload).
          this.prints = await listPrints(this.id);
          if (stale()) return;
          const idx = this.prints.findIndex((p) => String(p.printId) === String(this.printParam));
          if (idx < 0) {
            this.loadError = { title: 'Esta tirada no existe', detail: '' };
            return;
          }
          config = await loadPrint(this.printParam);
          if (stale()) return;
          const p = this.prints[idx];
          this.reprint = { printId: p.printId, n: idx + 1, seed: p.seed, players: p.players };
          this.seed = p.seed;
          this.count = p.players;
        } else if (this.id) {
          config = await loadApiBingo(this.id);
          const prints = await listPrints(this.id);
          if (stale()) return;
          this.prints = prints;
        } else {
          config = await loadSharedBingo(this.token);
          if (stale()) return;
          this.prints = [];
        }
        this.workingConfig = config;
        if (this.reprint) {
          // Tras el watch de workingConfig (que descarta los cartones): si no, los borraría.
          await this.$nextTick();
          this.generate();
        }
      } catch (e) {
        if (stale()) return;
        this.loadError = isGone(e)
          ? { title: 'Este bingo ya no existe', detail: '' }
          : { title: 'No se pudo cargar el bingo', detail: e.message || '' };
      } finally {
        if (!stale()) this.loading = false;
      }
    },
    generate() {
      if (!this.isValid) return;
      const n = Math.max(1, Math.min(MAX_PLAYERS, this.count || 1));
      // La semilla de cada cartón depende solo de (semilla, modo, índice): un cartón concreto no
      // cambia al variar el nº de jugadores, así que se puede reimprimir uno suelto (bingo/cards.js).
      this.players = Array.from({ length: n }, (_, i) => makePlayerCards(this.workingConfig, this.seed, i));
      this.generated = { seed: this.seed, players: n };
      this.savedPrintId = null;
      this.error = '';
    },
    // Con backend, «Imprimir» guarda antes la tirada (semilla + jugadores + snapshot del bingo) para
    // poder reimprimirla igual. Una reimpresión no guarda otra; los mismos cartones, tampoco.
    async printNow() {
      if (this.remote && !this.reprint && !this.savedPrintId) {
        if (this.busy || !this.generated) return;
        this.busy = true;
        this.error = '';
        try {
          const { printId } = await savePrint({
            bingoId: this.id,
            shareToken: this.token,
            seed: this.generated.seed,
            players: this.generated.players
          });
          this.savedPrintId = printId;
          if (this.id) this.prints = await listPrints(this.id);
        } catch (e) {
          this.error = isGone(e)
            ? 'Este bingo ya no existe: no se pudo guardar la tirada.'
            : `No se pudo guardar la tirada: ${e.message}`;
          return;
        } finally {
          this.busy = false;
        }
      }
      window.print();
    },
    openPrint(p) {
      this.$router.push({ path: `/imprimir/${this.id}`, query: { print: String(p.printId) } });
    },
    // «2026-10-01 13:34:29» (UTC de MySQL) → fecha corta local.
    formatDate(value) {
      const d = new Date(String(value).replace(' ', 'T') + 'Z');
      return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleString('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    }
  }
};
</script>

<style scoped lang="scss">
.print {
  min-height: 100%;
  overflow: auto;
  padding: 1.5rem;
  background: var(--bg);
}

/* En pantalla la hoja mide 285 mm de ancho (~1080 px) y no cabe en la ventana: se reduce solo para
   previsualizar. Al imprimir manda el @page, así que el papel sale a tamaño real. */
@media screen {
  .sheets {
    zoom: 0.6;
  }
}

.controls {
  max-width: 760px;
  margin: 0 auto 2rem;
  background: var(--panel);
  border-radius: var(--radius);
  padding: 1.5rem;
  box-shadow: var(--shadow);
}
.controls h1 {
  margin-top: 0;
}
.controls__hint {
  color: var(--ink-dim);
  font-size: 0.95rem;
  line-height: 1.5;
}
/* `:deep` para que ModeSettings y PrizeOrderHint reutilicen estas clases. */
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
.controls__note {
  color: var(--ink-dim);
  font-size: 0.8rem;
  flex: 1;
  min-width: 240px;
}
.controls input[type='text'],
.controls input[type='number'] {
  padding: 0.5rem 0.7rem;
  border-radius: 10px;
  border: 1px solid #3a3a63;
  background: var(--bg-2);
  color: var(--ink);
  font-size: 1rem;
}
.controls :deep(.controls__warn) {
  margin-top: 1rem;
  color: var(--accent-2);
}
.controls__bingo {
  margin: -0.4rem 0 0.6rem;
  font-size: 1.3rem;
  font-weight: 800;
  color: var(--accent-2);
}
.controls__reprint {
  margin: 0.8rem 0;
  padding: 0.8rem 1rem;
  border-left: 5px solid var(--accent-2);
  border-radius: 10px;
  background: var(--bg-2);
  line-height: 1.5;
}
.controls__reprint a,
.controls__hint a {
  color: var(--accent-2);
  font-weight: 700;
}
.controls__errors {
  margin: 0;
  padding-left: 1.2rem;
}
.controls input:disabled {
  opacity: 0.6;
}

/* Tiradas guardadas: cada una con su «Reimprimir». */
.prints {
  margin-top: 1.6rem;
}
.prints__title {
  margin: 0 0 0.6rem;
  font-size: 1.2rem;
}
.prints__list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}
.prints__row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.8rem;
  padding: 0.5rem 0.8rem;
  border-left: 4px solid var(--accent);
  border-radius: 10px;
  background: var(--bg-2);
}
.prints__meta {
  flex: 1 1 14rem;
  color: var(--ink-dim);
  font-size: 0.9rem;
  overflow-wrap: anywhere;
}
.prints__btn {
  font-size: 0.95rem;
  padding: 0.45em 0.9em;
}
.prints__new {
  color: var(--image);
  font-size: 0.85rem;
  font-weight: 700;
}

/* Cargando el bingo del backend. */
.tuning {
  display: grid;
  place-items: center;
}
.tuning__text {
  font-size: clamp(1.4rem, 3vw, 2.4rem);
  font-weight: 800;
  color: var(--ink-dim);
  letter-spacing: 0.06em;
}

.controls__back {
  display: inline-block;
  margin-top: 1.2rem;
  color: var(--ink-dim);
}

/* --- La hoja --- */
/* Medidas en mm para que la previsualización en pantalla sea la misma que en papel. El @page usa
   6 mm de margen, así que el área útil de un A4 apaisado es 285 × 198 mm. */
.sheets {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2rem;
}

/* Cada .sheet es una página A4 vertical del PDF, partida en tres franjas: solapa, banda de
   contenido y solapa. Las solapas (95,5 mm) son más cortas que la banda (105), así que al doblarlas
   las dos hacia atrás se juntan sin sobresalir y tapan por completo la cara de sorpresas: la hoja
   acaba siendo un cartón de 210 × 105 mm con los números por delante y nada por detrás.
   Las dos caras usan la MISMA banda central; si el reverso se saliera de ella, asomaría al doblar. */
.sheet {
  width: 210mm;
  height: 296mm;
  display: flex;
  flex-direction: column;
  background: #fff;
}

.sheet__flap {
  flex: 0 0 95.5mm;
}

.sheet__band {
  flex: 0 0 105mm;
  min-height: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4mm;
  padding: 4mm;
}

/* Guías de plegado, solo en la cara que se dobla. Al doblar quedan en el propio canto. */
.sheet--front .sheet__band {
  border-top: 1px dashed #bbb;
  border-bottom: 1px dashed #bbb;
}

.sheet :deep(.card) {
  min-width: 0;
}

/* Números: el 3×9 ocupa el ancho de la página → casillas de ~22 mm. */
.sheet--front :deep(.card) {
  width: 100%;
}
.sheet :deep(.grid--numeric .cell) {
  font-size: 5mm;
}

/* Sorpresas: uno encima del otro y a página completa, así cada 3×3 se lleva ~65 mm de ancho por
   casilla en vez de los ~31 de cuando iban lado a lado. El padding deja aire para el borde no
   imprimible, que aquí sí importa porque el contenido llega hasta arriba. */
.sheet--back,
.sheet--single {
  justify-content: center;
  gap: 6mm;
  padding: 10mm 8mm;
}
.sheet--back :deep(.card),
.sheet--single :deep(.card) {
  flex: 1;
  min-height: 0;
  width: 100%;
}
/* `grid-auto-rows` en vez de un `repeat(n, 1fr)` fijo: el nº de filas lo decide la config
   (`modes.*.card`) y cambia entre el cartón musical (3) y el de recuerdos (4). */
.sheet :deep(.grid--items) {
  flex: 1;
  min-height: 0;
  grid-auto-rows: 1fr;
}
/* Letra de las casillas: la regla `--cell-font` de BingoCard (según `--cols`), y que una palabra
   larga parta antes que salirse de la casilla en los cartones de 6 columnas. */
.sheet :deep(.grid--items .cell) {
  aspect-ratio: auto;
  min-height: 0;
  font-size: var(--cell-font);
  overflow-wrap: anywhere;
}
.sheet :deep(.cell__sub) {
  font-size: calc(var(--cell-font) * 0.75);
}
/* Cartones de casa (musical 3×3, recuerdos 4×5, ver bingo/printLayout.js): la letra fija de
   siempre, para que la fiesta de casa se imprima idéntica. */
.sheet :deep(.card--house-font .cell) {
  font-size: 4.6mm;
  overflow-wrap: normal;
}
.sheet :deep(.card--house-font .cell__sub) {
  font-size: 3.4mm;
}
/* El de recuerdos lleva 5 columnas contra las 3 del musical: casillas más estrechas, letra menor. */
.sheet :deep(.card--house-font.card--image .cell) {
  font-size: 3.2mm;
}
.sheet--back :deep(.card__title),
.sheet--single :deep(.card__title) {
  font-size: 6mm;
}

/* Hoja a una cara (surprises-only): no se dobla, así que no tiene que medir el papel entero. Más
   pequeña que el área útil de Chrome con «Márgenes: predeterminados» (~190 × 277 mm) para caber
   igual con márgenes «Ninguno» que con los predeterminados, sin páginas en blanco de más. */
.sheet--single {
  width: 186mm;
  height: 270mm;
  margin: 0 auto;
}

/* --- Impresión --- */
/* Margen 0: las franjas de plegado se miden contra el papel real, no contra un área útil que
   dependa del navegador. El contenido vive en la banda central, lejos del borde no imprimible. */
@page {
  size: A4 portrait;
  margin: 0;
}

@media print {
  .no-print {
    display: none !important;
  }
  .print {
    padding: 0;
    background: #fff;
  }
  .sheets {
    gap: 0;
  }
  .sheet {
    break-after: page;
  }
  .sheet:last-child {
    break-after: auto;
  }
}
</style>
