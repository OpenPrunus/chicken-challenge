'use strict';
/**
 * API du classement contre l'IA : lance le vrai site PHP (php -S) sur une COPIE de web/ et joue le rôle d'un
 * client honnête, puis d'un tricheur : faux tickets, rejeu, usurpation de pseudo, rafales, scores trop rapides.
 * (Le serveur interne de PHP ignore .htaccess : l'interdiction d'accès à data/ est testée par le test Docker de la CI.)
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
const SECRET = 'secret-de-test-pour-fabriquer-des-tickets-dans-les-tests-1234567890';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const tok = () => crypto.randomBytes(16).toString('hex');
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');

const procs = []; const dirs = [];
after(() => { procs.forEach((p) => p.kill()); dirs.forEach((d) => fs.rmSync(d, { recursive: true, force: true })); });
const freePort = () => new Promise((res, rej) => { const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); }); s.on('error', rej); });

/** Démarre un site sur une copie de web/ ; `env` règle les limites (durée minimale, débit…). */
async function startSite(env = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'chicken-api-')); dirs.push(dir);
  fs.cpSync(path.join(ROOT, 'web'), path.join(dir, 'web'), { recursive: true });
  fs.rmSync(path.join(dir, 'web', 'data'), { recursive: true, force: true }); fs.mkdirSync(path.join(dir, 'web', 'data'));
  const port = await freePort();
  const p = spawn(PHP, ['-S', `127.0.0.1:${port}`, '-t', path.join(dir, 'web')], {
    env: { ...process.env, CHICKEN_AUTOLOAD: path.join(ROOT, 'vendor', 'autoload.php'), CHICKEN_SECRET: SECRET, CHICKEN_MIN_POINT_SECONDS: '0', ...env }, stdio: 'ignore',
  });
  procs.push(p);
  const base = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 80; i++) { try { if ((await fetch(base + '/api.php')).ok) break; } catch {} await sleep(100); }
  const data = path.join(dir, 'web', 'data');
  return {
    base, data,
    post: (body, headers = {}) => fetch(base + '/api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', ...headers }, body: typeof body === 'string' ? body : JSON.stringify(body) }),
    get: (q = '') => fetch(base + '/api.php' + q).then((r) => r.json()),
    ticket: async (token, level = 1, headers = {}) => { const r = await fetch(base + '/api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', ...headers }, body: JSON.stringify({ action: 'start', token, level }) }); return { status: r.status, body: await r.json() }; },
    seed: (rows) => fs.writeFileSync(path.join(data, 'scores.json'), JSON.stringify(rows)),
    read: () => JSON.parse(fs.readFileSync(path.join(data, 'scores.json'), 'utf8')),
  };
}
/** Ticket fabriqué avec le secret connu du test (pour tester les cas d'horloge). */
const b64u = (s) => Buffer.from(s).toString('base64url');
function craft({ t, h, l = 1, n = crypto.randomBytes(8).toString('hex'), secret = SECRET }) {
  const payload = b64u(JSON.stringify({ n, t, h, l }));
  return `${payload}.${crypto.createHmac('sha256', secret).update(payload).digest('hex')}`;
}
let n = 0; const nick = (p) => `${p}${process.pid % 1000}${++n}`;
const play = async (site, token, name, me = 4, opp = 1, headers = {}) => {
  const { body } = await site.ticket(token, 1, headers);
  return site.post({ ticket: body.ticket, name, me, opp }, headers);
};

