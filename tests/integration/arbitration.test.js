'use strict';
/**
 * Arbitrage côté serveur : une capture annoncée doit être possible (poule à portée, main au bon endroit, bon moment),
 * un lancer doit être réalisable avec l'interface du jeu. Joue un défenseur honnête, puis des tricheurs.
 * (Nécessite `php` : lance le vrai serveur avec différents modes.)
 */
const { test, describe, after } = require('node:test');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const net = require('node:net');
const path = require('node:path');
const { E, sleep, legitHen, HEN, honestCatch, catchHonestly } = require('./helpers.js');

const ROOT = path.join(__dirname, '..', '..');
const PHP = process.env.PHP_BIN || 'php';
const type = (t) => (m) => m.t === t;
const procs = [];
after(() => procs.forEach((p) => p.kill()));
const freePort = () => new Promise((res, rej) => { const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); }); s.on('error', rej); });

async function startServer(env = {}) {
  const port = await freePort();
  const p = spawn(PHP, [path.join(ROOT, 'server', 'server.php')], { env: { ...process.env, WS_PORT: String(port), CHICKEN_DATA_DIR: '/tmp', ...env }, stdio: ['ignore', 'pipe', 'pipe'] });
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
  constructor(url) { this.ws = new WebSocket(url); this.inbox = []; this.ws.onmessage = (e) => this.inbox.push(JSON.parse(e.data)); this.opened = new Promise((res, rej) => { this.ws.onopen = res; this.ws.onerror = () => rej(new Error('connexion')); }); }
  send(o) { this.ws.send(JSON.stringify(o)); }
  async wait(pred, ms = 2500) { const t0 = Date.now(); while (Date.now() - t0 < ms) { const i = this.inbox.findIndex(pred); if (i >= 0) return this.inbox.splice(i, 1)[0]; await sleep(15); } return null; }
  async none(pred, ms = 250) { await sleep(ms); return !this.inbox.some(pred); }
  close() { try { this.ws.close(); } catch {} }
}
let n = 0;
async function join(srv, label) {
  const c = new Client(srv.url); await c.opened;
  c.send({ t: 'hello', name: `${label}${process.pid % 1000}x${++n}` });
  const w = await c.wait(type('welcome')); c.id = w.id; c.name = w.name; return c;
}
async function matchUp(srv) {
  const A = await join(srv, 'a'), B = await join(srv, 'b');
  A.send({ t: 'challenge', to: B.id }); await B.wait(type('invite')); B.send({ t: 'accept', from: A.id });
  const mA = await A.wait(type('match')); await B.wait(type('match'));
  const [server, def] = mA.serve ? [A, B] : [B, A];
  return { server, def, close: () => { A.close(); B.close(); } };
}
/** Le lanceur lance ; renvoie la poule reçue par le défenseur. */
async function throwFrom(server, def, hen = HEN) { server.send({ t: 'throw', hen }); return (await def.wait(type('throw'))).hen; }

