import fs from 'node:fs';
import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');
const match = source.match(/const associateCategories = ([\s\S]*?);\s*const category = await clickFirstFrameTextWhenReady\(cdp, associateCategories, 45000\)/);
assert.ok(match, 'Seller Support category expression is present');

function candidatesFor(program) {
  return vm.runInNewContext(match[1], {
    text: (value) => String(value ?? '').trim(),
    job: { case: { program } },
  });
}

test('FBA_ONSITE uses the explicit FBA support categories', () => {
  const categories = candidatesFor('FBA_ONSITE');
  assert.ok(categories.includes('FBA related'), 'Seller Flex/FBA Onsite must offer the FBA category');
  assert.ok(categories.includes('FBA relacionado'));
});

test('classic FBA still uses the FBA support categories', () => {
  assert.ok(candidatesFor('FBA').includes('FBA related'));
});

test('DBA does not accidentally switch into the FBA category', () => {
  const categories = candidatesFor('DELIVERY_BY_AMAZON');
  assert.ok(categories.includes('A-to-z Claims'));
  assert.ok(!categories.includes('FBA related'));
});
