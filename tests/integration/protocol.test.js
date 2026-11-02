'use strict';
/**
 * Test d'intégration du serveur multijoueur : lance le vrai `server/server.php` (ou se connecte à WS_URL)
 * et joue des parties avec de faux clients WebSocket.
 *
 *   npm run test:integration                          lance `php server/server.php` sur un port libre
 *   WS_URL=ws://localhost:8081 npm run test:integration   utilise un serveur déjà lancé (sans les tests de délais
 *                                                          ni d'origine, sauf CHICKEN_TIMEOUT_TESTS=1)
 */
const { test, describe, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const net = require('node:net');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const PHP = process.env.PHP_BIN || 'php';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const procs = [];
let URL_MAIN;                       // serveur « normal »
let URL_ORIGIN = null;              // serveur avec liste d'origines (si on lance nous-mêmes les serveurs)
let spawned = false;
let timeoutsShort = false;          // délais réduits à 1 s (FLIGHT/INVITE/SERVE)

function freePort() {
  return new Promise((resolve, reject) => {
    const s = net.createServer();
    s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => resolve(port)); });
    s.on('error', reject);
  });
}

async function startServer(env = {}) {
  const port = await freePort();
  const p = spawn(PHP, [path.join(ROOT, 'server', 'server.php')], {
    env: { ...process.env, WS_PORT: String(port), ...env }, stdio: ['ignore', 'pipe', 'pipe'],
  });
  procs.push(p);
  let log = '';
  await new Promise((resolve, reject) => {
    const to = setTimeout(() => reject(new Error('le serveur PHP ne démarre pas : ' + log)), 8000);
    p.stdout.on('data', (d) => { log += d; if (log.includes('listening')) { clearTimeout(to); resolve(); } });
    p.stderr.on('data', (d) => { log += d; });
    p.on('exit', (c) => { clearTimeout(to); reject(new Error(`serveur PHP arrêté (${c}) : ${log}`)); });
  });
  return { url: `ws://127.0.0.1:${port}`, port };
}

before(async () => {
  if (process.env.WS_URL) {
    URL_MAIN = process.env.WS_URL;
    timeoutsShort = process.env.CHICKEN_TIMEOUT_TESTS === '1';
  } else {
    spawned = true; timeoutsShort = true;
    URL_MAIN = (await startServer({ CHICKEN_FLIGHT_TIMEOUT: '1', CHICKEN_INVITE_TTL: '1', CHICKEN_SERVE_TIMEOUT: '1' })).url;
    URL_ORIGIN = await startServer({ WS_ALLOWED_ORIGINS: 'https://ok.example, https://aussi.example' });
  }
});
after(() => { for (const p of procs) p.kill(); });

// ------------------------------------------------------------------------------------------------ client de test
class Client {
  constructor(url = URL_MAIN) {
    this.ws = new WebSocket(url);
    this.inbox = [];
    this.ws.onmessage = (e) => this.inbox.push(JSON.parse(e.data));
    this.opened = new Promise((res, rej) => { this.ws.onopen = res; this.ws.onerror = () => rej(new Error('connexion refusée')); });
    this.closed = new Promise((res) => { this.ws.onclose = res; });
  }
  send(o) { this.ws.send(typeof o === 'string' ? o : JSON.stringify(o)); }
  async wait(pred, ms = 2500) {
    const t0 = Date.now();
    while (Date.now() - t0 < ms) {
      const i = this.inbox.findIndex(pred);
      if (i >= 0) return this.inbox.splice(i, 1)[0];
      await sleep(15);
    }
    return null;
  }
  async none(pred, ms = 250) { await sleep(ms); return !this.inbox.some(pred); }
  close() { try { this.ws.close(); } catch {} }
}
const type = (t) => (m) => m.t === t;

