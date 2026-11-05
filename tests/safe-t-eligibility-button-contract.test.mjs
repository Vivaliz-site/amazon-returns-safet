#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';

const worker = fs.readFileSync(
  new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url),
  'utf8',
);

const eligibilityMatch = worker.match(/const eligibilityLabels=(\[[^;]*\]);/);
assert.ok(eligibilityMatch, 'SAFE-T must explicitly enumerate approved eligibility button labels');
const eligibilityLabels = [...eligibilityMatch[1].matchAll(/'([^']+)'/g)].map(match => match[1]);
const normalizeLabel = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase();
for (const variant of ['Verificar Elegibilidade', 'Check Eligibility', 'Check Eligiblity']) {
  assert.ok(
    eligibilityLabels.map(normalizeLabel).includes(normalizeLabel(variant)),
    'SAFE-T eligibility must accept exact official button variant ' + variant,
  );
}
assert.ok(
  eligibilityLabels.every(label => !/submit|next|enviar|proximo|finalizar/i.test(label)),
  'SAFE-T eligibility must never match a submit or next button',
);
assert.ok(
  worker.includes("host.getAttribute('label')||host.getAttribute('aria-label')||host.innerText"),
  'SAFE-T eligibility discovery must use bounded semantic button text rather than an arbitrary DOM click.',
);
assert.ok(
  worker.includes("button.getAttribute('aria-label')"),
  'SAFE-T eligibility discovery must inspect the real button attributes inside a kat-button shadow root.',
);
assert.ok(
  worker.includes('SAFE_T_ELIGIBILITY_RETRY_ATTEMPTS'),
  'SAFE-T eligibility discovery must use a bounded retry window for asynchronously rendered controls.',
);
assert.ok(
  worker.includes('visitDocument(document)'),
  'SAFE-T eligibility discovery must traverse same-origin nested iframe documents deterministically.',
);
assert.ok(
  worker.includes('if(matches.length!==1)return false'),
  'SAFE-T eligibility fallback must fail closed unless exactly one enabled candidate exists.',
);
assert.ok(
  worker.includes("reason: 'SAFE_T_ELIGIBILITY_BUTTON_MISSING'"),
  'SAFE-T submit must preserve explicit UI drift when eligibility discovery remains ambiguous.',
);
assert.ok(
  !worker.includes("await cdp.clickKat('kat-button[label=\"Verificar Elegibilidade\"]')"),
  'SAFE-T submit must not regress to the brittle single exact-label eligibility selector.',
);

console.log('safe-t-eligibility-button-contract: OK');
