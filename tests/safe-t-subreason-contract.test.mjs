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
assert.ok(worker.includes("trigger.click();return true"),
  'SAFE-T subreason selection must open exactly one semantic dropdown before reading lazy options.');
assert.ok(worker.includes("if(matches.length!==1)return false"),
  'SAFE-T subreason dropdown opening must remain fail closed when semantic discovery is ambiguous.');
assert.ok(worker.includes("kat-option,option,[role=\"option\"]"),
  'SAFE-T subreason selection must inspect supported option representations across composed roots.');
assert.ok(worker.includes("if(el.shadowRoot)addRoot(el.shadowRoot)"),
  'SAFE-T subreason selection must traverse nested open shadow roots.');
assert.ok(worker.includes("String(option.getAttribute?.('value')??option.value??option.dataset?.value??'').trim()"),
  'SAFE-T subreason selection must derive an exact option code from value semantics only.');
assert.ok(worker.includes('if(exact.length>1)return -1'),
  'A direct subreason lookup must fail closed when the expected enum code appears more than once.');
assert.ok(worker.includes('directExact===1') && worker.includes('return true'),
  'One globally unique exact subreason option must be authoritative even when multiple dropdown hosts exist.');
assert.ok(worker.includes('directExact===-1') && worker.includes('return false'),
  'Ambiguous exact subreason matches must stop without opening or selecting any dropdown.');
assert.ok(worker.includes("if(exact.length!==1)return false"),
  'SAFE-T subreason selection must fail closed unless exactly one exact expected option exists.');
assert.ok(worker.includes('async function safeTSubreasonDiagnostics(cdp, value)'),
  'A sanitized pre-write diagnostic must be available when the exact option still cannot be selected.');
assert.ok(worker.includes("lookup_reason: await safeTSubreasonDiagnostics(cdp, reason.sub)"),
  'SAFE-T UI drift must expose sanitized subreason diagnostics through the existing lookup_reason channel.');
assert.ok(worker.includes("return ['d='+dropdowns.length,'r='+roots.length,'o='+options.length,'e='+exact].join(';')"),
  'Subreason diagnostics must expose only bounded structural counts.');
assert.ok(!worker.includes("'v='+values.join(',')"),
  'Subreason diagnostics must never emit raw DOM option values, even when they look enum-like.');
assert.ok(worker.includes(".slice(0,220)"),
  'Subreason diagnostics must remain bounded before entering bridge logs or result payloads.');
assert.ok(worker.includes("SUBREASON_DIAG_UNAVAILABLE"),
  'Subreason diagnostics must fail closed to a fixed non-sensitive marker if DOM inspection fails.');
assert.ok(worker.includes('await selectSafeTSubreason(cdp, reason.sub)'),
  'SAFE-T submit must route subreason selection through the guarded helper.');
assert.ok(!worker.includes('kat-dropdown[placeholder="Selecione a Subcategoria do Motivo"]'),
  'SAFE-T submit must not regress to one exact translated placeholder.');

console.log('safe-t-subreason-contract: OK');