let n = 0;
const uniq = (p) => `${p}${process.pid % 1000}x${++n}`;
async function join(label, kind = 0, url = URL_MAIN) {
  const c = new Client(url);
  await c.opened;
  const name = uniq(label);
  c.send({ t: 'hello', name, kind });
  const w = await c.wait(type('welcome'));
  assert.ok(w, `welcome pour ${label}`);
  c.id = w.id; c.name = w.name;
  return c;
}
async function matchUp(A, B) {
  A.send({ t: 'challenge', to: B.id });
  assert.ok(await B.wait(type('invite')), 'invitation reçue');
  B.send({ t: 'accept', from: A.id });
  const mA = await A.wait(type('match')), mB = await B.wait(type('match'));
  assert.ok(mA && mB, 'match créé');
  const [server, def] = mA.serve ? [A, B] : [B, A];
  return { server, def, mA, mB };
}
const HEN = { x: 0.2, y: 0.6, vx: -1.5, vy: -0.3, spin: 1.1, speed: 1.0 };
const withClients = (fn) => async (t) => {
  const cs = [];
  const mk = async (...a) => { const c = await join(...a); cs.push(c); return c; };
  try { await fn(mk, t); } finally { cs.forEach((c) => c.close()); }
};

// ------------------------------------------------------------------------------------------------ poignée de main
// Poignée de main HTTP brute, pour tester ce qu'un vrai navigateur ne ferait pas.
function rawHandshake(url, headers = {}, { upgrade = true } = {}) {
  const u = new globalThis.URL(url);
  return new Promise((resolve) => {
    const s = net.connect(Number(u.port), u.hostname);
    let data = '';
    s.on('data', (d) => { data += d; if (data.includes('\r\n\r\n')) { resolve({ head: data, socket: s }); } });
    s.on('close', () => resolve({ head: data, socket: s }));
    const h = { Host: u.host, ...(upgrade ? { Upgrade: 'websocket', Connection: 'Upgrade', 'Sec-WebSocket-Version': '13', 'Sec-WebSocket-Key': 'dGhlIHNhbXBsZSBub25jZQ==' } : {}), ...headers };
    s.write(`GET /ws HTTP/1.1\r\n${Object.entries(h).map(([k, v]) => `${k}: ${v}`).join('\r\n')}\r\n\r\n`);
  });
}

describe('poignée de main WebSocket', () => {
  test('répond 101 avec le Sec-WebSocket-Accept de la RFC 6455', async () => {
    const { head, socket } = await rawHandshake(URL_MAIN);
    assert.match(head, /^HTTP\/1\.1 101 /);
    assert.match(head, /Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK\+xOo=/);
    socket.destroy();
  });
  test('une requête HTTP ordinaire (sans Upgrade) est refusée en 400', async () => {
    const { head, socket } = await rawHandshake(URL_MAIN, {}, { upgrade: false });
    assert.match(head, /^HTTP\/1\.1 400 /);
    socket.destroy();
  });
  test('une trame cliente non masquée coupe la connexion', async () => {
    const { socket } = await rawHandshake(URL_MAIN);
    const closed = new Promise((r) => socket.on('close', r));
    socket.write(Buffer.from([0x81, 0x02, 0x68, 0x69]));             // texte « hi » sans masque
    await Promise.race([closed, sleep(2000).then(() => { throw new Error('connexion non coupée'); })]);
  });
  test('un message de plus de 8 Ko coupe la connexion', withClients(async () => {
    const c = new Client(); await c.opened;
    c.ws.send('x'.repeat(9000));
    await Promise.race([c.closed, sleep(2000).then(() => { throw new Error('connexion non coupée'); })]);
  }));
  test('ping WebSocket -> pong', async () => {
    const { socket } = await rawHandshake(URL_MAIN);
    const got = new Promise((r) => socket.once('data', r));
    const mask = Buffer.from([1, 2, 3, 4]);
    socket.write(Buffer.concat([Buffer.from([0x89, 0x80]), mask]));    // ping vide masqué
    const frame = await Promise.race([got, sleep(2000).then(() => null)]);
    assert.ok(frame && frame[0] === 0x8a, 'pong (0x8A)');
    socket.destroy();
  });
});