describe('parcours honnête', () => {
  test('ticket -> score : enregistré, visible au classement, sans jamais exposer le hash du jeton', async () => {
    const site = await startSite();
    const token = tok(), name = nick('alice');
    const t = await site.ticket(token, 2);
    assert.equal(t.status, 200);
    assert.match(t.body.ticket, /^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/);
    const r = await site.post({ ticket: t.body.ticket, name, me: 4, opp: 2 });
    assert.equal(r.status, 200); assert.deepEqual(await r.json(), { ok: true });
    const board = await site.get();
    assert.deepEqual(board, [{ name, played: 1, wins: 1, gf: 4, ga: 2 }]);
    assert.ok(!JSON.stringify(board).includes(sha(token)) && !JSON.stringify(board).includes('"th"'), 'pas de hash dans l\'API');
    assert.equal(site.read()[0].th, sha(token), 'mais il est bien conservé côté serveur');
  });

  test('plusieurs parties du même joueur s\'additionnent ; une défaite compte une partie jouée', async () => {
    const site = await startSite();
    const token = tok(), name = nick('bob');
    assert.equal((await play(site, token, name, 4, 1)).status, 200);
    assert.equal((await play(site, token, name, 2, 4)).status, 200);
    const [row] = await site.get();
    assert.deepEqual([row.played, row.wins, row.gf, row.ga], [2, 1, 6, 5]);
  });

  test('requêtes invalides : méthode, JSON, score, jeton, niveau', async () => {
    const site = await startSite();
    assert.equal((await fetch(site.base + '/api.php', { method: 'DELETE' })).status, 405);
    assert.equal((await site.post('pas du json')).status, 400);
    assert.equal((await site.post('[1,2]')).status, 403, 'tableau JSON sans ticket');
    const token = tok();
    const { body } = await site.ticket(token);
    assert.equal((await site.post({ ticket: body.ticket, name: nick('x'), me: 4, opp: 3 })).status, 422, 'un seul point d\'écart');
    assert.equal((await site.post({ ticket: body.ticket, name: '<<>>', me: 4, opp: 0 })).status, 422, 'pseudo vide après nettoyage');
    assert.equal((await site.post({ ticket: body.ticket, name: nick('x'), me: 13, opp: 0 })).status, 422);
    assert.equal((await site.ticket('court')).status, 400);
    assert.equal((await site.ticket(tok(), 9)).status, 400);
    assert.equal((await site.ticket(tok(), -1)).status, 400);
  });

  test('les parties refusées pour score invalide ne consomment pas le ticket', async () => {
    const site = await startSite();
    const { body } = await site.ticket(tok());
    assert.equal((await site.post({ ticket: body.ticket, name: nick('z'), me: 4, opp: 3 })).status, 422);
    assert.equal((await site.post({ ticket: body.ticket, name: nick('z'), me: 4, opp: 0 })).status, 200, 'le même ticket sert pour un score valide');
  });
});

