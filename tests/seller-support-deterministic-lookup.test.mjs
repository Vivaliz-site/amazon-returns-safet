import fs from 'node:fs';
import assert from 'node:assert/strict';

const worker = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');
const start = worker.indexOf('async function scanSupportCaseHistory');
const end = worker.indexOf('\nasync function findSupportCase', start);
assert.ok(start >= 0 && end > start, 'scanSupportCaseHistory must remain extractable');
const fnSource = worker.slice(start, end);
const scanSupportCaseHistory = (0, eval)(`(()=>{
  const text=value=>String(value??'').replace(/\\s+/g,' ').trim();
  const SUPPORT_CASE_LOOKUP_SCAN_BUDGET_MS=90000;
  const SUPPORT_CASE_TERMINAL_STATUSES=['RESOLVED','CLOSED','CANCELLED'];
  const SUPPORT_CASE_HISTORY_LIMIT=500;
  ${fnSource}
  return scanSupportCaseHistory;
})()`);

async function withFetch(fakeFetch, fn) {
  const originalFetch = globalThis.fetch;
  const originalSetTimeout = globalThis.setTimeout;
  globalThis.fetch = fakeFetch;
  globalThis.setTimeout = callback => { callback(); return 1; };
  try { return await fn(); }
  finally { globalThis.fetch = originalFetch; globalThis.setTimeout = originalSetTimeout; }
}

{
  const orderId = '702-7802983-5785045';
  const searchTexts = [];
  let unfilteredSearches = 0;
  let viewCalls = 0;
  const fakeFetch = async (url, options = {}) => {
    if (String(url).includes('SearchForCases')) {
      const body = JSON.parse(options.body || '{}');
      const term = body.caseFilters?.searchText || '';
      if (term) {
        searchTexts.push(term);
        return { ok: true, status: 200, json: async () => ({
          totalNumberOfResults: 1,
          caseSearchResultList: [{ caseId: '21839077561', status: 'Open', shortDescription: `Order ${orderId}` }],
        }) };
      }
      unfilteredSearches++;
      return { ok: true, status: 200, json: async () => ({ totalNumberOfResults: 0, caseSearchResultList: [] }) };
    }
    if (String(url).includes('ViewCase')) { viewCalls++; return { ok: true, status: 200, json: async () => ({ viewCaseMetaData: { caseStatus: 'Open' }, contacts: [{ body: `Order ${orderId}` }] }) }; }
    throw new Error(`unexpected fetch ${url}`);
  };
  const cdp = { evaluate: async expression => await (0, eval)(expression) };
  const found = await withFetch(fakeFetch, () => scanSupportCaseHistory(cdp, {
    case: { order_id: orderId, safe_t_id: '27845-46811-9805451' },
  }, '', { cutoffEpochSeconds: 0 }));
  assert.equal(found, '21839077561', 'Official searchText lookup must resolve the known support case.');
  assert.ok(searchTexts.includes(orderId), 'Lookup must query SearchForCases with caseFilters.searchText.');
  assert.equal(unfilteredSearches, 0, 'Deterministic search hit must avoid the deep unfiltered scan.');
  assert.equal(viewCalls, 1, 'Deterministic search must verify the single candidate with one paced ViewCase read.');
}

