import assert from 'node:assert/strict';
import test from 'node:test';
import { scanSafeTClaimPages } from '../scripts/amazon-returns/safe-t-discovery-pages.mjs';

const id = '64204-51742-6364222';
const page = (signature, matches = []) => ({ signature, matches });

test('discover claim on second page without a write', async () => {
  let index = 0;
  let moves = 0;
  const pages = [page('first'), page('second', [id])];
  const outcome = await scanSafeTClaimPages({
    readPage: async () => pages[index],
    advancePage: async () => { moves++; index++; return true; },
  });
  assert.deepEqual(outcome, { status: 'FOUND', external_id: id, pages_scanned: 2 });
  assert.equal(moves, 1);
});

test('no pagination proof means no claim absence assertion', async () => {
  const outcome = await scanSafeTClaimPages({readPage: async () => page('first'), advancePage: async () => false});
  assert.equal(outcome.status, 'UI_DRIFT');
  assert.equal(outcome.reason, 'SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED');
});

test('repeat/empty/unreadable page fail closed', async () => {
  let moved = false;
  const repeated = await scanSafeTClaimPages({readPage: async () => page('repeat'), advancePage: async () => {moved=true;return true;}});
  assert.equal(repeated.reason, 'SAFE_T_DISCOVERY_PAGE_REPEATED');
  assert.equal(moved, true);
  const unreadable = await scanSafeTClaimPages({readPage: async () => page('', []), advancePage: async () => true});
  assert.equal(unreadable.reason, 'SAFE_T_DISCOVERY_LIST_UNREADABLE');
});

test('ambiguous or malformed external ID never returns FOUND', async () => {
  const multiple = await scanSafeTClaimPages({readPage: async () => page('a', [id, '12345-12345-1234567']), advancePage: async () => false});
  assert.equal(multiple.status, 'FAILED');
  const malformed = await scanSafeTClaimPages({readPage: async () => page('a', ['not-a-claim']), advancePage: async () => false});
  assert.equal(malformed.reason, 'SAFE_T_DISCOVERY_ID_UNREADABLE');
});

test('bounded navigation never pretends completeness', async () => {
  let n = 0;
  const outcome = await scanSafeTClaimPages({readPage: async () => page('page'+n), advancePage: async () => {n++; return true;},maxPages: 2});
  assert.equal(outcome.reason, 'SAFE_T_DISCOVERY_PAGE_LIMIT_REACHED');
  assert.equal(n, 1);
});
