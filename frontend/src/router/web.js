import { useAuthStore } from '@/store/auth';

// Rutas de la web pública. Público por defecto; `meta.requiresAuth` pide sesión.
export default [
  { path: '/', name: 'home', component: () => import('@/views/HomeView.vue') },
  {
    path: '/construir',
    name: 'build',
    component: () => import('@/views/BingoListView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/construir/:id(\\d+)',
    name: 'build-editor',
    component: () => import('@/views/BingoEditorView.vue'),
    props: true,
    meta: { requiresAuth: true }
  },
  // Jugar e imprimir (M4). Sin `/cantar` ni `/imprimir` sueltos: en web el bingo siempre llega por
  // :id (con cuenta) o por el enlace compartido; la config ya cargada en el store es cosa de la APK.
  {
    path: '/jugar',
    name: 'play',
    component: () => import('@/views/PlayListView.vue'),
    meta: { requiresAuth: true }
  },
  // Enviar un bingo a la tele (M5): destino del QR de la APK (`?code=`) y de «Enviar a una tele»
  // de /jugar (`?bingo=`, pide el código).
  {
    path: '/enviar',
    name: 'send',
    component: () => import('@/views/SendToTvView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/cantar/:id(\\d+)',
    name: 'caller',
    component: () => import('@/views/CallerView.vue'),
    props: true,
    meta: { requiresAuth: true }
  },
  {
    // `?print=<id>` reimprime el snapshot de esa tirada.
    path: '/imprimir/:id(\\d+)',
    name: 'print',
    component: () => import('@/views/PrintView.vue'),
    props: true,
    meta: { requiresAuth: true }
  },
  // Enlace compartido: público, sin cuenta (se juega e imprime con el `share_token`).
  { path: '/b/:token', name: 'shared', component: () => import('@/views/SharedView.vue'), props: true },
  {
    path: '/b/:token/cantar',
    name: 'shared-caller',
    component: () => import('@/views/CallerView.vue'),
    props: true
  },
  {
    path: '/b/:token/imprimir',
    name: 'shared-print',
    component: () => import('@/views/PrintView.vue'),
    props: true
  },
  { path: '/privacidad', name: 'privacy', component: () => import('@/views/PrivacyView.vue') },
  { path: '/:pathMatch(.*)*', redirect: '/' }
];

/**
 * Guard: todo es público salvo `meta.requiresAuth`, que sin sesión vuelve a `/`.
 * La ruta pedida viaja en `?redirect=` y la portada vuelve a ella tras el login: el QR de la tele
 * abre `/enviar?code=…` en un móvil que puede no tener sesión todavía.
 */
export function installGuards(router) {
  router.beforeEach(async (to) => {
    const auth = useAuthStore();
    // También en rutas públicas: la portada cambia si ya hay sesión.
    if (!auth.authChecked) await auth.initialize();
    if (to.meta.requiresAuth && !auth.isAuthenticated) {
      return { name: 'home', query: { redirect: to.fullPath } };
    }
    return true;
  });
}

/**
 * Destino tras el login: la ruta de `?redirect=` si es interna (empieza por una sola `/`); si no,
 * `fallback`. Nunca una URL absoluta ni `//host`: sería un redirect abierto.
 */
export function safeRedirect(value, fallback = '/construir') {
  if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) return fallback;
  if (/[\\\s]/.test(value)) return fallback;
  return value;
}
