'use strict';
/** Utilitaires communs aux tests du serveur de jeu : lancers légitimes et défenseur honnête, simulés avec le vrai moteur. */
const E = require('../../web/assets/engine.js');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Un lancer que l'interface peut produire : visée sur un point du couloir, vitesse et effet donnés. */
function legitHen({ px = 220, py = 300, x0 = 0.2, speed = 1.6, spin = 0 } = {}) {
  const t = E.aimPoint(px, py);
  const h = E.makeHenAim(x0, 0.6, t, spin, speed);
  return { x: h.x, y: h.y, vx: h.vx, vy: h.vy, spin: h.spin, speed: h.speed };
}
/** Lancer par défaut des tests : rapide (vol de ~0,6 s) pour que les matchs complets restent courts. */
const HEN = legitHen();

/**
 * Défenseur honnête : simule la poule comme le fait le jeu (pas de 1/60 s), pose la main sur la poule dessinée et clique
 * dès que le moteur l'accepte. Retourne quand cliquer (ms après la réception du lancer) et où est la main.
 */
function honestCatch(hen, { dt = 1 / 60 } = {}) {
  const h = E.mirrorHen(hen);
  let c = 0;
  for (let i = 0; i < 4000 && h.z > 0; i++) {
    E.step(h, dt); c += dt;
    if (h.z <= 0) break;
    const s = 1 / (1 + 3 * h.z), mx = h.x * s, my = h.y * s;
    if (E.canCatch(h, mx, my)) return { delayMs: Math.round(c * 1000), x: +mx.toFixed(3), y: +my.toFixed(3) };
  }
  throw new Error('aucune capture possible pour ce lancer');
}
/** Le défenseur attrape honnêtement : attend le bon moment puis envoie la capture avec la position de sa main. */
async function catchHonestly(defender, hen = HEN, extraDelayMs = 0) {
  const c = honestCatch(hen);
  await sleep(c.delayMs + extraDelayMs);
  defender.send({ t: 'catch', x: c.x, y: c.y });
  return c;
}

module.exports = { E, sleep, legitHen, HEN, honestCatch, catchHonestly };
