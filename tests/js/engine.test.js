'use strict';
const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const E = require('../../web/assets/engine.js');

const near = (a, b, eps, msg) => assert.ok(Math.abs(a - b) <= eps, `${msg || ''} attendu ≈ ${b}, obtenu ${a}`);

// Simule une poule jusqu'à la fin du tunnel ; retourne les rebonds rencontrés et l'état final.
function fly(h, dt = 1 / 120, maxSteps = 5000) {
  const hits = [];
  let t = 0;
  for (let i = 0; i < maxSteps && (h.dir > 0 ? h.z < 1 : h.z > 0); i++) {
    const r = E.step(h, dt);
    t += dt;
    if (r) hits.push({ axis: r, z: h.z });
  }
  return { hits, t, h };
}

describe('projection', () => {
  test('le plan proche est à l\'échelle 1, le fond à 1/4', () => {
    const near0 = E.proj(1, 1, 0), far = E.proj(1, 1, 1);
    assert.equal(near0.s, 1);
    assert.equal(far.s, 0.25);
    assert.equal(near0.x, E.CX + E.HW);
    assert.equal(near0.y, E.CY + E.HH);
    near(far.x, E.CX + E.HW / 4, 1e-9);
  });
  test('le centre reste au point de fuite à toute profondeur', () => {
    for (const z of [0, 0.3, 0.7, 1]) {
      const p = E.proj(0, 0, z);
      assert.equal(p.x, E.CX);
      assert.equal(p.y, E.CY);
    }
  });
});

describe('visée (aimPoint)', () => {
  test('viser le centre pointe le fond du couloir', () => {
    const t = E.aimPoint(E.CX, E.CY);
    assert.equal(t.z, 1);
    near(t.x, 0, 1e-9); near(t.y, 0, 1e-9);
  });
  test('viser le bord gauche pointe le mur gauche, tout près', () => {
    const t = E.aimPoint(E.CX - E.HW * 0.97, E.CY);
    assert.equal(t.x, -1);
    assert.ok(t.z < 0.2, `z=${t.z}`);
  });
  test('viser le haut pointe le plafond, le bas le sol', () => {
    assert.equal(E.aimPoint(E.CX, E.CY - E.HH * 0.97).y, -1);
    assert.equal(E.aimPoint(E.CX, E.CY + E.HH * 0.97).y, 1);
  });
  test('les coordonnées sont bornées même loin hors du canvas', () => {
    const t = E.aimPoint(-5000, 9000);
    assert.ok(Math.abs(t.x) <= 1 && Math.abs(t.y) <= 1);
    assert.ok(t.z >= 0 && t.z <= 1);
  });
});

describe('lancers', () => {
  test('makeHen sans visée arrive pile au centre du fond', () => {
    const h = E.makeHen(+1, 0, 0.6, 0, 0, 0, 1.0);
    const { h: end, hits } = fly(h);
    assert.equal(hits.length, 0);
    near(end.x, 0, 0.02); near(end.y, 0, 0.05);
  });
  test('makeHenAim atteint le point visé en vol libre', () => {
    const tgt = { x: 0.3, y: -0.2, z: 1 };
    const h = E.makeHenAim(0, 0.6, tgt, 0, 1.0);
    const { h: end, hits } = fly(h);
    assert.equal(hits.length, 0);
    near(end.x, 0.3, 0.02); near(end.y, -0.2, 0.05);
  });
  test('viser un mur provoque un rebond sur ce mur, à la bonne profondeur', () => {
    const tgt = E.aimPoint(E.CX - E.HW * 0.6, E.CY);          // mur gauche
    const h = E.makeHenAim(0, 0.6, tgt, 0, 1.0);
    const { hits } = fly(h);
    assert.ok(hits.length >= 1);
    assert.equal(hits[0].axis, 'x');
    near(hits[0].z, tgt.z, 0.03, 'profondeur du 1er rebond');
  });
  test('viser le plafond rebondit en premier sur l\'axe y', () => {
    const tgt = E.aimPoint(E.CX, E.CY - E.HH * 0.7);
    const { hits } = fly(E.makeHenAim(0, 0.6, tgt, 0, 1.0));
    assert.equal(hits[0].axis, 'y');
  });
  test('mémorise vitesse et effet de départ (ils s\'érodent aux rebonds)', () => {
    const h = E.makeHenAim(0, 0.6, E.aimPoint(E.CX - 200, E.CY), 1.5, 1.2);
    fly(h);
    assert.equal(h.speed0, 1.2);
    assert.equal(h.spin0, 1.5);
    assert.ok(h.speed < h.speed0 && h.bounces > 0);
  });
});

