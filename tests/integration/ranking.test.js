'use strict';
/**
 * Classement multijoueur : lance le vrai serveur PHP avec un dossier de données temporaire, joue des matchs
 * complets avec de faux clients et vérifie le message `rated`, le fichier multi_scores.json et la réservation
 * des pseudos par jeton. (Nécessite `php` : ces tests ne s'appliquent pas à un serveur externe WS_URL.)
 */
const { test, describe, after } = require('node:test');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const PHP = process.env.PHP_BIN || 'php';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const HEN = { x: 0.2, y: 0.6, vx: -1.5, vy: -0.3, spin: 1.1, speed: 1.0 };
const type = (t) => (m) => m.t === t;
const token = () => crypto.randomBytes(16).toString('hex');
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');

const procs = []; const dirs = [];
after(() => { procs.forEach((p) => p.kill()); dirs.forEach((d) => fs.rmSync(d, { recursive: true, force: true })); });

const freePort = () => new Promise((res, rej) => {
  const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); }); s.on('error', rej);
});
const tmpDir = () => { const d = fs.mkdtempSync(path.join(os.tmpdir(), 'chicken-rk-')); dirs.push(d); return d; };

async function startServer(env = {}) {
  const port = await freePort();
  const p = spawn(PHP, [path.join(ROOT, 'server', 'server.php')], { env: { ...process.env, WS_PORT: String(port), ...env }, stdio: ['ignore', 'pipe', 'pipe'] });
  procs.push(p);
  let log = '';
  await new Promise((resolve, reject) => {
    const to = setTimeout(() => reject(new Error('serveur PHP muet : ' + log)), 8000);
    p.stdout.on('data', (d) => { log += d; if (log.includes('listening')) { clearTimeout(to); resolve(); } });
    p.stderr.on('data', (d) => { log += d; });
    p.on('exit', (c) => { clearTimeout(to); reject(new Error(`serveur PHP arrêté (${c}) : ${log}`)); });
  });
  return { url: `ws://127.0.0.1:${port}`, proc: p, log: () => log, stop: () => new Promise((r) => { p.once('exit', r); p.kill(); }) };
}

class Client {
  constructor(url) {
    this.ws = new WebSocket(url); this.inbox = []; this.all = [];
    this.ws.onmessage = (e) => { const m = JSON.parse(e.data); this.inbox.push(m); this.all.push(m); };
    this.opened = new Promise((res, rej) => { this.ws.onopen = res; this.ws.onerror = () => rej(new Error('connexion refusée')); });
  }
  send(o) { this.ws.send(JSON.stringify(o)); }
  async wait(pred, ms = 2500) {
    const t0 = Date.now();
    while (Date.now() - t0 < ms) { const i = this.inbox.findIndex(pred); if (i >= 0) return this.inbox.splice(i, 1)[0]; await sleep(15); }
    return null;
  }
  async none(pred, ms = 300) { await sleep(ms); return !this.inbox.some(pred); }
  close() { try { this.ws.close(); } catch {} }
}

let n = 0;
async function join(srv, label, tk = token(), kind = 0) {
  const c = new Client(srv.url); await c.opened;
  c.token = tk;
  const msg = { t: 'hello', name: `${label}${process.pid % 1000}x${++n}`, kind };
  if (tk) msg.token = tk;
  c.send(msg);
  const w = await c.wait(type('welcome'));
  assert.ok(w, 'welcome');
  Object.assign(c, { id: w.id, name: w.name, welcome: w });
  return c;
}
async function exactName(srv, name, tk) {                        // se connecte en demandant un pseudo précis
  const c = new Client(srv.url); await c.opened;
  c.send({ t: 'hello', name, token: tk });
  const w = await c.wait(type('welcome'));
  Object.assign(c, { id: w.id, name: w.name, welcome: w });
  return c;
}
async function matchUp(A, B) {
  A.send({ t: 'challenge', to: B.id });
  await B.wait(type('invite'));
  B.send({ t: 'accept', from: A.id });
  const mA = await A.wait(type('match')), mB = await B.wait(type('match'));
  assert.ok(mA && mB, 'match créé');
  const [server, def] = mA.serve ? [A, B] : [B, A];
  return { server, def, mA, mB };
}
/** Le serveur de jeu marque `points` fois (le défenseur rate à chaque lancer). */
async function score(server, def, points) {
  for (let i = 0; i < points; i++) {
    server.send({ t: 'throw', hen: HEN }); await def.wait(type('throw'));
    def.send({ t: 'miss' });
    await server.wait(type('result')); await def.wait(type('result'));
  }
}
/** Fait gagner `winner` 4-0 en respectant les tours : s'il n'a pas la poule, il la rattrape puis la relance. */
async function winMatch({ server, def }, winner) {
  const loser = winner === server ? def : server;
  let holder = server;
  for (let i = 0; i < 4; i++) {
    if (holder !== winner) {
      holder.send({ t: 'throw', hen: HEN }); await winner.wait(type('throw'));
      await sleep(170);                                         // un rattrapage doit durer plus de 150 ms
      winner.send({ t: 'catch' });
      await holder.wait(type('result')); await winner.wait(type('result'));
      holder = winner;
    }
    winner.send({ t: 'throw', hen: HEN }); await loser.wait(type('throw'));
    loser.send({ t: 'miss' });
    await winner.wait(type('result')); await loser.wait(type('result'));
  }
}
const readFile = (dir) => { const f = path.join(dir, 'multi_scores.json'); return fs.existsSync(f) ? JSON.parse(fs.readFileSync(f, 'utf8')) : null; };
const cleanup = (...cs) => cs.forEach((c) => c.close());

