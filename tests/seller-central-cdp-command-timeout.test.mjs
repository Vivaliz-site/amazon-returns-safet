import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const workers = [
  ['read', fileURLToPath(new URL('../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs', import.meta.url)), '\nfunction authState'],
  ['bridge', fileURLToPath(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url)), '\nfunction authenticationState'],
];

class FakeSocket extends EventTarget {
  send() {}
  close() { this.dispatchEvent(new Event('close')); }
}

for (const [name, path, endMarker] of workers) {
  test(`${name} worker times out an unanswered CDP command and clears pending state`, async () => {
    const source = fs.readFileSync(path, 'utf8');
    const start = source.indexOf('class Cdp {');
    const end = source.indexOf(endMarker, start);
    assert.ok(start >= 0 && end > start, `${name} CDP class markers must remain auditable`);
    const klass = source.slice(start, end);
    const Cdp = vm.runInNewContext(`${klass}\nCdp`, { Error, setTimeout, clearTimeout });
    const cdp = new Cdp(new FakeSocket(), null, 25);
    const guard = new Promise((_, reject) => setTimeout(() => reject(new Error('test guard expired')), 250));
    await assert.rejects(Promise.race([cdp.send('Runtime.evaluate'), guard]), /CDP command timed out/);
    assert.equal(cdp.pending.size, 0, `${name} timed-out command must be removed from pending map`);
  });
}
