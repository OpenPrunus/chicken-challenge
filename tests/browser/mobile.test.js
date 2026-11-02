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
  let last;
  for (;;) {
    try { last = await fn(); if (last) return last; } catch (e) { last = 'erreur : ' + e.message; }
    if (Date.now() - t0 > ms) throw new Error(`délai dépassé : ${what} (dernière valeur : ${JSON.stringify(last)})`);
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
  // état propre : on vide le stockage AVANT le chargement (la page lit ses réglages au démarrage)
  await call('Storage.clearDataForOrigin', { origin: new URL(BASE).origin, storageTypes: 'local_storage' });
  await call('Page.navigate', { url: `${BASE}/${query}` });
  await waitFor(() => ev('document.readyState === "complete" && !!document.getElementById("play")'), 10000, 'page chargée');
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
  const d = await ev('({ w: document.getElementById("cv").width, h: document.getElementById("cv").height })');   // 800x600 ou 600xH
  return { x: r.x + cx * r.w / d.w, y: r.y + cy * r.h / d.h };
}
async function startAiGame(device) {
  await open(device);
  await ev('document.getElementById("pseudo").value = "mobile"');
  await tapSel('#play');
  await waitFor(() => ev('!!window.__chickenDebug && !!window.__chickenDebug()'), 5000, 'partie démarrée');
  E.setLayout(E.computeLayout(device.w, device.h));            // même disposition que la page, pour calculer les positions attendues
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

  test('portrait : le jeu occupe tout l\'écran (canvas vertical), sans invite de rotation', async () => {
    await startAiGame(PHONE_PORTRAIT);
    assert.equal(await ev('document.body.classList.contains("playing")'), true);
    assert.equal(await ev('document.getElementById("rotate")'), null, 'plus d\'invite de rotation');
    const l = E.computeLayout(390, 844);
    const dims = await ev('({ w: document.getElementById("cv").width, h: document.getElementById("cv").height })');
    assert.deepEqual(dims, { w: l.W, h: l.H }, 'canvas vertical 600 x H');
    const r = await rectOf('#cv'), vp = await ev('({ w: innerWidth, h: innerHeight })');
    assert.ok(r.w >= vp.w * 0.97, `largeur utilisée ${r.w}/${vp.w}`);
    assert.ok(r.h >= vp.h * 0.97, `hauteur utilisée ${r.h}/${vp.h} (le 4:3 n'en aurait utilisé que ${(vp.w * 0.75).toFixed(0)})`);
    assert.ok(r.w <= vp.w + 0.5 && r.h <= vp.h + 0.5, 'ne dépasse pas de l\'écran');
    near(r.w / r.h, dims.w / dims.h, 0.01, 'ratio du canvas');
    assert.equal(await ev('document.documentElement.scrollWidth <= innerWidth'), true);
  });

  test('portrait : la barre du haut tient entre les scores et ne cache pas les noms', async () => {
    await startAiGame(PHONE_PORTRAIT);
    const bar = await rectOf('#bar'), cv = await rectOf('#cv'), k = cv.w / E.layout.W;     // px CSS par px canvas
    near(bar.cx, cv.cx, 6, 'barre centrée');
    assert.ok((bar.y + bar.h - cv.y) / k <= 92, `la barre (jusqu'à ${(bar.y + bar.h - cv.y) / k} px canvas) doit finir au-dessus des noms (92)`);
    assert.ok((bar.h) >= 43.5, 'tactile');
  });

  test('rotation en pleine partie : la disposition suit, la partie continue', async () => {
    await startAiGame(PHONE_PORTRAIT);
    await ev('(() => { const G = window.__chickenDebug(); G.me = 2; G.opp = 1; })()');
    const dims = () => ev('({ w: document.getElementById("cv").width, h: document.getElementById("cv").height })');
    assert.equal((await dims()).w, 600);

    await call('Emulation.setDeviceMetricsOverride', { width: 844, height: 390, deviceScaleFactor: 2, mobile: true });   // on tourne le téléphone
    await waitFor(async () => (await dims()).w === 800, 3000, 'paysage appliqué');
    let r = await rectOf('#cv'), vp = await ev('({ w: innerWidth, h: innerHeight })');
    near(r.w / r.h, 4 / 3, 0.01, 'retour au 4:3');
    assert.ok(r.h >= vp.h * 0.97, 'hauteur pleine en paysage');
    let s = await state();
    assert.deepEqual([s.state, s.turn], ['serve', 'me'], 'la partie continue');
    assert.deepEqual(await ev('(() => { const G = window.__chickenDebug(); return [G.me, G.opp]; })()'), [2, 1], 'le score est conservé');

    await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });   // et on le remet droit
    await waitFor(async () => (await dims()).w === 600, 3000, 'portrait réappliqué');
    r = await rectOf('#cv'); vp = await ev('({ w: innerWidth, h: innerHeight })');
    assert.ok(r.w >= vp.w * 0.97 && r.h >= vp.h * 0.97, 'plein écran à nouveau');
    // et le jeu reste jouable après la rotation : un tir au doigt part bien
    E.setLayout(E.computeLayout(390, 844));
    const a = await canvasToViewport(E.layout.CX, E.layout.CY + E.layout.HH * 0.9);
    await touch('touchStart', [{ ...a, id: 1 }]); await sleep(60);
    await touch('touchEnd', []); await sleep(80);
    s = await state();
    assert.equal(s.state, 'flight', 'le lancer part après rotation');
  });

  test('le décor est reconstruit à chaque rotation (pas d\'image étirée)', async () => {
    await startAiGame(PHONE_PORTRAIT);
    const bgW = () => ev('(() => { const G = window.__chickenDebug(); return G.bg ? G.bg.width : null; })()');
    await sleep(200);
    assert.equal(await bgW(), 600, 'décor portrait');
    await call('Emulation.setDeviceMetricsOverride', { width: 844, height: 390, deviceScaleFactor: 2, mobile: true });
    await waitFor(async () => (await bgW()) === 800, 3000, 'décor paysage');
  });

  test('paysage : le canvas 4:3 occupe toute la hauteur et reste centré', async () => {
    await startAiGame(PHONE_LANDSCAPE);
    assert.equal(await ev('document.getElementById("rotate")'), null);
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
for (const [orientation, DEVICE] of [['paysage', PHONE_LANDSCAPE], ['portrait', PHONE_PORTRAIT]]) describe(`commandes au doigt (${orientation})`, () => {
  const lay = () => E.layout;
  const hold = () => { const L = lay(); return { downX: L.CX - 0.3 * L.HW, downY: L.CY + 0.95 * L.HH, toX: L.CX - 0.7 * L.HW, toY: L.CY + 0.3 * L.HH }; };

  test('poser le doigt verrouille la position, glisser vise avec le décalage tactile, relâcher lance', async () => {
    await startAiGame(DEVICE);
    assert.deepEqual([(await state()).state, (await state()).turn], ['serve', 'me']);
    const L = lay(), h = hold();
    const down = await canvasToViewport(h.downX, h.downY);
    await touch('touchStart', [{ ...down, id: 1 }]);
    await sleep(60);
    let s = await state();
    assert.ok(s.hold, 'tir armé au toucher');
    assert.equal(s.mouse.touch, true);
    const sx = E.clamp((h.downX - L.CX) / L.HW, -1, 1) * 0.8;
    near(s.hold.sx, sx, 0.02, 'position du joueur verrouillée sous le doigt');

    const to = await canvasToViewport(h.toX, h.toY);
    for (let i = 1; i <= 6; i++) { await touch('touchMove', [{ x: down.x + (to.x - down.x) * i / 6, y: down.y + (to.y - down.y) * i / 6, id: 1 }]); await sleep(25); }
    await sleep(120);                                           // doigt immobile : pas d'effet parasite
    s = await state();
    const aim = await ev('window.__chickenDebug.aim()');
    const expected = E.aimFromPointer(s.mouse.px, s.mouse.py, true);
    for (const k of ['x', 'y', 'z']) near(aim[k], expected[k], 1e-9, `visée ${k}`);
    const noOffset = E.aimPoint(s.mouse.px, s.mouse.py);
    assert.ok(Math.abs(aim.y - noOffset.y) > 0.01 || Math.abs(aim.z - noOffset.z) > 0.01, 'le décalage tactile est appliqué');
    near(s.hold.sx, sx, 0.02, 'la position reste verrouillée pendant la visée');

    await touch('touchEnd', []);
    await sleep(80);
    s = await state();
    assert.equal(s.hold, null);
    assert.equal(s.state, 'flight');
    assert.equal(s.hen.dir, 1, 'la poule part vers l\'adversaire');
  });

  test('un doigt posé dans la zone du pouce (bas de l\'écran) vise le sol devant, glisser vers le haut vise plus loin', async () => {
    await startAiGame(DEVICE);
    const L = lay();
    const low = await canvasToViewport(L.CX, L.H - 120);
    await touch('touchStart', [{ ...low, id: 1 }]); await sleep(60);
    const near_ = await ev('window.__chickenDebug.aim()');
    const high = await canvasToViewport(L.CX, L.CY + 0.2 * L.HH);
    for (let i = 1; i <= 5; i++) { await touch('touchMove', [{ x: low.x, y: low.y + (high.y - low.y) * i / 5, id: 1 }]); await sleep(25); }
    await sleep(100);
    const far = await ev('window.__chickenDebug.aim()');
    assert.ok(far.z > near_.z, `glisser vers le haut vise plus loin (${near_.z} -> ${far.z})`);
    await touch('touchEnd', []);
  });

  test('un second doigt ne déplace ni n\'annule la visée', async () => {
    await startAiGame(DEVICE);
    const L = lay();
    const a = await canvasToViewport(L.CX - 0.2 * L.HW, L.CY + 0.8 * L.HH), b = await canvasToViewport(L.CX + 0.6 * L.HW, L.CY - 0.4 * L.HH);
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
    await startAiGame(DEVICE);
    const L = lay();
    const a = await canvasToViewport(L.CX, L.CY + 0.8 * L.HH);
    await touch('touchStart', [{ ...a, id: 1 }]);
    await sleep(50);
    assert.ok((await state()).hold);
    await touch('touchCancel', []);
    await sleep(80);
    const s = await state();
    assert.equal(s.hold, null);
    assert.equal(s.state, 'serve', 'aucun lancer');
  });

  const setIncoming = (z) => ev(`(() => { const G = window.__chickenDebug(); G.state = 'flight'; G.turn = 'opp'; G.cooldown = 0;
      G.hen = { z: ${z}, dir: -1, x: 0.1, y: 0.05, vx: 0, vy: 0, spin: 0, speed: 0.0001, speed0: 0.8, spin0: 0, rot: 0, bounces: 0 }; })()`);

  test('attraper : taper sur la poule au bon moment la rattrape', async () => {
    await startAiGame(DEVICE);
    await setIncoming(0.2);
    const p = E.proj(0.1, 0.05, 0.2);
    const tap = await canvasToViewport(p.x, p.y);
    await tapAt(tap.x, tap.y);
    await sleep(60);
    assert.equal((await state()).state, 'caught');
  });

  test('taper loin de la poule la rate : le poing se ferme et le prochain essai est bloqué un instant', async () => {
    await startAiGame(DEVICE);
    await setIncoming(0.2);
    const L = lay();
    const far = await canvasToViewport(L.W - 60, L.H - 100);
    await tapAt(far.x, far.y);
    await sleep(60);
    const s = await state();
    assert.equal(s.state, 'flight', 'toujours en vol');
    assert.ok(s.cooldown > 0, 'poing fermé / essai bloqué');
  });

  test('taper trop tôt (poule encore loin) ne rattrape pas', async () => {
    await startAiGame(DEVICE);
    await setIncoming(0.6);
    const p = E.proj(0.1, 0.05, 0.6);
    const tap = await canvasToViewport(p.x, p.y);
    await tapAt(tap.x, tap.y);
    await sleep(60);
    assert.equal((await state()).state, 'flight');
  });
});

describe('commandes à la souris', () => {
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

// --------------------------------------------------------------------------------------------------- classements
/** Remplace fetch() par un faux serveur de classements (avant le chargement de la page). */
async function withBoardMock(mock, fn) {
  const { identifier } = await call('Page.addScriptToEvaluateOnNewDocument', { source: `
    (() => {
      const mock = ${JSON.stringify(mock)}; window.__calls = [];
      const real = window.fetch.bind(window);
      window.fetch = (url, opts) => {
        const u = String(url);
        if (!u.startsWith('api.php')) return real(url, opts);
        window.__calls.push(u);
        const multi = u.includes('board=multi');
        const status = (multi ? mock.multiStatus : mock.aiStatus) || 200;
        return Promise.resolve(new Response(JSON.stringify(multi ? mock.multi : mock.ai), { status, headers: { 'Content-Type': 'application/json' } }));
      };
    })();` });
  try { await fn(); } finally { await call('Page.removeScriptToEvaluateOnNewDocument', { identifier }); }
}
const boardTexts = (sel) => ev(`[...document.querySelectorAll(${JSON.stringify(sel)} + ' li')].map((l) => l.textContent)`);
const AI_ROWS = [{ name: 'Zoé', wins: 3, played: 5, gf: 20, ga: 12 }];
const MULTI_ROWS = [
  { name: 'Alice', rating: 1034, played: 9, wins: 7, losses: 2, gf: 30, ga: 12 },
  { name: 'Bob', rating: 1012, played: 4, wins: 2, losses: 2, gf: 14, ga: 15 },
  { name: '<img src=x onerror="window.__xss=1">', rating: 990, played: 3, wins: 1, losses: 2, gf: 8, ga: 12 },
];

describe('classements (menu)', () => {
  test('deux onglets : contre l\'IA par défaut, puis multijoueur (Elo) dans l\'ordre du serveur', async () => {
    await withBoardMock({ ai: AI_ROWS, multi: MULTI_ROWS }, async () => {
      await open(DESKTOP, '?e2e=1');
      await ev('document.querySelector("#lang [data-lang=en]").click()');
      await waitFor(async () => (await boardTexts('#board')).length === 1, 3000, 'classement IA');
      assert.match((await boardTexts('#board'))[0], /^Zoé — 3 W \/ 5 games \(20-12\)$/);
      assert.equal(await ev('document.querySelector("#boardTabs .sel").dataset.board'), 'ai');

      await ev('document.querySelector("#boardTabs [data-board=multi]").click()');
      await waitFor(async () => (await boardTexts('#board')).length === 3, 3000, 'classement multijoueur');
      const rows = await boardTexts('#board');
      assert.equal(rows[0], 'Alice — 1034 pts · 7 W / 2 L (30-12)');
      assert.equal(rows[1], 'Bob — 1012 pts · 2 W / 2 L (14-15)');
      assert.equal(await ev('document.querySelector("#boardTabs .sel").dataset.board'), 'multi');
      assert.ok((await ev('window.__calls')).includes('api.php?board=multi'), 'appelle la bonne route');
    });
  });

  test('l\'onglet choisi est mémorisé au rechargement', async () => {
    await withBoardMock({ ai: AI_ROWS, multi: MULTI_ROWS }, async () => {
      await open(DESKTOP, '?e2e=1');
      await ev('document.querySelector("#boardTabs [data-board=multi]").click()');
      await sleep(150);
      await call('Page.navigate', { url: `${BASE}/?e2e=1` });
      await waitFor(() => ev('document.readyState === "complete"'), 5000, 'rechargement');
      await waitFor(async () => (await boardTexts('#board')).length === 3, 3000, 'multi rechargé');
      assert.equal(await ev('document.querySelector("#boardTabs .sel").dataset.board'), 'multi');
    });
  });

  test('un pseudo contenant du HTML est affiché tel quel, jamais exécuté', async () => {
    await withBoardMock({ ai: AI_ROWS, multi: MULTI_ROWS }, async () => {
      await open(DESKTOP, '?e2e=1');
      await ev('document.querySelector("#boardTabs [data-board=multi]").click()');
      await waitFor(async () => (await boardTexts('#board')).length === 3, 3000, 'multi');
      await sleep(200);
      assert.equal(await ev('window.__xss'), undefined, 'aucun script exécuté');
      assert.equal(await ev('document.querySelectorAll("#board img").length'), 0, 'aucune balise injectée');
      assert.ok((await boardTexts('#board'))[2].startsWith('<img src=x onerror='), 'le texte brut est visible');
    });
  });

  test('classement vide et erreur serveur : messages clairs, dans la langue choisie', async () => {
    await withBoardMock({ ai: [], multi: [] }, async () => {
      await open(DESKTOP, '?e2e=1');
      await ev('document.querySelector("#lang [data-lang=en]").click()');
      await waitFor(async () => /Nobody has played yet/.test((await boardTexts('#board'))[0] || ''), 3000, 'IA vide');
      await ev('document.querySelector("#boardTabs [data-board=multi]").click()');
      await waitFor(async () => /No ranked game yet/.test((await boardTexts('#board'))[0] || ''), 3000, 'multi vide');
      await ev('document.querySelector("#lang [data-lang=fr]").click()');
      await waitFor(async () => /Aucune partie classée/.test((await boardTexts('#board'))[0] || ''), 3000, 'multi vide (fr)');
    });
    await withBoardMock({ ai: [], multi: [], multiStatus: 500 }, async () => {
      await open(DESKTOP, '?e2e=1');
      await ev('document.querySelector("#lang [data-lang=en]").click()');
      await ev('document.querySelector("#boardTabs [data-board=multi]").click()');
      await waitFor(async () => /Leaderboard unavailable/.test((await boardTexts('#board'))[0] || ''), 3000, 'erreur 500');
    });
  });

  test('le changement de langue retraduit les lignes du classement multijoueur', async () => {
    await withBoardMock({ ai: AI_ROWS, multi: MULTI_ROWS }, async () => {
      await open(DESKTOP, '?e2e=1');
      await ev('document.querySelector("#lang [data-lang=en]").click()');
      await ev('document.querySelector("#boardTabs [data-board=multi]").click()');
      await waitFor(async () => (await boardTexts('#board')).length === 3, 3000, 'multi');
      assert.match((await boardTexts('#board'))[0], /7 W \/ 2 L/);
      await ev('document.querySelector("#lang [data-lang=fr]").click()');
      await waitFor(async () => /7 V \/ 2 D/.test((await boardTexts('#board'))[0] || ''), 3000, 'retraduit');
    });
  });

  test('sur téléphone, les onglets et les lignes tiennent dans l\'écran même avec de longs pseudos', async () => {
    const long = [{ name: 'W'.repeat(15), rating: 1500, played: 99, wins: 88, losses: 11, gf: 999, ga: 888 }];
    await withBoardMock({ ai: AI_ROWS, multi: long }, async () => {
      await open(PHONE_PORTRAIT);
      await ev('document.querySelector("#boardTabs [data-board=multi]").click()');
      await waitFor(async () => (await boardTexts('#board')).length === 1, 3000, 'multi');
      await ev('document.querySelector("#board").scrollIntoView()');
      const o = await ev('({ sw: document.documentElement.scrollWidth, iw: innerWidth })');
      assert.ok(o.sw <= o.iw, `défilement horizontal (${o.sw} > ${o.iw})`);
      for (const b of await ev('[...document.querySelectorAll("#boardTabs button")].map((e) => e.getBoundingClientRect().height)')) assert.ok(b >= 43.5, `onglet ${b}px`);
    });
  });
});

describe('fin de match multijoueur : message de classement', () => {
  const setNet = (g) => ev(`(() => { const G = window.__chickenDebug(); Object.assign(G, ${JSON.stringify(g)}); return window.__chickenDebug.overMsg(); })()`);
  test('victoire classée (anglais) et ordinaux', async () => {
    await startAiGame(DESKTOP);
    await ev('document.querySelector("#lang [data-lang=en]")?.click()');
    const base = { mode: 'net', ranked: true };
    assert.match(await setNet({ ...base, rated: { rating: 1017, delta: 17, rank: 1, total: 12 } }), /Ranking: 1017 pts \(\+17\) — 1st of 12/);
    assert.match(await setNet({ ...base, rated: { rating: 1003, delta: -9, rank: 2, total: 12 } }), /Ranking: 1003 pts \(-9\) — 2nd of 12/);
    assert.match(await setNet({ ...base, rated: { rating: 990, delta: -13, rank: 3, total: 4 } }), /— 3rd of 4/);
    assert.match(await setNet({ ...base, rated: { rating: 990, delta: -13, rank: 11, total: 40 } }), /— 11th of 40/);
  });
  test('partie non classée, ou pas encore de résultat', async () => {
    await startAiGame(DESKTOP);
    assert.match(await setNet({ mode: 'net', ranked: false, rated: null }), /Unranked game|Partie non classée/);
    assert.equal(await setNet({ mode: 'net', ranked: true, rated: null }), '', 'rien tant que le serveur n\'a pas répondu');
    assert.equal(await setNet({ mode: 'ai', ranked: false, rated: null }), '', 'jamais en mode IA');
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
