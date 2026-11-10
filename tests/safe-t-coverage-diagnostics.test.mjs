import assert from "node:assert/strict";
import test from "node:test";
import {completeClaimListCoverage, coverageMismatchCodes} from "../scripts/amazon-returns/safe-t-discovery-pages.mjs";

const proof = () => ({
  host: "sellercentral.amazon.com.br",
  view: "all", claim_type: "ALL", fulfillment: "ALL",
  date_filter_type: "CLAIM_DATE", date_filter_days: 90,
  page_size: 100, page_start_key: 0,
  range_start: 1, range_end: 39, total: 39,
  rendered: 39, parsed_orders: 39, parsed_claims: 39, unique_claims: 39,
  start_date: "08/13/2026", end_date: "11/11/2026",
});
const refund = "2026-09-09 18:38:59";

test("healthy source-backed coverage produces zero diagnostic codes", () => {
  assert.equal(completeClaimListCoverage(proof(), refund), true);
  assert.deepEqual(coverageMismatchCodes(proof(), refund), []);
});

test("missing real refund date is diagnosed without asserting absence of claims", () => {
  assert.equal(completeClaimListCoverage(proof(), null), false);
  assert.ok(coverageMismatchCodes(proof(), null).includes("REFUND_AT_FORMAT"));
});

test("official filtered/paginated/partial coverage names only invariant codes", () => {
  const mutated = {...proof(), view:"pending", rendered:38, page_size:50};
  assert.equal(completeClaimListCoverage(mutated, refund), false);
  assert.deepEqual(coverageMismatchCodes(mutated, refund).sort(),
    ["LIST_RENDERED", "PAGE_SIZE", "VIEW"].sort());
  assert.match(JSON.stringify(coverageMismatchCodes(mutated, refund)), /^[A-Z_\[\]", ]+$/);
});

test("refund beyond the examined 90-day window is independently labeled", () => {
  assert.equal(completeClaimListCoverage(proof(), "2026-06-01 12:00:00"), false);
  assert.deepEqual(coverageMismatchCodes(proof(), "2026-06-01 12:00:00"),
    ["REFUND_OUTSIDE_WINDOW"]);
});
