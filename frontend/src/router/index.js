import { createRouter, createWebHashHistory } from 'vue-router';

// Dos apps en un mismo código: la web pública (portada, login, construir…) y la APK de la tele.
// `process.env.VUE_APP_MODE` es una constante de build: webpack resuelve el `if` y ni siquiera
// empaqueta el router del otro lado (ni los chunks de sus vistas). Por eso es `require` dentro de
// la rama y no un `import` arriba: con el import, las rutas de la tele (y la fiesta de casa)
// viajarían también en el bundle web.
let routes;
let installGuards = null;
if (process.env.VUE_APP_MODE === 'mobile') {
  routes = require('./mobile').default;
} else {
  const web = require('./web');
  routes = web.default;
  installGuards = web.installGuards;
}

// Hash history: funciona igual en web y dentro de la webview de Capacitor (APK).
const router = createRouter({
  history: createWebHashHistory(),
  routes
});

if (installGuards) installGuards(router);

export default router;
