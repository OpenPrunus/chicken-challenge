'use strict';
/**
 * Vecteurs de test de la physique : générés par le vrai moteur JavaScript (web/assets/engine.js) et rejoués par
 * PHPUnit contre server/physics.php. Si le moteur change, `npm run fixtures` les régénère (et un test échoue
 * tant que tests/fixtures/physics-vectors.json n'est pas à jour) : les deux implémentations ne peuvent pas diverger en silence.
 */
const fs = require('node:fs');
const path = require('node:path');
const E = require('../../web/assets/engine.js');

function rng(seed) {                                    // mulberry32 : déterministe
  return () => { seed |= 0; seed = (seed + 0x6D2B79F5) | 0; let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
}
const r6 = (v) => Math.round(v * 1e9) / 1e9;           // 9 décimales : assez pour comparer, assez court pour le fichier

/** Lancers « légitimes » : ce que l'interface peut réellement produire (visée sur le tunnel, puissance, coup de poignet). */
function legitThrows(n, seed) {
  const rnd = rng(seed), out = [];
  for (let i = 0; i < n; i++) {
    const tgt = E.aimPoint(rnd() * 800, rnd() * 600);
    const x0 = (rnd() * 2 - 1) * 0.8, speed = 0.7 + rnd() * 0.9;
    const spin = rnd() < 0.4 ? 0 : (rnd() * 2 - 1) * 2.2;
    const h = E.makeHenAim(x0, 0.6, tgt, spin, speed);
    out.push({ x: r6(h.x), y: r6(h.y), vx: r6(h.vx), vy: r6(h.vy), spin: r6(h.spin), speed: r6(h.speed) });
  }
  return out;
}
const fromWire = (w) => ({ z: 0, dir: 1, x: w.x, y: w.y, vx: w.vx, vy: w.vy, spin: w.spin, speed: w.speed, speed0: w.speed, spin0: w.spin, rot: 0, bounces: 0 });

/** États de la poule à pas fixe (1/120) : référence pour la physique PHP. */
function thrownVectors(throws) {
  return throws.map((w) => {
    const h = fromWire(w), samples = [];
    for (let n = 1; n <= 1500 && h.z < 1; n++) {
      E.step(h, 1 / 120);
      if (n % 15 === 0 || h.z >= 1) samples.push({ n, z: r6(h.z), x: r6(h.x), y: r6(h.y), vx: r6(h.vx), vy: r6(h.vy), spin: r6(h.spin), speed: r6(h.speed), bounces: h.bounces });
    }
    return { hen: w, samples };
  });
}

/** Décisions de capture du moteur sur une grille d'états et de mains. */
function catchVectors(seed) {
  const rnd = rng(seed), out = [];
  for (let i = 0; i < 300; i++) {
    const h = { z: r6(rnd() * 0.45), x: r6(rnd() * 2 - 1), y: r6(rnd() * 2 - 1), bounces: Math.floor(rnd() * 5), spin0: r6((rnd() * 2 - 1) * 2.4), speed0: r6(0.7 + rnd() * 1.0) };
    const m = [r6(rnd() * 2 - 1), r6(rnd() * 2 - 1)];
    const boost = [1, 1.3, 1.7, 2][Math.floor(rnd() * 4)];
    out.push({ h, m, boost, expect: E.canCatch(h, m[0], m[1], boost) });
  }
  return out;
}

/**
 * Captures HONNÊTES : un vrai client simule la poule avec ses propres pas de temps (de 7 à 50 ms, comme la boucle du jeu),
 * pose la main près de la poule dessinée et clique dès que le moteur l'accepte. On enregistre le temps écoulé (côté client)
 * et la main : le serveur doit accepter ces captures, quelle que soit la latence (le temps mesuré par le serveur est plus long).
 */
function honestCatches(throws, seed) {
  const rnd = rng(seed), out = [];
  for (const w of throws) {
    const jitter = rnd() < 0.5 ? 'fixed' : 'variable';
    const base = [1 / 30, 1 / 60, 1 / 144][Math.floor(rnd() * 3)];
    const h = E.mirrorHen(w);                                // poule du défenseur
    const boost = [1, 1.3, 1.7, 2][Math.floor(rnd() * 4)];            // taille de l'écran du joueur
    let c = 0, first = null, last = null;
    for (let i = 0; i < 4000 && h.z > 0; i++) {
      const dt = jitter === 'fixed' ? base : 0.007 + rnd() * 0.043;
      E.step(h, dt); c += dt;
      if (h.z <= 0) break;
      const s = 1 / (1 + 3 * h.z);
      const reach = E.catchReach(h);
      // la main n'est jamais parfaite : soit un décalage aléatoire jusqu'à ~20 % du rayon de capture, soit un doigt posé
      // n'importe où sur la poule VISIBLE (jusqu'à 90 % de son rayon : sur téléphone la poule est grosse, on tape sur son bord)
      let mx, my;
      if (rnd() < 0.5) { mx = h.x * s + (rnd() * 2 - 1) * 0.21 * reach.r; my = h.y * s + (rnd() * 2 - 1) * 0.21 * reach.r; }
      else {
        const rv = E.HEN_VISUAL_UNITS * boost * s * 1.15, a = rnd() * 2 * Math.PI, d = Math.sqrt(rnd()) * 0.9 * rv;
        mx = h.x * s + Math.cos(a) * d; my = h.y * s + Math.sin(a) * d;
      }
      if (E.canCatch(h, mx, my, boost)) {
        const rec = { hen: w, c: r6(c), hand: [r6(mx), r6(my)], dt: jitter === 'fixed' ? r6(base) : 'variable', boost };
        if (!first) first = rec; last = rec;
      }
    }
    if (first) { out.push(first); if (last !== first) out.push(last); }   // premier et dernier instant où un vrai joueur pouvait cliquer
  }
  return out;
}

/** Lancers extrêmes : visée tout contre un mur / le plafond, vitesse maximale -> vitesses latérales et rebonds maximaux. */
function stressThrows(n, seed) {
  const rnd = rng(seed), out = [];
  for (let i = 0; i < n; i++) {
    const edge = rnd();
    const px = edge < 0.5 ? (rnd() < 0.5 ? rnd() * 60 : 740 + rnd() * 60) : rnd() * 800;
    const py = edge >= 0.5 ? (rnd() < 0.5 ? rnd() * 40 : 560 + rnd() * 40) : rnd() * 600;
    const tgt = E.aimPoint(px, py), x0 = (rnd() * 2 - 1) * 0.8, speed = 1.2 + rnd() * 0.4;
    const spin = (rnd() * 2 - 1) * 2.2;
    const h = E.makeHenAim(x0, 0.6, tgt, spin, speed);
    out.push({ x: r6(h.x), y: r6(h.y), vx: r6(h.vx), vy: r6(h.vy), spin: r6(h.spin), speed: r6(h.speed) });
  }
  return out;
}

function build() {
  const throws = legitThrows(60, 20261103);
  return {
    version: 1,
    constants: { grav: E.GRAV },
    thrown: thrownVectors(throws.slice(0, 30)),
    canCatch: catchVectors(77),
    honest: honestCatches([...legitThrows(100, 424242), ...stressThrows(80, 31337)], 99),
    legit: legitThrows(250, 5150),
  };
}

module.exports = { build, legitThrows, stressThrows, fromWire, rng };
if (require.main === module) {
  const file = path.join(__dirname, 'physics-vectors.json');
  fs.writeFileSync(file, JSON.stringify(build()) + '\n');
  console.log(`écrit ${file} (${(fs.statSync(file).size / 1024).toFixed(0)} Ko)`);
}