describe('classement multijoueur (Elo)', () => {
  test('un match classé jusqu\'à 4-0 : variation Elo, rang, fichier sur disque', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const a = await join(srv, 'alice'), b = await join(srv, 'bob');
    assert.equal(a.welcome.ranked, true);
    const { server, def, mA } = await matchUp(a, b);
    assert.equal(mA.ranked, true);
    await score(server, def, 4);
    const rw = await server.wait(type('rated')), rl = await def.wait(type('rated'));
    assert.deepEqual([rw.result, rw.rating, rw.delta, rw.rank, rw.total], ['win', 1020, 20, 1, 2]);
    assert.deepEqual([rl.result, rl.rating, rl.delta, rl.rank, rl.total], ['loss', 980, -20, 2, 2]);

    const file = readFile(dir);
    assert.equal(file.version, 1);
    const w = file.players[server.name.toLowerCase()], l = file.players[def.name.toLowerCase()];
    assert.deepEqual([w.rating, w.played, w.wins, w.losses, w.gf, w.ga], [1020, 1, 1, 0, 4, 0]);
    assert.deepEqual([l.rating, l.played, l.wins, l.losses, l.gf, l.ga], [980, 1, 0, 1, 0, 4]);
    assert.equal(w.th, sha(server.token), 'seul le hash du jeton est stocké');
    assert.ok(!JSON.stringify(file).includes(server.token), 'jamais le jeton lui-même');
    cleanup(a, b); await srv.stop();
  });

  test('le jeton et son hash ne sortent jamais du serveur (lobby, match, résultats)', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const a = await join(srv, 'alice'), b = await join(srv, 'bob');
    const { server, def } = await matchUp(a, b);
    await score(server, def, 4);
    await sleep(200);
    const everything = JSON.stringify([...a.all, ...b.all]);
    for (const c of [a, b]) { assert.ok(!everything.includes(c.token)); assert.ok(!everything.includes(sha(c.token))); }
    assert.ok(!/"th"/.test(everything));
    cleanup(a, b); await srv.stop();
  });

  test('un invité (sans jeton) joue sans être classé : rien n\'est écrit', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const a = await join(srv, 'alice'), g = await join(srv, 'guest', null);
    assert.equal(g.welcome.ranked, false);
    const { server, def, mA, mB } = await matchUp(a, g);
    assert.equal(mA.ranked, false); assert.equal(mB.ranked, false);
    await score(server, def, 4);
    assert.ok(await a.none(type('rated'), 400) && await g.none(type('rated'), 100));
    assert.equal(readFile(dir), null, 'aucun fichier créé');
    cleanup(a, g); await srv.stop();
  });

  test('un jeton invalide est traité comme un invité', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    for (const bad of ['court', 'G'.repeat(32), 'A'.repeat(32), 12345, null, ['a']]) {
      const c = new Client(srv.url); await c.opened;
      c.send({ t: 'hello', name: uniq('bad'), token: bad });
      assert.equal((await c.wait(type('welcome'))).ranked, false, `jeton ${JSON.stringify(bad)}`);
      c.close();
    }
    await srv.stop();
  });

  test('deux onglets du même navigateur (même jeton) ne peuvent pas se classer entre eux', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const tk = token();
    const a = await join(srv, 'tab1', tk), b = await join(srv, 'tab2', tk);
    const { server, def, mA } = await matchUp(a, b);
    assert.equal(mA.ranked, false);
    await score(server, def, 4);
    assert.ok(await a.none(type('rated'), 300));
    assert.equal(readFile(dir), null);
    cleanup(a, b); await srv.stop();
  });

  test('un pseudo réservé ne peut plus être pris par un autre jeton, ni par un invité', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const tkA = token();
    const a = await exactName(srv, `Reine${process.pid % 100}`, tkA), b = await join(srv, 'bob');
    const { server, def } = await matchUp(a, b);
    await score(server, def, 4);
    await a.wait(type('rated')); await b.wait(type('rated'));
    const reserved = a.name;
    cleanup(a); await sleep(250);                               // le propriétaire se déconnecte : le pseudo n'est plus « en ligne »

    const thief = await exactName(srv, reserved.toUpperCase(), token());
    assert.notEqual(thief.name.toLowerCase(), reserved.toLowerCase(), 'autre jeton : pseudo refusé (suffixe)');
    assert.equal(thief.welcome.ranked, true);
    const guest = await exactName(srv, reserved, undefined);
    assert.notEqual(guest.name.toLowerCase(), reserved.toLowerCase(), 'invité : pseudo refusé');
    const owner = await exactName(srv, reserved, tkA);
    assert.equal(owner.name, reserved, 'le propriétaire retrouve son pseudo');
    cleanup(thief, guest, owner, b); await srv.stop();
  });

  test('un joueur qui ne s\'est pas encore classé ne réserve rien (se connecter ne suffit pas)', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const first = await exactName(srv, 'Libre', token()); first.close(); await sleep(250);
    const second = await exactName(srv, 'Libre', token());
    assert.equal(second.name, 'Libre');
    cleanup(second); await srv.stop();
  });

  test('abandon : celui qui part avec au moins un point marqué perd, l\'autre gagne', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const a = await join(srv, 'alice'), b = await join(srv, 'bob');
    const { server, def } = await matchUp(a, b);
    await score(server, def, 1);                                // 1-0 pour `server`, qui s'en va quand même
    server.close();
    const r = await def.wait(type('rated'), 3000);
    assert.equal(r.result, 'win', 'le joueur resté gagne par forfait');
    assert.ok(r.delta > 0);
    assert.ok(await def.wait(type('oppleft')));
    const file = readFile(dir);
    const leaver = file.players[server.name.toLowerCase()], stayer = file.players[def.name.toLowerCase()];
    assert.deepEqual([leaver.played, leaver.wins, leaver.losses, stayer.wins, stayer.losses], [1, 0, 1, 1, 0]);
    assert.deepEqual([leaver.gf, leaver.ga], [1, 0], 'le score du moment est conservé');
    cleanup(def); await srv.stop();
  });

  test('abandon à 0-0 : rien n\'est enregistré', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const a = await join(srv, 'alice'), b = await join(srv, 'bob');
    const { server, def } = await matchUp(a, b);
    server.send({ t: 'leave' });
    assert.ok(await def.wait(type('oppleft')));
    assert.ok(await def.none(type('rated'), 300));
    assert.equal(readFile(dir), null);
    cleanup(a, b); await srv.stop();
  });

  test('quitter après la fin du match n\'enregistre pas une seconde fois', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const a = await join(srv, 'alice'), b = await join(srv, 'bob');
    const { server, def } = await matchUp(a, b);
    await score(server, def, 4);
    await server.wait(type('rated')); await def.wait(type('rated'));
    def.send({ t: 'leave' });                                   // le perdant s'en va une fois le match terminé
    await server.wait(type('oppleft'));
    await sleep(150);
    const file = readFile(dir);
    assert.equal(file.players[def.name.toLowerCase()].played, 1);
    assert.equal(file.players[server.name.toLowerCase()].played, 1);
    cleanup(a, b); await srv.stop();
  });

  test('revanche : chaque match est enregistré séparément et les notes évoluent', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const a = await join(srv, 'alice'), b = await join(srv, 'bob');
    let { server, def } = await matchUp(a, b);
    await score(server, def, 4);
    const r1 = await server.wait(type('rated')); await def.wait(type('rated'));
    server.send({ t: 'rematch' }); await def.wait(type('rematch_ask')); def.send({ t: 'rematch' });
    const n1 = await server.wait(type('match')), n2 = await def.wait(type('match'));
    const [s2, d2] = n1.serve ? [server, def] : [def, server];
    await score(s2, d2, 4);
    const r2 = await s2.wait(type('rated')); await d2.wait(type('rated'));
    const file = readFile(dir);
    assert.equal(file.players[a.name.toLowerCase()].played, 2);
    assert.equal(file.players[b.name.toLowerCase()].played, 2);
    assert.equal(r2.result === 'win' ? file.players[s2.name.toLowerCase()].wins : 0, r2.result === 'win' ? 1 + (s2 === server ? 1 : 0) : 0);
    assert.equal(r1.result, 'win');
    cleanup(a, b); await srv.stop();
  });

  test('le classement survit à un redémarrage du serveur', async () => {
    const dir = tmpDir();
    let srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const tkA = token(), tkB = token();
    const nameA = `Pers${process.pid % 100}a`, nameB = `Pers${process.pid % 100}b`;
    let a = await exactName(srv, nameA, tkA), b = await exactName(srv, nameB, tkB);
    let m = await matchUp(a, b);
    await score(m.server, m.def, 4);
    const r1 = await m.server.wait(type('rated')); await m.def.wait(type('rated'));
    cleanup(a, b); await srv.stop();

    srv = await startServer({ CHICKEN_DATA_DIR: dir });
    assert.match(srv.log(), /Ranking: 2 player\(s\) loaded/);
    a = await exactName(srv, nameA, tkA); b = await exactName(srv, nameB, tkB);
    assert.equal(a.name, nameA); assert.equal(b.name, nameB);
    m = await matchUp(a, b);
    await score(m.server, m.def, 4);
    const r2 = await m.server.wait(type('rated'));
    assert.ok(r2, 'match classé après redémarrage');
    const file = readFile(dir);
    assert.equal(file.players[nameA.toLowerCase()].played, 2, 'l\'historique a été conservé');
    assert.ok(r2.rating !== r1.rating || r2.result !== r1.result);
    cleanup(a, b); await srv.stop();
  });

  test('les rangs sont corrects avec plusieurs joueurs (notes calculées à la main)', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const a = await join(srv, 'a'), b = await join(srv, 'b'), c = await join(srv, 'c');

    // Match 1 : A bat B 4-0 -> A = 1000 + 20 = 1020, B = 980 (K = 40, probabilité 0.5)
    let m = await matchUp(a, b);
    await winMatch(m, a);
    let ra = await a.wait(type('rated')); await b.wait(type('rated'));
    assert.deepEqual([ra.rating, ra.rank], [1020, 1]);
    a.send({ t: 'leave' }); b.send({ t: 'leave' }); await sleep(250);          // retour au salon

    // Match 2 : C (1000) bat A (1020) -> probabilité de C = 0.4713 : C +21 = 1021, A -21 = 999
    m = await matchUp(c, a);
    await winMatch(m, c);
    const rc = await c.wait(type('rated')); const ra2 = await a.wait(type('rated'));
    assert.deepEqual([rc.result, rc.rating, rc.delta, rc.rank, rc.total], ['win', 1021, 21, 1, 3]);
    assert.deepEqual([ra2.result, ra2.rating, ra2.delta, ra2.rank, ra2.total], ['loss', 999, -21, 2, 3]);
    const file = readFile(dir);
    assert.deepEqual([a, b, c].map((x) => file.players[x.name.toLowerCase()].rating), [999, 980, 1021]);
    cleanup(a, b, c); await srv.stop();
  });
});

