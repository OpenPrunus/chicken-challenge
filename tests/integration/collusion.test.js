'use strict';
/**
 * Anti-collusion : parties trop rapides non classées, gains réduits quand le même couple se rencontre trop souvent,
 * persistance de ces compteurs, expiration de la fenêtre, abandons. (Nécessite `php`.)
 */
const { test, describe, after } = require('node:test');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { sleep, HEN, catchHonestly, wsOptions } = require('./helpers.js');

const ROOT = path.join(__dirname, '..', '..');
const PHP = process.env.PHP_BIN || 'php';
const type = (t) => (m) => m.t === t;
const token = () => crypto.randomBytes(16).toString('hex');
const procs = []; const dirs = [];
after(() => { procs.forEach((p) => p.kill()); dirs.forEach((d) => fs.rmSync(d, { recursive: true, force: true })); });
const freePort = () => new Promise((res, rej) => { const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); }); s.on('error', rej); });
const tmpDir = () => { const d = fs.mkdtempSync(path.join(os.tmpdir(), 'chicken-col-')); dirs.push(d); return d; };

async function startServer(env = {}) {
  const port = await freePort();
  const p = spawn(PHP, [path.join(ROOT, 'server', 'server.php')], { env: { ...process.env, WS_PORT: String(port), ...env }, stdio: ['ignore', 'pipe', 'pipe'] });
  procs.push(p);
  let log = '';
  await new Promise((resolve, reject) => {
    const to = setTimeout(() => reject(new Error('serveur muet : ' + log)), 8000);
    p.stdout.on('data', (d) => { log += d; if (log.includes('listening')) { clearTimeout(to); resolve(); } });
    p.stderr.on('data', (d) => { log += d; });
    p.on('exit', (c) => { clearTimeout(to); reject(new Error(`serveur arrêté (${c}) : ${log}`)); });
  });
  return { url: `ws://127.0.0.1:${port}`, log: () => log, stop: () => new Promise((r) => { p.once('exit', r); p.kill(); }) };
}
class Client {
  constructor(url, ip) { this.ws = new WebSocket(url, wsOptions(ip)); this.inbox = []; this.ws.onmessage = (e) => this.inbox.push(JSON.parse(e.data)); this.opened = new Promise((res, rej) => { this.ws.onopen = res; this.ws.onerror = () => rej(new Error('connexion')); }); }
  send(o) { this.ws.send(JSON.stringify(o)); }
  async wait(pred, ms = 2500) { const t0 = Date.now(); while (Date.now() - t0 < ms) { const i = this.inbox.findIndex(pred); if (i >= 0) return this.inbox.splice(i, 1)[0]; await sleep(15); } return null; }
  async none(pred, ms = 250) { await sleep(ms); return !this.inbox.some(pred); }
  close() { try { this.ws.close(); } catch {} }
}
async function player(srv, name, tk) {
  const c = new Client(srv.url); await c.opened; c.token = tk;
  c.send({ t: 'hello', name, token: tk });
  const w = await c.wait(type('welcome')); c.id = w.id; c.name = w.name; return c;
}
const readFile = (dir) => { const f = path.join(dir, 'multi_scores.json'); return fs.existsSync(f) ? JSON.parse(fs.readFileSync(f, 'utf8')) : null; };
async function matchUp(A, B) {
  A.send({ t: 'challenge', to: B.id }); await B.wait(type('invite')); B.send({ t: 'accept', from: A.id });
  const mA = await A.wait(type('match')), mB = await B.wait(type('match'));
  assert.ok(mA && mB);
  return mA.serve ? { server: A, def: B } : { server: B, def: A };
}
/** `server` marque `points` fois (le défenseur rate à chaque lancer), avec une pause entre les points. */
async function score(server, def, points, pauseMs = 0) {
  for (let i = 0; i < points; i++) {
    server.send({ t: 'throw', hen: HEN }); await def.wait(type('throw'));
    def.send({ t: 'miss' }); await server.wait(type('result')); await def.wait(type('result'));
    if (pauseMs) await sleep(pauseMs);
  }
}
/** Un match complet 4-0 entre A et B ; `winner` gagne. Retourne les messages `rated` (ou `unrated`) des deux joueurs. */
async function playMatch(A, B, winner) {
  let { server, def } = await matchUp(A, B);
  if (server !== winner) {                                        // le gagnant doit avoir la poule : il la rattrape d'abord
    server.send({ t: 'throw', hen: HEN }); await def.wait(type('throw'));
    await catchHonestly(def, HEN);
    await server.wait(type('result')); await def.wait(type('result'));
    [server, def] = [def, server];
  }
  await score(server, def, 4);
  const get = async (c) => (await c.wait((m) => m.t === 'rated' || m.t === 'unrated'));
  const out = { winner: await get(server), loser: await get(def) };
  A.send({ t: 'leave' }); B.send({ t: 'leave' }); await sleep(200);
  return out;
}

