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

/** Joue `fn` avec une disposition donnée puis remet la disposition paysage par défaut. */
function withLayout(layout, fn) {
  E.setLayout(layout);
  try { fn(); } finally { E.setLayout(E.computeLayout(1280, 800)); }
}

describe('disposition (paysage / portrait)', () => {
  test('ordinateur et téléphone en paysage : 800x600, la poule garde sa géométrie d\'origine', () => {
    for (const [w, h] of [[1280, 800], [1920, 1080], [844, 390], [1024, 768], [1180, 820]]) {
      assert.deepEqual(E.computeLayout(w, h), { W: 800, H: 600, CX: 400, CY: 285, HW: 360, HH: 250, scale: 1, portrait: false }, `${w}x${h}`);
    }
  });
  test('téléphone en portrait : canvas 600 de large dont la hauteur suit le format de l\'écran', () => {
    const l = E.computeLayout(390, 844);
    assert.equal(l.portrait, true);
    assert.equal(l.W, 600);
    assert.equal(l.H, Math.round(600 / (390 / 844)));
    near(l.W / l.H, 390 / 844, 0.001, 'le canvas épouse le format de l\'écran');
    assert.equal(l.CX, 300);
  });
  test('la hauteur du canvas portrait est bornée (tablette presque carrée, écran très allongé)', () => {
    assert.equal(E.computeLayout(768, 1024).H, 800);           // tablette 3:4 -> 600 x 800
    assert.equal(E.computeLayout(1000, 1000).portrait, true);  // fenêtre carrée : portrait, hauteur minimale
    assert.equal(E.computeLayout(1000, 1000).H, 800);
    assert.equal(E.computeLayout(300, 1200).H, 1400);          // très allongé : plafonné (bandes sur les côtés)
  });
  test('seuil entre paysage et portrait à 1.15', () => {
    assert.equal(E.computeLayout(1150, 1000).portrait, false);
    assert.equal(E.computeLayout(1149, 1000).portrait, true);
  });
  test('dimensions invalides : paysage par défaut', () => {
    for (const [w, h] of [[0, 0], [1, 0], [0, 500], [-5, 100], [NaN, NaN]]) assert.equal(E.computeLayout(w, h).portrait, false, `${w}x${h}`);
  });
  test('portrait : le tunnel tient dans le canvas, avec une bande libre en haut pour les scores', () => {
    for (const [w, h] of [[390, 844], [360, 640], [412, 915], [768, 1024], [320, 568], [430, 932]]) {
      const l = E.computeLayout(w, h);
      assert.ok(l.CX - l.HW >= 0 && l.CX + l.HW <= l.W, `largeur ${w}x${h}`);
      assert.ok(l.CY - l.HH >= 60, `bande des scores libre (haut du tunnel à ${l.CY - l.HH}) ${w}x${h}`);
      assert.ok(l.CY + l.HH <= l.H - 120, `zone du pouce en bas (${l.H - (l.CY + l.HH)} px) ${w}x${h}`);
    }
  });
  test('setLayout change la projection et la visée ; le paysage par défaut revient proprement', () => {
    const portrait = E.computeLayout(390, 844);
    withLayout(portrait, () => {
      for (const z of [0, 0.5, 1]) { const p = E.proj(0, 0, z); assert.deepEqual([p.x, p.y], [portrait.CX, portrait.CY]); }
      const c = E.proj(1, 1, 0);
      assert.deepEqual([c.x, c.y], [portrait.CX + portrait.HW, portrait.CY + portrait.HH]);
      assert.equal(E.aimPoint(portrait.CX, portrait.CY).z, 1, 'viser le point de fuite = le fond');
    });
    const p = E.proj(1, 1, 0);                                  // retour au paysage
    assert.deepEqual([p.x, p.y], [400 + 360, 285 + 250]);
  });
  test('invariant : le point visé se reprojette sous le doigt, en paysage comme en portrait', () => {
    for (const [w, h] of [[1280, 800], [390, 844], [768, 1024]]) {
      const l = E.computeLayout(w, h);
      withLayout(l, () => {
        for (let iu = -7; iu <= 7; iu++) for (let iv = -7; iv <= 7; iv++) {
          const px = l.CX + (iu / 10) * l.HW, py = l.CY + (iv / 10) * l.HH;
          const t = E.aimPoint(px, py);
          const back = E.proj(t.x, t.y, t.z);
          const m = Math.max(Math.abs(iu), Math.abs(iv)) / 10;
          if (m >= 0.25 && m <= 0.76) { near(back.x, px, 1e-6, `x ${w}x${h} (${iu},${iv})`); near(back.y, py, 1e-6, `y ${w}x${h} (${iu},${iv})`); }
        }
      });
    }
  });
  test('la physique de la poule ne dépend pas de la disposition', () => {
    const run = () => fly(E.makeHenAim(0.2, 0.6, { x: -1, y: 0, z: 0.3 }, 1.2, 1.1)).h;
    const a = run();
    let b;
    withLayout(E.computeLayout(390, 844), () => { b = run(); });
    for (const k of ['x', 'y', 'z', 'vx', 'vy', 'speed', 'bounces']) assert.equal(a[k], b[k], k);
  });
  test('un doigt dans la zone du pouce (bas de l\'écran portrait) vise le sol devant soi, glisser vers le haut vise plus loin', () => {
    const l = E.computeLayout(390, 844);
    withLayout(l, () => {
      const low = E.aimFromPointer(l.CX, l.H - 150, true);
      const high = E.aimFromPointer(l.CX, l.CY, true);
      assert.ok(low.z < high.z, `${low.z} < ${high.z}`);
      assert.equal(high.z, 1);
    });
  });
});

