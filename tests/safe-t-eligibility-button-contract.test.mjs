#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';

const worker = fs.readFileSync(
  new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url),
  'utf8',
);

assert.ok(
  worker.includes("const eligibilityLabels=['Verificar Elegibilidade','Verificar elegibilidade','Check Eligibility','Check eligibility'];"),
  'SAFE-T eligibility discovery must tolerate the observed Portuguese/English label variants.',
);
assert.ok(
  worker.includes("host.getAttribute('label')||host.getAttribute('aria-label')||host.innerText"),
  'SAFE-T eligibility discovery must use bounded semantic button text rather than an arbitrary DOM click.',
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