describe('origines autorisées', () => {
  const needSpawn = (t) => { if (spawned) return false; t.skip('serveur externe : liste d\'origines inconnue'); return true; };
  test('origine autorisée -> 101', async (t) => {
    if (needSpawn(t)) return;
    const { head, socket } = await rawHandshake(URL_ORIGIN.url, { Origin: 'https://ok.example' });
    assert.match(head, /^HTTP\/1\.1 101 /); socket.destroy();
  });
  test('la seconde origine de la liste est acceptée aussi', async (t) => {
    if (needSpawn(t)) return;
    const { head, socket } = await rawHandshake(URL_ORIGIN.url, { Origin: 'https://aussi.example' });
    assert.match(head, /^HTTP\/1\.1 101 /); socket.destroy();
  });
  test('autre origine -> 400', async (t) => {
    if (needSpawn(t)) return;
    const { head, socket } = await rawHandshake(URL_ORIGIN.url, { Origin: 'https://evil.example' });
    assert.match(head, /^HTTP\/1\.1 400 /); socket.destroy();
  });
  test('sans en-tête Origin -> 400 (les navigateurs en envoient toujours un)', async (t) => {
    if (needSpawn(t)) return;
    const { head, socket } = await rawHandshake(URL_ORIGIN.url);
    assert.match(head, /^HTTP\/1\.1 400 /); socket.destroy();
  });
});

// ------------------------------------------------------------------------------------------------ salon
describe('salon d\'attente', () => {
  test('hello -> welcome + liste des joueurs', withClients(async (mk) => {
    const a = await mk('alice', 1);
    const lobby = await a.wait((m) => m.t === 'lobby' && m.players.some((p) => p.id === a.id));
    const me = lobby.players.find((p) => p.id === a.id);
    assert.deepEqual([me.name, me.kind, me.status], [a.name, 1, 'lobby']);
  }));
  test('les pseudos en double reçoivent un suffixe (insensible à la casse)', withClients(async (mk) => {
    const a = await mk('dup');
    const b = new Client(); await b.opened;
    b.send({ t: 'hello', name: a.name.toUpperCase() });
    const w = await b.wait(type('welcome'));
    assert.notEqual(w.name.toLowerCase(), a.name.toLowerCase());
    assert.ok(w.name.toLowerCase().startsWith(a.name.toLowerCase().slice(0, 12)));
    b.close();
  }));
  test('pseudo nettoyé et tronqué à 15 caractères ; kind borné à 0-3', async () => {
    const c = new Client(); await c.opened;
    c.send({ t: 'hello', name: '<img src=x onerror=1>' + 'z'.repeat(40), kind: 99 });
    const w = await c.wait(type('welcome'));
    assert.ok(w.name.length <= 15 && !/[<>=]/.test(w.name), w.name);
    const lobby = await c.wait((m) => m.t === 'lobby' && m.players.some((p) => p.id === w.id));
    assert.equal(lobby.players.find((p) => p.id === w.id).kind, 3);
    c.close();
  });
  test('un pseudo vide est refusé (code invalid_name)', async () => {
    const c = new Client(); await c.opened;
    c.send({ t: 'hello', name: '<<>>' });
    const e = await c.wait(type('error'));
    assert.equal(e.code, 'invalid_name');
    c.close();
  });
  test('messages ignorés avant hello, JSON invalide, second hello', withClients(async () => {
    const c = new Client(); await c.opened;
    c.send({ t: 'auto' }); c.send({ t: 'challenge', to: 1 }); c.ws.send('pas du json'); c.ws.send('[1,2]'); c.ws.send('null');
    assert.ok(await c.none(() => true), 'aucune réponse');
    c.send({ t: 'hello', name: uniq('ok') });
    const w = await c.wait(type('welcome'));
    assert.ok(w, 'toujours utilisable après des messages invalides');
    c.send({ t: 'hello', name: uniq('autre') });
    assert.ok(await c.none(type('welcome')), 'un second hello est ignoré');
    c.close();
  }));
  test('ping applicatif -> pong', withClients(async (mk) => {
    const a = await mk('png'); a.send({ t: 'ping' });
    assert.ok(await a.wait(type('pong')));
  }));
  test('le départ d\'un joueur met la liste à jour', withClients(async (mk) => {
    const a = await mk('stay'), b = await mk('leave');
    await a.wait((m) => m.t === 'lobby' && m.players.some((p) => p.id === b.id));
    b.close();
    const l = await a.wait((m) => m.t === 'lobby' && !m.players.some((p) => p.id === b.id));
    assert.ok(l, 'liste sans le joueur parti');
  }));
});