describe('arbitrage des captures (mode par défaut : enforce)', () => {
  test('une capture honnête est acceptée : la poule passe au défenseur, personne ne marque', async () => {
    const srv = await startServer(); const { server, def, close } = await matchUp(srv);
    await throwFrom(server, def);
    await catchHonestly(def);
    const rs = await server.wait(type('result')), rd = await def.wait(type('result'));
    assert.deepEqual([rs.kind, rs.you, rs.opp, rd.kind, rd.by], ['catch', 0, 0, 'catch', 'you']);
    assert.equal(rs.corrected, undefined);
    close(); await srv.stop();
  });

  test('une capture honnête reste acceptée avec une grosse latence réseau (jusqu\'à ~0,9 s d\'aller-retour)', async () => {
    const srv = await startServer(); const { server, def, close } = await matchUp(srv);
    await throwFrom(server, def);
    await catchHonestly(def, HEN, 900);                                       // le message de capture arrive 900 ms trop tard
    assert.equal((await server.wait(type('result'), 3000)).kind, 'catch');
    close(); await srv.stop();
  });

  test('« attrapée » sans position de main : refusée, le lanceur marque, les deux joueurs sont prévenus (corrected)', async () => {
    const srv = await startServer(); const { server, def, close } = await matchUp(srv);
    await throwFrom(server, def);
    await sleep(500); def.send({ t: 'catch' });
    const rs = await server.wait(type('result')), rd = await def.wait(type('result'));
    assert.deepEqual([rs.kind, rs.by, rs.you, rs.opp, rs.corrected], ['goal', 'you', 1, 0, true]);
    assert.deepEqual([rd.kind, rd.by, rd.you, rd.opp, rd.corrected, rd.role], ['goal', 'opp', 0, 1, true, 'defender']);
    assert.match(srv.log(), /REFUSED capture/);
    close(); await srv.stop();
  });

  test('main loin de la poule : refusée', async () => {
    const srv = await startServer(); const { server, def, close } = await matchUp(srv);
    await throwFrom(server, def);
    const c = honestCatch(HEN);
    await sleep(c.delayMs);
    def.send({ t: 'catch', x: -Math.sign(c.x || 1) * 1.4, y: -Math.sign(c.y || 1) * 1.4 });
    const r = await server.wait(type('result'));
    assert.deepEqual([r.kind, r.you, r.corrected], ['goal', 1, true]);
    close(); await srv.stop();
  });

  test('capture trop tôt (poule encore loin) : refusée, même avec la main au bon endroit', async () => {
    const srv = await startServer(); const { server, def, close } = await matchUp(srv);
    await throwFrom(server, def);
    const c = honestCatch(HEN);
    await sleep(200);                                                          // bien avant que la poule soit à portée
    def.send({ t: 'catch', x: c.x, y: c.y });
    assert.equal((await server.wait(type('result'))).corrected, true);
    close(); await srv.stop();
  });

  test('capture trop tard (la poule est déjà passée) : refusée', async () => {
    const srv = await startServer(); const { server, def, close } = await matchUp(srv);
    await throwFrom(server, def);
    const c = honestCatch(HEN);
    await sleep(c.delayMs + 2600);                                             // la poule est arrivée depuis longtemps
    def.send({ t: 'catch', x: c.x, y: c.y });
    const r = await server.wait(type('result'), 3000);
    assert.deepEqual([r.kind, r.corrected], ['goal', true]);
    close(); await srv.stop();
  });

  test('coordonnées invalides (texte, NaN, hors tunnel, tableau) : refusées', async () => {
    for (const bad of [{ x: 'a', y: 0 }, { x: null, y: 0 }, { x: 0, y: [1] }, { x: 9, y: 0 }, { x: 0, y: -9 }, { x: true, y: false }]) {
      const srv = await startServer(); const { server, def, close } = await matchUp(srv);
      await throwFrom(server, def);
      await sleep(honestCatch(HEN).delayMs);
      def.send({ t: 'catch', ...bad });
      assert.equal((await server.wait(type('result'))).corrected, true, JSON.stringify(bad));
      close(); await srv.stop();
    }
  });

  test('un tricheur qui annonce « attrapée » pour chaque lancer, sans viser, perd tous ses points : 0-4', async () => {
    const srv = await startServer(); const { server, def, close } = await matchUp(srv);
    let last;
    for (let i = 0; i < 4; i++) {
      await throwFrom(server, def);
      await sleep(honestCatch(HEN).delayMs);
      def.send({ t: 'catch', x: 1.4, y: 1.4 });                                // « toujours attrapée », main n'importe où
      last = await server.wait(type('result')); await def.wait(type('result'));
      assert.equal(last.corrected, true);
    }
    assert.deepEqual([last.you, last.opp, last.over], [4, 0, true]);
    close(); await srv.stop();
  });

  test('après un refus, la partie continue normalement : le lanceur marqueur relance, le défenseur peut ensuite attraper', async () => {
    const srv = await startServer(); const { server, def, close } = await matchUp(srv);
    await throwFrom(server, def); await sleep(500); def.send({ t: 'catch' });
    await server.wait(type('result')); await def.wait(type('result'));
    await throwFrom(server, def);                                              // le marqueur garde la poule et relance
    await catchHonestly(def);
    assert.equal((await server.wait(type('result'))).kind, 'catch');
    close(); await srv.stop();
  });

  test('« miss » n\'est pas concerné : il donne le point au lanceur, sans drapeau corrected', async () => {
    const srv = await startServer(); const { server, def, close } = await matchUp(srv);
    await throwFrom(server, def); def.send({ t: 'miss' });
    const r = await server.wait(type('result'));
    assert.deepEqual([r.kind, r.you, r.corrected], ['goal', 1, undefined]);
    close(); await srv.stop();
  });

  test('des captures honnêtes sur des lancers variés (rebonds, effet, vitesses) sont toutes acceptées', async () => {
    const srv = await startServer(); const { server, def, close } = await matchUp(srv);
    const cases = [{ px: 30, py: 300, x0: -0.6, spin: 2.0 }, { px: 770, py: 100, x0: 0.7, spin: -2.2 }, { px: 400, py: 20, x0: 0, spin: 1.0, speed: 1.3 }, { px: 400, py: 580, x0: 0.3, spin: 0, speed: 1.6 }, { px: 100, py: 560, x0: -0.8, spin: -1.0, speed: 1.1 }];
    let holder = server, other = def;
    for (const k of cases) {
      const hen = legitHen(k);
      holder.send({ t: 'throw', hen }); await other.wait(type('throw'));
      await catchHonestly(other, hen);
      const r = await holder.wait(type('result'));
      assert.equal(r.kind, 'catch', `lancer ${JSON.stringify(k)} : ${JSON.stringify(r)}`);
      await other.wait(type('result'));
      [holder, other] = [other, holder];                                       // celui qui a attrapé relance
    }
    close(); await srv.stop();
  });
});