describe('parties trop rapides', () => {
  test('par défaut (1,5 s par point), un 4-0 bouclé en quelques centaines de millisecondes n\'est pas classé', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const a = await player(srv, 'ra' + process.pid % 100, token()), b = await player(srv, 'rb' + process.pid % 100, token());
    const { server, def } = await matchUp(a, b);
    await score(server, def, 4);
    const u1 = await server.wait(type('unrated')), u2 = await def.wait(type('unrated'));
    assert.deepEqual([u1.reason, u2.reason], ['too_fast', 'too_fast']);
    assert.ok(await server.none(type('rated'), 200));
    assert.equal(fs.existsSync(path.join(dir, 'multi_scores.json')), false, 'rien n\'est écrit');
    assert.match(srv.log(), /Unranked match/);
    a.close(); b.close(); await srv.stop();
  });

  test('une partie qui dure aussi longtemps que le jeu le demande est classée', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir, CHICKEN_RANK_MIN_POINT_SECONDS: '0.3' });     // 4 points -> 1,2 s
    const a = await player(srv, 'ra' + process.pid % 100, token()), b = await player(srv, 'rb' + process.pid % 100, token());
    const { server, def } = await matchUp(a, b);
    await score(server, def, 4, 380);
    assert.equal((await server.wait(type('rated'))).result, 'win');
    assert.ok(readFile(dir));
    a.close(); b.close(); await srv.stop();
  });

  test('abandon immédiat après un point : pas classé (on ne fabrique pas de victoires en 1 s)', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir });
    const a = await player(srv, 'ra' + process.pid % 100, token()), b = await player(srv, 'rb' + process.pid % 100, token());
    const { server, def } = await matchUp(a, b);
    await score(server, def, 1);
    server.close();
    assert.equal((await def.wait(type('unrated'), 3000)).reason, 'too_fast');
    assert.equal(fs.existsSync(path.join(dir, 'multi_scores.json')), false);
    def.close(); await srv.stop();
  });

  test('la durée minimale se règle à 0 pour désactiver le contrôle', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir, CHICKEN_RANK_MIN_POINT_SECONDS: '0' });
    const a = await player(srv, 'ra' + process.pid % 100, token()), b = await player(srv, 'rb' + process.pid % 100, token());
    const r = await playMatch(a, b, a);
    assert.equal(r.winner.t, 'rated');
    a.close(); b.close(); await srv.stop();
  });
});