// ------------------------------------------------------------------------------------------------ défis
describe('défis et matchmaking', () => {
  test('défi refusé : le challenger est notifié (code refused + nom)', withClients(async (mk) => {
    const a = await mk('a'), b = await mk('b');
    a.send({ t: 'challenge', to: b.id });
    assert.equal((await a.wait(type('waiting'))).name, b.name);
    const inv = await b.wait(type('invite'));
    assert.deepEqual([inv.from.id, inv.from.name], [a.id, a.name]);
    b.send({ t: 'decline', from: a.id });
    const d = await a.wait(type('declined'));
    assert.deepEqual([d.code, d.name], ['refused', b.name]);
  }));
  test('défi accepté : un match, un seul serveur, adversaires corrects', withClients(async (mk) => {
    const a = await mk('a', 1), b = await mk('b', 2);
    const { mA, mB } = await matchUp(a, b);
    assert.notEqual(mA.serve, mB.serve);
    assert.deepEqual([mA.opp.name, mA.opp.kind, mB.opp.name, mB.opp.kind], [b.name, 2, a.name, 1]);
  }));
  test('défier soi-même ou un joueur inconnu : unavailable', withClients(async (mk) => {
    const a = await mk('a');
    a.send({ t: 'challenge', to: a.id });
    assert.equal((await a.wait(type('declined'))).code, 'unavailable');
    a.send({ t: 'challenge', to: 999999 });
    assert.equal((await a.wait(type('declined'))).code, 'unavailable');
  }));
  test('accepter un défi inexistant : stale_invite', withClients(async (mk) => {
    const a = await mk('a'), b = await mk('b');
    b.send({ t: 'accept', from: a.id });
    assert.equal((await b.wait(type('error'))).code, 'stale_invite');
    assert.ok(await a.none(type('match')));
  }));
  test('on ne peut pas défier un joueur déjà en match', withClients(async (mk) => {
    const a = await mk('a'), b = await mk('b'), c = await mk('c');
    await matchUp(a, b);
    c.send({ t: 'challenge', to: a.id });
    assert.equal((await c.wait(type('declined'))).code, 'unavailable');
  }));
  test('le challenger qui part annule l\'invitation (invite_cancel)', withClients(async (mk) => {
    const a = await mk('a'), b = await mk('b');
    a.send({ t: 'challenge', to: b.id });
    await b.wait(type('invite'));
    a.close();
    assert.equal((await b.wait(type('invite_cancel'))).from, a.id);
  }));
  test('match automatique : file d\'attente, annulation, appariement', withClients(async (mk) => {
    const a = await mk('a'), b = await mk('b'), c = await mk('c');
    a.send({ t: 'auto' });
    assert.ok(await a.wait(type('queued')));
    a.send({ t: 'cancel' });
    assert.ok(await a.wait(type('unqueued')));
    a.send({ t: 'auto' }); await a.wait(type('queued'));
    b.send({ t: 'auto' });
    const mA = await a.wait(type('match')), mB = await b.wait(type('match'));
    assert.ok(mA && mB && mA.serve !== mB.serve);
    assert.ok(await c.none(type('match')), 'un tiers n\'est pas apparié');
  }));
  test('statuts du salon : queue puis match', withClients(async (mk) => {
    const a = await mk('a'), b = await mk('b'), obs = await mk('obs');
    a.send({ t: 'auto' });
    let l = await obs.wait((m) => m.t === 'lobby' && m.players.find((p) => p.id === a.id)?.status === 'queue');
    assert.ok(l);
    b.send({ t: 'auto' });
    l = await obs.wait((m) => m.t === 'lobby' && [a.id, b.id].every((id) => m_status(m, id) === 'match'));
    assert.ok(l);
  }));
  test('invitation sans réponse : expire (code timeout)', withClients(async (mk, t) => {
    if (!timeoutsShort) return t.skip('délais non réduits');
    const a = await mk('a'), b = await mk('b');
    a.send({ t: 'challenge', to: b.id });
    await b.wait(type('invite'));
    const d = await a.wait(type('declined'), 4000);
    assert.equal(d && d.code, 'timeout');
    assert.ok(await b.wait(type('invite_cancel'), 1000));
  }));
});
const m_status = (m, id) => m.players.find((p) => p.id === id)?.status;

