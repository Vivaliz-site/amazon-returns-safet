#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';

const worker = fs.readFileSync(
  new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url),
  'utf8',
);

assert.ok(
  worker.includes('async function setSafeTItemQuantity(cdp, quantity)'),
  'SAFE-T item selection must verify that the quantity field was actually written.',
);
assert.ok(
  worker.includes("reason: 'SAFE_T_ITEM_QUANTITY_NOT_WRITABLE'"),
  'SAFE-T item selection must fail closed with an explicit quantity-write drift reason.',
);
assert.ok(
  worker.includes('async function clickSafeTNextButton(cdp)'),
  'SAFE-T next-step discovery must be centralized in a guarded helper.',
);
assert.ok(
  worker.includes('SAFE_T_NEXT_RETRY_ATTEMPTS'),
  'SAFE-T next-step discovery must use a bounded retry window for async controls.',
);
assert.ok(
  worker.includes("button.getAttribute('aria-label')"),
  'SAFE-T next-step discovery must inspect the real button attributes inside a kat-button shadow root.',
);
assert.ok(
  worker.includes('visitDocument(document)'),
  'SAFE-T next-step discovery must traverse same-origin nested iframe documents.',
);
assert.ok(
  worker.includes('if(matches.length!==1)return false'),
  'SAFE-T next-step discovery must fail closed unless exactly one enabled candidate exists.',
);
assert.ok(
  worker.includes("reason: 'SAFE_T_ITEM_NEXT_UNAVAILABLE'"),
  'SAFE-T item selection must expose a specific next-step drift reason.',
);
assert.ok(
  !worker.includes('const nextEnabled = await cdp.evaluate'),
  'SAFE-T item flow must not infer an absent next button as enabled.',
);

console.log('safe-t-item-selection-contract: OK');
