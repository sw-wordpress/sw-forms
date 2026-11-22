import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

const dist = path.resolve(process.argv[2] ?? '');
assert.ok(process.argv[2], 'Usage: node pages/.vitepress/verify.mjs DIST_DIRECTORY');
const base = '/sw-forms/';
const footer = 'Open source by Stefan Warnat – free to use, free to change, better together.';
const expected = ['index', 'getting-started', 'building-forms', 'configuration',
  'notifications-webhooks', 'entries-privacy', 'templates-import', 'troubleshooting', 'Changelog', '404'];
const files = readdirSync(dist).filter((name) => name.endsWith('.html'));
assert.deepEqual(files.sort(), expected.map((name) => `${name}.html`).sort());
assert.ok(!existsSync(path.join(dist, 'README.html')), 'Maintainer guide must not be published');
const documents = new Map(files.map((name) => [name, readFileSync(path.join(dist, name), 'utf8')]));
let links = 0;
for (const [name, html] of documents) {
  // VitePress emits a client-rendered 404 shell; verify all prerendered user pages.
  if (name === '404.html') continue;
  assert.equal(html.split(footer).length - 1, 1, `${name}: exact footer must occur once`);
  assert.ok(html.includes(`<footer class="swf-docs-footer">${footer}</footer>`), `${name}: universal footer missing`);
  assert.ok(html.includes(`${base}Changelog.html`), `${name}: Changelog navigation missing`);
  for (const match of html.matchAll(/(?:href|src)="([^"]+)"/g)) {
    const value = match[1].replaceAll('&amp;', '&');
    if (/^(https?:|mailto:|data:|tel:)/.test(value)) continue;
    const url = new URL(value, `https://docs.invalid${base}${name}`);
    assert.ok(url.pathname.startsWith(base), `${name}: link outside project base: ${value}`);
    let target = decodeURIComponent(url.pathname.slice(base.length));
    if (!target || target.endsWith('/')) target += 'index.html';
    assert.ok(existsSync(path.join(dist, target)), `${name}: missing target: ${value}`);
    if (url.hash && target.endsWith('.html')) {
      const targetHtml = documents.get(target) ?? readFileSync(path.join(dist, target), 'utf8');
      const id = decodeURIComponent(url.hash.slice(1));
      assert.ok(targetHtml.includes(`id="${id}"`), `${name}: missing anchor: ${value}`);
    }
    links++;
  }
}
const assets = readdirSync(path.join(dist, 'assets'), { recursive: true });
assert.ok(assets.some((name) => /localSearchIndex/.test(name)), 'Local search index missing');
const css = assets.filter((name) => name.endsWith('.css')).map((name) => readFileSync(path.join(dist, 'assets', name), 'utf8')).join('\n');
assert.match(css, /\.swf-docs-footer\{display:block/, 'Universal footer display rule missing');
console.log(`Verified ${documents.size - 1} prerendered user pages, ${links} local links/assets, Changelog navigation, exact universal footer, local search and ${base} base.`);
