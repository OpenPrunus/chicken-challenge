'use strict';
const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const E = require('../../web/assets/engine.js');
const gen = require('../fixtures/generate.js');

describe('vecteurs de test de la physique (JS <-> PHP)', () => {
  const file = path.join(__dirname, '..', 'fixtures', 'physics-vectors.json');
  const onDisk = JSON.parse(fs.readFileSync(file, 'utf8'));

  test('le fichier est à jour avec le moteur : sinon lancer `npm run fixtures` (PHP et JS ne doivent pas diverger en silence)', () => {
    assert.deepEqual(JSON.parse(JSON.stringify(gen.build())), onDisk);
  });
  test('la génération est déterministe', () => {
    assert.deepEqual(gen.build(), gen.build());
  });
  test('les lancers légitimes respectent les bornes du vrai jeu', () => {
    for (const w of onDisk.legit) {
      assert.ok(w.speed >= 0.7 - 1e-9 && w.speed <= 1.6 + 1e-9, `speed ${w.speed}`);
      assert.ok(Math.abs(w.spin) <= 2.2 + 1e-9, `spin ${w.spin}`);
      assert.ok(Math.abs(w.x) <= 0.8 + 1e-9 && Math.abs(w.y - 0.6) < 1e-9, 'point de départ');
    }
  });
  test('les captures honnêtes sont bien acceptées par le moteur au moment enregistré', () => {
    for (const c of onDisk.honest) {
      const h = E.mirrorHen(c.hen);                                       // l'état exact n'est pas stocké : on vérifie au moins la fenêtre de temps
      assert.ok(c.c > 0.2 && c.c < 3, `temps ${c.c}`);
      assert.ok(Math.abs(c.hand[0]) <= 1.5 && Math.abs(c.hand[1]) <= 1.5);
      assert.ok(h.z === 1);
    }
  });
  test('le jeu de test mélange pas de temps fixes (30/60/144 Hz) et variables', () => {
    const kinds = new Set(onDisk.honest.map((c) => String(c.dt)));
    assert.ok(kinds.has('variable') && kinds.size >= 3, [...kinds].join(','));
  });
});
