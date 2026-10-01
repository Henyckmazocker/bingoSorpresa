<template>
  <div class="page home">
    <main class="page__panel home__panel">
      <p class="home__eyebrow">📺 En antena</p>
      <h1 class="home__title">Bingo Sorpresa</h1>
      <p class="home__lead">
        Un bingo de toda la vida con sorpresas: entre bola y bola caen tus fotos. Monta el tuyo,
        cántalo en el navegador o en la tele, e imprime los cartones.
      </p>

      <!-- Con sesión: los dos apartados. -->
      <section v-if="auth.isAuthenticated" class="home__session">
        <p class="home__hello">
          Hola, <strong>{{ userName }}</strong>
        </p>
        <router-link class="btn btn--primary home__cta" to="/jugar">🎱 Jugar</router-link>
        <router-link class="btn home__cta" to="/construir">🛠️ Construir</router-link>
      </section>

      <!-- Sin sesión: el botón de Google (GIS), o el aviso si el build no trae cliente OAuth. -->
      <section v-else class="home__login">
        <p v-if="fromTv" class="home__hint">📺 Entra con tu cuenta para mandar un bingo a la tele.</p>
        <div v-if="error" class="home__error" role="alert">{{ error }}</div>
        <div v-if="googleClientId" ref="googleBtn" class="home__google"></div>
        <p v-else class="home__notice">Login no configurado</p>
      </section>

      <footer class="home__footer">
        <router-link to="/privacidad">Privacidad</router-link>
      </footer>
    </main>
  </div>
</template>

<script>
import { useAuthStore } from '@/store/auth';
import { safeRedirect } from '@/router/web';

const GIS_SRC = 'https://accounts.google.com/gsi/client';

// Carga el script de Google Identity Services una sola vez (volver a la portada no lo duplica).
let gisPromise = null;
function loadGis() {
  if (window.google?.accounts?.id) return Promise.resolve();
  if (!gisPromise) {
    gisPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = GIS_SRC;
      script.async = true;
      script.onload = resolve;
      script.onerror = () => {
        gisPromise = null;
        reject(new Error('No se pudo cargar el login de Google.'));
      };
      document.head.appendChild(script);
    });
  }
  return gisPromise;
}

export default {
  name: 'HomeView',
  setup() {
    return { auth: useAuthStore() };
  },
  data() {
    return {
      // Build arg del Dockerfile de producción; vacío en dev → «Login no configurado».
      googleClientId: process.env.VUE_APP_GOOGLE_CLIENT_ID || '',
      error: ''
    };
  },
  computed: {
    // Viene del QR de la tele (`/enviar?code=…`) sin sesión.
    fromTv() {
      const r = this.$route.query.redirect;
      return typeof r === 'string' && r.startsWith('/enviar');
    },
    userName() {
      return this.auth.user?.name || 'jugador';
    }
  },
  mounted() {
    if (!this.auth.isAuthenticated) this.renderGoogleButton();
  },
  methods: {
    // Google Identity Services: initialize + renderButton.
    async renderGoogleButton() {
      if (!this.googleClientId) return;
      try {
        await loadGis();
      } catch (e) {
        this.error = e.message;
        return;
      }
      // La vista pudo desmontarse (o iniciarse sesión) mientras cargaba el script.
      if (!this.$refs.googleBtn) return;
      window.google.accounts.id.initialize({
        client_id: this.googleClientId,
        callback: this.handleGoogleCredential
      });
      window.google.accounts.id.renderButton(this.$refs.googleBtn, {
        theme: 'filled_black',
        size: 'large',
        shape: 'pill',
        text: 'continue_with',
        width: 280
      });
    },
    async handleGoogleCredential(response) {
      this.error = '';
      try {
        await this.auth.loginWithGoogle(response.credential);
        // Si el guard nos trajo aquí desde una ruta con sesión (p. ej. el QR de la tele,
        // `/enviar?code=…`), se vuelve a ella; si no, a Construir.
        this.$router.push(safeRedirect(this.$route.query.redirect));
      } catch (e) {
        this.error = e.message || 'Error de inicio de sesión';
      }
    }
  }
};
</script>

<style scoped lang="scss">
.home {
  display: grid;
  place-items: center;
}

.home__panel {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: clamp(0.8rem, 2.4vh, 1.6rem);
  text-align: center;
}

.home__eyebrow {
  margin: 0;
  color: var(--ink-dim);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.12em;
}

.home__title {
  margin: 0;
  font-size: clamp(2.4rem, 8vw, 5rem);
  font-weight: 900;
  line-height: 1;
  background: linear-gradient(135deg, var(--accent), var(--accent-2));
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}

.home__lead {
  margin: 0;
  max-width: 34em;
  color: var(--ink-dim);
  font-size: clamp(1rem, 2vw, 1.25rem);
  line-height: 1.5;
}

.home__session,
.home__login {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  margin-top: 0.5rem;
}

.home__hello {
  margin: 0;
  font-size: clamp(1.1rem, 2.2vw, 1.5rem);
}

.home__cta {
  text-decoration: none;
  min-width: 14rem;
  text-align: center;
}

.home__cta:not(.btn--primary) {
  color: var(--ink);
}

.home__google {
  min-height: 44px;
}

.home__notice {
  margin: 0;
  padding: 0.6em 1.2em;
  border: 2px dashed var(--ink-dim);
  border-radius: var(--radius);
  color: var(--ink-dim);
  font-weight: 700;
}

.home__error {
  padding: 0.6em 1em;
  border-radius: 12px;
  background: color-mix(in srgb, var(--accent) 22%, var(--panel));
  font-weight: 700;
}

.home__footer {
  margin-top: 0.5rem;
  a {
    color: var(--ink-dim);
  }
}

.home__hint {
  margin: 0;
  color: var(--accent-2);
  font-weight: 700;
}
</style>