{
  const orderId = '701-2474306-0966605';
  const recentId = '21839999991';
  const oldId = '21830000001';
  const viewed = [];
  const fakeFetch = async (url, options = {}) => {
    if (String(url).includes('SearchForCases')) {
      const body = JSON.parse(options.body || '{}');
      if (body.caseFilters?.searchText) return { ok: true, status: 200, json: async () => ({ totalNumberOfResults: 0, caseSearchResultList: [] }) };
      return { ok: true, status: 200, json: async () => ({ totalNumberOfResults: 2, caseSearchResultList: [
        { caseId: recentId, status: 'Open', creationDate: 1789000000, shortDescription: 'Refund review' },
        { caseId: oldId, status: 'Open', creationDate: 1787000000, shortDescription: 'Refund review' },
      ] }) };
    }
    if (String(url).includes('ViewCase')) {
      const id = new URL(`https://x${url}`).searchParams.get('caseId');
      viewed.push(id);
      return { ok: true, status: 200, json: async () => ({
        viewCaseMetaData: { caseStatus: 'Open' },
        contacts: id === oldId ? [{ body: `Order ${orderId}` }] : [{ body: 'unrelated' }],
      }) };
    }
    throw new Error(`unexpected fetch ${url}`);
  };
  const cdp = { evaluate: async expression => await (0, eval)(expression) };
  const found = await withFetch(fakeFetch, () => scanSupportCaseHistory(cdp, {
    case: { order_id: orderId, safe_t_id: null },
  }, '', { includeTerminal: true, cutoffEpochSeconds: 1788500000 }));
  assert.equal(found, null, 'Fallback must not match a support case older than the reconciliation cutoff.');
  assert.deepEqual(viewed, [recentId], 'Fallback must ViewCase only candidates at or after the cutoff.');
}

{
  const pages = [];
  const fakeFetch = async (url, options = {}) => {
    if (!String(url).includes('SearchForCases')) throw new Error(`unexpected fetch ${url}`);
    const body = JSON.parse(options.body || '{}');
    if (body.caseFilters?.searchText) return { ok: true, status: 200, json: async () => ({ totalNumberOfResults: 0, caseSearchResultList: [] }) };
    pages.push(body.page);
    const rows = body.page === 0
      ? Array.from({ length: 50 }, (_, index) => ({ caseId: String(21840000000 + index), status: 'Open', creationDate: 1789000000, shortDescription: 'Other topic' }))
      : [{ caseId: '21830000001', status: 'Open', creationDate: 1787000000, shortDescription: 'Other topic' }];
    return { ok: true, status: 200, json: async () => ({ totalNumberOfResults: 51, caseSearchResultList: rows }) };
  };
  const cdp = { evaluate: async expression => await (0, eval)(expression) };
  const found = await withFetch(fakeFetch, () => scanSupportCaseHistory(cdp, {
    case: { order_id: '701-0000000-0000000', safe_t_id: null },
  }, '', { cutoffEpochSeconds: 1788000000 }));
  assert.equal(found, null);
  assert.deepEqual(pages.slice(0, 2), [0, 1], 'Fallback must advance SearchForCases pages instead of rescanning page zero.');
}

{
  let viewCalls = 0;
  const preferredId = '21839077561';
  const fakeFetch = async (url, options = {}) => {
    if (String(url).includes('SearchForCases')) {
      const body = JSON.parse(options.body || '{}');
      if (body.caseFilters?.searchText === preferredId) return { ok: true, status: 200, json: async () => ({
        totalNumberOfResults: 1,
        caseSearchResultList: [{ caseId: preferredId, status: 'Open', shortDescription: 'Unrelated support case' }],
      }) };
      return { ok: true, status: 200, json: async () => ({ totalNumberOfResults: 0, caseSearchResultList: [] }) };
    }
    if (String(url).includes('ViewCase')) {
      viewCalls++;
      return { ok: true, status: 200, json: async () => ({ viewCaseMetaData: { caseStatus: 'Open' }, contacts: [{ body: 'unrelated' }] }) };
    }
    throw new Error(`unexpected fetch ${url}`);
  };
  const cdp = { evaluate: async expression => await (0, eval)(expression) };
  const found = await withFetch(fakeFetch, () => scanSupportCaseHistory(cdp, {
    case: { order_id: '702-7802983-5785045', safe_t_id: '27845-46811-9805451' },
  }, preferredId, { cutoffEpochSeconds: 1788000000 }));
  assert.equal(found, null, 'A preferred support ID must not suppress a write unless its detail correlates to the case.');
  assert.equal(viewCalls, 1, 'Preferred support ID must be verified with one paced ViewCase read.');
}

console.log('seller-support-deterministic-lookup-test: OK');
