/* Enregistre le service worker : le jeu se lance aussi sans réseau (après une première visite). */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  });
}