describe('robustesse du stockage', () => {
  test('dossier non inscriptible : les matchs continuent, les joueurs reçoivent leur note, le serveur reste vivant', async () => {
    const srv = await startServer({ CHICKEN_DATA_DIR: '/chemin/inexistant/chicken' });
    assert.match(srv.log(), /not writable|WARNING/);
    const a = await join(srv, 'alice'), b = await join(srv, 'bob');
    const { server, def } = await matchUp(a, b);
    await score(server, def, 4);
    const r = await server.wait(type('rated'));
    assert.equal(r.delta, 20, 'note calculée en mémoire');
    assert.match(srv.log(), /ERROR: cannot write/);
    const c = await join(srv, 'carol'); assert.ok(c.id, 'le serveur répond toujours');
    cleanup(a, b, c); await srv.stop();
  });

  test('fichier de classement corrompu au démarrage : le serveur démarre avec une table vide', async () => {
    const dir = tmpDir();
    fs.writeFileSync(path.join(dir, 'multi_scores.json'), '{ ceci n\'est pas du json');
    const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    assert.match(srv.log(), /Ranking: 0 player\(s\) loaded/);
    const a = await join(srv, 'alice'), b = await join(srv, 'bob');
    const { server, def } = await matchUp(a, b);
    await score(server, def, 4);
    assert.ok(await server.wait(type('rated')));
    assert.equal(readFile(dir).players[server.name.toLowerCase()].played, 1, 'le fichier est réécrit proprement');
    cleanup(a, b); await srv.stop();
  });

  test('aucun fichier temporaire ne traîne après une écriture', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const a = await join(srv, 'alice'), b = await join(srv, 'bob');
    const { server, def } = await matchUp(a, b);
    await score(server, def, 4);
    await server.wait(type('rated'));
    assert.deepEqual(fs.readdirSync(dir).sort(), ['multi_scores.json']);
    cleanup(a, b); await srv.stop();
  });
});
let u = 0;
const uniq = (p) => `${p}${process.pid % 1000}u${++u}`;
