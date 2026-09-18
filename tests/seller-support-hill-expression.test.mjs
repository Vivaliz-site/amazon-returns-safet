import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');

test('clickHillChat embedded CDP expression parses', () => {
  const fnStart = source.indexOf('async function clickHillChat(cdp)');
  assert.notEqual(fnStart, -1, 'clickHillChat must exist');
  const tail = source.slice(fnStart);
  const marker = 'const point = await cdp.evaluate(`';
  const exprStart = tail.indexOf(marker);
  assert.notEqual(exprStart, -1, 'clickHillChat must evaluate an embedded expression');
  const bodyStart = exprStart + marker.length;
  const bodyEnd = tail.indexOf('`);', bodyStart);
  assert.notEqual(bodyEnd, -1, 'embedded expression must terminate');
  const expression = tail.slice(bodyStart, bodyEnd);
  assert.doesNotThrow(() => new Function('return (' + expression + ');'));
});
