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
assert.ok(worker.includes('const isInteractive=element=>'),
  'SAFE-T subreason selection must define a structural interactability guard.');
assert.ok(worker.includes('interactiveDropdowns.length!==1'),
  'Direct exact selection must require exactly one interactive semantic subreason dropdown.');
assert.ok(worker.includes('interactiveExact.length!==1'),
  'Direct exact selection must require exactly one interactive exact option.');
assert.ok(worker.includes('const ownerDropdown=option=>'),
  'A unique exact subreason option must be able to identify its owning dropdown without translated labels.');
assert.ok(worker.includes("host.tagName==='KAT-DROPDOWN'"),
  'Owner discovery must stop only at an explicit KAT dropdown host.');
assert.ok(worker.includes("option.closest?.('kat-dropdown')"),
  'Owner discovery must first support a KAT option rendered in the dropdown light DOM.');
assert.ok(worker.includes('if(exact.length!==1)return 0'),
  'Owner-based fallback must fail closed unless the expected option is globally unique.');
assert.ok(worker.includes('ownerTrigger.click();return 1'),
  'Owner-based fallback must open only the dropdown that structurally owns the unique expected option.');
assert.ok(worker.includes('const trustedOwnerPoint=await cdp.evaluate'),
  'SAFE-T subreason selection must have a trusted pointer fallback for KAT dropdown hosts that ignore synthetic click().');
assert.ok(worker.includes('const trustedOptionPoint=await cdp.evaluate'),
  'Trusted subreason fallback must locate the exact visible option after opening its owner dropdown.');
assert.ok(worker.includes("cdp.send('Input.dispatchMouseEvent'"),
  'Trusted subreason fallback must dispatch real pointer events through CDP.');
assert.ok(worker.includes('const trustedSelected='),
  'Trusted subreason fallback must verify the dropdown accepted the exact selection before succeeding.');
assert.ok(worker.includes("'s='+semantic.length") && worker.includes("'i='+interactiveSemantic.length") && worker.includes("'ve='+visibleExact"),
  'Sanitized diagnostics must expose only structural semantic/interactivity counts for the next production proof.');
assert.ok(worker.includes("if(exact.length!==1)return false"),
  'SAFE-T subreason selection must fail closed unless exactly one exact expected option exists.');
assert.ok(worker.includes('async function safeTSubreasonDiagnostics(cdp, value)'),
  'A sanitized pre-write diagnostic must be available when the exact option still cannot be selected.');
const diagnosticSource=worker.split('async function safeTSubreasonDiagnostics(cdp, value)')[1]?.split('async function safeTSubmit(cdp, job)')[0]||'';
assert.ok(diagnosticSource.includes("const hints=['subcategoria','subcategory','sub category','subreason'];"),
  'SAFE-T subreason diagnostics must define their semantic hints in their own scope.');
assert.ok(worker.includes("lookup_reason: await safeTSubreasonDiagnostics(cdp, reason.sub)"),
  'SAFE-T UI drift must expose sanitized subreason diagnostics through the existing lookup_reason channel.');
assert.ok(worker.includes("return ['d='+dropdowns.length,'s='+semantic.length,'i='+interactiveSemantic.length,'r='+roots.length,'o='+options.length,'e='+exact.length,'ve='+visibleExact].join(';')"),
  'Subreason diagnostics must expose only bounded structural counts, including semantic and interactivity counts.');
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