describe('contre les faux scores', () => {
  test('sans ticket, avec un faux ticket, ou avec une signature modifiée : refusé (403)', async () => {
    const site = await startSite();
    const name = nick('forge');
    assert.equal((await site.post({ name, me: 12, opp: 0 })).status, 403, 'l\'ancien format (sans ticket)');
    assert.equal((await site.post({ ticket: '', name, me: 12, opp: 0 })).status, 403);
    assert.equal((await site.post({ ticket: 'a.b', name, me: 12, opp: 0 })).status, 403);
    assert.equal((await site.post({ ticket: craft({ t: Math.floor(Date.now() / 1000), h: sha(tok()), secret: 'mauvais-secret-de-meme-longueur-que-le-vrai-secret-ok-123456' }), name, me: 12, opp: 0 })).status, 403, 'signé avec un autre secret');
    const { body } = await site.ticket(tok());
    const [p, sig] = body.ticket.split('.');
    const flipped = sig[0] === 'a' ? 'b' : 'a';
    assert.equal((await site.post({ ticket: `${p}.${flipped}${sig.slice(1)}`, name, me: 12, opp: 0 })).status, 403, 'signature modifiée');
    const forgedPayload = b64u(JSON.stringify({ ...JSON.parse(Buffer.from(p, 'base64url')), l: 0, h: sha('autre') }));
    assert.equal((await site.post({ ticket: `${forgedPayload}.${sig}`, name, me: 12, opp: 0 })).status, 403, 'charge utile modifiée');
    assert.deepEqual(await site.get(), [], 'rien n\'a été enregistré');
  });

  test('un ticket ne sert qu\'une fois (rejeu refusé)', async () => {
    const site = await startSite();
    const { body } = await site.ticket(tok());
    const msg = { ticket: body.ticket, name: nick('replay'), me: 4, opp: 0 };
    assert.equal((await site.post(msg)).status, 200);
    for (let i = 0; i < 3; i++) assert.equal((await site.post(msg)).status, 403, `rejeu ${i + 1}`);
    assert.equal((await site.get())[0].played, 1);
  });

  test('ticket expiré ou daté du futur : refusé', async () => {
    const site = await startSite();
    const h = sha(tok()), now = Math.floor(Date.now() / 1000), name = nick('clock');
    assert.equal((await site.post({ ticket: craft({ t: now - 3 * 3600 - 30, h }), name, me: 4, opp: 0 })).status, 403, 'plus de 3 h');
    assert.equal((await site.post({ ticket: craft({ t: now + 3600, h }), name, me: 4, opp: 0 })).status, 403, 'daté du futur');
    assert.equal((await site.post({ ticket: craft({ t: now - 60, h }), name, me: 4, opp: 0 })).status, 200, 'ticket valide fabriqué avec le bon secret');
  });

  test('partie trop rapide : refusée tant que la durée minimale n\'est pas écoulée', async () => {
    const site = await startSite({ CHICKEN_MIN_POINT_SECONDS: '0.4' });                    // 4-0 -> 1,6 s minimum
    const { body } = await site.ticket(tok());
    const msg = { ticket: body.ticket, name: nick('speed'), me: 4, opp: 0 };
    const early = await site.post(msg);
    assert.equal(early.status, 422); assert.deepEqual(await early.json(), { error: 'too_fast' });
    assert.deepEqual(await site.get(), []);
    await sleep(2800);                                                                      // l'heure du ticket est à la seconde près : on laisse de la marge
    assert.equal((await site.post(msg)).status, 200, 'même ticket, une fois le temps écoulé');
  });

  test('avec la durée par défaut, un script qui poste immédiatement un 12-0 est refusé', async () => {
    const site = await startSite({ CHICKEN_MIN_POINT_SECONDS: '2' });
    const { body } = await site.ticket(tok());
    const r = await site.post({ ticket: body.ticket, name: nick('bot'), me: 12, opp: 0 });
    assert.equal(r.status, 422); assert.equal((await r.json()).error, 'too_fast');
  });

  test('limite de débit par jeton : au-delà de N parties par heure, 429 avec retry_after', async () => {
    const site = await startSite({ CHICKEN_RATE_RECORDS: '3' });
    const token = tok(), name = nick('rate');
    for (let i = 0; i < 3; i++) assert.equal((await play(site, token, name, 4, 0, { 'X-Forwarded-For': `198.51.100.${i + 1}` })).status, 200, `partie ${i + 1}`);
    const r = await play(site, token, name, 4, 0, { 'X-Forwarded-For': '198.51.100.99' });         // autre IP, même jeton
    assert.equal(r.status, 429);
    const j = await r.json(); assert.equal(j.error, 'rate_limited'); assert.ok(j.retry_after > 3000 && j.retry_after <= 3600, `retry_after ${j.retry_after}`);
    assert.equal((await site.get())[0].played, 3);
    assert.equal((await play(site, tok(), nick('other'), 4, 0, { 'X-Forwarded-For': '203.0.113.50' })).status, 200, 'un autre joueur, une autre IP : pas touché');
  });

  test('limite de débit par adresse IP : plusieurs jetons depuis la même IP partagent le quota', async () => {
    const site = await startSite({ CHICKEN_RATE_RECORDS: '3' });
    const ip = { 'X-Forwarded-For': '198.51.100.7' };
    for (let i = 0; i < 3; i++) assert.equal((await play(site, tok(), nick('multi'), 4, 0, ip)).status, 200);
    assert.equal((await play(site, tok(), nick('multi'), 4, 0, ip)).status, 429, 'quatrième jeton, même IP');
    assert.equal((await play(site, tok(), nick('multi'), 4, 0, { 'X-Forwarded-For': '198.51.100.8' })).status, 200, 'autre IP');
  });

  test('limite de débit sur les tickets : on ne peut pas non plus les fabriquer en rafale', async () => {
    const site = await startSite({ CHICKEN_RATE_TICKETS: '3' });
    const token = tok();
    for (let i = 0; i < 3; i++) assert.equal((await site.ticket(token)).status, 200);
    const r = await site.ticket(token);
    assert.equal(r.status, 429); assert.equal(r.body.error, 'rate_limited');
  });

  test('une rafale de 300 faux scores sans ticket n\'en enregistre aucun', async () => {
    const site = await startSite();
    const codes = await Promise.all(Array.from({ length: 300 }, (_, i) => site.post({ name: 'Tricheur', me: 12, opp: 0 }).then((r) => r.status)));
    assert.ok(codes.every((c) => c === 403), 'tous refusés');
    assert.deepEqual(await site.get(), []);
  });

  test('un script qui s\'invente 50 victoires en rafale avec de vrais tickets est stoppé par la limite', async () => {
    const site = await startSite({ CHICKEN_RATE_RECORDS: '8' });
    const token = tok(), ip = { 'X-Forwarded-For': '203.0.113.200' };
    const results = [];
    for (let i = 0; i < 50; i++) results.push((await play(site, token, 'Tricheur', 12, 0, ip)).status);
    assert.equal(results.filter((s) => s === 200).length, 8, 'huit parties au maximum');
    assert.ok(results.slice(8).every((s) => s === 429 || s === 403), 'tout le reste est refusé (limite de débit, ou plus de ticket une fois le quota de tickets épuisé)');
    assert.equal((await site.get())[0].played, 8);
  });
});