describe('lancers hors des possibilités du jeu', () => {
  test('refusés en mode enforce ; ceux du vrai jeu passent (extrêmes compris)', async () => {
    const srv = await startServer(); const { server, def, close } = await matchUp(srv);
    for (const cheat of [{ ...HEN, speed: 1.9 }, { ...HEN, speed: 1.7 }, { ...HEN, spin: -2.6 }, { ...HEN, vx: 60 }, { ...HEN, vy: -55 }, { ...HEN, x: 0.95 }, { ...HEN, y: 0 }, { ...HEN, vx: 0, vy: 30 }]) {                                          // vy 30 : toucherait le sol plus près que le jeu ne le permet
      server.send({ t: 'throw', hen: cheat });
      assert.ok(await def.none(type('throw'), 60), JSON.stringify(cheat));
    }
    assert.match(srv.log(), /REFUSED lancer/);
    server.send({ t: 'throw', hen: legitHen({ px: 2, py: 300, x0: 0.8, speed: 1.6, spin: 2.2 }) });       // le plus tordu que le jeu permette
    assert.ok(await def.wait(type('throw')), 'lancer extrême mais légitime');
    close(); await srv.stop();
  });
});

describe('modes du serveur', () => {
  test('mode log : une capture impossible est acceptée mais journalisée (« suspect »)', async () => {
    const srv = await startServer({ CHICKEN_CATCH_CHECK: 'log' }); const { server, def, close } = await matchUp(srv);
    await throwFrom(server, def); await sleep(500); def.send({ t: 'catch' });
    const r = await server.wait(type('result'));
    assert.equal(r.kind, 'catch');
    assert.match(srv.log(), /suspect capture/);
    close(); await srv.stop();
  });

  test('mode off : on croit le client (comportement d\'avant), sans journal', async () => {
    const srv = await startServer({ CHICKEN_CATCH_CHECK: 'off', CHICKEN_THROW_CHECK: 'off' }); const { server, def, close } = await matchUp(srv);
    server.send({ t: 'throw', hen: { ...HEN, speed: 1.9, vx: 50 } });
    assert.ok(await def.wait(type('throw')), 'lancer hors limites accepté en mode off');
    await sleep(300); def.send({ t: 'catch' });
    assert.equal((await server.wait(type('result'))).kind, 'catch');
    assert.doesNotMatch(srv.log(), /REFUSED|suspect/);
    close(); await srv.stop();
  });

  test('mode throw log : lancer suspect relayé mais journalisé', async () => {
    const srv = await startServer({ CHICKEN_THROW_CHECK: 'log' }); const { server, def, close } = await matchUp(srv);
    server.send({ t: 'throw', hen: { ...HEN, speed: 1.9 } });
    assert.ok(await def.wait(type('throw')));
    assert.match(srv.log(), /suspect lancer/);
    close(); await srv.stop();
  });

  test('valeur d\'environnement inconnue : retour au mode strict (enforce)', async () => {
    const srv = await startServer({ CHICKEN_CATCH_CHECK: 'peut-etre', CHICKEN_THROW_CHECK: '' }); const { server, def, close } = await matchUp(srv);
    server.send({ t: 'throw', hen: { ...HEN, speed: 1.9 } });
    assert.ok(await def.none(type('throw'), 100), 'lancer refusé');
    close(); await srv.stop();
  });

  test('tolérance de temps réglable (CHICKEN_CATCH_SLACK) : trop serrée, une capture honnête en retard est refusée', async () => {
    const srv = await startServer({ CHICKEN_CATCH_SLACK: '0.05' }); const { server, def, close } = await matchUp(srv);
    await throwFrom(server, def);
    await catchHonestly(def, HEN, 700);
    assert.equal((await server.wait(type('result'), 3000)).corrected, true);
    close(); await srv.stop();
  });
});
