#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';

const worker = fs.readFileSync(
  new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url),
  'utf8',
);

assert.ok(
  worker.includes('async function setSafeTOrderInput(cdp, orderId)'),
  'SAFE-T submit must centralize order-field discovery in a guarded helper.',
);
assert.ok(
  worker.includes('kat-input[placeholder="Número do pedido"]'),
  'SAFE-T order-field discovery must preserve the known exact selector as the first contract.',
);
assert.ok(
  worker.includes('await cdp.setFrameKat(selector, orderId)'),
  'SAFE-T order-field discovery must support the same trusted field inside an iframe.',
);
assert.ok(
  worker.includes("const orderHints=['pedido','order']"),
  'SAFE-T fallback discovery must be restricted to pedido/order field hints.',
);
assert.ok(
  worker.includes('if(matches.length!==1)return false'),
  'SAFE-T semantic fallback must fail closed unless exactly one candidate exists.',
);
assert.ok(
  worker.includes('const orderInputReady = await setSafeTOrderInput(cdp, orderId);'),
  'SAFE-T submit must use the guarded order-field helper before eligibility checks.',
);
assert.ok(
  worker.includes("if (!orderInputReady)"),
  'SAFE-T submit must preserve an explicit fail-closed path when order-field discovery is ambiguous.',
);

console.log('safe-t-order-input-contract: OK');
