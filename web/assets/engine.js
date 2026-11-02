// Logique pure du jeu (physique de la poule, visée, effet, capture, règles du score).
// Aucune dépendance au DOM ni à l'horloge : chargée dans le navigateur (window.ChickenEngine) et
// testée sous Node (module.exports), voir tests/js/.
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.ChickenEngine = factory();
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  // Repère du canvas 800x600 : le plan proche du tunnel est un rectangle de demi-taille HW x HH.
  const W = 800, H = 600, CX = W / 2, CY = 285, HW = 360, HH = 250;
  const GRAV = 0.5;                                    // gravité (unités tunnel / s²)

  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

  // Projection en perspective : z = 0 (plan proche, joueur) -> z = 1 (fond du tunnel, adversaire).
  function proj(x, y, z) {
    const s = 1 / (1 + 3 * z);
    return { x: CX + x * HW * s, y: CY + y * HH * s, s };
  }

  // Poule lancée sans cible précise : (a, b) ∈ [-1, 1] donnent la direction ; a = b = 0 -> pile au centre.
  function makeHen(dir, x0, y0, a, b, spin, speed) {
    const T = 1 / speed;                               // durée approx. du vol
    return {
      z: dir > 0 ? 0 : 1, dir, x: x0, y: y0, speed, spin, speed0: speed, spin0: spin, rot: 0, bounces: 0,
      vx: a * 3.2 * speed,
      vy: -y0 / T - 0.5 * GRAV * T + b * 2.8 * speed,
    };
  }

  // Poule lancée de (x0, y0) vers un point précis du couloir (tgt = {x, y, z}) ; elle y rebondit si c'est une surface.
  function makeHenAim(x0, y0, tgt, spin, speed) {
    const T = tgt.z / speed;
    return {
      z: 0, dir: 1, x: x0, y: y0, speed, spin, speed0: speed, spin0: spin, rot: 0, bounces: 0,
      vx: (tgt.x - x0) / T,
      vy: (tgt.y - y0) / T - 0.5 * GRAV * T,
    };
  }

  // Point visé : première surface (mur, plafond, sol ou fond) touchée par le rayon caméra -> pixel (px, py).
  function aimPoint(px, py) {
    const u = clamp((px - CX) / HW, -0.97, 0.97), v = clamp((py - CY) / HH, -0.97, 0.97);
    const D = clamp(1 / Math.max(Math.abs(u), Math.abs(v), 0.25), 1.3, 4);   // distance (1 = plan proche, 4 = fond)
    return { x: clamp(u * D, -1, 1), y: clamp(v * D, -1, 1), z: (D - 1) / 3 };
  }

  // Au doigt, le viseur est décalé au-dessus du point touché pour ne pas être caché par la main.
  const TOUCH_AIM_OFFSET = 70;
  function aimFromPointer(px, py, isTouch) {
    return aimPoint(px, py - (isTouch ? TOUCH_AIM_OFFSET : 0));
  }

  // Un pas de physique. Retourne 'x' | 'y' si la poule a rebondi (mur / sol ou plafond), sinon null.
  function step(h, dt) {
    h.z += h.dir * h.speed * dt;
    h.x += h.vx * dt; h.y += h.vy * dt;
    h.vx += h.spin * dt;
    h.vy += GRAV * dt;
    h.rot += dt * (6 + h.spin * 4) * h.dir;
    let hit = null;
    if (Math.abs(h.x) > 1) {
      h.x = Math.sign(h.x); h.vx *= -0.88; h.spin *= 0.7; hit = 'x';
    }
    if (h.y > 1 || h.y < -1) {
      h.y = Math.sign(h.y); h.vy *= -0.82; hit = hit || 'y';
    }
    if (hit) { h.bounces++; h.speed = Math.max(0.35, h.speed * 0.93); }     // perd de la vitesse
    return hit;
  }

  // Coup de poignet : vitesse latérale (px/s) de la souris sur les ~120 dernières ms avant `now`.
  // hist = [{t, px}, ...]. Souris immobile -> 0 (tir droit) ; mouvement sec -> effet signé, borné à ±2.2.
  function flickSpin(hist, now) {
    const rec = hist.filter((q) => now - q.t <= 120);
    if (rec.length < 2) return 0;
    const f = rec[0], l = rec[rec.length - 1];
    if (now - l.t > 70 || l.t - f.t < 25) return 0;
    const v = (l.px - f.px) / ((l.t - f.t) / 1000);
    if (Math.abs(v) < 250) return 0;                                         // zone morte
    return clamp((Math.abs(v) - 250) / 700, 0, 1) * Math.sign(v) * 2.2;
  }

  // Jauge de puissance qui oscille 0 -> 1 -> 0 (période 1,4 s).
  function power(elapsedMs) {
    const k = (elapsedMs / 700) % 2;
    return k < 1 ? k : 2 - k;
  }

  // Pondération de la capture : plus la poule est « folle » (rebonds, effet, vitesse de départ), plus la
  // zone de capture est tolérante. Une poule droite reste exigeante ; une poule chaotique reste rattrapable.
  function chaos(h) {
    const c = 0.25 * h.bounces
            + 0.30 * Math.min(1, Math.abs(h.spin0 ?? h.spin) / 2)
            + 0.30 * Math.max(0, ((h.speed0 ?? h.speed) - 0.9) / 0.7);
    return clamp(c, 0, 1);
  }
  function catchReach(h) {
    const c = chaos(h);
    return { r: 0.42 + 0.22 * c, zmax: 0.25 + 0.08 * c, c };   // rayon de la main et fenêtre de timing
  }
  function canCatch(h, mx, my) {
    const reach = catchReach(h);
    return h.z < reach.zmax && Math.hypot(h.x - mx, h.y - my) < reach.r;
  }

  // Perspective inversée (multijoueur) : la poule lancée par l'adversaire part du fond, gauche/droite en miroir.
  function mirrorHen(hen) {
    return {
      z: 1, dir: -1, x: -hen.x, y: hen.y, vx: -hen.vx, vy: hen.vy, spin: -hen.spin,
      speed: hen.speed, speed0: hen.speed, spin0: hen.spin, rot: 0, bounces: 0,
    };
  }

  // Fin de partie : 4 points avec 2 d'écart, ou 12 points au plus.
  function matchOver(me, opp) {
    return me === 12 || opp === 12 || (Math.max(me, opp) >= 4 && Math.abs(me - opp) >= 2);
  }

  return {
    W, H, CX, CY, HW, HH, GRAV, TOUCH_AIM_OFFSET, clamp, proj, makeHen, makeHenAim, aimPoint, aimFromPointer, step,
    flickSpin, power, chaos, catchReach, canCatch, mirrorHen, matchOver,
  };
});
