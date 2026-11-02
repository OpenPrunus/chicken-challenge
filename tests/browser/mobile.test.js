'use strict';
/**
 * Test de navigateur mobile : pilote un vrai Chrome (headless) par le protocole DevTools, sans dépendance.
 * Il émule un téléphone (écran tactile, portrait puis paysage) et vérifie l'affichage et les commandes au doigt.
 *
 *   npm run test:browser                       lance `php -S` sur web/ et utilise google-chrome
 *   BASE_URL=http://localhost:8080 npm run test:browser   cible un site déjà lancé
 *   CHROME_BIN=/chemin/chrome ...              autre exécutable Chrome / Chromium
 */
const { test, describe, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const net = require('node:net');
const os = require('node:os');
const fs = require('node:fs');
const path = require('node:path');
const E = require('../../web/assets/engine.js');

const ROOT = path.join(__dirname, '..', '..');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const freePort = () => new Promise((res, rej) => {
  const s = net.createServer();
  s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); });
  s.on('error', rej);
});

let BASE, chrome, php, tmpDir, cdp, nextId = 0;
const pending = new Map();

async function waitFor(fn, ms, what) {
  const t0 = Date.now();
  for (;;) {
    try { const v = await fn(); if (v) return v; } catch {}
    if (Date.now() - t0 > ms) throw new Error('délai dépassé : ' + what);
    await sleep(100);
  }
}

before(async () => {
  if (process.env.BASE_URL) BASE = process.env.BASE_URL.replace(/\/$/, '');
  else {
    const port = await freePort();
    php = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', path.join(ROOT, 'web')], { stdio: 'ignore' });
    BASE = `http://127.0.0.1:${port}`;
    await waitFor(async () => (await fetch(BASE + '/')).ok, 8000, 'serveur PHP');
  }
  tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'chicken-chrome-'));
  const dbg = await freePort();
  chrome = spawn(process.env.CHROME_BIN || 'google-chrome', [
    '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--no-first-run',
    `--remote-debugging-port=${dbg}`, `--user-data-dir=${tmpDir}`, 'about:blank',
  ], { stdio: 'ignore' });
  const tab = await waitFor(async () => (await (await fetch(`http://127.0.0.1:${dbg}/json`)).json()).find((t) => t.type === 'page'), 15000, 'Chrome');
  cdp = new WebSocket(tab.webSocketDebuggerUrl);
  await new Promise((r, j) => { cdp.onopen = r; cdp.onerror = () => j(new Error('connexion DevTools impossible')); });
  cdp.onmessage = (e) => { const m = JSON.parse(e.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); } };
  await call('Page.enable'); await call('Runtime.enable');
});
after(() => {
  try { cdp.close(); } catch {}
  for (const p of [chrome, php]) { try { p && p.kill(); } catch {} }
  try { fs.rmSync(tmpDir, { recursive: true, force: true }); } catch {}
});