// ------------------------------------------------------------------------------------------------ parties
describe('déroulement d\'un match', () => {
  test('un lancer hors tour est ignoré', withClients(async (mk) => {
    const { server, def } = await matchUp(await mk('a'), await mk('b'));
    def.send({ t: 'throw', hen: HEN });
    assert.ok(await server.none(type('throw')));
  }));
  test('lancer valide : relayé au défenseur, numéro d\'échange, valeurs bornées', withClients(async (mk) => {
    const { server, def } = await matchUp(await mk('a'), await mk('b'));
    server.send({ t: 'throw', hen: { ...HEN, x: 5, speed: 99, spin: -50, vx: 1e9 } });
    const th = await def.wait(type('throw'));
    assert.deepEqual([th.hen.x, th.hen.speed, th.hen.spin, th.hen.vx, th.rally], [1, 1.9, -2.6, 60, 1]);
    server.send({ t: 'throw', hen: HEN });
    assert.ok(await def.none(type('throw')), 'pas de second lancer pendant le vol');
  }));
  test('lancers invalides ignorés (champ manquant, chaîne, null)', withClients(async (mk) => {
    const { server, def } = await matchUp(await mk('a'), await mk('b'));
    server.send({ t: 'throw', hen: { x: 0 } });
    server.send({ t: 'throw', hen: { ...HEN, speed: '1' } });
    server.send({ t: 'throw', hen: null });
    server.send({ t: 'throw' });
    assert.ok(await def.none(type('throw')));
    server.send({ t: 'throw', hen: HEN });                      // et un lancer correct passe ensuite
    assert.ok(await def.wait(type('throw')));
  }));
  test('rattrapage : refusé s\'il est instantané, puis passe la poule au défenseur', withClients(async (mk) => {
    const { server, def } = await matchUp(await mk('a'), await mk('b'));
    server.send({ t: 'throw', hen: HEN }); await def.wait(type('throw'));
    def.send({ t: 'catch' });
    assert.ok(await server.none(type('result'), 120), 'catch < 150 ms refusé');
    await sleep(200);
    server.send({ t: 'catch' });                                // le lanceur ne peut pas se rattraper lui-même
    assert.ok(await def.none(type('result'), 120));
    def.send({ t: 'catch' });
    const rs = await server.wait(type('result')), rd = await def.wait(type('result'));
    assert.deepEqual([rs.kind, rs.role, rs.by, rs.next, rs.you, rs.opp], ['catch', 'thrower', 'opp', 'opp', 0, 0]);
    assert.deepEqual([rd.kind, rd.role, rd.by, rd.next], ['catch', 'defender', 'you', 'you']);
    def.send({ t: 'throw', hen: HEN });                         // le défenseur relance immédiatement
    assert.ok(await server.wait(type('throw')));
  }));
  test('but : le lanceur marque et garde la poule ; scores vus de chaque côté', withClients(async (mk) => {
    const { server, def } = await matchUp(await mk('a'), await mk('b'));
    server.send({ t: 'throw', hen: HEN }); await def.wait(type('throw'));
    def.send({ t: 'miss' });
    const rs = await server.wait(type('result')), rd = await def.wait(type('result'));
    assert.deepEqual([rs.kind, rs.by, rs.you, rs.opp, rs.next, rs.over], ['goal', 'you', 1, 0, 'you', false]);
    assert.deepEqual([rd.by, rd.you, rd.opp, rd.next], ['opp', 0, 1, 'opp']);
    server.send({ t: 'throw', hen: HEN });                       // le marqueur relance
    assert.ok(await def.wait(type('throw')));
  }));
  test('« miss » du lanceur lui-même ou sans lancer en cours : ignoré', withClients(async (mk) => {
    const { server, def } = await matchUp(await mk('a'), await mk('b'));
    def.send({ t: 'miss' });
    assert.ok(await server.none(type('result')), 'pas de lancer en cours');
    server.send({ t: 'throw', hen: HEN }); await def.wait(type('throw'));
    server.send({ t: 'miss' });
    assert.ok(await def.none(type('result')), 'le lanceur ne peut pas se déclarer raté');
  }));
  test('position de l\'adversaire relayée et bornée', withClients(async (mk) => {
    const { server, def } = await matchUp(await mk('a'), await mk('b'));
    server.send({ t: 'pos', x: 0.4 });
    assert.equal((await def.wait(type('pos'))).x, 0.4);
    server.send({ t: 'pos', x: 50 });
    assert.equal((await def.wait(type('pos'))).x, 1);
    server.send({ t: 'pos', x: 'a' });
    assert.ok(await def.none(type('pos')));
  }));
  test('match complet jusqu\'à 4-0, puis revanche avec remise à zéro', withClients(async (mk) => {
    const { server, def } = await matchUp(await mk('a'), await mk('b'));
    let r;
    for (let i = 1; i <= 4; i++) {
      server.send({ t: 'throw', hen: HEN }); await def.wait(type('throw'));
      def.send({ t: 'miss' });
      r = await server.wait(type('result')); await def.wait(type('result'));
      assert.equal(r.you, i);
      assert.equal(r.over, i === 4, `over à ${i}-0`);
    }
    server.send({ t: 'throw', hen: HEN });
    assert.ok(await def.none(type('throw')), 'plus de lancer une fois le match terminé');
    server.send({ t: 'rematch' });
    assert.ok(await def.wait(type('rematch_ask')));
    def.send({ t: 'rematch' });
    const n1 = await server.wait(type('match')), n2 = await def.wait(type('match'));
    assert.ok(n1 && n2 && n1.serve !== n2.serve);
    assert.equal(n2.serve, true, 'le perdant de la partie précédente sert en premier');
  }));
  test('plafond à 12 points : 12-11 termine le match, sans écart de 2', withClients(async (mk) => {
    const { server, def } = await matchUp(await mk('a'), await mk('b'));
    let holder = server;                                        // celui qui a la poule
    const other = () => (holder === server ? def : server);
    // fait marquer `winner` en respectant les tours : le lanceur marque si l'autre rate ; pour faire marquer
    // celui qui n'a pas la poule, il doit d'abord la rattraper, la relancer, puis le premier rate.
    async function point(winner) {
      let r;
      if (holder !== winner) {
        holder.send({ t: 'throw', hen: HEN }); await other().wait(type('throw'));
        await sleep(170);                                       // un rattrapage doit durer > 150 ms
        other().send({ t: 'catch' });
        await holder.wait(type('result')); await other().wait(type('result'));
        holder = winner;
      }
      holder.send({ t: 'throw', hen: HEN }); await other().wait(type('throw'));
      other().send({ t: 'miss' });
      r = await holder.wait(type('result')); await other().wait(type('result'));
      return r;
    }
    let last;
    const t0 = Date.now();
    for (let i = 0; i < 22; i++) {                              // 1-0, 1-1, 2-1, 2-2 ... 11-11 : jamais 2 d'écart
      last = await point(i % 2 === 0 ? server : def);
      assert.equal(last.over, false, `terminé trop tôt à la manche ${i + 1}`);
    }
    assert.ok(Date.now() - t0 < 15000, 'le scénario ne doit pas dépendre des délais du serveur');
    last = await point(server);
    assert.equal(last.over, true);
    assert.deepEqual([last.you, last.opp], [12, 11]);
  }));
  test('départ en cours de match : l\'adversaire est prévenu (oppleft) et retourne au salon', withClients(async (mk) => {
    const a = await mk('a'), b = await mk('b');
    const { server, def } = await matchUp(a, b);
    def.send({ t: 'leave' });
    assert.ok(await server.wait(type('oppleft')));
    assert.ok(await def.wait(type('left')));
    const l = await server.wait((m) => m.t === 'lobby' && [a.id, b.id].every((id) => m_status(m, id) === 'lobby'));
    assert.ok(l, 'les deux sont de nouveau disponibles');
  }));
  test('déconnexion brutale en match : oppleft', withClients(async (mk) => {
    const { server, def } = await matchUp(await mk('a'), await mk('b'));
    def.close();
    assert.ok(await server.wait(type('oppleft')));
  }));
  test('flood : l\'excédent au-delà de 80 messages par seconde est ignoré sans casser la connexion', withClients(async (mk) => {
    const a = await mk('flood');
    for (let i = 0; i < 400; i++) a.send({ t: 'ping' });
    await sleep(500);
    const pongs = a.inbox.filter(type('pong')).length;
    // fenêtre fixe d'une seconde : une rafale à cheval sur deux secondes peut laisser passer jusqu'à 2 x 80
    assert.ok(pongs <= 160, `${pongs} réponses : le flood n'est pas limité`);
    assert.ok(pongs >= 20, 'il en passe quand même');
    a.send({ t: 'ping' }); a.inbox.length = 0;
    await sleep(1100);                                           // la fenêtre suivante est libre
    a.send({ t: 'ping' });
    assert.ok(await a.wait(type('pong')), 'la connexion reste utilisable');
  }));
});

