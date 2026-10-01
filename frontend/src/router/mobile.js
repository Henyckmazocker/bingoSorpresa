// Rutas de la APK (la tele). Arranca en la pantalla de emparejado: código + QR para recibir un
// bingo desde el móvil, que se carga en el store y pasa al cantor. La APK no imprime: no hay
// `/imprimir`.
export default [
  { path: '/', name: 'tv-home', component: () => import('@/views/TvHomeView.vue') },
  // Antesala del cantor cuando el bingo recibido trae canciones de YouTube: «Empezar» o «Ensayar».
  { path: '/listo', name: 'tv-ready', component: () => import('@/views/TvReadyView.vue') },
  // El cantor con la config ya cargada en el store (sin ella, CallerView vuelve a `/`).
  { path: '/cantar', name: 'caller', component: () => import('@/views/CallerView.vue') },
  { path: '/:pathMatch(.*)*', redirect: '/' }
];
