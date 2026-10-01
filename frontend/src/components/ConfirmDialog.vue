<template>
  <!-- Diálogo de confirmación propio: el confirm() nativo es un diálogo del sistema en la WebView
       de la tele, sin estilo ni foco TV y con navegación de mando a merced del fabricante.
       Se monta con v-if: montado = abierto. -->
  <div class="confirm" @mousedown.self.prevent>
    <div
      ref="panel"
      class="confirm__panel"
      role="alertdialog"
      aria-modal="true"
      aria-labelledby="confirm-message"
    >
      <p id="confirm-message" class="confirm__message">{{ message }}</p>
      <!-- Contenido extra opcional (p. ej. el campo «escribe BORRAR»). Sus controles marcados con
           `data-confirm-focus` entran en el foco atrapado, delante de los botones. -->
      <slot />
      <!-- Una sola fila: con el D-pad solo hay que moverse izquierda-derecha. -->
      <div class="confirm__actions">
        <button ref="cancel" class="btn confirm__btn" @click="cancel">{{ cancelLabel }}</button>
        <button
          ref="confirm"
          class="btn btn--primary confirm__btn"
          :disabled="confirmDisabled"
          @click="accept"
        >
          {{ confirmLabel }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
// Teclas que el D-pad/teclado usaría para sacar el foco del diálogo: se interceptan todas.
const NAV_KEYS = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Tab'];

export default {
  name: 'ConfirmDialog',
  props: {
    message: { type: String, required: true },
    confirmLabel: { type: String, default: 'Aceptar' },
    cancelLabel: { type: String, default: 'Cancelar' },
    // Bloquea «Aceptar» hasta que el padre lo permita (p. ej. hasta teclear BORRAR).
    confirmDisabled: { type: Boolean, default: false }
  },
  emits: ['confirm', 'cancel'],
  mounted() {
    // Quién abrió el diálogo: al cancelar el foco vuelve ahí, o el mando se queda sin punto de partida.
    this.opener = document.activeElement;
    this.closed = false;

    // «Atrás» del mando: en Capacitor no llega como keydown (la Activity lo consume y hace
    // history.back), así que apilamos una entrada propia y cancelamos al recibir su popstate.
    // Misma URL y el history.state del router intacto (spread) para que vue-router, que también
    // escucha popstate, vea la misma ruta con la misma `position` y no navegue a ningún sitio.
    this.historyPushed = true;
    this.ignorePop = false;
    history.pushState({ ...history.state, confirmDialog: true }, '');
    window.addEventListener('popstate', this.onPopState);

    // Fase de captura: actuamos antes que el onKey de la vista y que la navegación nativa.
    window.addEventListener('keydown', this.onKeyDown, true);

    // Foco inicial en el botón seguro.
    this.$refs.cancel.focus();
  },
  beforeUnmount() {
    window.removeEventListener('keydown', this.onKeyDown, true);
    // Si el padre nos desmonta sin pasar por cancel/accept, deshacemos igualmente la entrada.
    this.undoHistoryEntry();
    // Si esperamos nuestro propio popstate (history.back asíncrono), el listener se queda hasta
    // recibirlo y se quita solo; si no, se quita ya.
    if (!this.ignorePop) window.removeEventListener('popstate', this.onPopState);
  },
  methods: {
    // Controles por los que circula el foco: los del slot marcados y los dos botones (sin los
    // deshabilitados, que no pueden tener foco).
    buttons() {
      const extra = Array.from(this.$refs.panel.querySelectorAll('[data-confirm-focus]'));
      return [...extra, this.$refs.cancel, this.$refs.confirm].filter((el) => !el.disabled);
    },
    onKeyDown(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        this.cancel();
        return;
      }
      if (!NAV_KEYS.includes(e.key)) return;
      // En un campo de texto, izquierda/derecha mueven el cursor: no salen del campo.
      if (e.target.tagName === 'INPUT' && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
        e.stopPropagation();
        return;
      }
      // Foco atrapado: nada de navegación espacial nativa ni Tab hacia la página de debajo.
      e.preventDefault();
      e.stopPropagation();

      const buttons = this.buttons();
      const index = buttons.indexOf(document.activeElement);
      // Si el foco se hubiera escapado (p.ej. clic de ratón fuera), lo recuperamos en el seguro.
      if (index === -1) {
        this.$refs.cancel.focus();
        return;
      }
      let step = 0;
      if (e.key === 'ArrowRight' || (e.key === 'Tab' && !e.shiftKey)) step = 1;
      else if (e.key === 'ArrowLeft' || (e.key === 'Tab' && e.shiftKey)) step = -1;
      // Arriba/abajo: con campo extra, entre el campo y los botones; sin él, fila única y el foco
      // se queda donde está.
      else if (buttons.length > 2 && e.key === 'ArrowDown') step = 1;
      else if (buttons.length > 2 && e.key === 'ArrowUp') step = -1;
      if (step) buttons[(index + step + buttons.length) % buttons.length].focus();
    },
    onPopState() {
      if (this.ignorePop) {
        // Es el popstate que provocamos nosotros al cerrar: no es un «atrás» del usuario.
        this.ignorePop = false;
        window.removeEventListener('popstate', this.onPopState);
        return;
      }
      // «Atrás» real: el navegador ya quitó nuestra entrada, no hay que deshacerla.
      this.historyPushed = false;
      this.cancel();
    },
    undoHistoryEntry() {
      if (!this.historyPushed) return;
      this.historyPushed = false;
      this.ignorePop = true;
      history.back();
    },
    cancel() {
      if (this.closed) return;
      this.closed = true;
      this.undoHistoryEntry();
      if (this.opener && this.opener.isConnected) this.opener.focus();
      this.$emit('cancel');
    },
    accept() {
      if (this.closed || this.confirmDisabled) return;
      this.closed = true;
      this.undoHistoryEntry();
      // El foco tras confirmar lo decide el padre: el que abrió puede dejar de tener sentido.
      this.$emit('confirm');
    }
  }
};
</script>

<style scoped lang="scss">
/* Fijo y a pantalla completa: el body no hace scroll, así que todo debe caber sin él.
   Por encima del chip de sorpresa (.cue, z-index 40), que convive y se va solo. */
.confirm {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: grid;
  place-items: center;
  padding: 16px;
  background: rgba(5, 5, 15, 0.72);
}

.confirm__panel {
  width: min(760px, 100%);
  max-height: 100%;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  gap: clamp(1rem, 3vw, 2rem);
  padding: clamp(1.2rem, 3.5vw, 2.6rem);
  border-radius: var(--radius);
  background: var(--panel);
  box-shadow: var(--shadow);
  color: var(--ink);
}

.confirm__message {
  margin: 0;
  font-size: clamp(1.2rem, 2.6vw, 2rem);
  font-weight: 700;
  line-height: 1.3;
  text-align: center;
}

.confirm__actions {
  display: flex;
  flex-wrap: nowrap;
  justify-content: center;
  gap: 1rem;
}

.confirm__btn {
  flex: 0 1 auto;
  min-width: clamp(140px, 18vw, 240px);
}

/* El panel ya usa --panel: el botón seguro necesita destacar sobre él. */
.confirm__btn:not(.btn--primary) {
  background: color-mix(in srgb, var(--ink) 12%, var(--panel));
}
</style>
