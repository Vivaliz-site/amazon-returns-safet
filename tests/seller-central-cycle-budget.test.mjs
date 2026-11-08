import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const file = path => fs.readFileSync(new URL(path, import.meta.url), 'utf8');

test('daily Seller Central session shares one bounded deadline for write/read/write drains', () => {
  const runner = file('../scripts/amazon-returns/run-seller-central-daily.sh');
  assert.match(runner, /export SELLER_CENTRAL_CYCLE_DEADLINE_MS=/);
  assert.match(runner, /seller-central-bridge-worker\.mjs/);
  assert.match(runner, /seller-central-safe-t-read-worker\.mjs/);
});

test('both canonical --drain workers check the budget before pulling another job', () => {
  for (const path of [
    '../scripts/amazon-returns/seller-central-bridge-worker.mjs',
    '../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs',
  ]) {
    const source = file(path);
    assert.match(source, /resolveCycleDeadline/);
    assert.match(source, /withinCycleBudget/);
    assert.match(source, /drain_budget_exhausted/);
    assert.match(source, /--drain/);
  }
});

test('budget helper is bounded, shared and fail-closed without touching live jobs', async () => {
  const { resolveCycleDeadline, withinCycleBudget, MAX_CYCLE_MS } =
    await import('../scripts/amazon-returns/seller-central-cycle-budget.mjs');
  const now = 1794100000000;
  assert.equal(MAX_CYCLE_MS, 20 * 60 * 1000);
  assert.equal(resolveCycleDeadline(undefined, now), now + MAX_CYCLE_MS);
  const external = String(now + 300000);
  assert.equal(resolveCycleDeadline(external, now), now + 300000);
  assert.equal(withinCycleBudget(now + 300000, now + 100000), true);
  assert.equal(withinCycleBudget(now + 300000, now + 281000), false,
    'do not pull new work when less than 30 seconds remain');
  assert.equal(withinCycleBudget(now + 300000, now + 300000), false);
  assert.equal(resolveCycleDeadline(String(now - 1000), now), now - 1000,
    'expired deadline cannot silently restart a full drain');
  for (const bad of ['invalid','-1','Infinity','1794100000000.5',
    '1794100000000s','0',String(now + MAX_CYCLE_MS + 1)]) {
    assert.throws(() => resolveCycleDeadline(bad, now), /cycle deadline/i,
      `unsafe budget accepted: ${bad}`);
  }
});
