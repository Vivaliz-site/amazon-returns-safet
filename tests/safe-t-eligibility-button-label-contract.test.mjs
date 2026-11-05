import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const worker = fs.readFileSync(
  new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url),
  'utf8'
);
const start = worker.indexOf('async function clickSafeTEligibilityButton(cdp)');
const end = worker.indexOf('async function setSafeTQuantity(', start);
assert.ok(start >= 0 && end > start, 'eligibility step must exist');
const step = worker.slice(start, end);
const allowedLiteral = step.match(/const eligibilityLabels=(\[[^\]]+\]);/);
assert.ok(allowedLiteral, 'explicit eligibility label allowlist required');
const labels = [...allowedLiteral[1].matchAll(/'([^']+)'/g)].map(x => x[1]);
const normalize = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase();

test('real Amazon Brazil UI label Check Eligiblity is accepted exactly', () => {
  // Observed on the authenticated, official create-v2 page on 2026-11-05.
  assert.ok(labels.map(normalize).includes(normalize('Check Eligiblity')));
  assert.ok(labels.map(normalize).includes(normalize('Check Eligibility')));
  assert.ok(labels.map(normalize).includes(normalize('Verificar Elegibilidade')));
});

test('eligibility action stays unique-control-only and does not click Submit or Next', () => {
  assert.match(step, /matches\.length!==1/);
  assert.match(step, /labels\.includes\(raw\)/);
  assert.match(step, /button\.disabled/);
  assert.doesNotMatch(labels.join(' '), /Submit|Next|Finalizar|Enviar/);
});
