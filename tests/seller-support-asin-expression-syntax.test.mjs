import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');

test('Seller Support ASIN readiness expression is valid JavaScript after template evaluation', () => {
  const match = source.match(/const asinSelector = '([^']+)';\s+const asinRequired = await cdp\.evaluate\((`[^`]+`)\);/);
  assert.ok(match, 'ASIN readiness CDP expression must remain auditable');
  const expression = vm.runInNewContext(match[2], { asinSelector: match[1] });
  assert.doesNotThrow(() => new Function(`return ${expression}`));
  assert.match(expression, /Enter ASIN/);
  assert.match(expression, /Inserir ASIN/);
});