describe('physique (step)', () => {
  test('rebond sur un mur : vitesse inversée et amortie, perte de vitesse en profondeur', () => {
    const h = { z: 0.5, dir: 1, x: 0.99, y: 0, vx: 2, vy: 0, spin: 1, speed: 1, rot: 0, bounces: 0 };
    const r = E.step(h, 0.02);
    assert.equal(r, 'x');
    assert.equal(h.x, 1);
    assert.ok(h.vx < 0, 'vx inversée');
    near(h.speed, 0.93, 1e-9);
    assert.equal(h.bounces, 1);
  });
  test('rebond sur le sol et le plafond', () => {
    const floor = { z: 0.5, dir: 1, x: 0, y: 0.99, vx: 0, vy: 3, spin: 0, speed: 1, rot: 0, bounces: 0 };
    assert.equal(E.step(floor, 0.02), 'y'); assert.equal(floor.y, 1); assert.ok(floor.vy < 0);
    const ceil = { z: 0.5, dir: 1, x: 0, y: -0.99, vx: 0, vy: -3, spin: 0, speed: 1, rot: 0, bounces: 0 };
    assert.equal(E.step(ceil, 0.02), 'y'); assert.equal(ceil.y, -1); assert.ok(ceil.vy > 0);
  });
  test('la vitesse en profondeur ne descend jamais sous 0.35', () => {
    const h = { z: 0.5, dir: 1, x: 1, y: 0, vx: 1, vy: 0, spin: 0, speed: 0.36, rot: 0, bounces: 0 };
    E.step(h, 0.01);
    assert.equal(h.speed, 0.35);
  });
  test('l\'effet courbe la trajectoire du côté du spin', () => {
    const run = (spin) => fly(E.makeHen(+1, 0, 0.6, 0, 0, spin, 1.0)).h.x;
    assert.ok(run(1.5) > run(0) + 0.3, 'spin positif -> vers la droite');
    assert.ok(run(-1.5) < run(0) - 0.3, 'spin négatif -> vers la gauche');
  });
  test('z progresse dans le sens de dir', () => {
    const a = { z: 0.5, dir: 1, x: 0, y: 0, vx: 0, vy: 0, spin: 0, speed: 1, rot: 0, bounces: 0 };
    const b = { ...a, dir: -1 };
    E.step(a, 0.1); E.step(b, 0.1);
    assert.ok(a.z > 0.5 && b.z < 0.5);
  });
});

