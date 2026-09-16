import fs from 'node:fs';
import assert from 'node:assert/strict';

const worker = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');

const updateStart = worker.indexOf('async function supportUpdate');
assert.ok(updateStart >= 0, 'supportUpdate must exist');
const updateEnd = worker.indexOf('\nasync function ', updateStart + 1);
const supportUpdate = worker.slice(updateStart, updateEnd > updateStart ? updateEnd : undefined);

for (const label of ['Send', 'Send message', 'Reply', 'Enviar', 'Enviar mensagem', 'Responder']) {
  assert.ok(
    supportUpdate.includes(`'${label}'`),
    `supportUpdate must recognize Seller Central reply action label: ${label}`,
  );
}
assert.match(supportUpdate, /querySelectorAll\('kat-button,button'\)/, 'reply action must continue to use trusted visible button hosts');

console.log('seller-support-reply-labels-contract-test: OK');
