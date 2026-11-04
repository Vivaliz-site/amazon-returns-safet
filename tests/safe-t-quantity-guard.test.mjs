#!/usr/bin/env node
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { safeTUnreceivedQuantity } from '../scripts/amazon-returns/safe-t-quantity-guard.mjs';

for (const [caseData, expected] of [
  [{ quantity_refunded: 1, quantity_received: 0 }, { allowed: true, quantity: 1 }],
  [{ quantity_refunded: 3, quantity_received: 1 }, { allowed: true, quantity: 2 }],
  [{ quantity_refunded: 3, quantity_received: 2 }, { allowed: true, quantity: 1 }],
  [{ quantity_refunded: 1, quantity_received: 1 }, { allowed: false, reason: 'SAFE_T_NO_UNRECEIVED_REFUNDED_UNITS' }],
  [{ quantity_refunded: 1, quantity_received: 2 }, { allowed: false, reason: 'SAFE_T_NO_UNRECEIVED_REFUNDED_UNITS' }],
  [{ quantity_refunded: 0, quantity_received: 0 }, { allowed: false, reason: 'SAFE_T_REFUNDED_QUANTITY_UNVERIFIED' }],
  [{ quantity_refunded: null, quantity_received: 0 }, { allowed: false, reason: 'SAFE_T_REFUNDED_QUANTITY_UNVERIFIED' }],
  [{ quantity_refunded: '1', quantity_received: 0 }, { allowed: false, reason: 'SAFE_T_REFUNDED_QUANTITY_UNVERIFIED' }],
  [{ quantity_refunded: 1.5, quantity_received: 0 }, { allowed: false, reason: 'SAFE_T_REFUNDED_QUANTITY_UNVERIFIED' }],
  [{ quantity_refunded: 1, quantity_received: null }, { allowed: false, reason: 'SAFE_T_RECEIVED_QUANTITY_UNVERIFIED' }],
  [{ quantity_refunded: 1, quantity_received: -1 }, { allowed: false, reason: 'SAFE_T_RECEIVED_QUANTITY_UNVERIFIED' }],
  [{ quantity_refunded: 1, quantity_received: '0' }, { allowed: false, reason: 'SAFE_T_RECEIVED_QUANTITY_UNVERIFIED' }],
  [{ quantity_refunded: 1, quantity_received: 0.5 }, { allowed: false, reason: 'SAFE_T_RECEIVED_QUANTITY_UNVERIFIED' }],
  [null, { allowed: false, reason: 'SAFE_T_REFUNDED_QUANTITY_UNVERIFIED' }],
]) {
  assert.deepEqual(safeTUnreceivedQuantity(caseData), expected, JSON.stringify(caseData));
}

const source = readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');
const submit = source.split('async function safeTSubmit(cdp, job) {')[1]?.split('async function safeTAppeal(cdp, job) {')[0];
assert.ok(submit, 'SAFE-T submit handler must be present');
assert.ok(source.includes("import { safeTUnreceivedQuantity } from './safe-t-quantity-guard.mjs';"), 'Worker must use the pure guard');
assert.ok(submit.includes('const quantityGate = safeTUnreceivedQuantity(job.case);'), 'Validate quantity before UI');
assert.ok(submit.includes("if (!quantityGate.allowed) return bridgeResult('FAILED'"), 'Guard must fail closed');
assert.ok(submit.indexOf('safeTUnreceivedQuantity(job.case)') < submit.indexOf('captureTrackingEvidence(cdp, orderId)'), 'Fail before UI navigation and external I/O');
assert.ok(submit.includes('const quantity = quantityGate.quantity;'), 'Use the exact validated outstanding quantity');
assert.ok(!submit.includes('Math.max(1, Number(job.case?.quantity_refunded'), 'Never fabricate a minimum claim quantity');
console.log('safe-t-quantity-guard: OK');
