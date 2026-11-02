'use strict';
const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const read = (p) => fs.readFileSync(path.join(ROOT, p), 'utf8');
const I18N_SRC = read('web/assets/i18n.js');

// Charge i18n.js dans un contexte isolé, avec un faux navigateur.
function load({ languages, language, stored = null, throwStorage = false } = {}) {
  const calls = { applied: [], store: {} };
  if (stored) calls.store.cc_lang = stored;
  const doc = {
    documentElement: { lang: '' },
    querySelectorAll: () => [],
  };
  const win = {};
  const ctx = vm.createContext({
    window: win, document: doc,
    navigator: { languages, language },
    localStorage: throwStorage
      ? { getItem() { throw new Error('bloqué'); }, setItem() { throw new Error('bloqué'); } }
      : { getItem: (k) => calls.store[k] ?? null, setItem: (k, v) => { calls.store[k] = v; } },
  });
  vm.runInContext(I18N_SRC, ctx);
  return { I18N: win.I18N, doc, store: calls.store };
}

describe('détection de la langue', () => {
  for (const [langs, expected] of [
    [['fr-FR', 'fr', 'en'], 'fr'], [['fr-CA'], 'fr'], [['FR'], 'fr'], [['en-US'], 'en'],
    [['de-DE', 'fr'], 'en'], [['es-ES'], 'en'], [['ja'], 'en'], [['frr'], 'fr'],
  ]) {
    test(`${JSON.stringify(langs)} -> ${expected}`, () => assert.equal(load({ languages: langs }).I18N.lang, expected));
  }
  test('sans navigator.languages, retombe sur navigator.language', () => {
    assert.equal(load({ language: 'fr-BE' }).I18N.lang, 'fr');
    assert.equal(load({ language: 'en-GB' }).I18N.lang, 'en');
  });
  test('sans aucune information : anglais', () => assert.equal(load({}).I18N.lang, 'en'));
  test('le choix mémorisé prime sur la langue du navigateur', () => {
    assert.equal(load({ languages: ['en-US'], stored: 'fr' }).I18N.lang, 'fr');
    assert.equal(load({ languages: ['fr-FR'], stored: 'en' }).I18N.lang, 'en');
  });
  test('une valeur mémorisée invalide est ignorée', () => {
    assert.equal(load({ languages: ['fr-FR'], stored: 'klingon' }).I18N.lang, 'fr');
  });
  test('fonctionne même si localStorage est inaccessible', () => {
    const { I18N } = load({ languages: ['fr-FR'], throwStorage: true });
    assert.equal(I18N.lang, 'fr');
    assert.doesNotThrow(() => I18N.setLang('en'));
    assert.equal(I18N.lang, 'en');
  });
});

describe('traduction', () => {
  test('t() interpole {variables} et retombe sur la clé si inconnue', () => {
    const { I18N } = load({ languages: ['en-US'] });
    assert.equal(I18N.t('invite.text', { name: 'Alice' }), 'Alice challenges you!');
    assert.equal(I18N.t('cle.inconnue'), 'cle.inconnue');
    assert.equal(I18N.t('invite.text', {}), ' challenges you!');
  });
  test('tn() choisit le singulier ou le pluriel', () => {
    const { I18N } = load({ languages: ['fr-FR'] });
    assert.match(I18N.tn('lobby.online', 1, { name: 'A' }), /1 joueur en ligne/);
    assert.match(I18N.tn('lobby.online', 3, { name: 'A' }), /3 joueurs en ligne/);
    assert.match(I18N.tn('lobby.online', 0, { name: 'A' }), /0 joueurs/);
  });
  test('setLang change la langue, la mémorise, met à jour <html lang> et prévient les écouteurs', () => {
    const { I18N, doc, store } = load({ languages: ['en-US'] });
    const seen = [];
    I18N.onChange((l) => seen.push(l));
    I18N.setLang('fr');
    assert.equal(I18N.lang, 'fr'); assert.equal(store.cc_lang, 'fr'); assert.equal(doc.documentElement.lang, 'fr');
    assert.equal(I18N.t('menu.play'), 'JOUER CONTRE L\'IA');
    I18N.setLang('en');
    assert.deepEqual(seen, ['fr', 'en']);
  });
  test('setLang ignore une langue non prise en charge', () => {
    const { I18N } = load({ languages: ['en-US'] });
    I18N.setLang('de');
    assert.equal(I18N.lang, 'en');
  });
});

describe('cohérence des dictionnaires', () => {
  const { dict } = load({ languages: ['en-US'] }).I18N;
  const keys = (l) => Object.keys(dict[l]).sort();
  const vars = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(',');

  test('FR et EN ont exactement les mêmes clés', () => {
    assert.deepEqual(keys('fr'), keys('en'));
  });
  test('aucune traduction vide', () => {
    for (const l of ['fr', 'en']) for (const [k, v] of Object.entries(dict[l])) assert.ok(String(v).trim(), `${l}:${k}`);
  });
  test('les {variables} sont les mêmes en FR et en EN pour chaque clé', () => {
    for (const k of keys('fr')) assert.equal(vars(dict.fr[k]), vars(dict.en[k]), `variables de ${k}`);
  });
  test('toutes les clés utilisées dans index.php existent', () => {
    const html = read('web/index.php');
    const used = [...html.matchAll(/data-i18n(?:-html|-title|-ph)?="([^"]+)"/g)].map((m) => m[1]);
    assert.ok(used.length > 15);
    for (const k of used) { assert.ok(k in dict.fr, `fr:${k}`); assert.ok(k in dict.en, `en:${k}`); }
  });
  test('toutes les clés t()/tn() de game.js existent (statiques et familles dynamiques)', () => {
    const js = read('web/assets/game.js');
    const used = new Set([...js.matchAll(/\bt[n]?\(\s*'([\w.]+)'\s*[,)]/g)].map((m) => m[1]));
    assert.ok(used.size > 25);
    for (const k of used) {
      const ok = k in dict.fr || (k + '_one') in dict.fr;       // clés pluriel : lobby.online_one / _other
      assert.ok(ok, `clé utilisée mais absente : ${k}`);
    }
    // familles construites dynamiquement dans game.js
    const families = [
      ...[0, 1, 2, 3].map((i) => `char${i}`), ...[0, 1, 2].map((i) => `level${i}`), ...[0, 1, 2, 3].map((i) => `splat${i}`),
      'st.lobby', 'st.queue', 'st.match', 'flash.goal', 'flash.caught',
    ];
    for (const k of families) assert.ok(k in dict.fr && k in dict.en, k);
  });
  test('tous les codes du serveur ont un message srv.<code> dans les deux langues', () => {
    const php = read('server/server.php');
    const codes = new Set([...php.matchAll(/'code'\s*=>\s*'(\w+)'/g)].map((m) => m[1]));
    assert.ok(codes.size >= 5, `codes trouvés : ${[...codes]}`);
    for (const c of codes) { assert.ok(`srv.${c}` in dict.fr, `fr:srv.${c}`); assert.ok(`srv.${c}` in dict.en, `en:srv.${c}`); }
  });
});