describe('pseudos réservés', () => {
  test('un autre jeton ne peut pas poster sous un pseudo déjà pris (casse ignorée) ; le propriétaire peut', async () => {
    const site = await startSite();
    const owner = tok(), thief = tok(), name = nick('Reine');
    assert.equal((await play(site, owner, name, 4, 0)).status, 200);
    const r = await play(site, thief, name.toUpperCase(), 12, 0);
    assert.equal(r.status, 403); assert.equal((await r.json()).error, 'name_taken');
    assert.equal((await play(site, owner, name, 4, 1)).status, 200, 'le propriétaire continue');
    const [row] = await site.get();
    assert.deepEqual([row.played, row.wins, row.gf, row.ga], [2, 2, 8, 1], 'le voleur n\'a rien ajouté');
  });

  test('une ancienne ligne sans jeton est réservée par le premier jeton qui poste, puis protégée', async () => {
    const site = await startSite();
    site.seed([{ name: 'Ancien', played: 3, wins: 1, gf: 5, ga: 9 }]);
    const first = tok(), second = tok();
    assert.equal((await play(site, first, 'Ancien', 4, 0)).status, 200, 'le premier la réserve');
    assert.equal((await play(site, second, 'Ancien', 4, 0)).status, 403);
    assert.equal(site.read()[0].played, 4);
  });

  test('un joueur peut utiliser plusieurs pseudos libres avec son jeton', async () => {
    const site = await startSite();
    const token = tok();
    assert.equal((await play(site, token, nick('a'), 4, 0)).status, 200);
    assert.equal((await play(site, token, nick('b'), 4, 0)).status, 200);
    assert.equal((await site.get()).length, 2);
  });
});

describe('robustesse', () => {
  test('rafale de 20 parties simultanées légitimes : aucune n\'est perdue (verrou)', async () => {
    const site = await startSite({ CHICKEN_RATE_RECORDS: '100', CHICKEN_RATE_TICKETS: '100' });
    const players = Array.from({ length: 20 }, () => ({ token: tok(), name: nick('par') }));
    const tickets = await Promise.all(players.map((p, i) => site.ticket(p.token, 1, { 'X-Forwarded-For': `198.51.100.${i + 1}` })));
    const codes = await Promise.all(players.map((p, i) => site.post({ ticket: tickets[i].body.ticket, name: p.name, me: 4, opp: 0 }, { 'X-Forwarded-For': `198.51.100.${i + 1}` }).then((r) => r.status)));
    assert.ok(codes.every((c) => c === 200), codes.join(','));
    assert.equal((await site.get('')).length, 20);
    assert.equal(site.read().reduce((s, r) => s + r.played, 0), 20);
  });

  test('le même ticket envoyé 10 fois en parallèle n\'est compté qu\'une seule fois', async () => {
    const site = await startSite();
    const { body } = await site.ticket(tok());
    const msg = { ticket: body.ticket, name: nick('race'), me: 4, opp: 0 };
    const codes = await Promise.all(Array.from({ length: 10 }, () => site.post(msg).then((r) => r.status)));
    assert.equal(codes.filter((c) => c === 200).length, 1, codes.join(','));
    assert.equal(site.read()[0].played, 1);
  });

  test('le secret est créé au premier appel s\'il n\'est pas fourni, et réutilisé ensuite', async () => {
    const site = await startSite({ CHICKEN_SECRET: '' });
    assert.equal(fs.existsSync(path.join(site.data, 'secret.key')), false);
    const token = tok();
    const { body } = await site.ticket(token);
    const secret = fs.readFileSync(path.join(site.data, 'secret.key'), 'utf8').trim();
    assert.match(secret, /^[a-f0-9]{64}$/);
    assert.equal((await site.post({ ticket: body.ticket, name: nick('sec'), me: 4, opp: 0 })).status, 200, 'le ticket reste valable (même secret)');
    assert.equal(fs.readFileSync(path.join(site.data, 'secret.key'), 'utf8').trim(), secret, 'secret stable');
  });

  test('les compteurs sont purgés : le fichier d\'état ne grossit pas indéfiniment', async () => {
    const site = await startSite({ CHICKEN_RATE_WINDOW: '1', CHICKEN_RATE_RECORDS: '100' });
    for (let i = 0; i < 5; i++) await play(site, tok(), nick('purge'), 4, 0, { 'X-Forwarded-For': `198.51.100.${i + 1}` });
    await sleep(1300);
    await play(site, tok(), nick('purge'), 4, 0, { 'X-Forwarded-For': '203.0.113.1' });
    const abuse = JSON.parse(fs.readFileSync(path.join(site.data, 'abuse.json'), 'utf8'));
    assert.ok(Object.keys(abuse.rate).length <= 4, `clés restantes : ${Object.keys(abuse.rate).length}`);
  });

  test('le classement multijoueur reste lisible via la même API, sans hash', async () => {
    const site = await startSite();
    fs.writeFileSync(path.join(site.data, 'multi_scores.json'), JSON.stringify({ version: 1, players: { alice: { name: 'Alice', th: 'secrethash', rating: 1020, played: 1, wins: 1, losses: 0, gf: 4, ga: 0 } } }));
    const board = await site.get('?board=multi');
    assert.deepEqual(board, [{ name: 'Alice', rating: 1020, played: 1, wins: 1, losses: 0, gf: 4, ga: 0 }]);
  });
});
