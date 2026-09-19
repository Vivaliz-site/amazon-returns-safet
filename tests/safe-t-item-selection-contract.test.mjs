#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';

const worker = fs.readFileSync(
  new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url),
  'utf8',
);

assert.ok(worker.includes('async function setSafeTQuantity(cdp, quantity)'),
  'SAFE-T item step must centralize quantity discovery.');
assert.ok(worker.includes('await cdp.setFrameKat(selector, value)'),
  'SAFE-T quantity discovery must support same-origin iframe layouts.');
assert.ok(worker.includes("reason: 'SAFE_T_QUANTITY_INPUT_MISSING'"),
  'SAFE-T item step must fail closed when quantity cannot be written.');
assert.ok(worker.includes('async function clickSafeTNextButton(cdp)'),
  'SAFE-T workflow must centralize bounded next-button discovery.');
assert.ok(worker.includes("const nextLabels=['Próximo','Proximo','Next','Continuar','Continue'];"),
  'SAFE-T next-button discovery must use an explicit Portuguese/English allowlist.');
assert.ok(worker.includes('if(matches.length!==1)return false'),
  'SAFE-T next-button discovery must fail closed unless exactly one visible enabled candidate exists.');
assert.ok(worker.includes('visitDocument(frame.contentDocument)'),
  'SAFE-T next-button discovery must cover same-origin iframe layouts.');
assert.ok((worker.match(/await clickSafeTNextButton\(cdp\)/g) || []).length >= 3,
  'SAFE-T item, reason and evidence steps must use the guarded next-button helper.');
assert.ok(!worker.includes('const nextEnabled = await cdp.evaluate'),
  'SAFE-T item selection must not rely on the brittle host disabled-attribute probe.');

console.log('safe-t-item-selection-contract: OK');
