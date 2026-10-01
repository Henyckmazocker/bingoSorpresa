// Sesión del usuario. `me` devuelve aquí el
// usuario completo, así que con JWT (Google o dev-login) también sabemos quién está dentro.
import { defineStore } from 'pinia';
import { apiCall, setCsrfToken, setJwt, getJwt } from '@/services/api';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    isAuthenticated: false,
    authChecked: false
  }),
  actions: {
    /** Resuelve la sesión al arrancar. El JWT (Google o dev-login) manda sobre la cookie de sesión. */
    async initialize() {
      try {
        if (getJwt()) {
          // El backend valida el token en cada petición; `me` confirma que sigue valiendo
          // (cuenta borrada → 401, desactivada → 403) y trae el usuario.
          const data = await apiCall('me');
          this.user = data.user;
          this.isAuthenticated = true;
          return;
        }
        const data = await apiCall('check_auth');
        this.user = data.user;
        this.isAuthenticated = true;
        if (data.csrf_token) setCsrfToken(data.csrf_token);
      } catch (e) {
        // Token caducado o rechazado: fuera, sin llamar a `logout` (no hay sesión que cerrar).
        setJwt(null);
        this.user = null;
        this.isAuthenticated = false;
      } finally {
        this.authChecked = true;
      }
    },

    async loginWithGoogle(googleToken) {
      const data = await apiCall('login', { google_token: googleToken });
      this.user = data.user;
      this.isAuthenticated = true;
      this.authChecked = true;
      if (data.csrf_token) setCsrfToken(data.csrf_token);
      if (data.jwt_token) setJwt(data.jwt_token);
    },

    /** Acceso de desarrollo: guarda un JWT firmado a mano (sin Google) y lo valida con `me`. */
    async loginWithToken(token) {
      setJwt(token);
      this.authChecked = false;
      await this.initialize();
      if (!this.isAuthenticated) throw new Error('El token no es válido.');
    },

    async logout() {
      try { await apiCall('logout'); } catch (e) { /* da igual: se limpia el lado cliente */ }
      this.clear();
    },

    /** Borra la cuenta (el backend exige `confirm: "BORRAR"`) y limpia la sesión local. */
    async deleteAccount(confirm) {
      await apiCall('delete_account', { confirm });
      this.clear();
    },

    clear() {
      setJwt(null);
      setCsrfToken(null);
      this.user = null;
      this.isAuthenticated = false;
    }
  }
});
