(() => {
'use strict';

const { t, tn, ordinal } = window.I18N;            // traductions FR / EN (assets/i18n.js)
const NCHARS = 4;                         // noms : clés char0..char3
const LEVELS = [                          // noms : clés level0..level2
  { base: 0.60, throwSpeed: [0.7, 1.1] },
  { base: 0.78, throwSpeed: [0.8, 1.4] },
  { base: 0.92, throwSpeed: [1.0, 1.7] },
];

const $ = (id) => document.getElementById(id);
const cv = $('cv'), ctx = cv.getContext('2d');
// Logique pure (physique, visée, effet, capture, règles) : assets/engine.js, testée sous Node (tests/js).
const E = window.ChickenEngine;
const { clamp, proj, step, makeHen, makeHenAim, chaos, catchReach, canCatch, mirrorHen } = E;
// Disposition courante (paysage 4:3 ou portrait plein écran) : mise à jour par applyLayout().
let W = 800, H = 600, CX = 400, CY = 285, HW = 360, HH = 250, LS = 1, PORTRAIT = false, HB = 1;     // HB : agrandissement de la poule sur petit écran
const aimPoint = () => E.aimFromPointer(G.mouse.px, G.mouse.py, G.mouse.touch);   // point du couloir visé (souris ou doigt)
const E2E = new URLSearchParams(location.search).has('e2e');                          // mode des tests de navigateur
const COARSE = matchMedia('(pointer: coarse)').matches;                              // téléphone / tablette

// La disposition suit le format de la fenêtre (rotation du téléphone comprise) : le canvas est redimensionné, le
// décor reconstruit, et la partie en cours continue (tout est en coordonnées normalisées du tunnel).
function applyLayout() {
  const vv = window.visualViewport;
  const l = E.computeLayout(vv ? vv.width : innerWidth, vv ? vv.height : innerHeight);
  if (l.W !== W || l.H !== H || l.HH !== HH) {
    E.setLayout(l);
    ({ W, H, CX, CY, HW, HH } = l); LS = l.scale; PORTRAIT = l.portrait;
    cv.width = W; cv.height = H;
    if (G) G.bg = null;
  }
  fitCanvas();
}
function fitCanvas() {                                                                // plus grande taille qui tient dans la zone sûre
  const game = $('game');
  if (game.hidden) return;
  const cs = getComputedStyle(game);
  const aw = game.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
  const ah = game.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
  const k = Math.min(aw / W, ah / H);
  cv.style.width = Math.floor(W * k) + 'px'; cv.style.height = Math.floor(H * k) + 'px';
  HB = E.henBoost(W * k);                                                           // largeur réelle du canvas à l'écran
}
let layoutRaf = 0;
const relayout = () => { cancelAnimationFrame(layoutRaf); layoutRaf = requestAnimationFrame(applyLayout); };
addEventListener('resize', relayout);
window.visualViewport?.addEventListener('resize', relayout);
addEventListener('orientationchange', relayout);
const flickSpin = () => E.flickSpin(G.mouse.hist, performance.now());   // effet donné par le coup de poignet
const matchOver = () => E.matchOver(G.me, G.opp);
const power = (t0) => E.power(performance.now() - t0);                   // jauge qui oscille 0 -> 1 -> 0
const rand = (a, b) => a + Math.random() * (b - a);

let avatar = +localStorage.getItem('pc_avatar') || 0;
let level = +(localStorage.getItem('pc_level') ?? 1);
let G = null;                       // état de la partie
let looping = false;                // une seule boucle d'animation à la fois

// ---------- Menu ----------
$('pseudo').value = localStorage.getItem('pc_pseudo') || '';
for (let i = 0; i < NCHARS; i++) {
  const b = document.createElement('button');
  b.title = t('char' + i);
  const c = document.createElement('canvas'); c.width = 56; c.height = 72;
  b.appendChild(c);
  b.onclick = () => { avatar = i; refreshMenu(); };
  $('avatars').appendChild(b);
  const cx = c.getContext('2d'); drawChar(cx, i, 28, 70, 66, true, 1);
}
document.querySelectorAll('#levels button').forEach((b) => {
  b.onclick = () => { level = +b.dataset.level; refreshMenu(); };
});
function refreshMenu() {
  [...$('avatars').children].forEach((b, i) => b.classList.toggle('sel', i === avatar));
  document.querySelectorAll('#levels button').forEach((b) =>
    b.classList.toggle('sel', +b.dataset.level === level));
}
refreshMenu();

// Classements : « contre l'IA » (scores envoyés par le navigateur) et « multijoueur » (calculé par le serveur de jeu, Elo).
let boardKind = (() => { try { return localStorage.getItem('cc_board') === 'multi' ? 'multi' : 'ai'; } catch { return 'ai'; } })();
const sameName = (a, b) => String(a).toLowerCase() === String(b).toLowerCase();

function renderBoard(ol, rows, kind, me) {
  ol.innerHTML = '';
  if (!rows.length) { ol.innerHTML = `<li>${t(kind === 'multi' ? 'board.empty.multi' : 'board.empty')}</li>`; return; }
  rows.forEach((p) => {
    const li = document.createElement('li');
    const b = document.createElement('b');
    b.textContent = p.name;                          // textContent : pas d'injection
    li.append(b, kind === 'multi'
      ? t('board.row.multi', { r: p.rating, w: p.wins, l: p.losses, gf: p.gf, ga: p.ga })
      : t('board.row', { w: p.wins, p: p.played, gf: p.gf, ga: p.ga }));
    if (me && sameName(p.name, me)) li.classList.add('me');
    ol.appendChild(li);
  });
}
async function fetchBoard(kind) {
  const r = await fetch(kind === 'multi' ? 'api.php?board=multi' : 'api.php');
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}
async function loadBoard() {
  document.querySelectorAll('#boardTabs button').forEach((b) => b.classList.toggle('sel', b.dataset.board === boardKind));
  $('boardNote').textContent = t(boardKind === 'multi' ? 'board.multi.note' : 'board.ai.note');
  try { renderBoard($('board'), await fetchBoard(boardKind), boardKind, $('pseudo').value.trim()); }
  catch { $('board').innerHTML = `<li>${t('board.error')}</li>`; }
}
async function loadLobbyBoard() {
  try { renderBoard($('boardLobby'), await fetchBoard('multi'), 'multi', net.name); }
  catch { $('boardLobby').innerHTML = `<li>${t('board.error')}</li>`; }
}
document.querySelectorAll('#boardTabs button').forEach((b) => {
  b.onclick = () => { boardKind = b.dataset.board; try { localStorage.setItem('cc_board', boardKind); } catch {} loadBoard(); };
});
loadBoard();

$('play').onclick = () => { mobileEnter(); start(); };
$('again').onclick = () => (G && G.mode === 'net' ? netRematch() : start());
$('toMenu').onclick = () => leaveGame();
$('btnQuit').onclick = () => {
  if (!G) return;
  if (G.state === 'over' || (G.me + G.opp === 0) || confirm(t('game.quit.confirm'))) leaveGame();
};
$('btnFs').onclick = toggleFs;
function toggleFs() {
  if (document.fullscreenElement) document.exitFullscreen();
  else document.documentElement.requestFullscreen?.().catch(() => {});
}
function leaveGame() {                                // en multijoueur on retourne au salon, sinon au menu
  if (G && G.mode === 'net') {
    net.send({ t: 'leave' });
    G = null;
    if (document.fullscreenElement) document.exitFullscreen();
    document.body.classList.remove('playing');
    $('game').hidden = true; $('lobby').hidden = false; loadLobbyBoard();
  } else quit();
}
function quit() {
  G = null;
  if (document.fullscreenElement) document.exitFullscreen();
  document.body.classList.remove('playing');
  $('game').hidden = true; $('menu').hidden = false; loadBoard();
}

// ---------- Son ----------
let ac = null;
function audio() { return ac = ac || new (window.AudioContext || window.webkitAudioContext)(); }
function beep(freq, dur = 0.12, type = 'square', vol = 0.06) {
  try {
    const a = audio(), o = a.createOscillator(), g = a.createGain();
    o.type = type; o.frequency.value = freq;
    g.gain.setValueAtTime(vol, a.currentTime);
    g.gain.exponentialRampToValueAtTime(0.0001, a.currentTime + dur);
    o.connect(g); g.connect(a.destination);
    o.start(); o.stop(a.currentTime + dur);
  } catch {}
}
function thump() {                                   // impact "splat" : bruit + grave qui chute
  try {
    const a = audio(), n = a.sampleRate * 0.35;
    const buf = a.createBuffer(1, n, a.sampleRate), d = buf.getChannelData(0);
    for (let i = 0; i < n; i++) d[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / n, 3);
    const s = a.createBufferSource(), g = a.createGain();
    s.buffer = buf; g.gain.value = 0.25; s.connect(g); g.connect(a.destination); s.start();
    const o = a.createOscillator(), og = a.createGain();
    o.frequency.setValueAtTime(180, a.currentTime);
    o.frequency.exponentialRampToValueAtTime(35, a.currentTime + 0.3);
    og.gain.setValueAtTime(0.25, a.currentTime);
    og.gain.exponentialRampToValueAtTime(0.0001, a.currentTime + 0.3);
    o.connect(og); og.connect(a.destination); o.start(); o.stop(a.currentTime + 0.3);
  } catch {}
}
// --- bruits de poule synthétisés ---
// "cot" : bref grognement nasal (dent de scie filtrée, hauteur qui chute vite)
function cluck(delay = 0, pitch = 1, vol = 0.18, dur = 0.09) {
  try {
    const a = audio(), t = a.currentTime + delay;
    const o = a.createOscillator(), f = a.createBiquadFilter(), g = a.createGain();
    o.type = 'sawtooth';
    o.frequency.setValueAtTime(520 * pitch, t);
    o.frequency.exponentialRampToValueAtTime(260 * pitch, t + dur);
    f.type = 'bandpass'; f.Q.value = 4;
    f.frequency.setValueAtTime(1400 * pitch, t);
    f.frequency.exponentialRampToValueAtTime(700 * pitch, t + dur);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(vol, t + 0.012);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(f); f.connect(g); g.connect(a.destination);
    o.start(t); o.stop(t + dur + 0.02);
  } catch {}
}
// cri affolé : hauteur qui chute avec un fort vibrato, de plus en plus faible
function squawk(delay = 0, pitch = 1, dur = 0.55, vol = 0.2) {
  try {
    const a = audio(), t = a.currentTime + delay;
    const o = a.createOscillator(), f = a.createBiquadFilter(), g = a.createGain();
    const lfo = a.createOscillator(), lg = a.createGain();
    o.type = 'sawtooth';
    o.frequency.setValueAtTime(900 * pitch, t);
    o.frequency.exponentialRampToValueAtTime(260 * pitch, t + dur);
    lfo.frequency.value = 28; lg.gain.value = 70 * pitch;      // vibrato "kkkrâââ"
    lfo.connect(lg); lg.connect(o.frequency);
    f.type = 'bandpass'; f.Q.value = 3;
    f.frequency.setValueAtTime(1800, t);
    f.frequency.exponentialRampToValueAtTime(600, t + dur);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(vol, t + 0.03);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(f); f.connect(g); g.connect(a.destination);
    o.start(t); lfo.start(t); o.stop(t + dur + 0.02); lfo.stop(t + dur + 0.02);
  } catch {}
}
const sndThrow = () => { cluck(0, 1.15, 0.14, 0.07); cluck(0.08, 1.3, 0.12, 0.07); };
const sndCatch = () => { cluck(0, 0.9, 0.16, 0.1); cluck(0.11, 0.8, 0.14, 0.1); };
// rebond : "côt !" aigu et écrasé, hauteur variable pour ne pas lasser
const sndBounce = () => { cluck(0, rand(1.0, 1.4), 0.2, 0.07); };
// but encaissé : impact, puis la poule qui panique "cot cot cot cooot…" et s'effondre
function prout(delay = 0, vol = 0.2) {              // pet sonore : dent de scie grave qui ronronne
  try {
    const a = audio(), t = a.currentTime + delay;
    const o = a.createOscillator(), f = a.createBiquadFilter(), g = a.createGain();
    const lfo = a.createOscillator(), lg = a.createGain();
    o.type = 'sawtooth'; o.frequency.setValueAtTime(95, t); o.frequency.exponentialRampToValueAtTime(55, t + 0.35);
    lfo.frequency.value = 38; lg.gain.value = 30; lfo.connect(lg); lg.connect(o.frequency);
    f.type = 'lowpass'; f.frequency.value = 420;
    g.gain.setValueAtTime(vol, t); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.38);
    o.connect(f); f.connect(g); g.connect(a.destination);
    o.start(t); lfo.start(t); o.stop(t + 0.4); lfo.stop(t + 0.4);
  } catch {}
}
const sndGoal = (hitMe) => {
  thump();
  if (hitMe && Math.random() < 0.5) prout(0.05);
  if (hitMe) {
    [0.12, 0.3, 0.46, 0.6].forEach((d, i) => cluck(d, 1.25 - i * 0.05, 0.22, 0.08));
    squawk(0.78, 1, 0.7, 0.2);
  } else {                                        // elle s'écrase au fond : plus lointain, plus bref
    cluck(0.12, 1.1, 0.12, 0.08); cluck(0.28, 1.0, 0.1, 0.08);
    squawk(0.42, 0.9, 0.45, 0.1);
  }
};
const sndWin = () => [523, 659, 784, 1046].forEach((f, i) => setTimeout(() => beep(f, 0.2, 'triangle', 0.1), i * 140));

// ---------- Partie ----------
function start(netInfo) {
  const name = $('pseudo').value.trim().slice(0, 15) || 'Anonyme';
  localStorage.setItem('pc_pseudo', name);
  localStorage.setItem('pc_avatar', avatar);
  localStorage.setItem('pc_level', level);
  const others = Array.from({ length: NCHARS }, (_, i) => i).filter((i) => i !== avatar);
  G = {
    name, level: LEVELS[level], levelIdx: level,
    me: 0, opp: 0,
    mode: netInfo ? 'net' : 'ai', ranked: netInfo ? netInfo.ranked !== false : false, why: netInfo ? netInfo.why || null : null, rated: null, unrated: null,
    myKind: avatar,
    oppKind: netInfo ? netInfo.opp.kind : others[Math.floor(Math.random() * others.length)],
    oppName: netInfo ? netInfo.opp.name : null,
    rally: 0, localResolved: false, pending: null, oppXt: 0, posX: 9,
    ticket: netInfo || E2E ? null : requestTicket(level),       // (en mode test ?e2e, pas de ticket : on ne consomme pas le quota)
    time: 0, held: null, bg: null,
    state: 'serve', turn: netInfo && !netInfo.serve ? 'opp' : 'me',      // qui lance
    timer: 0, hen: null, flash: '', flashT: 0, flashWho: 'me',
    mouse: { x: 0, y: 0, px: CX, py: CY, vx: 0, t: 0, hist: [], touch: false },
    hold: null, cooldown: 0, last: performance.now(), oppPose: 0,
    oppX: 0, particles: [], splat: null, shake: 0, bounceFx: [],
  };
  document.body.classList.add('playing');
  $('menu').hidden = true; $('lobby').hidden = true; $('invite').hidden = true;
  $('game').hidden = false; $('over').hidden = true;
  applyLayout();                                       // portrait ou paysage selon la fenêtre, canvas ajusté à l'écran
  $('again').textContent = netInfo ? t('over.rematch') : t('over.again'); $('again').disabled = false;
  if (!looping) { looping = true; requestAnimationFrame(loop); }
}

function bounceFx(h, hit) {
  const p = proj(h.x, h.y, h.z);
  G.bounceFx.push({ x: p.x, y: p.y, t: 0.3, hit, s: p.s });
  feathers(p.x, p.y, 5, 180 * p.s + 40);
  sndBounce();
}

function playerThrow(power, spin, tgt, x0) {
  G.hen = makeHenAim(x0, 0.6, tgt, spin, 0.7 + power * 0.9);
  G.state = 'flight'; sndThrow();
  if (G.mode === 'net') {                              // trajectoire déterministe : on n'envoie que les paramètres
    const h = G.hen;
    G.rally++; G.localResolved = false;
    net.send({ t: 'throw', hen: { x: h.x, y: h.y, vx: h.vx, vy: h.vy, spin: h.spin, speed: h.speed } });
  }
}

// Poule envoyée par l'adversaire : perspective inversée (gauche/droite miroir, elle part du fond).
function launchNet(hen) {
  G.hen = mirrorHen(hen);
  G.oppXt = G.oppX = -hen.x;
  G.state = 'flight'; G.oppPose = 0.25; G.localResolved = false; sndThrow();
}

function aiThrow() {
  const [lo, hi] = G.level.throwSpeed;
  const x0 = G.oppX = rand(-0.6, 0.6);
  G.hen = makeHen(-1, x0, 0.35, rand(-1, 1), rand(-1, 0.6), rand(-2, 2) * (Math.random() < 0.7 ? 1 : 0.2), rand(lo, hi));
  G.state = 'flight'; G.oppPose = 0.25; sndThrow();
}

// ---------- Effets ----------
function feathers(x, y, n, power) {
  for (let i = 0; i < n; i++) {
    const a = rand(0, Math.PI * 2), v = rand(0.3, 1) * power;
    G.particles.push({
      x, y, vx: Math.cos(a) * v, vy: Math.sin(a) * v - power * 0.3,
      rot: rand(0, 6), vr: rand(-8, 8), life: rand(0.9, 1.8), max: 1.8,
      sz: rand(7, 15), col: ['#fff', '#f4f0e0', '#ffd9a0', '#e8d8c0'][i % 4],
    });
  }
}

function goal(who) {
  const h = G.hen || { x: 0, y: 0 };
  const atFar = who === 'me';                         // j'ai marqué : la poule s'écrase au fond
  const p = proj(h.x, h.y, atFar ? 1 : 0);
  G.splat = { x: p.x, y: p.y, s: p.s, t: 0, near: !atFar, word: Math.floor(Math.random() * 4) };
  G.hen = null;
  feathers(p.x, p.y, atFar ? 40 : 90, atFar ? 220 : 520);
  if (!atFar) G.shake = 0.5;
  G[who]++;
  G.held = null; G.state = 'goal'; G.timer = 1.9;
  G.flash = 'goal'; G.flashT = 1.9; G.flashWho = who;
  G.turn = who;                                       // le marqueur récupère la poule et relance
  sndGoal(!atFar);
}

function tryCatch() {
  const h = G.hen;
  if (!G || G.state !== 'flight' || !h || h.dir > 0 || G.cooldown > 0) return;
  const m = G.mouse;
  const ok = canCatch(h, m.x, m.y, HB);
  if (ok) {
    G.held = { x: h.x, y: h.y, z: 0.02 }; G.hen = null; G.state = 'caught'; G.timer = 0.4; G.turn = 'me'; sndCatch();
    if (G.mode === 'net') { G.localResolved = true; net.send({ t: 'catch', x: +m.x.toFixed(3), y: +m.y.toFixed(3) }); }     // le serveur vérifie que la capture était possible
    G.flash = 'caught'; G.flashT = 0.6; G.flashWho = 'me';
  } else {
    G.cooldown = 0.5;           // trop tôt : les mains se ferment dans le vide
    beep(120, 0.1, 'square', 0.04);
  }
}

const startX = () => clamp(G.mouse.x, -1, 1) * 0.8;

function update(dt) {
  G.cooldown = Math.max(0, G.cooldown - dt);
  G.flashT = Math.max(0, G.flashT - dt);
  G.time += dt;
  G.oppPose = Math.max(0, G.oppPose - dt);
  G.shake = Math.max(0, G.shake - dt);
  const m = G.mouse;
  m.vx *= Math.pow(0.02, dt);                         // amortit la vitesse de la souris
  if (G.mode === 'net') G.oppX += (G.oppXt - G.oppX) * Math.min(1, dt * 8);   // l'adversaire glisse sur sa ligne

  G.particles.forEach((p) => {
    p.x += p.vx * dt; p.y += p.vy * dt; p.vy += 420 * dt; p.vx *= Math.pow(0.35, dt);
    p.rot += p.vr * dt; p.life -= dt;
  });
  G.particles = G.particles.filter((p) => p.life > 0);
  G.bounceFx.forEach((b) => (b.t -= dt));
  G.bounceFx = G.bounceFx.filter((b) => b.t > 0);
  if (G.splat) G.splat.t += dt;

  if (G.state === 'serve') {
    G.timer += dt;
    if (G.mode === 'ai' && G.turn === 'opp' && G.timer > 0.7) aiThrow();
    if (G.mode === 'net' && G.turn === 'opp' && G.pending) { launchNet(G.pending); G.pending = null; }
  } else if (G.state === 'caught') {
    G.timer -= dt;
    if (G.timer <= 0) { G.state = 'serve'; G.timer = 0; }
  } else if (G.state === 'goal') {
    G.timer -= dt;
    if (G.timer <= 0) {
      if (matchOver()) return finish();
      G.state = 'serve'; G.timer = 0; G.splat = null;
    }
  } else if (G.state === 'flight') {
    const h = G.hen;
    const waiting = G.mode === 'net' && h.dir > 0 && h.z >= 1;      // arrivée : on attend le verdict du défenseur
    const hit = waiting ? null : step(h, dt);
    if (hit) bounceFx(h, hit);
    if (waiting) h.z = 1;

    if (G.mode === 'net') {
      if (h.dir < 0 && h.z <= 0) {                                   // je n'ai pas rattrapé : but encaissé
        h.z = 0; G.localResolved = true; net.send({ t: 'miss' }); goal('opp');
      }
    } else if (h.dir > 0 && h.z >= 1) {
      // l'IA tente de rattraper : plus c'est vite, tordu et rebondissant, plus c'est dur
      const p = clamp(G.level.base - 0.25 * (h.speed - 0.7) - 0.2 * Math.min(1, Math.abs(h.spin)) - 0.05 * h.bounces, 0.1, 0.95);
      if (Math.random() < p) {
        G.held = { x: h.x, y: h.y, z: 1 }; G.hen = null; G.state = 'caught'; G.timer = 0.55; G.turn = 'opp'; G.oppPose = 0.5;
        G.flash = 'caught'; G.flashT = 0.7; G.flashWho = 'opp'; sndCatch();
      } else {
        h.z = 1; goal('me');
      }
    } else if (h.dir < 0 && h.z <= 0) {
      h.z = 0; goal('opp');
    }
  }
}

async function finish() {
  G.state = 'over';
  const won = G.me > G.opp;
  $('overTitle').textContent = won ? t('over.win') : t('over.lose');
  $('overScore').textContent = `${G.name} ${G.me} – ${G.opp} ${G.mode === 'net' ? G.oppName : t('level' + G.levelIdx)}`;
  $('overMsg').textContent = G.mode === 'net' ? netOverMsg() : t('over.saving');
  $('over').hidden = false;
  won ? sndWin() : beep(100, 0.5, 'sawtooth', 0.08);
  if (G.mode === 'net') return;
  // Le score est accompagné du ticket signé reçu au début de la partie (voir requestTicket) : sans lui, le serveur refuse.
  const ticket = await G.ticket;
  try {
    const r = await fetch('api.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ticket, name: G.name, me: G.me, opp: G.opp }),
    });
    let err = ''; if (!r.ok) { try { err = (await r.json()).error || ''; } catch {} }
    $('overMsg').textContent = r.ok ? t('over.saved')
      : r.status === 429 ? t('over.notSavedRate')
      : err === 'name_taken' ? t('over.nameTaken')
      : t('over.notSaved');
    if (r.ok) loadBoard();
  } catch { $('overMsg').textContent = t('over.notSaved2'); }
}