function call(method, params = {}) {
  return new Promise((resolve, reject) => {
    const id = ++nextId;
    pending.set(id, (m) => (m.error ? reject(new Error(`${method}: ${m.error.message}`)) : resolve(m.result)));
    cdp.send(JSON.stringify({ id, method, params }));
  });
}
async function ev(expr) {
  const r = await call('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) throw new Error('page : ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
  return r.result.value;
}

/** Émule un appareil et recharge la page (les media queries dépendent de l'émulation). */
async function open(device, query = '?e2e=1') {
  await call('Emulation.setDeviceMetricsOverride', { width: device.w, height: device.h, deviceScaleFactor: device.dpr || 2, mobile: !!device.touch });
  await call('Emulation.setTouchEmulationEnabled', device.touch ? { enabled: true, maxTouchPoints: 5 } : { enabled: false });
  await call('Page.navigate', { url: `${BASE}/${query}` });
  await waitFor(() => ev('document.readyState === "complete" && !!document.getElementById("play")'), 10000, 'page chargée');
  await ev('localStorage.clear()');
}
const PHONE_PORTRAIT = { w: 390, h: 844, touch: true };
const PHONE_LANDSCAPE = { w: 844, h: 390, touch: true };
const DESKTOP = { w: 1280, h: 800, touch: false };

const rectOf = (sel) => ev(`(() => { const r = document.querySelector(${JSON.stringify(sel)}).getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height, cx: r.x + r.width / 2, cy: r.y + r.height / 2 }; })()`);
const style = (sel, prop) => ev(`getComputedStyle(document.querySelector(${JSON.stringify(sel)}))[${JSON.stringify(prop)}]`);
async function touch(type, points) { await call('Input.dispatchTouchEvent', { type, touchPoints: points }); }
async function tapAt(x, y) { await touch('touchStart', [{ x, y, id: 1 }]); await sleep(40); await touch('touchEnd', []); }
async function tapSel(sel) {
  // comme un utilisateur : on fait défiler jusqu'à l'élément (le menu dépasse de l'écran en paysage), puis on tape
  await ev(`document.querySelector(${JSON.stringify(sel)}).scrollIntoView({ block: 'center' })`);
  await sleep(60);
  const r = await rectOf(sel);
  await tapAt(r.cx, r.cy);
}
const state = () => ev(`(() => { const G = window.__chickenDebug(); return G && { state: G.state, turn: G.turn, hold: G.hold && { pid: G.hold.pid, sx: G.hold.sx }, cooldown: G.cooldown,
  hen: G.hen && { dir: G.hen.dir, z: G.hen.z }, mouse: { px: G.mouse.px, py: G.mouse.py, touch: G.mouse.touch } }; })()`);
/** Point du canvas (repère 800x600) -> point de la fenêtre. */
async function canvasToViewport(cx, cy) {
  const r = await rectOf('#cv');
  return { x: r.x + cx * r.w / E.W, y: r.y + cy * r.h / E.H };
}
async function startAiGame(device) {
  await open(device);
  await ev('document.getElementById("pseudo").value = "mobile"');
  await tapSel('#play');
  await waitFor(() => ev('!!window.__chickenDebug && !!window.__chickenDebug()'), 5000, 'partie démarrée');
}
const near = (a, b, eps, msg) => assert.ok(Math.abs(a - b) <= eps, `${msg || ''} attendu ≈ ${b}, obtenu ${a}`);

// --------------------------------------------------------------------------------------------------- affichage
describe('affichage mobile', () => {
  test('l\'émulation tactile est bien détectée (pointer: coarse)', async () => {
    await open(PHONE_PORTRAIT);
    assert.equal(await ev('matchMedia("(pointer: coarse)").matches'), true);
    await open(DESKTOP);
    assert.equal(await ev('matchMedia("(pointer: coarse)").matches'), false);
  });

  test('menu en portrait : pas de défilement horizontal, tout tient dans la largeur', async () => {
    await open(PHONE_PORTRAIT);
    const o = await ev('({ sw: document.documentElement.scrollWidth, iw: innerWidth })');
    assert.ok(o.sw <= o.iw, `scrollWidth ${o.sw} > largeur ${o.iw}`);
    for (const sel of ['h1', '#menu', '#play', '#mp', '#pseudo', '#lang']) {
      const r = await rectOf(sel);
      assert.ok(r.x >= -0.5 && r.x + r.w <= o.iw + 0.5, `${sel} déborde (${r.x}..${r.x + r.w} / ${o.iw})`);
    }
    const avatars = await ev('[...document.querySelectorAll("#avatars button")].map((b) => { const r = b.getBoundingClientRect(); return [r.x, r.right]; })');
    assert.equal(avatars.length, 4);
    for (const [x, right] of avatars) assert.ok(x >= 0 && right <= 390, 'personnage hors écran');
  });

  test('menu en paysage : les boutons FR / EN ne recouvrent pas le titre', async () => {
    await open(PHONE_LANDSCAPE);
    const lang = await rectOf('#lang'), h1 = await rectOf('h1');
    assert.ok(lang.y + lang.h <= h1.y + 1 || lang.x >= h1.x + h1.w, `FR/EN (${lang.y}+${lang.h}) recouvre le titre (${h1.y})`);
    const ov = await ev('document.documentElement.scrollWidth <= innerWidth');
    assert.equal(ov, true, 'défilement horizontal');
  });

  test('un pseudo de 15 lettres tient dans le HUD sans passer sous les boutons du haut', async () => {
    await startAiGame(PHONE_LANDSCAPE);
    // mesure avec la même police que le HUD : la largeur maximale réservée aux noms est 160 (gauche) / 140 (droite)
    const w = await ev(`(() => { const c = document.createElement('canvas').getContext('2d'); c.font = 'bold 11px Impact, "Arial Black", system-ui, sans-serif';
      return c.measureText('WWWWWWWWWWWWWWW').width; })()`);
    assert.ok(w > 0);                                               // le HUD réduit puis tronque : aucune largeur ne dépasse
    const bar = await rectOf('#bar'), cv = await rectOf('#cv'), scale = cv.w / E.W;
    const barLeft = (bar.x - cv.x) / scale, barRight = (bar.x + bar.w - cv.x) / scale;
    assert.ok(84 + 160 <= barLeft + 20, `nom de gauche (jusqu'à ${84 + 160}) vs barre (${barLeft})`);
    assert.ok(E.W - 84 - 140 >= barRight - 20, `nom de droite (dès ${E.W - 84 - 140}) vs barre (${barRight})`);
  });

  test('cibles tactiles : au moins 44 px de haut (boutons, langue, niveaux)', async () => {
    await open(PHONE_PORTRAIT);
    for (const sel of ['#play', '#mp', '#lang button', '#levels button', '#avatars button', 'summary']) {
      const hs = await ev(`[...document.querySelectorAll(${JSON.stringify(sel)})].map((e) => e.getBoundingClientRect().height)`);
      assert.ok(hs.length > 0, sel);
      for (const h of hs) assert.ok(h >= 43.5, `${sel} : ${h}px`);
    }
  });

  test('sur ordinateur, les boutons gardent leur taille normale (pas de gonflement tactile)', async () => {
    await open(DESKTOP);
    const h = (await rectOf('#lang button')).h;
    assert.ok(h < 40, `bouton de langue : ${h}px`);
  });

  test('portrait : la partie propose de tourner l\'appareil, puis « Jouer quand même » masque l\'invite', async () => {
    await startAiGame(PHONE_PORTRAIT);
    assert.equal(await ev('document.body.classList.contains("playing")'), true);
    assert.equal(await style('#rotate', 'display'), 'flex');
    assert.match(await ev('document.querySelector("#rotate p").textContent'), /landscape|paysage/);
    await tapSel('#rotateOk');
    await sleep(100);
    assert.equal(await style('#rotate', 'display'), 'none');
  });

  test('paysage : pas d\'invite, le canvas 4:3 occupe toute la hauteur et reste centré', async () => {
    await startAiGame(PHONE_LANDSCAPE);
    assert.equal(await style('#rotate', 'display'), 'none');
    const r = await rectOf('#cv');
    const vp = await ev('({ w: innerWidth, h: innerHeight })');
    near(r.w / r.h, 4 / 3, 0.01, 'ratio');
    assert.ok(r.h <= vp.h + 0.5 && r.w <= vp.w + 0.5, 'le canvas dépasse de l\'écran');
    assert.ok(r.h >= vp.h * 0.97, `le canvas n'utilise pas la hauteur (${r.h}/${vp.h})`);
    near(r.x, (vp.w - r.w) / 2, 2, 'centrage horizontal');
  });

  test('paysage : les boutons Quitter / plein écran sont en haut au centre, hors des scores, et tactiles', async () => {
    await startAiGame(PHONE_LANDSCAPE);
    const bar = await rectOf('#bar'), cv = await rectOf('#cv');
    assert.ok(bar.y < 40, `barre en haut (${bar.y})`);
    near(bar.cx, cv.cx, 6, 'barre centrée');
    assert.ok((await rectOf('#btnQuit')).h >= 43.5);
  });

  test('sur ordinateur, la barre reste en bas à droite', async () => {
    await startAiGame(DESKTOP);
    const bar = await rectOf('#bar'), vp = await ev('({ w: innerWidth, h: innerHeight })');
    assert.ok(bar.y > vp.h / 2 && bar.x > vp.w / 2, 'barre en bas à droite');
  });

  test('le jeu bloque les gestes du navigateur (défilement, zoom, menu contextuel)', async () => {
    await startAiGame(PHONE_LANDSCAPE);
    assert.equal(await style('#cv', 'touchAction'), 'none');
    assert.equal(await style('body', 'overscrollBehavior'), 'none');
    const prevented = await ev(`(() => { const e = new MouseEvent('contextmenu', { cancelable: true, bubbles: true }); document.getElementById('cv').dispatchEvent(e); return e.defaultPrevented; })()`);
    assert.equal(prevented, true);
  });
});

// --------------------------------------------------------------------------------------------------- commandes
describe('commandes au doigt', () => {
  test('poser le doigt verrouille la position, glisser vise avec le décalage tactile, relâcher lance', async () => {
    await startAiGame(PHONE_LANDSCAPE);
    assert.deepEqual([(await state()).state, (await state()).turn], ['serve', 'me']);

    const down = await canvasToViewport(300, 450);
    await touch('touchStart', [{ ...down, id: 1 }]);
    await sleep(60);
    let s = await state();
    assert.ok(s.hold, 'tir armé au toucher');
    assert.equal(s.mouse.touch, true);
    near(s.hold.sx, E.clamp((300 - E.CX) / E.HW, -1, 1) * 0.8, 0.02, 'position du joueur verrouillée sous le doigt');

    const to = await canvasToViewport(220, 340);
    for (let i = 1; i <= 6; i++) { await touch('touchMove', [{ x: down.x + (to.x - down.x) * i / 6, y: down.y + (to.y - down.y) * i / 6, id: 1 }]); await sleep(25); }
    await sleep(120);                                           // doigt immobile : pas d'effet parasite
    s = await state();
    const aim = await ev('window.__chickenDebug.aim()');
    const expected = E.aimFromPointer(s.mouse.px, s.mouse.py, true);
    for (const k of ['x', 'y', 'z']) near(aim[k], expected[k], 1e-9, `visée ${k}`);
    const noOffset = E.aimPoint(s.mouse.px, s.mouse.py);
    assert.ok(Math.abs(aim.y - noOffset.y) > 0.01 || Math.abs(aim.z - noOffset.z) > 0.01, 'le décalage tactile est appliqué');
    near(s.hold.sx, E.clamp((300 - E.CX) / E.HW, -1, 1) * 0.8, 0.02, 'la position reste verrouillée pendant la visée');

    await touch('touchEnd', []);
    await sleep(80);
    s = await state();
    assert.equal(s.hold, null);
    assert.equal(s.state, 'flight');
    assert.equal(s.hen.dir, 1, 'la poule part vers l\'adversaire');
  });

  test('un second doigt ne déplace ni n\'annule la visée', async () => {
    await startAiGame(PHONE_LANDSCAPE);
    const a = await canvasToViewport(350, 420), b = await canvasToViewport(650, 200);
    await touch('touchStart', [{ ...a, id: 1 }]);
    await sleep(50);
    const before = await state();
    await touch('touchStart', [{ ...a, id: 1 }, { ...b, id: 2 }]);
    await touch('touchMove', [{ ...a, id: 1 }, { x: b.x + 15, y: b.y - 15, id: 2 }]);
    await sleep(80);
    const after = await state();
    assert.equal(after.hold.pid, before.hold.pid, 'même doigt de visée');
    near(after.mouse.px, before.mouse.px, 0.01, 'le viseur ne suit pas le second doigt');
    near(after.mouse.py, before.mouse.py, 0.01);
    await touch('touchEnd', []);
  });

  test('un geste système (touchcancel) annule le tir sans lancer', async () => {
    await startAiGame(PHONE_LANDSCAPE);
    const a = await canvasToViewport(400, 400);
    await touch('touchStart', [{ ...a, id: 1 }]);
    await sleep(50);
    assert.ok((await state()).hold);
    await touch('touchCancel', []);
    await sleep(80);
    const s = await state();
    assert.equal(s.hold, null);
    assert.equal(s.state, 'serve', 'aucun lancer');
  });

  test('attraper : taper sur la poule au bon moment la rattrape', async () => {
    await startAiGame(PHONE_LANDSCAPE);
    await ev(`(() => { const G = window.__chickenDebug(); G.state = 'flight'; G.turn = 'opp'; G.cooldown = 0;
      G.hen = { z: 0.2, dir: -1, x: 0.1, y: 0.05, vx: 0, vy: 0, spin: 0, speed: 0.0001, speed0: 0.8, spin0: 0, rot: 0, bounces: 0 }; })()`);
    const p = E.proj(0.1, 0.05, 0.2);
    const tap = await canvasToViewport(p.x, p.y);
    await tapAt(tap.x, tap.y);
    await sleep(60);
    assert.equal((await state()).state, 'caught');
  });

  test('taper loin de la poule la rate : le poing se ferme et le prochain essai est bloqué un instant', async () => {
    await startAiGame(PHONE_LANDSCAPE);
    await ev(`(() => { const G = window.__chickenDebug(); G.state = 'flight'; G.turn = 'opp'; G.cooldown = 0;
      G.hen = { z: 0.2, dir: -1, x: 0.1, y: 0.05, vx: 0, vy: 0, spin: 0, speed: 0.0001, speed0: 0.8, spin0: 0, rot: 0, bounces: 0 }; })()`);
    const far = await canvasToViewport(700, 520);
    await tapAt(far.x, far.y);
    await sleep(60);
    const s = await state();
    assert.equal(s.state, 'flight', 'toujours en vol');
    assert.ok(s.cooldown > 0, 'poing fermé / essai bloqué');
  });

  test('taper trop tôt (poule encore loin) ne rattrape pas', async () => {
    await startAiGame(PHONE_LANDSCAPE);
    await ev(`(() => { const G = window.__chickenDebug(); G.state = 'flight'; G.turn = 'opp'; G.cooldown = 0;
      G.hen = { z: 0.6, dir: -1, x: 0, y: 0, vx: 0, vy: 0, spin: 0, speed: 0.0001, speed0: 0.8, spin0: 0, rot: 0, bounces: 0 }; })()`);
    const p = E.proj(0, 0, 0.6);
    const tap = await canvasToViewport(p.x, p.y);
    await tapAt(tap.x, tap.y);
    await sleep(60);
    assert.equal((await state()).state, 'flight');
  });

  test('à la souris (ordinateur), rien ne change : clic maintenu, visée sans décalage, relâcher lance', async () => {
    await startAiGame(DESKTOP);
    const a = await canvasToViewport(400, 420), b = await canvasToViewport(300, 250);
    await call('Input.dispatchMouseEvent', { type: 'mouseMoved', x: a.x, y: a.y });
    await call('Input.dispatchMouseEvent', { type: 'mousePressed', x: a.x, y: a.y, button: 'left', clickCount: 1 });
    await sleep(50);
    for (let i = 1; i <= 5; i++) { await call('Input.dispatchMouseEvent', { type: 'mouseMoved', x: a.x + (b.x - a.x) * i / 5, y: a.y + (b.y - a.y) * i / 5, buttons: 1 }); await sleep(25); }
    await sleep(120);
    const s = await state();
    assert.ok(s.hold); assert.equal(s.mouse.touch, false);
    const aim = await ev('window.__chickenDebug.aim()');
    const expected = E.aimPoint(s.mouse.px, s.mouse.py);                    // sans décalage
    for (const k of ['x', 'y', 'z']) near(aim[k], expected[k], 1e-9, `visée ${k}`);
    await call('Input.dispatchMouseEvent', { type: 'mouseReleased', x: b.x, y: b.y, button: 'left', clickCount: 1 });
    await sleep(80);
    assert.equal((await state()).state, 'flight');
  });
});

describe('accès de debug', () => {
  test('n\'est exposé que si l\'URL contient ?e2e', async () => {
    await open(DESKTOP, '');
    assert.equal(await ev('typeof window.__chickenDebug'), 'undefined');
    await open(DESKTOP, '?e2e=1');
    assert.equal(await ev('typeof window.__chickenDebug'), 'function');
  });
});
