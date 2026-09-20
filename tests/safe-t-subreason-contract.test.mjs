#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';

const worker=fs.readFileSync(
  new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs',import.meta.url),
  'utf8',
);

assert.ok(worker.includes('async function selectSafeTSubreason(cdp, value)'),
  'SAFE-T subreason selection must use a dedicated guarded helper.');
assert.ok(worker.includes("const hints=['subcategoria','subcategory','sub category','subreason'];"),
  'SAFE-T subreason dropdown discovery must use an explicit semantic allowlist.');
assert.ok(!worker.includes("if(!hints.some(h=>meta.includes(h)))continue"),
  'SAFE-T subreason selection must not discard the correct dropdown solely because translated host metadata changed; exact option value is the authoritative selector.');
assert.ok(worker.includes('if(candidates.length===1)chosen=candidates[0]'),
  'SAFE-T subreason discovery may select directly only when exactly one dropdown contains the expected option value.');
assert.ok(worker.includes('if(hinted.length!==1)return false'),
  'If more than one dropdown contains the exact option value, semantic hints must disambiguate to exactly one or fail closed.');
assert.ok(worker.includes("String(option.getAttribute('value')||'').trim()===expected"),
  'SAFE-T subreason selection must require the exact expected option value.');
assert.ok(worker.includes('if(exact.length>1)return false'),
  'SAFE-T subreason selection must fail closed when one dropdown exposes the expected option value more than once.');
assert.ok(worker.includes('if(exact.length!==1)continue'),
  'Dropdowns without the exact expected option value must be ignored instead of causing a false-negative UI drift.');
assert.ok(worker.includes('visit(frame.contentDocument)'),
  'SAFE-T subreason discovery must traverse same-origin iframe documents.');
assert.ok(worker.includes('await selectSafeTSubreason(cdp, reason.sub)'),
  'SAFE-T submit must route subreason selection through the guarded helper.');
assert.ok(!worker.includes('kat-dropdown[placeholder="Selecione a Subcategoria do Motivo"]'),
  'SAFE-T submit must not regress to one exact translated placeholder.');

console.log('safe-t-subreason-contract: OK');