describe('visée au doigt (aimFromPointer)', () => {
  test('à la souris, c\'est exactement aimPoint', () => {
    for (const [px, py] of [[400, 285], [200, 100], [650, 450], [10, 10]]) {
      assert.deepEqual(E.aimFromPointer(px, py, false), E.aimPoint(px, py));
    }
  });
  test('au doigt, le viseur est décalé de TOUCH_AIM_OFFSET pixels au-dessus du doigt', () => {
    assert.equal(E.TOUCH_AIM_OFFSET, 70);
    for (const [px, py] of [[400, 355], [250, 300], [600, 420]]) {
      assert.deepEqual(E.aimFromPointer(px, py, true), E.aimPoint(px, py - E.TOUCH_AIM_OFFSET));
    }
  });
  test('un doigt posé au centre, 70 px sous le point de fuite, vise le fond du couloir', () => {
    const t = E.aimFromPointer(E.CX, E.CY + E.TOUCH_AIM_OFFSET, true);
    assert.equal(t.z, 1);
    near(t.x, 0, 1e-9); near(t.y, 0, 1e-9);
  });
  test('un doigt bas sur l\'écran peut viser le plafond (le décalage ne bloque pas la visée haute)', () => {
    const t = E.aimFromPointer(E.CX, E.H - 20, true);                      // doigt tout en bas
    assert.ok(t.y > -1, 'pas encore le plafond');
    assert.equal(E.aimFromPointer(E.CX, E.CY - E.HH * 0.97 + E.TOUCH_AIM_OFFSET, true).y, -1);
  });
  test('au doigt comme à la souris, les valeurs restent bornées', () => {
    const t = E.aimFromPointer(-9999, 9999, true);
    assert.ok(Math.abs(t.x) <= 1 && Math.abs(t.y) <= 1 && t.z >= 0 && t.z <= 1);
  });
  test('un lancer visé au doigt rebondit comme à la souris (même physique)', () => {
    const tgtT = E.aimFromPointer(E.CX - 150, E.CY + E.TOUCH_AIM_OFFSET, true);
    const tgtM = E.aimFromPointer(E.CX - 150, E.CY, false);
    assert.deepEqual(tgtT, tgtM);
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

describe('taille de la poule sur petit écran', () => {
  const DEVICES = { 'PC 1280x800': [1280, 800], 'téléphone paysage': [844, 390], 'grand téléphone portrait': [412, 915], 'téléphone portrait': [390, 844], 'petit téléphone': [360, 640], 'tablette portrait': [768, 1024] };
  // taille réelle du canvas à l'écran pour une fenêtre donnée, comme fitCanvas() la calcule
  const screenOf = ([w, h]) => { const l = E.computeLayout(w, h); const k = Math.min(w / l.W, h / l.H); return { l, k, cssW: l.W * k }; };

  test('henBoost : 1 sur PC (rien ne change), jusqu\'à 2 sur téléphone', () => {
    for (const w of [1067, 1100, 1500, 2000]) assert.equal(E.henBoost(w), 1, `${w}px`);
    near(E.henBoost(768), 1000 / 768, 1e-9);
    near(E.henBoost(520), 1000 / 520, 1e-9);
    assert.equal(E.henBoost(390), 2);
    assert.equal(E.henBoost(200), 2, 'plafonné à 2');
  });
  test('henBoost : décroît quand l\'écran grandit, valeurs invalides = 1', () => {
    let prev = Infinity;
    for (const w of [250, 320, 390, 520, 768, 900, 1000, 1067, 1400]) { const b = E.henBoost(w); assert.ok(b <= prev + 1e-12, `${w}`); prev = b; }
    for (const bad of [0, -10, NaN, undefined]) assert.equal(E.henBoost(bad), 1);
  });
  test('sur PC la poule garde exactement sa taille d\'origine', () => {
    const { l, cssW } = screenOf(DEVICES['PC 1280x800']);
    E.setLayout(l);
    try {
      assert.equal(E.henBoost(cssW), 1);
      assert.equal(E.henRadius(0, E.henBoost(cssW)), 100);
      near(E.henRadius(0.25, 1), 100 / 1.75, 1e-9);
    } finally { E.setLayout(E.computeLayout(1280, 800)); }
  });
  test('au moment où la capture devient possible, la poule fait au moins ~65 % de sa taille sur PC (elle ne paraît plus « loin »)', () => {
    const zmax = E.catchReach({ bounces: 0, spin0: 0, speed0: 0.8 }).zmax;
    const px = (name) => { const { l, k, cssW } = screenOf(DEVICES[name]); E.setLayout(l); try { return E.henRadius(zmax, E.henBoost(cssW)) * k; } finally { E.setLayout(E.computeLayout(1280, 800)); } };
    const pc = px('PC 1280x800');
    for (const name of Object.keys(DEVICES)) assert.ok(px(name) >= pc * 0.65, `${name} : ${px(name).toFixed(0)}px contre ${pc.toFixed(0)}px sur PC`);
    assert.ok(px('téléphone portrait') >= 50, `un doigt fait ~45 px : la poule doit être plus grosse (${px('téléphone portrait').toFixed(0)}px)`);
  });
  test('on tape sur ce qu\'on voit : taper sur la poule dessinée la rattrape toujours, partout dans le tunnel (coins compris)', () => {
    for (const [w, h] of [[1280, 800], [844, 390], [390, 844], [768, 1024]]) {
      const l = E.computeLayout(w, h);
      const boost = E.henBoost(l.W * Math.min(w / l.W, h / l.H));              // l'agrandissement de CET écran
      E.setLayout(l);
      try {
        for (const hen of [{ bounces: 0, spin0: 0, speed0: 0.8 }, { bounces: 3, spin0: 2, speed0: 1.5 }]) {
          const zmax = E.catchReach(hen).zmax;
          for (const z of [0.02, 0.1, 0.2, zmax - 0.005]) {
            for (const x of [-1, -0.7, 0, 0.7, 1]) for (const y of [-1, -0.5, 0, 0.5, 1]) {
              const p = E.proj(x, y, z);                                          // où la poule est dessinée
              const mx = (p.x - l.CX) / l.HW, my = (p.y - l.CY) / l.HH;             // la main posée exactement dessus
              assert.ok(E.canCatch({ ...hen, z, x, y }, mx, my, boost), `${w}x${h} poule (${x},${y}) z=${z.toFixed(3)} : tap sur la poule dessinée raté`);
            }
          }
        }
      } finally { E.setLayout(E.computeLayout(1280, 800)); }
    }
  });
  test('la zone « poule dessinée » est celle de sa taille visible : elle grandit avec l\'agrandissement du téléphone', () => {
    const l = E.computeLayout(390, 844); E.setLayout(l);
    try {
      const hen = { bounces: 0, spin0: 0, speed0: 0.8, z: 0.24, x: 1, y: 1 };      // dans un coin : la règle d'origine ne l'attrape pas (0.59 > 0.42)
      const s = 1 / (1 + 3 * hen.z);
      // on s'écarte de la poule dessinée vers le centre du tunnel (dx < 0) : seule la zone « poule dessinée » peut accepter
      const drawn = (d) => E.canCatch(hen, hen.x * s - d, hen.y * s, 1);
      const drawn2 = (d) => E.canCatch(hen, hen.x * s - d, hen.y * s, 2);
      assert.equal(E.canCatch(hen, 0, 0), false, 'le centre est trop loin');
      const visual1 = E.HEN_VISUAL_UNITS * 1 * s * 1.15, visual2 = E.HEN_VISUAL_UNITS * 2 * s * 1.15;
      assert.ok(drawn(visual1 * 0.9) && !drawn(visual1 * 1.1), 'sur PC : le rayon de la poule dessinée');
      assert.ok(drawn2(visual2 * 0.9) && !drawn2(visual2 * 1.1), 'sur téléphone : deux fois plus grand');
      assert.ok(visual2 > visual1 * 1.9);
    } finally { E.setLayout(E.computeLayout(1280, 800)); }
  });
  test('garde-fou de difficulté : rester immobile ne rattrape pas plus qu\'avec la règle d\'origine (≤ +6 points)', () => {
    const fx = require('../fixtures/physics-vectors.json');
    const original = (h, mx, my) => { const r = E.catchReach(h); return h.z < r.zmax && Math.hypot(h.x - mx, h.y - my) < r.r; };
    for (const [boost, spot] of [[1, [0, 0]], [2, [0, 0]], [1, [0, 0.5]], [2, [-0.5, 0]]]) {
      let orig = 0, now = 0;
      for (const w of fx.legit) {
        const d = E.mirrorHen(w); let a = false, b = false;
        for (let i = 0; i < 2000 && d.z > 0; i++) { E.step(d, 1 / 120); if (d.z <= 0) break; a ||= original(d, spot[0], spot[1]); b ||= E.canCatch(d, spot[0], spot[1], boost); }
        orig += a ? 1 : 0; now += b ? 1 : 0;
      }
      const gain = (100 * (now - orig)) / fx.legit.length;
      assert.ok(gain >= 0 && gain <= 6, `main fixe ${JSON.stringify(spot)}, boost ${boost} : +${gain.toFixed(1)} points (origine ${(100 * orig / fx.legit.length).toFixed(0)} %)`);
    }
  });
  test('rester immobile au centre ne rattrape jamais tout : au moins 15 % des lancers y échappent', () => {
    const fx = require('../fixtures/physics-vectors.json');
    let caught = 0;
    for (const w of fx.legit) {
      const d = E.mirrorHen(w); let ok = false;
      for (let i = 0; i < 2000 && d.z > 0; i++) { E.step(d, 1 / 120); if (d.z <= 0) break; ok ||= E.canCatch(d, 0, 0, 2); }
      caught += ok ? 1 : 0;
    }
    assert.ok(caught / fx.legit.length <= 0.85, `${(100 * caught / fx.legit.length).toFixed(0)} % attrapés sans bouger`);
  });
  test('régression : avant, taper sur une poule dessinée dans un coin la ratait (distance 0.59 > 0.42)', () => {
    const l = E.computeLayout(1280, 800); E.setLayout(l);
    const hen = { bounces: 0, spin0: 0, speed0: 0.8, z: 0.24, x: 1, y: 1 };
    const p = E.proj(1, 1, 0.24);
    const mx = (p.x - l.CX) / l.HW, my = (p.y - l.CY) / l.HH;
    assert.ok(Math.hypot(hen.x - mx, hen.y - my) > 0.42, 'l\'ancien test seul aurait échoué');
    assert.ok(E.canCatch(hen, mx, my), 'le nouveau test accepte');
  });
  test('le comportement d\'origine est conservé : main près de la position d\'arrivée (x, y) = attrapée', () => {
    const h = { bounces: 0, spin0: 0, speed0: 0.8, z: 0.2, x: 0.5, y: -0.3 };
    assert.ok(E.canCatch(h, 0.5, -0.3));
    assert.ok(E.canCatch(h, 0.5 + 0.41, -0.3));
    // z=0.2 -> s=0.625 : la poule est dessinée à x=0.3125 ; une main à 0.6 de la position d'arrivée ET à 0.79 de la poule dessinée rate
    assert.ok(!E.canCatch(h, 0.5 + 0.6, -0.3), 'trop loin des deux positions : raté');
  });
  test('ne rend pas la capture gratuite : loin de la poule dessinée ET de sa position d\'arrivée = raté', () => {
    const h = { bounces: 0, spin0: 0, speed0: 0.8, z: 0.2, x: 0.5, y: -0.3 };
    assert.ok(!E.canCatch(h, -0.9, 0.9));
    assert.ok(!E.canCatch(h, 0.5, 0.9));
    assert.ok(!E.canCatch({ ...h, z: 0.26 }, 0.5, -0.3), 'fenêtre de timing inchangée');
  });
  test('la fenêtre de timing n\'est pas élargie par l\'agrandissement ni par la capture à l\'écran', () => {
    const h = { bounces: 0, spin0: 0, speed0: 0.8, x: 0, y: 0 };
    assert.equal(E.canCatch({ ...h, z: 0.26 }, 0, 0), false);
    assert.equal(E.canCatch({ ...h, z: 0.24 }, 0, 0), true);
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
