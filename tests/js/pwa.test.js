// PWA : le jeu peut s'installer et se lancer hors ligne.
'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const WEB = path.join(__dirname, '..', '..', 'web');
const read = (f) => fs.readFileSync(path.join(WEB, f), 'utf8');
const manifest = JSON.parse(read('manifest.webmanifest'));

test('manifeste : nom, démarrage, affichage, couleurs et icônes qui existent', () => {
  assert.ok(manifest.name && manifest.short_name);
  assert.equal(manifest.start_url, './');
  assert.equal(manifest.display, 'standalone');
  assert.match(manifest.theme_color, /^#[0-9a-f]{6}$/i);
  const sizes = manifest.icons.map((i) => i.sizes);
  assert.ok(sizes.includes('192x192') && sizes.includes('512x512'));
  for (const icon of manifest.icons) assert.ok(fs.existsSync(path.join(WEB, icon.src)), icon.src);
  assert.ok(fs.existsSync(path.join(WEB, 'icons/apple-touch-icon.png')));
});

test('la page relie le manifeste, l\'icône iOS et enregistre le service worker', () => {
  const html = read('index.php');
  assert.match(html, /<link rel="manifest" href="manifest\.webmanifest">/);
  assert.match(html, /apple-touch-icon/);
  assert.match(html, /<script src="pwa\.js" defer><\/script>/);
  assert.match(read('pwa.js'), /serviceWorker\.register\('sw\.js'\)/);
});

test('service worker : réseau d\'abord, cache en secours, jamais l\'API ni les données', () => {
  const sw = read('sw.js');
  assert.match(sw, /addEventListener\('fetch'/);
  assert.match(sw, /caches\.match/);
  assert.match(sw, /api\.php/);
  assert.match(sw, /\/data\//);
  new Function(sw.replace(/self\.addEventListener/g, '(() => {})'));        // syntaxe valide
});