describe('rencontres répétées entre les mêmes joueurs', () => {
  test('gains : 1, 1, 0.5, 0.25, puis plus rien — les statistiques continuent de compter', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir, CHICKEN_RANK_MIN_POINT_SECONDS: '0' });
    const a = await player(srv, 'ca' + process.pid % 100, token()), b = await player(srv, 'cb' + process.pid % 100, token());
    const seen = [];
    for (let i = 0; i < 6; i++) {
      const r = await playMatch(a, b, a);                                      // A gagne à chaque fois
      seen.push({ factor: r.winner.factor, delta: r.winner.delta, rating: r.winner.rating });
    }
    assert.deepEqual(seen.map((s) => s.factor), [1, 1, 0.5, 0.25, 0, 0]);
    assert.ok(seen[0].delta === 20 && seen[1].delta > 0 && seen[1].delta < 20);
    assert.ok(seen[2].delta > 0 && seen[2].delta < seen[1].delta, 'gain divisé par deux');
    assert.ok(seen[3].delta < seen[2].delta);
    assert.deepEqual([seen[4].delta, seen[5].delta], [0, 0], 'plus aucun gain');
    assert.equal(seen[4].rating, seen[3].rating, 'la note ne bouge plus');
    const f = readFile(dir);
    assert.equal(f.players[a.name.toLowerCase()].played, 6, 'les parties comptent quand même');
    assert.equal(Object.values(f.pairs)[0].length, 6);
    a.close(); b.close(); await srv.stop();
  });

  test('le perdant est protégé de la même façon : il ne perd plus de points non plus', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir, CHICKEN_RANK_MIN_POINT_SECONDS: '0' });
    const a = await player(srv, 'ca' + process.pid % 100, token()), b = await player(srv, 'cb' + process.pid % 100, token());
    let last;
    for (let i = 0; i < 5; i++) last = await playMatch(a, b, a);
    assert.equal(last.loser.delta, 0);
    assert.equal(last.loser.factor, 0);
    a.close(); b.close(); await srv.stop();
  });

  test('un autre adversaire n\'est pas pénalisé', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir, CHICKEN_RANK_MIN_POINT_SECONDS: '0' });
    const a = await player(srv, 'ca' + process.pid % 100, token()), b = await player(srv, 'cb' + process.pid % 100, token()), c = await player(srv, 'cc' + process.pid % 100, token());
    for (let i = 0; i < 4; i++) await playMatch(a, b, a);
    const fresh = await playMatch(a, c, a);
    assert.equal(fresh.winner.factor, 1);
    assert.ok(fresh.winner.delta > 0);
    a.close(); b.close(); c.close(); await srv.stop();
  });

  test('le couple est le même dans les deux sens (B gagne contre A après que A a gagné contre B)', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir, CHICKEN_RANK_MIN_POINT_SECONDS: '0' });
    const a = await player(srv, 'ca' + process.pid % 100, token()), b = await player(srv, 'cb' + process.pid % 100, token());
    const factors = [];
    for (const w of [a, b, a, b, a]) factors.push((await playMatch(a, b, w)).winner.factor);
    assert.deepEqual(factors, [1, 1, 0.5, 0.25, 0]);
    a.close(); b.close(); await srv.stop();
  });

  test('plusieurs pseudos, même navigateur : déjà non classés entre eux ; deux navigateurs complices : plafonnés', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir, CHICKEN_RANK_MIN_POINT_SECONDS: '0' });
    const tk = token();
    const same1 = await player(srv, 'sa' + process.pid % 100, tk), same2 = await player(srv, 'sb' + process.pid % 100, tk);
    const { mA } = { mA: null };
    const m = await matchUp(same1, same2);
    await score(m.server, m.def, 4);
    assert.ok(await m.server.none(type('rated'), 300), 'même jeton : jamais classé');
    same1.close(); same2.close();
    const c1 = await player(srv, 'ka' + process.pid % 100, token()), c2 = await player(srv, 'kb' + process.pid % 100, token());
    let total = 0;
    for (let i = 0; i < 12; i++) total += (await playMatch(c1, c2, c1)).winner.delta;
    assert.ok(total < 60, `12 victoires « arrangées » : seulement +${total} points`);
    c1.close(); c2.close(); await srv.stop();
  });

  test('les compteurs survivent à un redémarrage du serveur', async () => {
    const dir = tmpDir();
    let srv = await startServer({ CHICKEN_DATA_DIR: dir, CHICKEN_RANK_MIN_POINT_SECONDS: '0' });
    const tkA = token(), tkB = token(), nA = 'pa' + process.pid % 100, nB = 'pb' + process.pid % 100;
    let a = await player(srv, nA, tkA), b = await player(srv, nB, tkB);
    await playMatch(a, b, a); await playMatch(a, b, a);
    a.close(); b.close(); await srv.stop();

    srv = await startServer({ CHICKEN_DATA_DIR: dir, CHICKEN_RANK_MIN_POINT_SECONDS: '0' });
    a = await player(srv, nA, tkA); b = await player(srv, nB, tkB);
    const third = await playMatch(a, b, a);
    assert.equal(third.winner.factor, 0.5, 'la troisième rencontre du jour compte déjà à moitié');
    a.close(); b.close(); await srv.stop();
  });

  test('la fenêtre expire : après le délai, le couple repart à zéro', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir, CHICKEN_RANK_MIN_POINT_SECONDS: '0', CHICKEN_PAIR_WINDOW: '3' });
    const a = await player(srv, 'wa' + process.pid % 100, token()), b = await player(srv, 'wb' + process.pid % 100, token());
    for (let i = 0; i < 4; i++) await playMatch(a, b, a);                      // 4 rencontres rapprochées : la prochaine ne paierait rien
    await sleep(3300);
    const again = await playMatch(a, b, a);
    assert.equal(again.winner.factor, 1, 'plus de rencontre dans la fenêtre');
    a.close(); b.close(); await srv.stop();
  });

  test('un abandon compte comme une rencontre du couple (on ne contourne pas la limite en partant)', async () => {
    const dir = tmpDir(); const srv = await startServer({ CHICKEN_DATA_DIR: dir, CHICKEN_RANK_MIN_POINT_SECONDS: '0' });
    const a = await player(srv, 'fa' + process.pid % 100, token()), b = await player(srv, 'fb' + process.pid % 100, token());
    await playMatch(a, b, a); await playMatch(a, b, a);
    const m = await matchUp(a, b);
    await score(m.server, m.def, 1);
    m.server.send({ t: 'leave' });                                              // forfait à 1-0
    const r = await m.def.wait(type('rated'), 3000);
    assert.equal(r.factor, 0.5, 'troisième rencontre : gains réduits, même par forfait');
    a.close(); b.close(); await srv.stop();
  });
});