// ------------------------------------------------------------------------------------------------ délais
describe('délais du serveur', () => {
  const needShort = (t) => { if (timeoutsShort) return false; t.skip('délais non réduits (lancer sans WS_URL, ou CHICKEN_TIMEOUT_TESTS=1)'); return true; };
  test('sans verdict du défenseur, le lanceur marque', withClients(async (mk, t) => {
    if (needShort(t)) return;
    const { server, def } = await matchUp(await mk('a'), await mk('b'));
    server.send({ t: 'throw', hen: HEN }); await def.wait(type('throw'));
    const rs = await server.wait(type('result'), 4000);
    assert.deepEqual([rs.kind, rs.by, rs.you], ['goal', 'you', 1]);
    assert.equal((await def.wait(type('result'))).by, 'opp');
  }));
  test('le joueur qui ne lance pas perd le point', withClients(async (mk, t) => {
    if (needShort(t)) return;
    const { server, def } = await matchUp(await mk('a'), await mk('b'));
    // le serveur de jeu ne lance jamais : au bout du délai (+ 2,5 s de grâce après un but), l'adversaire marque
    const r = await def.wait(type('result'), 6000);
    assert.ok(r, 'point attribué');
    assert.deepEqual([r.kind, r.by, r.you], ['goal', 'you', 1]);
    assert.equal((await server.wait(type('result'))).by, 'opp');
  }));
});