describe('coup de poignet (flickSpin)', () => {
  const mk = (pxs, dtMs, now) => pxs.map((px, i) => ({ t: now - (pxs.length - 1 - i) * dtMs, px }));
  test('historique vide ou trop court -> pas d\'effet', () => {
    assert.equal(E.flickSpin([], 1000), 0);
    assert.equal(E.flickSpin([{ t: 990, px: 100 }], 1000), 0);
  });
  test('souris immobile -> tir droit', () => {
    assert.equal(E.flickSpin(mk([300, 300, 300, 300], 20, 1000), 1000), 0);
  });
  test('mouvement lent sous la zone morte -> pas d\'effet', () => {
    assert.equal(E.flickSpin(mk([300, 302, 304, 306], 20, 1000), 1000), 0);     // ~100 px/s
  });
  test('mouvement sec : effet signé selon la direction', () => {
    const right = E.flickSpin(mk([300, 330, 360, 390], 20, 1000), 1000);          // +1500 px/s
    const left = E.flickSpin(mk([390, 360, 330, 300], 20, 1000), 1000);
    assert.ok(right > 1 && left < -1);
    near(right, -left, 1e-9);
  });
  test('l\'effet est borné à ±2.2', () => {
    const v = E.flickSpin(mk([0, 200, 400, 600], 20, 1000), 1000);
    assert.equal(v, 2.2);
  });
  test('un mouvement qui date (souris arrêtée depuis > 70 ms) est ignoré', () => {
    assert.equal(E.flickSpin(mk([300, 330, 360, 390], 20, 1000), 1100), 0);
  });
  test('seuls les ~120 derniers ms comptent', () => {
    const old = [{ t: 500, px: 0 }, { t: 520, px: 500 }];
    const still = mk([300, 300, 300], 20, 1000);
    assert.equal(E.flickSpin([...old, ...still], 1000), 0);
  });
});

describe('jauge de puissance', () => {
  test('oscille en triangle 0 -> 1 -> 0 sur 1,4 s', () => {
    near(E.power(0), 0, 1e-9); near(E.power(350), 0.5, 1e-9); near(E.power(700), 1, 1e-9);
    near(E.power(1050), 0.5, 1e-9); near(E.power(1400), 0, 1e-9); near(E.power(2100), 1, 1e-9);
  });
  test('reste toujours dans [0, 1]', () => {
    for (let t = 0; t < 10000; t += 37) { const p = E.power(t); assert.ok(p >= 0 && p <= 1); }
  });
});

describe('pondération de la capture', () => {
  const H = (bounces, spin0, speed0) => ({ bounces, spin0, speed0, speed: speed0, spin: spin0 });
  test('un tir droit et lent garde la tolérance de base', () => {
    assert.equal(E.chaos(H(0, 0, 0.8)), 0);
    assert.deepEqual(E.catchReach(H(0, 0, 0.8)), { r: 0.42, zmax: 0.25, c: 0 });
  });
  test('le chaos augmente avec les rebonds, l\'effet et la vitesse', () => {
    assert.ok(E.chaos(H(1, 0, 0.8)) > E.chaos(H(0, 0, 0.8)));
    assert.ok(E.chaos(H(0, 1, 0.8)) > E.chaos(H(0, 0, 0.8)));
    assert.ok(E.chaos(H(0, 0, 1.5)) > E.chaos(H(0, 0, 1.0)));
  });
  test('le signe de l\'effet n\'a pas d\'importance', () => {
    assert.equal(E.chaos(H(0, 1.7, 1)), E.chaos(H(0, -1.7, 1)));
  });
  test('le chaos est borné dans [0, 1] et la tolérance plafonne à 0.64 / 0.33', () => {
    const max = E.catchReach(H(9, 5, 3));
    assert.equal(max.c, 1);
    near(max.r, 0.64, 1e-9); near(max.zmax, 0.33, 1e-9);
  });
  test('retombe sur speed/spin si speed0/spin0 sont absents', () => {
    assert.equal(E.chaos({ bounces: 0, spin: 2, speed: 0.8 }), 0.3);
  });
  test('canCatch : cas limites de la zone et de la fenêtre', () => {
    const straight = H(0, 0, 0.8), wild = H(3, 2, 1.5);
    const at = (h, z, off) => E.canCatch({ ...h, z, x: 0, y: 0 }, off, 0);
    assert.ok(at(straight, 0.20, 0.40));
    assert.ok(!at(straight, 0.20, 0.55), 'tir droit : reste exigeant');
    assert.ok(!at(straight, 0.30, 0.10), 'hors fenêtre de base');
    assert.ok(at(wild, 0.20, 0.55), 'poule folle : main plus tolérante');
    assert.ok(at(wild, 0.20, 0.62));
    assert.ok(!at(wild, 0.20, 0.72), 'jamais gratuit');
    assert.ok(at(wild, 0.31, 0.10), 'fenêtre élargie');
    assert.ok(!at(wild, 0.36, 0.10), 'hors fenêtre élargie');
  });
  test('la distance est euclidienne (x et y comptent)', () => {
    const h = { bounces: 0, spin0: 0, speed0: 0.8, z: 0.1, x: 0, y: 0 };
    assert.ok(E.canCatch(h, 0.29, 0.29));        // distance 0.410 < 0.42
    assert.ok(!E.canCatch(h, 0.30, 0.30));       // distance 0.424 > 0.42 (ni x ni y seuls ne dépassent 0.42)
  });
});

