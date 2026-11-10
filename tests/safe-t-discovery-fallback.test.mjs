import assert from "node:assert/strict";
import test from "node:test";
import fs from "node:fs";
import { resolveReadOnlySafeTDiscovery } from "../scripts/amazon-returns/safe-t-discovery-exact-search.mjs";

const drift = { status: "UI_DRIFT", reason: "SAFE_T_EXACT_SEARCH_ABSENCE_UNVERIFIED", pages_scanned: 1 };
const found = { status: "FOUND", external_id: "64204-51742-6364222", pages_scanned: 2 };

test("empty exact-search is recovered only by positively verified paginated discovery", async () => {
  let resets=0,lists=0;
  const out=await resolveReadOnlySafeTDiscovery({
    exactSearch:drift, resetToUnfilteredList:async()=>{resets++;return true;},
    scanList:async()=>{lists++;return found;}
  });
  assert.deepEqual(out,found);assert.equal(resets,1);assert.equal(lists,1);
});

test("no positive claim after fallback remains UI_DRIFT, never NOT_FOUND", async () => {
  const out=await resolveReadOnlySafeTDiscovery({
    exactSearch:drift,resetToUnfilteredList:async()=>true,
    scanList:async()=>({status:"NOT_FOUND",pages_scanned:3})
  });
  assert.equal(out.status,"UI_DRIFT");
});

test("verified complete list observation without refund window match is preserved through fallback", async () => {
  for (const status of ["VERIFIED_LIST_NO_MATCH", "LIST_OBSERVED_REFUND_NOT_IN_WINDOW"]) {
    const scanned = { status, pages_scanned: 1, coverage: { total: 39, unique_claims: 39 } };
    const out = await resolveReadOnlySafeTDiscovery({
      exactSearch: drift,
      resetToUnfilteredList: async () => true,
      scanList: async () => scanned,
    });
    assert.deepEqual(out, scanned);
  }
});

test("multiple claim failures and successful exact lookup never trigger fallback", async () => {
  for(const input of [{status:"FAILED",reason:"MULTIPLE_SAFE_T_CLAIMS_FOR_ORDER"},{status:"FOUND",external_id:"64204-51742-6364222"}]){
    const out=await resolveReadOnlySafeTDiscovery({exactSearch:input,
      resetToUnfilteredList:async()=>{throw Error("unexpected reset")},scanList:async()=>{throw Error("unexpected list")}
    });
    assert.deepEqual(out,input);
  }
});

test("failed unfiltered reset must not inspect a filtered result",async()=>{
  let scanned=0;
  const out=await resolveReadOnlySafeTDiscovery({exactSearch:drift,
    resetToUnfilteredList:async()=>false,
    scanList:async()=>{scanned++;return found;}
  });
  assert.equal(out.status,"UI_DRIFT");assert.equal(scanned,0);
});

test("worker resets the official list before bounded fallback; never trusts absent search",()=>{
  const source=fs.readFileSync(new URL("../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs",import.meta.url),"utf8");
  assert.match(source,/resolveReadOnlySafeTDiscovery\(/);
  assert.match(source,/maxPages: 3/);
  assert.match(source,/resetToUnfilteredList/);
});