// Ticket de partie : délivré par le serveur au début d'une partie contre l'IA, signé, à usage unique.
function requestTicket(level) {
  return fetch('api.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'start', token: playerToken(), level }),
  }).then((r) => (r.ok ? r.json() : null)).then((j) => (j && j.ticket) || null).catch(() => null);
}

function rng(seed) {                                  // générateur déterministe : décor identique à chaque partie
  return () => { seed |= 0; seed = (seed + 0x6D2B79F5) | 0; let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
}

// Le tunnel est statique : on le dessine une seule fois dans un canvas hors écran.
function buildTunnel() {
  const o = document.createElement('canvas'); o.width = W; o.height = H;
  const c = o.getContext('2d'), R = rng(7);
  c.lineCap = 'round'; c.lineJoin = 'round';
  c.fillStyle = '#fbfbf8'; c.fillRect(0, 0, W, H);
  const Z = (D) => (D - 1) / 3;                       // D = distance (1 → 4), pour un grillage uniforme en profondeur
  const surf = {
    left:  (D, v) => proj(-1, v, Z(D)),
    right: (D, v) => proj(1, v, Z(D)),
    ceil:  (D, v) => proj(v, -1, Z(D)),
  };
  // grillage : petits "Y" gris tracés sur les murs et le plafond
  c.strokeStyle = '#b3b9be';
  for (const k of ['left', 'right', 'ceil']) {
    for (let D = 0.5; D < 4; D += 0.1) {
      for (let v = -1.25; v < 1.25; v += 0.2) {
        const d0 = D + (R() - 0.5) * 0.05, v0 = v + (R() - 0.5) * 0.08, th = R() * 6.28;
        const p = (d, vv) => surf[k](d, vv);
        const a = p(d0, v0);
        const d1 = d0 + Math.cos(th) * 0.05, v1 = v0 + Math.sin(th) * 0.09;
        const b = p(d1, v1);
        const t2 = th + 0.55 + R() * 0.3, t3 = th - 0.55 - R() * 0.3;
        const e = p(d1 + Math.cos(t2) * 0.035, v1 + Math.sin(t2) * 0.065);
        const f = p(d1 + Math.cos(t3) * 0.035, v1 + Math.sin(t3) * 0.065);
        c.lineWidth = Math.max(0.7, 2.1 / D);
        c.beginPath(); c.moveTo(a.x, a.y); c.lineTo(b.x, b.y); c.lineTo(e.x, e.y); c.moveTo(b.x, b.y); c.lineTo(f.x, f.y); c.stroke();
      }
    }
  }
  // sol : chemin de terre / paille beige aux bords irréguliers
  const wf = (D) => 0.86 - 0.16 * (D - 1);
  const pts = [];
  for (let D = 0.5; D <= 4.01; D += 0.1) pts.push(proj(-wf(D) + (R() - 0.5) * 0.08, 1, Z(D)));
  for (let D = 4; D >= 0.49; D -= 0.1) pts.push(proj(wf(D) + (R() - 0.5) * 0.08, 1, Z(D)));
  c.fillStyle = '#c9bd93';
  c.beginPath(); pts.forEach((p, i) => (i ? c.lineTo(p.x, p.y) : c.moveTo(p.x, p.y))); c.closePath(); c.fill();
  // taches de terre, brins de paille
  for (let i = 0; i < 26; i++) {
    const D = 0.6 + R() * 3.3, x = (R() - 0.5) * 1.5 * wf(D), p = proj(x, 1, Z(D));
    c.fillStyle = R() < 0.5 ? '#b3a678' : '#d9cfae';
    c.beginPath(); c.ellipse(p.x, p.y, (30 + R() * 60) * p.s, (6 + R() * 12) * p.s, 0, 0, 7); c.fill();
  }
  c.strokeStyle = '#e6dcb8';
  for (let i = 0; i < 60; i++) {
    const D = 0.6 + R() * 3.3, x = (R() - 0.5) * 1.6 * wf(D), p = proj(x, 1, Z(D)), L = (10 + R() * 26) * p.s;
    c.lineWidth = Math.max(1, 3 * p.s); c.beginPath(); c.moveTo(p.x, p.y); c.lineTo(p.x + L, p.y - L * 0.15 * (R() - 0.5)); c.stroke();
  }
  // bouts de coquille / plumes blanches posés au sol
  for (let i = 0; i < 7; i++) {
    const D = 0.7 + R() * 2.6, x = (R() - 0.5) * 1.3 * wf(D), p = proj(x, 1, Z(D));
    c.fillStyle = '#fff'; c.strokeStyle = '#888'; c.lineWidth = Math.max(1, 2 * p.s);
    c.beginPath(); c.ellipse(p.x, p.y, 20 * p.s + 3, 7 * p.s + 1.5, (R() - 0.5) * 0.6, 0, 7); c.fill(); c.stroke();
  }
  // poutres du plafond (grises, doubles traits) et cadre en bois au fond
  const grey = '#9aa0a6', beam = (sg) => {
    const pt = (z, dx) => proj(sg * (1 - 0.58 * z) + dx, -1 + 0.38 * z, z);
    const a0 = pt(-0.12, 0), a1 = pt(0.93, 0), b1 = pt(0.93, -sg * 0.09), b0 = pt(-0.12, -sg * 0.18);
    c.fillStyle = '#fbfbf8'; c.strokeStyle = grey; c.lineWidth = 4;
    c.beginPath(); c.moveTo(a0.x, a0.y); c.lineTo(a1.x, a1.y); c.lineTo(b1.x, b1.y); c.lineTo(b0.x, b0.y); c.closePath(); c.fill(); c.stroke();
  };
  beam(-1); beam(1);
  const fx = 0.42, fy = -0.6, FZ = 0.93;
  const tl = proj(-fx, fy, FZ), tr = proj(fx, fy, FZ), bl = proj(-fx, 1, FZ), br = proj(fx, 1, FZ);
  c.fillStyle = '#fbfbf8'; c.strokeStyle = grey; c.lineWidth = 4;
  c.fillRect(tl.x - 8, tl.y - 10, tr.x - tl.x + 16, 16); c.strokeRect(tl.x - 8, tl.y - 10, tr.x - tl.x + 16, 16);
  [[tl, bl], [tr, br]].forEach(([t, b]) => {
    c.fillRect(t.x - 9, t.y, 18, b.y - t.y); c.strokeRect(t.x - 9, t.y, 18, b.y - t.y);
    c.beginPath(); c.arc(t.x, t.y + 6, 12, 0, 7); c.stroke();                   // nœud de la corde
    c.beginPath(); c.moveTo(t.x - 9, t.y + 24); c.lineTo(t.x + 9, t.y + 36); c.stroke();
  });
  return o;
}

function drawTunnel() {
  G.bg = G.bg || buildTunnel();
  ctx.drawImage(G.bg, 0, 0);
}

// ----- dessins vectoriels -----
function drawHand(c, x, y, size, closed = false, alpha = 1, flip = 1) {   // silhouette noire, comme dans l'original
  c.save(); c.globalAlpha = alpha; c.translate(x, y); c.scale(size / 100 * flip, size / 100);
  c.fillStyle = '#000'; c.strokeStyle = '#000'; c.lineCap = 'round';
  if (closed) {
    c.beginPath(); c.ellipse(0, 4, 42, 38, 0, 0, 7); c.fill();
    for (let i = 0; i < 4; i++) { c.beginPath(); c.arc(-27 + i * 18, -30 + Math.abs(i - 1.5) * 4, 11, 0, 7); c.fill(); }
    c.beginPath(); c.ellipse(-40, 12, 12, 22, 0.5, 0, 7); c.fill();
  } else {
    c.beginPath(); c.ellipse(0, 18, 34, 38, 0, 0, 7); c.fill();
    const L = [50, 60, 56, 44];
    for (let i = 0; i < 4; i++) {
      const a = -0.34 + i * 0.22, x0 = -22 + i * 14;
      c.lineWidth = 17; c.beginPath(); c.moveTo(x0, 4); c.lineTo(x0 + Math.sin(a) * L[i], 4 - Math.cos(a) * L[i]); c.stroke();
    }
    c.lineWidth = 19; c.beginPath(); c.moveTo(-26, 30); c.lineTo(-54, 2); c.stroke();
  }
  c.restore();
}

function drawHenArt(c, x, y, R, rot = 0, view = 'front', o = {}) {
  const lw = Math.max(1.4, R * 0.05), spread = o.spread || 0, flap = o.flap ?? 0.5;
  c.save(); c.globalAlpha = o.alpha ?? 1; c.translate(x, y); c.rotate(rot); c.scale(o.sx || 1, o.sy || 1);
  c.lineJoin = 'round'; c.lineCap = 'round'; c.strokeStyle = '#000'; c.fillStyle = '#fff';
  // pattes (derrière le corps)
  for (const sg of [-1, 1]) {
    const fx = sg * (0.3 + spread * 0.5) * R, fy = (0.88 + spread * 0.05) * R;
    for (const [col, w] of [['#000', lw * 3.3], ['#ffa200', lw * 1.7]]) {
      c.strokeStyle = col; c.lineWidth = w;
      c.beginPath();
      for (const a of [-0.65, 0, 0.65]) { const aa = a + sg * spread * 0.7; c.moveTo(fx, fy); c.lineTo(fx + Math.sin(aa) * 0.34 * R, fy + Math.cos(aa) * 0.3 * R); }
      c.stroke();
    }
  }
  c.strokeStyle = '#000'; c.lineWidth = lw;
  // ailes : touffes festonnées, écartées quand la poule fonce sur l'écran
  for (const sg of [-1, 1]) {
    c.save(); c.translate(sg * 0.9 * R, -0.08 * R); c.rotate(sg * (0.35 + flap * 0.8 + spread * 0.4));
    c.fillStyle = '#fff'; c.beginPath();
    const W2 = (0.38 + spread * 0.25) * R;
    c.ellipse(sg * W2 * 0.6, 0, W2, 0.2 * R, 0, 0, 7); c.fill(); c.stroke();
    for (const k of [-1, 0, 1]) { c.beginPath(); c.ellipse(sg * (W2 * 1.35), k * 0.15 * R, 0.14 * R, 0.1 * R, 0, 0, 7); c.fill(); c.stroke(); }
    c.restore();
  }
  // corps
  c.fillStyle = '#fff'; c.beginPath(); c.ellipse(0, 0, R, 0.95 * R, 0, 0, 7); c.fill(); c.stroke();
  // crête
  c.beginPath(); c.moveTo(-0.2 * R, -0.88 * R); c.lineTo(-0.15 * R, -1.18 * R); c.lineTo(-0.03 * R, -0.96 * R);
  c.lineTo(0.08 * R, -1.24 * R); c.lineTo(0.17 * R, -0.95 * R); c.lineTo(0.27 * R, -1.1 * R); c.lineTo(0.3 * R, -0.82 * R);
  c.closePath(); c.fill(); c.stroke();
  if (view === 'front') {
    for (const ex of [-0.3 * R, 0.28 * R]) {
      if (o.dizzy) {
        c.fillStyle = '#000'; c.beginPath(); c.arc(ex, -0.38 * R, 0.15 * R, 0, 7); c.fill();
        c.fillStyle = '#fff'; c.beginPath(); c.arc(ex, -0.38 * R, 0.1 * R, 0, 7); c.fill();
        c.fillStyle = '#000'; c.beginPath(); c.arc(ex, -0.38 * R, 0.045 * R, 0, 7); c.fill();
      } else {                                         // yeux ronds affolés
        c.fillStyle = '#fff'; c.beginPath(); c.arc(ex, -0.4 * R, 0.13 * R, 0, 7); c.fill(); c.stroke();
        c.fillStyle = '#000'; c.beginPath(); c.arc(ex, -0.4 * R, 0.04 * R, 0, 7); c.fill();
      }
    }
    c.fillStyle = '#ffa200';
    c.beginPath(); c.moveTo(-0.2 * R, -0.2 * R);
    c.bezierCurveTo(-0.2 * R, -0.02 * R, -0.06 * R, 0.16 * R, 0, 0.16 * R);
    c.bezierCurveTo(0.06 * R, 0.16 * R, 0.2 * R, -0.02 * R, 0.2 * R, -0.2 * R);
    c.bezierCurveTo(0.1 * R, -0.28 * R, -0.1 * R, -0.28 * R, -0.2 * R, -0.2 * R);
    c.fill(); c.stroke();
    c.fillStyle = '#000'; c.beginPath(); c.arc(-0.05 * R, -0.12 * R, 0.018 * R, 0, 7); c.arc(0.05 * R, -0.12 * R, 0.018 * R, 0, 7); c.fill();
  } else {                                             // de dos : plis des ailes et queue
    c.beginPath(); c.arc(-0.4 * R, 0.15 * R, 0.5 * R, -0.4, 1.1); c.stroke();
    c.beginPath(); c.arc(0.4 * R, 0.15 * R, 0.5 * R, Math.PI - 1.1, Math.PI + 0.4); c.stroke();
    c.beginPath(); c.moveTo(-0.14 * R, 0.8 * R); c.lineTo(-0.05 * R, 1.08 * R); c.lineTo(0.03 * R, 0.86 * R);
    c.lineTo(0.12 * R, 1.1 * R); c.lineTo(0.18 * R, 0.8 * R); c.fill(); c.stroke();
  }
  c.restore();
}

function drawChar(c, kind, x, y, h, raise = true, alpha = 1) {   // x,y = pieds ; h = hauteur
  const lw = Math.max(1, h * 0.03);
  const P = [
    { skin: '#e9b48e', top: '#b5533c', bot: '#3a5fa0' },       // fermier
    { skin: '#f4a6b7', top: '#e8c53a', bot: '#3a5fa0' },       // cochon
    { skin: '#e3b08a', top: '#222',    bot: '#444' },          // punk
    { skin: '#f0c9a8', top: '#8a5aa8', bot: '#8a5aa8' },       // mamie
  ][kind];
  c.save(); c.globalAlpha = alpha; c.lineJoin = 'round'; c.lineCap = 'round'; c.lineWidth = lw; c.strokeStyle = '#000';
  const hy = y - 0.68 * h;                                       // centre de la tête
  // jambes + chaussures
  for (const sg of [-1, 1]) {
    c.fillStyle = P.bot; c.beginPath(); c.roundRect(x + sg * 0.085 * h - 0.055 * h, y - 0.2 * h, 0.11 * h, 0.17 * h, 2); c.fill(); c.stroke();
    c.fillStyle = '#222'; c.beginPath(); c.ellipse(x + sg * 0.09 * h + sg * 0.02 * h, y - 0.02 * h, 0.075 * h, 0.04 * h, 0, 0, 7); c.fill(); c.stroke();
  }
  // bras levés (ou le long du corps)
  for (const sg of [-1, 1]) {
    const hx = x + sg * (raise ? 0.32 : 0.25) * h, hyy = y - (raise ? 0.9 : 0.28) * h;
    c.strokeStyle = '#000'; c.lineWidth = 0.085 * h; c.beginPath(); c.moveTo(x + sg * 0.17 * h, y - 0.5 * h); c.lineTo(hx, hyy); c.stroke();
    c.strokeStyle = P.top; c.lineWidth = 0.06 * h; c.beginPath(); c.moveTo(x + sg * 0.17 * h, y - 0.5 * h); c.lineTo(hx, hyy); c.stroke();
    c.strokeStyle = '#000'; c.lineWidth = lw; c.fillStyle = P.skin; c.beginPath(); c.arc(hx, hyy, 0.05 * h, 0, 7); c.fill(); c.stroke();
  }
  // buste
  c.fillStyle = P.top; c.beginPath(); c.roundRect(x - 0.19 * h, y - 0.55 * h, 0.38 * h, 0.37 * h, 0.05 * h); c.fill(); c.stroke();
  if (kind === 0) {                                              // salopette
    c.fillStyle = P.bot; c.beginPath(); c.roundRect(x - 0.11 * h, y - 0.46 * h, 0.22 * h, 0.28 * h, 2); c.fill(); c.stroke();
    c.beginPath(); c.moveTo(x - 0.11 * h, y - 0.46 * h); c.lineTo(x - 0.13 * h, y - 0.55 * h); c.moveTo(x + 0.11 * h, y - 0.46 * h); c.lineTo(x + 0.13 * h, y - 0.55 * h); c.stroke();
  } else if (kind === 2) {                                       // veste cloutée
    c.fillStyle = '#aaa';
    for (let i = 0; i < 5; i++) { c.beginPath(); c.arc(x - 0.14 * h + i * 0.07 * h, y - 0.52 * h, 0.014 * h, 0, 7); c.fill(); }
    c.beginPath(); c.moveTo(x, y - 0.55 * h); c.lineTo(x, y - 0.18 * h); c.stroke();
  } else if (kind === 3) {                                       // tablier
    c.fillStyle = '#fff'; c.beginPath(); c.roundRect(x - 0.12 * h, y - 0.42 * h, 0.24 * h, 0.24 * h, 3); c.fill(); c.stroke();
  }
  // tête
  c.fillStyle = P.skin;
  if (kind === 1) {                                              // oreilles de cochon
    for (const sg of [-1, 1]) { c.beginPath(); c.moveTo(x + sg * 0.06 * h, hy - 0.11 * h); c.lineTo(x + sg * 0.17 * h, hy - 0.2 * h); c.lineTo(x + sg * 0.14 * h, hy - 0.04 * h); c.closePath(); c.fill(); c.stroke(); }
  }
  if (kind === 2) {                                              // crête verte
    c.fillStyle = '#4cc04c';
    for (let i = -2; i <= 2; i++) { c.beginPath(); c.moveTo(x + i * 0.04 * h - 0.025 * h, hy - 0.1 * h); c.lineTo(x + i * 0.04 * h, hy - 0.26 * h + Math.abs(i) * 0.03 * h); c.lineTo(x + i * 0.04 * h + 0.025 * h, hy - 0.1 * h); c.fill(); c.stroke(); }
    c.fillStyle = P.skin;
  }
  c.beginPath(); c.arc(x, hy, 0.14 * h, 0, 7); c.fill(); c.stroke();
  if (kind === 0) {                                              // casquette
    c.fillStyle = '#6b7a3a'; c.beginPath(); c.arc(x, hy - 0.01 * h, 0.145 * h, Math.PI, 0); c.closePath(); c.fill(); c.stroke();
    c.beginPath(); c.ellipse(x + 0.1 * h, hy - 0.01 * h, 0.1 * h, 0.025 * h, 0, 0, 7); c.fill(); c.stroke();
  }
  if (kind === 3) {                                              // chignon gris
    c.fillStyle = '#c9c9c9'; c.beginPath(); c.arc(x, hy - 0.16 * h, 0.06 * h, 0, 7); c.fill(); c.stroke();
    c.beginPath(); c.arc(x, hy - 0.01 * h, 0.145 * h, Math.PI * 1.05, Math.PI * 1.95); c.fill(); c.stroke();
  }
  // visage patibulaire
  c.fillStyle = '#000';
  for (const sg of [-1, 1]) { c.beginPath(); c.arc(x + sg * 0.05 * h, hy + 0.01 * h, 0.017 * h, 0, 7); c.fill(); }
  c.lineWidth = lw * 1.2;
  c.beginPath(); c.moveTo(x - 0.085 * h, hy - 0.035 * h); c.lineTo(x - 0.02 * h, hy - 0.01 * h); c.moveTo(x + 0.085 * h, hy - 0.035 * h); c.lineTo(x + 0.02 * h, hy - 0.01 * h); c.stroke();
  if (kind === 1) {                                              // groin
    c.fillStyle = '#e88aa0'; c.beginPath(); c.ellipse(x, hy + 0.06 * h, 0.065 * h, 0.045 * h, 0, 0, 7); c.fill(); c.stroke();
    c.fillStyle = '#000'; c.beginPath(); c.arc(x - 0.02 * h, hy + 0.06 * h, 0.01 * h, 0, 7); c.arc(x + 0.02 * h, hy + 0.06 * h, 0.01 * h, 0, 7); c.fill();
  } else {
    c.beginPath(); c.moveTo(x - 0.04 * h, hy + 0.08 * h); c.lineTo(x + 0.04 * h, hy + 0.075 * h); c.stroke();
    if (kind === 0) { c.fillStyle = '#3a2a1a'; c.beginPath(); c.ellipse(x, hy + 0.055 * h, 0.06 * h, 0.018 * h, 0, 0, 7); c.fill(); }   // moustache
  }
  if (kind === 3) {                                              // lunettes
    c.lineWidth = lw; c.beginPath(); c.arc(x - 0.05 * h, hy + 0.01 * h, 0.036 * h, 0, 7); c.arc(x + 0.05 * h, hy + 0.01 * h, 0.036 * h, 0, 7); c.stroke();
  }
  c.restore();
}

// ----- scène -----

function drawHenInFlight() {
  const h = G.hen;
  if (!h) return;
  const p = proj(h.x, h.y, h.z), sh = proj(h.x, 1, h.z);
  c2shadow(sh);
  const front = h.dir < 0;                             // elle fonce vers nous : pleine face, ailes écartées
  const flap = 0.5 + 0.5 * Math.sin(G.time * 16);
  drawHenArt(ctx, p.x, p.y, E.henRadius(h.z, HB), Math.sin(h.rot) * 0.35, front ? 'front' : 'back', { spread: front ? 0.55 : 0, flap });
}
function c2shadow(sh) {
  ctx.fillStyle = '#6a5d3a55';
  ctx.beginPath(); ctx.ellipse(sh.x, sh.y, 70 * LS * HB * sh.s * 1.3, 14 * LS * HB * sh.s * 1.3, 0, 0, 7); ctx.fill();
}

// poule tenue dans deux mains (après un rattrapage ou en attente de lancer)
function drawHeld(x, y, z, alpha = 1) {
  const p = proj(x, y, z), R = E.henRadius(z, HB) * 0.8;
  drawHenArt(ctx, p.x, p.y, R, 0, z < 0.5 ? 'back' : 'front', { alpha, spread: 0.3 });
  drawHand(ctx, p.x - R * 0.95, p.y + R * 0.25, R * 1.2, true, alpha, 1);
  drawHand(ctx, p.x + R * 0.95, p.y + R * 0.25, R * 1.2, true, alpha, -1);
}

// trajectoire prévue (rebonds compris), pointillés orange dans le tunnel
function drawPreview(hen) {
  const c = { ...hen };
  ctx.fillStyle = '#ff9a00'; ctx.strokeStyle = '#000'; ctx.lineWidth = 1.5;
  for (let i = 0; i < 400 && c.z < 1; i++) {
    const hit = step(c, 1 / 120);
    if (i % 6 === 0 || hit) {
      const p = proj(c.x, c.y, c.z);
      ctx.beginPath(); ctx.arc(p.x, p.y, (hit ? 7 : 4) * p.s * 1.4 + 1.5, 0, 7); ctx.fill(); ctx.stroke();
    }
  }
}

function burst(x, y, r, spikes, col, alpha) {
  ctx.save(); ctx.globalAlpha = alpha; ctx.fillStyle = col; ctx.strokeStyle = '#000'; ctx.lineWidth = 5; ctx.lineJoin = 'round';
  ctx.beginPath();
  for (let i = 0; i < spikes * 2; i++) { const a = i * Math.PI / spikes, rr = i % 2 ? r * 0.62 : r; ctx.lineTo(x + Math.cos(a) * rr, y + Math.sin(a) * rr * 0.8); }
  ctx.closePath(); ctx.fill(); ctx.stroke(); ctx.restore();
}

// la poule s'écrase "contre la vitre" : aplatie, pattes écartées, étourdie
function drawSplat() {
  const s = G.splat;
  if (!s) return;
  const k = Math.min(1, s.t / 0.1), settle = Math.max(0, 1 - s.t / 0.45);      // impact instantané puis léger rebond
  if (s.near) {
    const x = clamp(s.x, 150, W - 150), y = clamp(s.y, 150, H - 130);
    const a = clamp(1.25 - s.t * 0.55, 0.35, 1);
    burst(x, y, 300 * LS * k, 11, '#fff3b0', Math.min(1, 1.6 - s.t * 2.6) * 0.9);
    // coulure sur la vitre
    ctx.fillStyle = `rgba(245,240,215,${0.55 * a})`;
    ctx.beginPath(); ctx.ellipse(x, y + 20, 330 * k, 250 * k, 0, 0, 7); ctx.fill();
    drawHenArt(ctx, x, y, 240 * LS, 0, 'front', { sx: 1.25 + 0.35 * settle, sy: 0.72 - 0.25 * settle * k, dizzy: true, spread: 1, alpha: a });
    ctx.save(); ctx.translate(clamp(CX + 250, 170, W - 170), 175); ctx.rotate(-0.12);          // toujours entièrement dans le canvas ctx.globalAlpha = clamp(1.4 - s.t * 1.6, 0, 1);
    ctx.font = '900 64px Impact, "Arial Black", sans-serif'; ctx.lineWidth = 10; ctx.lineJoin = 'round';
    ctx.strokeStyle = '#000'; ctx.fillStyle = '#ff4d2e'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.strokeText(t('splat' + s.word), 0, 0); ctx.fillText(t('splat' + s.word), 0, 0); ctx.restore();
  } else {
    burst(s.x, s.y, 80 * LS * k, 9, '#fff3b0', Math.min(1, 1.6 - s.t * 2.6) * 0.9);
    drawHenArt(ctx, s.x, s.y, 36, 0, 'front', { sx: 1.3, sy: 0.65, dizzy: true, spread: 1 });
  }
}

function drawParticles() {
  G.particles.forEach((p) => {                         // plumes blanches cernées de gris
    ctx.save(); ctx.globalAlpha = clamp(p.life / 0.6, 0, 1);
    ctx.translate(p.x, p.y); ctx.rotate(p.rot); ctx.fillStyle = '#fff'; ctx.strokeStyle = '#666'; ctx.lineWidth = 1.6;
    ctx.beginPath(); ctx.ellipse(0, 0, p.sz, p.sz * 0.3, 0, 0, 7); ctx.fill(); ctx.stroke();
    ctx.restore();
  });
  G.bounceFx.forEach((b) => {
    ctx.strokeStyle = `rgba(40,40,40,${b.t / 0.3})`; ctx.lineWidth = 3;
    ctx.beginPath(); ctx.arc(b.x, b.y, (0.3 - b.t) * 220 * b.s + 6, 0, 7); ctx.stroke();
  });
}

const FONT = 'Impact, "Arial Black", system-ui, sans-serif';
function digits(x, y, n, align) {                      // score dans une case noire (comme l'original)
  const w = 64, h = 42, x0 = align === 'right' ? x - w : x;
  ctx.fillStyle = '#000'; ctx.fillRect(x0, y, w, h);
  ctx.fillStyle = '#fff'; ctx.font = `bold 32px ${FONT}`; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
  ctx.fillText(String(n).padStart(2, '0'), x0 + w / 2, y + h / 2 + 2);
}
function tag(x, y, text, align, size = 26) {
  ctx.font = `bold ${size}px ${FONT}`;
  const h = Math.round(size * 1.23), w = ctx.measureText(text).width + 22, x0 = align === 'right' ? x - w : x;
  ctx.fillStyle = '#d5121b'; ctx.fillRect(x0, y, w, h);
  ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(text, x0 + w / 2, y + h / 2 + 1);
}

function drawActors() {
  // adversaire au fond du couloir : glisse sur sa ligne comme un pantin de carton
  const far = proj(G.oppX, 1, 0.93);
  const up = G.oppPose > 0 || (G.state === 'flight' && G.hen && G.hen.dir > 0);
  drawChar(ctx, G.oppKind, far.x, far.y, 560 * LS * far.s, up);
  if (G.state === 'serve' && G.turn === 'opp') drawHeld(G.oppX, 0.3, 0.93, 0.95);
  if (G.state === 'serve' && G.turn === 'me') drawHeld(G.hold ? G.hold.sx : startX(), 0.6, 0.02);
  if (G.state === 'caught' && G.held) drawHeld(G.held.x, G.held.y, G.held.z);

}

// Texte du HUD qui tient dans maxW (on réduit la police, puis on tronque) : un pseudo de 15 lettres ne doit pas
// passer sous les boutons Quitter / plein écran, placés en haut au centre sur mobile.
function fitText(text, x, y, maxW, start = 15) {
  let size = start;
  ctx.font = `bold ${size}px ${FONT}`;
  while (size > 11 && ctx.measureText(text).width > maxW) { size--; ctx.font = `bold ${size}px ${FONT}`; }
  if (ctx.measureText(text).width > maxW) {
    while (text.length > 1 && ctx.measureText(text + '…').width > maxW) text = text.slice(0, -1);
    text += '…';
  }
  ctx.fillText(text, x, y);
}

function drawHUD() {
  // scores : étiquettes rouges + cases noires
  if (PORTRAIT) {                                       // portrait : scores en haut, noms sous les cases (la barre du haut reste libre)
    tag(14, 12, t('tag.you'), 'left', 20); digits(14, 44, G.me, 'left');
    tag(W - 14, 12, t('tag.opp'), 'right', 20); digits(W - 14, 44, G.opp, 'right');
    ctx.fillStyle = '#333'; ctx.textBaseline = 'top';
    ctx.textAlign = 'left'; fitText(G.name.toUpperCase(), 14, 92, 190, 17);
    ctx.textAlign = 'right'; fitText((G.oppName || t('char' + G.oppKind)).toUpperCase(), W - 14, 92, 190, 17);
  } else {
    tag(14, 12, t('tag.you'), 'left'); digits(14, 48, G.me, 'left');
    ctx.fillStyle = '#333'; ctx.textAlign = 'left'; ctx.textBaseline = 'top'; fitText(G.name.toUpperCase(), 84, 56, 160);
    tag(W - 14, 12, t('tag.opp'), 'right'); digits(W - 14, 48, G.opp, 'right');
    ctx.textAlign = 'right'; ctx.fillStyle = '#333'; fitText((G.oppName || t('char' + G.oppKind)).toUpperCase(), W - 84, 56, 140);
  }
  ctx.textAlign = 'left'; ctx.fillStyle = '#999'; ctx.font = `${PORTRAIT ? 18 : 13}px system-ui`; ctx.textBaseline = 'bottom';
  ctx.fillText(t('game.rule'), 12, H - 8);

  // la main : curseur noir ; poing fermé après un clic raté
  const m = G.mouse, cp = proj(m.x, m.y, 0), h = G.hen;
  const defending = G.state === 'flight' && h && h.dir < 0;
  const touchUI = m.touch || COARSE;                      // interface au doigt : pas de main de souris au repos, textes tactiles
  if (G.hold) {
    const pw = power(G.hold.t0), tgt = aimPoint();
    drawPreview(makeHenAim(G.hold.sx, 0.6, tgt, flickSpin(), 0.7 + pw * 0.9));
    // viseur : point visé sur le mur, le plafond, le sol ou le fond
    const tp = proj(tgt.x, tgt.y, tgt.z);
    ctx.strokeStyle = '#d5121b'; ctx.lineWidth = 3;
    ctx.beginPath(); ctx.arc(tp.x, tp.y, 18 * tp.s + 7, 0, 7);
    ctx.moveTo(tp.x - 26 * tp.s - 10, tp.y); ctx.lineTo(tp.x + 26 * tp.s + 10, tp.y);
    ctx.moveTo(tp.x, tp.y - 26 * tp.s - 10); ctx.lineTo(tp.x, tp.y + 26 * tp.s + 10); ctx.stroke();
    if (m.touch) {                                       // où est le doigt : bague + trait vers le viseur
      ctx.strokeStyle = '#000a'; ctx.lineWidth = 3; ctx.setLineDash([5, 5]);
      ctx.beginPath(); ctx.arc(m.px, m.py, 26, 0, 7); ctx.moveTo(m.px, m.py - 26); ctx.lineTo(m.px, m.py - E.TOUCH_AIM_OFFSET + 18); ctx.stroke(); ctx.setLineDash([]);
    }
    const gw = PORTRAIT ? 300 : 240, gh = PORTRAIT ? 30 : 22, gy = H - gh - 16;
    ctx.fillStyle = '#fff'; ctx.fillRect(CX - gw / 2, gy, gw, gh);
    ctx.fillStyle = '#ff9a00'; ctx.fillRect(CX - gw / 2, gy, gw * pw, gh);
    ctx.strokeStyle = '#000'; ctx.lineWidth = 3; ctx.strokeRect(CX - gw / 2, gy, gw, gh);
  } else if (defending || G.state === 'caught') {
    // au doigt, la main n'apparaît que là où l'on vient de taper (poing fermé si raté, ou poule attrapée)
    if (!touchUI || G.cooldown > 0 || G.state === 'caught') drawHand(ctx, cp.x, cp.y, 150 * (1 + 0.3 * (h ? chaos(h) : 0)), G.cooldown > 0 || G.state === 'caught');
  } else if (!touchUI) {
    drawHand(ctx, cp.x, cp.y, 90, false, 0.85);
  }
  ctx.textBaseline = 'middle'; ctx.textAlign = 'center'; ctx.font = `bold ${PORTRAIT ? 27 : 22}px ${FONT}`; ctx.fillStyle = '#222';
  if (G.state === 'serve') {
    ctx.fillText(G.turn === 'me'
      ? (G.hold ? t('hint.aim') : t(touchUI ? 'hint.serve.touch' : 'hint.serve'))
      : t('hint.wait'), CX, H - (PORTRAIT ? 86 : 62));
  } else if (G.state === 'flight' && G.hen.dir < 0) {
    ctx.fillText(t(touchUI ? 'hint.catch.touch' : 'hint.catch'), CX, H - (PORTRAIT ? 86 : 62));
  }
  if (G.flashT > 0) {
    ctx.save(); ctx.translate(CX, CY - 40);
    const goalMsg = G.flash === 'goal';
    const k = 1 + (goalMsg && G.flashT > 1.6 ? (G.flashT - 1.6) * 3 : 0);
    ctx.scale(k, k); ctx.rotate(goalMsg ? -0.06 : 0);
    ctx.font = goalMsg ? `110px ${FONT}` : `60px ${FONT}`;
    ctx.lineWidth = 14; ctx.strokeStyle = '#000'; ctx.lineJoin = 'round';
    ctx.fillStyle = goalMsg ? (G.flashWho === 'me' ? '#ffb000' : '#e5252a') : '#2fbf4a';
    ctx.strokeText(t('flash.' + G.flash), 0, 0); ctx.fillText(t('flash.' + G.flash), 0, 0);
    ctx.restore();
  }
}

function loop(now) {
  if (!G) { looping = false; return; }
  const dt = Math.min(0.05, (now - G.last) / 1000); G.last = now;
  if (G.state !== 'over') update(dt);
  if (!G) return;
  ctx.save();
  if (G.shake > 0) ctx.translate(rand(-1, 1) * G.shake * 30, rand(-1, 1) * G.shake * 30);
  drawTunnel(); drawActors(); drawHenInFlight(); drawSplat(); drawParticles(); drawHUD();
  ctx.restore();
  requestAnimationFrame(loop);
}

// ---------- Entrées ----------
// Souris : on se place en bougeant, clic maintenu + souris pour viser, relâcher pour lancer, clic pour attraper.
// Doigt   : on pose le doigt (la position est verrouillée), on glisse pour viser (viseur décalé au-dessus du
//           doigt), on relâche pour lancer ; pour attraper, on tape sur la poule au bon moment.
function pointer(e) {
  const r = cv.getBoundingClientRect();
  const px = (e.clientX - r.left) * (W / r.width), py = (e.clientY - r.top) * (H / r.height);
  const m = G.mouse, t = performance.now();
  const nx = clamp((px - CX) / HW, -1, 1), ny = clamp((py - CY) / HH, -1, 1);
  if (m.t) {
    const inst = (nx - m.x) / Math.max(0.008, (t - m.t) / 1000);
    m.vx = m.vx * 0.5 + inst * 0.5;
  }
  m.x = nx; m.y = ny; m.px = px; m.py = py; m.t = t;
  m.touch = e.pointerType === 'touch';
  m.hist.push({ t, px }); while (m.hist.length && t - m.hist[0].t > 300) m.hist.shift();
}
const foreign = (e) => G && G.hold && e.pointerId !== G.hold.pid;       // un second doigt ne perturbe pas la visée
cv.addEventListener('pointermove', (e) => { if (G && !foreign(e)) pointer(e); });
cv.addEventListener('pointerdown', (e) => {
  if (!G || foreign(e)) return;
  pointer(e); cv.setPointerCapture?.(e.pointerId);
  if (G.state === 'serve' && G.turn === 'me') {
    G.hold = { t0: performance.now(), sx: startX(), pid: e.pointerId };   // le clic / le doigt verrouille la position du joueur
  } else tryCatch();
});
cv.addEventListener('pointerup', (e) => {
  if (!G || !G.hold || foreign(e)) return;
  pointer(e);
  const hd = G.hold, pw = clamp(power(hd.t0), 0.1, 1);
  const spin = flickSpin(), tgt = aimPoint();
  G.hold = null;
  if (G.state === 'serve' && G.turn === 'me') playerThrow(pw, spin, tgt, hd.sx);
});
cv.addEventListener('pointercancel', (e) => { if (G && G.hold && e.pointerId === G.hold.pid) G.hold = null; });   // geste système : on annule le tir
cv.addEventListener('contextmenu', (e) => e.preventDefault());                                                  // pas de menu au toucher long

// Le son n'est autorisé par les navigateurs mobiles qu'après un geste : on réveille l'AudioContext au premier toucher.
addEventListener('pointerdown', () => { try { audio().resume(); } catch {} }, { passive: true });

// Mobile : plein écran quand l'appareil le permet (iPhone : pas d'API plein écran dans Safari ; on joue alors en fenêtre).
function mobileEnter() {
  if (!COARSE) return;
  document.documentElement.requestFullscreen?.().catch(() => {});
}
if (!document.documentElement.requestFullscreen) $('btnFs').hidden = true;
addEventListener('keydown', (e) => {
  if (!G) return;
  if (e.code === 'Space') { e.preventDefault(); tryCatch(); }
  else if (e.code === 'Escape') { if (!document.fullscreenElement) $('btnQuit').click(); }
  else if (e.code === 'KeyF') toggleFs();
});

// ---------- Multijoueur (WebSocket) ----------
// Jeton secret propre au navigateur : il identifie le joueur auprès du serveur (le serveur n'en garde que le hash)
// pour réserver son pseudo dans le classement. Perdu si l'on efface les données du site.
function playerToken() {
  const gen = () => [...crypto.getRandomValues(new Uint8Array(16))].map((x) => x.toString(16).padStart(2, '0')).join('');
  try {
    let tk = localStorage.getItem('cc_token');
    if (!/^[a-f0-9]{32}$/.test(tk || '')) { tk = gen(); localStorage.setItem('cc_token', tk); }
    return tk;
  } catch { return gen(); }                          // stockage indisponible : jeton valable pour cette session seulement
}
const net = {
  ws: null, id: null, name: '', queued: false, inviteFrom: null, pingT: 0,
  send(o) { if (this.ws && this.ws.readyState === 1) this.ws.send(JSON.stringify(o)); },
  url() {
    if (window.CHICKEN_WS) return window.CHICKEN_WS;
    return (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.hostname + ':8081';
  },
  close() {
    clearInterval(this.pingT);
    if (this.ws) { this.ws.onclose = null; this.ws.close(); }
    this.ws = null; this.id = null; this.queued = false;
  },
};
let lobbyMsg = { key: 'lobby.connecting' };
function renderStatus() {
  const { key, vars, n } = lobbyMsg;
  $('lobbyStatus').textContent = n != null ? tn(key, n, vars) : t(key, vars);
}
const lobbyStatus = (key, vars, n) => { lobbyMsg = { key, vars, n }; renderStatus(); };
const autoLabel = () => { $('auto').textContent = net.queued ? t('lobby.cancel') : t('lobby.auto'); };
const srvText = (m, fallback) => lobbyStatus(m.code ? 'srv.' + m.code : fallback, { name: m.name });

$('mp').onclick = () => { mobileEnter(); openLobby(); };
$('lobbyBack').onclick = () => { net.close(); $('lobby').hidden = true; $('invite').hidden = true; $('menu').hidden = false; loadBoard(); };
$('auto').onclick = () => net.send({ t: net.queued ? 'cancel' : 'auto' });
$('invYes').onclick = () => { if (net.inviteFrom) net.send({ t: 'accept', from: net.inviteFrom.id }); $('invite').hidden = true; net.inviteFrom = null; };
$('invNo').onclick = () => { if (net.inviteFrom) net.send({ t: 'decline', from: net.inviteFrom.id }); $('invite').hidden = true; net.inviteFrom = null; };

// Variation de classement affichée à la fin d'un match multijoueur (envoyée par le serveur après le dernier point).
function netOverMsg() {
  if (!G || G.mode !== 'net') return '';
  if (G.rated) {
    const r = G.rated;
    const base = t('over.rated', { rating: r.rating, delta: (r.delta > 0 ? '+' : '') + r.delta, rank: r.rank, ord: ordinal(r.rank), total: r.total });
    // même adversaire trop souvent aujourd'hui : on le dit au joueur
    return r.factor !== undefined && r.factor < 1 ? `${base} ${t(r.factor === 0 ? 'over.reducedNone' : 'over.reduced')}` : base;
  }
  if (G.unrated) return t('over.unrated.' + G.unrated);
  return G.ranked === false ? t(G.why ? 'over.why.' + G.why : 'over.unranked') : '';
}

function renderInvite() {
  if (net.inviteFrom) $('inviteText').textContent = t('invite.text', { name: net.inviteFrom.name });
}

// changement de langue à chaud : tout ce qui est construit en JS est re-rendu
window.I18N.onChange(() => {
  [...$('avatars').children].forEach((b, i) => { b.title = t('char' + i); });
  loadBoard(); loadLobbyBoard(); renderStatus(); renderInvite(); autoLabel(); if (G) $('overMsg').textContent = G.mode === 'net' && G.state === 'over' ? netOverMsg() : $('overMsg').textContent;
  if (net.players) renderPlayers(net.players, true);
  if (G) $('again').textContent = G.mode === 'net' ? t('over.rematch') : t('over.again');
});

function openLobby() {
  let name = $('pseudo').value.trim().slice(0, 15);
  if (!name) name = 'Joueur' + Math.floor(Math.random() * 900 + 100);
  $('pseudo').value = name;
  localStorage.setItem('pc_pseudo', name); localStorage.setItem('pc_avatar', avatar);
  $('menu').hidden = true; $('lobby').hidden = false;
  $('players').innerHTML = ''; $('auto').disabled = true; autoLabel();
  lobbyStatus('lobby.connecting');
  net.close();
  let opened = false;
  const ws = net.ws = new WebSocket(net.url());
  ws.onopen = () => {
    opened = true;
    net.send({ t: 'hello', name, kind: avatar, token: playerToken() });
    net.pingT = setInterval(() => net.send({ t: 'ping' }), 25000);
  };
  ws.onmessage = (e) => { try { onNet(JSON.parse(e.data)); } catch {} };
  ws.onclose = () => {
    clearInterval(net.pingT); net.ws = null; net.id = null;
    if (G && G.mode === 'net' && G.state !== 'over') {
      G.state = 'over'; $('overTitle').textContent = t('over.lostTitle');
      $('overScore').textContent = ''; $('overMsg').textContent = t('over.lostMsg');
      $('again').disabled = true; $('over').hidden = false;
    } else if (G && G.mode === 'net') {
      $('overMsg').textContent = t('over.lostMsg2'); $('again').disabled = true;
    } else {
      $('auto').disabled = true;
      lobbyStatus(opened ? 'net.lost' : navigator.onLine === false ? 'net.offline' : 'net.down');
    }
  };
}

function renderPlayers(list, keepStatus) {
  net.players = list;
  const ul = $('players'); ul.innerHTML = '';
  if (!keepStatus) lobbyStatus('lobby.online', { name: net.name }, list.length);
    list.forEach((p) => {
    const li = document.createElement('li');
    const c = document.createElement('canvas'); c.width = 28; c.height = 36;
    drawChar(c.getContext('2d'), p.kind, 14, 35, 34, true, 1);
    const n = document.createElement('b'); n.textContent = p.name + (p.id === net.id ? ' ' + t('lobby.you') : '');
    const st = document.createElement('span'); st.className = 'st'; st.textContent = t('st.' + p.status);
    li.append(c, n, st);
    if (p.id === net.id) li.classList.add('me');
    else if (p.status === 'lobby') {
      li.classList.add('free'); li.title = t('lobby.challenge', { name: p.name });
      li.onclick = () => net.send({ t: 'challenge', to: p.id });
    }
    ul.appendChild(li);
  });
}

function onNet(m) {
  switch (m.t) {
    case 'welcome': net.id = m.id; net.name = m.name; net.ranked = m.ranked; $('pseudo').value = m.name; $('auto').disabled = false; loadLobbyBoard(); break;
    case 'lobby': renderPlayers(m.players); break;
    case 'waiting': lobbyStatus('lobby.waiting', { name: m.name }); break;
    case 'declined': srvText(m, 'srv.refused'); break;
    case 'error': srvText(m, 'srv.error'); break;
    case 'queued': net.queued = true; autoLabel(); lobbyStatus('lobby.searching'); break;
    case 'unqueued': net.queued = false; autoLabel(); break;
    case 'invite':
      net.inviteFrom = m.from;
      renderInvite();
      $('invite').hidden = false; beep(660, 0.12, 'triangle', 0.1); setTimeout(() => beep(880, 0.15, 'triangle', 0.1), 130);
      break;
    case 'invite_cancel':
      if (net.inviteFrom && net.inviteFrom.id === m.from) { $('invite').hidden = true; net.inviteFrom = null; }
      break;
    case 'match':
      net.queued = false; autoLabel();
      start({ opp: m.opp, serve: m.serve, ranked: m.ranked, why: m.why });
      break;
    case 'throw':
      if (!G || G.mode !== 'net' || G.state === 'over') break;
      G.rally = m.rally;
      if (G.state === 'serve' && G.turn === 'opp') launchNet(m.hen); else G.pending = m.hen;   // en attendant la fin de l'animation
      break;
    case 'pos': if (G && G.mode === 'net') G.oppXt = -m.x; break;
    case 'result': onResult(m); break;
    case 'rated': if (G && G.mode === 'net') { G.rated = m; if (G.state === 'over') $('overMsg').textContent = netOverMsg(); } break;
    case 'unrated': if (G && G.mode === 'net') { G.unrated = m.reason; if (G.state === 'over') $('overMsg').textContent = netOverMsg(); } break;
    case 'rematch_ask': if (G && G.state === 'over') $('overMsg').textContent = t('over.rematchAsk'); break;
    case 'oppleft':
      if (!G || G.mode !== 'net') break;
      if (G.state !== 'over') {
        G.state = 'over'; $('overTitle').textContent = t('over.forfeit');
        $('overScore').textContent = `${G.name} ${G.me} – ${G.opp} ${G.oppName}`; $('over').hidden = false; sndWin();
      }
      $('overMsg').textContent = [t('over.oppLeft'), netOverMsg()].filter(Boolean).join(' '); $('again').disabled = true;
      break;
  }
}

// Verdict du serveur. Le défenseur a déjà joué la scène en local (détection des collisions locale,
// pour ne pas subir le lag) : dans ce cas on ne fait que resynchroniser les scores.
function onResult(m) {
  if (!G || G.mode !== 'net' || G.state === 'over') return;
  const alreadyPlayed = m.role === 'defender' && G.localResolved && !m.corrected;       // corrected : le serveur a refusé ma capture, on rejoue la scène
  if (!alreadyPlayed) {
    if (m.kind === 'catch') {                          // l'adversaire a rattrapé ma poule
      const h = G.hen;
      G.held = { x: h ? h.x : 0, y: h ? h.y : 0, z: 1 };
      G.hen = null; G.state = 'caught'; G.timer = 0.55; G.turn = 'opp'; G.oppPose = 0.5;
      G.flash = 'caught'; G.flashT = 0.7; G.flashWho = 'opp'; sndCatch();
    } else {
      goal(m.by === 'you' ? 'me' : 'opp');
    }
  }
  G.me = m.you; G.opp = m.opp;
  G.turn = m.next === 'you' ? 'me' : 'opp';
  G.localResolved = false;
}

function netRematch() {
  net.send({ t: 'rematch' });
  $('again').disabled = true; $('overMsg').textContent = t('over.waitRematch');
}

// position du joueur envoyée ~10 fois/s pour que l'adversaire te voie glisser sur ta ligne
setInterval(() => {
  if (!G || G.mode !== 'net' || G.state === 'over') return;
  const x = +(G.hold ? G.hold.sx : startX()).toFixed(2);
  if (Math.abs(x - G.posX) > 0.02) { G.posX = x; net.send({ t: 'pos', x }); }
}, 100);

applyLayout();

// Tests de navigateur (tests/browser) : expose l'état du jeu uniquement si l'URL contient ?e2e.
if (new URLSearchParams(location.search).has('e2e')) window.__chickenDebug = Object.assign(() => G, { aim: () => aimPoint(), overMsg: () => netOverMsg(), henBoost: () => HB, finish: () => finish(), onResult: (m) => onResult(m) });
})();