describe('perspective inversée (multijoueur)', () => {
  test('mirrorHen : gauche/droite en miroir, haut/bas inchangés, part du fond', () => {
    const m = E.mirrorHen({ x: 0.4, y: 0.6, vx: -1.5, vy: -0.3, spin: 1.1, speed: 1.2 });
    assert.deepEqual([m.x, m.y, m.vx, m.vy, m.spin], [-0.4, 0.6, 1.5, -0.3, -1.1]);
    assert.equal(m.z, 1); assert.equal(m.dir, -1);
    assert.equal(m.speed0, 1.2); assert.equal(m.spin0, 1.1);
  });
  test('les deux clients rejouent la même trajectoire (synchronisation)', () => {
    // Le lanceur simule sa poule (z: 0 -> 1) ; le défenseur la version miroir (z: 1 -> 0).
    for (const [x0, a, spin, speed] of [[0, 0, 0, 1], [-0.5, -1, 1.5, 1.4], [0.6, 0.8, -2, 0.9], [0.2, -0.3, 0.7, 1.7]]) {
      const sent = E.makeHen(+1, x0, 0.6, a, 0.3, spin, speed);
      const wire = { x: sent.x, y: sent.y, vx: sent.vx, vy: sent.vy, spin: sent.spin, speed: sent.speed };
      const mine = sent, theirs = E.mirrorHen(wire);
      for (let i = 0; i < 4000 && mine.z < 1; i++) {
        E.step(mine, 1 / 120); E.step(theirs, 1 / 120);
        near(theirs.z, 1 - mine.z, 1e-9, 'profondeur');
        near(theirs.x, -mine.x, 1e-9, 'x en miroir');
        near(theirs.y, mine.y, 1e-9, 'y identique');
        assert.equal(theirs.bounces, mine.bounces);
      }
    }
  });
});

describe('règles du score (matchOver)', () => {
  const cases = [
    [0, 0, false], [3, 0, false], [4, 0, true], [0, 4, true], [4, 3, false], [4, 2, true], [5, 3, true],
    [5, 4, false], [6, 4, true], [10, 11, false], [11, 11, false], [12, 11, true], [11, 12, true], [12, 10, true],
  ];
  for (const [me, opp, expected] of cases) {
    test(`${me}-${opp} -> ${expected ? 'terminé' : 'en cours'}`, () => assert.equal(E.matchOver(me, opp), expected));
  }
});

describe('robustesse (lancers aléatoires)', () => {
  test('aucune valeur invalide, poule toujours dans le tunnel, vol de durée raisonnable', () => {
    let seed = 12345;
    const rnd = () => ((seed = (seed * 1664525 + 1013904223) % 4294967296) / 4294967296);
    for (let n = 0; n < 500; n++) {
      const tgt = E.aimPoint(rnd() * E.W, rnd() * E.H);
      const h = E.makeHenAim((rnd() - 0.5) * 1.6, 0.6, tgt, (rnd() - 0.5) * 4.4, 0.7 + rnd() * 0.9);
      const { t } = fly(h);
      for (const k of ['x', 'y', 'z', 'vx', 'vy', 'speed']) assert.ok(Number.isFinite(h[k]), `${k} fini`);
      assert.ok(Math.abs(h.x) <= 1 && Math.abs(h.y) <= 1, 'dans le tunnel');
      assert.ok(t > 0.2 && t < 6, `durée de vol ${t}`);
    }
  });
});
