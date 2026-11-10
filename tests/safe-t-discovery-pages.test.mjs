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


const verifiedCoverage=()=>({
  host:'sellercentral.amazon.com.br',view:'all',claim_type:'ALL',fulfillment:'ALL',
  date_filter_type:'CLAIM_DATE',date_filter_days:90,
  page_size:100,page_start_key:0,range_start:1,range_end:39,total:39,
  rendered:39,parsed_orders:39,parsed_claims:39,unique_claims:39,
  start_date:'08/13/2026',end_date:'11/11/2026',
});
test('complete official 90-day all-claims view acknowledges read-only no-match',async()=>{
  let advanced=0;
  const out=await scanSafeTClaimPages({
    readPage:async()=>({signature:'39-source-backed-claims',matches:[],coverage:verifiedCoverage()}),
    advancePage:async()=>{advanced++;return true;},
    refundAt:'2026-09-09 18:38:59',maxPages:3,
  });
  assert.equal(out.status,'VERIFIED_LIST_NO_MATCH');
  assert.equal(out.coverage.total,39);
  assert.equal(advanced,0);
});
test('incomplete or filtered official views never prove absence',async()=>{
  const changes=[
    {range_end:38},{rendered:38},{parsed_orders:38},{parsed_claims:38},
    {unique_claims:38},{view:'pending'},{claim_type:'FBA'},
    {fulfillment:'FBA'},{date_filter_type:'ORDER_DATE'},
    {date_filter_days:7},{page_size:30},{page_start_key:30},
    {host:'example.net'},
  ];
  for(const change of changes){
    const out=await scanSafeTClaimPages({
      readPage:async()=>({signature:'39-source-backed-claims',matches:[],coverage:{...verifiedCoverage(),...change}}),
      advancePage:async()=>false,refundAt:'2026-09-09 18:38:59',maxPages:3,
    });
    assert.equal(out.status,'UI_DRIFT',JSON.stringify(change));
    assert.equal(out.reason,'SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED');
  }
});

test('complete list but refund date unknown/outside window: terminal observation, no page hunt, no absence claim', async () => {
  for (const refundAt of [null, '2026-05-01 00:00:00']) {
    let advanced = 0;
    const out = await scanSafeTClaimPages({
      readPage: async () => ({ signature: '39-source-backed-claims', matches: [], coverage: verifiedCoverage() }),
      advancePage: async () => { advanced++; return true; },
      refundAt, maxPages: 3,
    });
    assert.equal(out.status, 'LIST_OBSERVED_REFUND_NOT_IN_WINDOW', String(refundAt));
    assert.equal(out.reason, 'SAFE_T_DISCOVERY_REFUND_NOT_IN_LISTED_WINDOW');
    assert.equal(advanced, 0);
  }
});
test('incomplete list with unknown refund date stays UI_DRIFT', async () => {
  const out = await scanSafeTClaimPages({
    readPage: async () => ({ signature: 's', matches: [], coverage: { ...verifiedCoverage(), rendered: 38 } }),
    advancePage: async () => false, refundAt: null, maxPages: 3,
  });
  assert.equal(out.status, 'UI_DRIFT');
});
