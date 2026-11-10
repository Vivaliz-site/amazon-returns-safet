const ORDER_ID = /^\d{3}-\d{7}-\d{7}$/;
const CLAIM_ID = /^\d{5}-\d{5}-\d{7}$/;

/**
 * Read-only, fail-closed lookup through the Seller Central order search.
 * An empty/filtered page NEVER certifies absence of an external SAFE-T claim
 * or authorizes a new submission.
 */
export async function scanSafeTExactOrder({
  orderId,
  triggerSearch,
  readMatches,
  pause = ms => new Promise(resolve => setTimeout(resolve, ms)),
  maxChecks = 8,
}) {
  if (typeof orderId !== 'string' || !ORDER_ID.test(orderId)) {
    return { status: 'FAILED', reason: 'ORDER_ID_REQUIRED_FOR_DISCOVERY', pages_scanned: 0 };
  }
  if (typeof triggerSearch !== 'function' || typeof readMatches !== 'function') {
    throw new TypeError('triggerSearch and readMatches are required');
  }
  if (!Number.isInteger(maxChecks) || maxChecks < 1 || maxChecks > 20) {
    throw new RangeError('maxChecks must be between 1 and 20');
  }
  if (await triggerSearch(orderId) !== true) {
    return { status: 'UI_DRIFT', reason: 'SAFE_T_EXACT_SEARCH_CONTROL_UNAVAILABLE', pages_scanned: 0 };
  }
  for (let attempt = 0; attempt < maxChecks; attempt++) {
    const observed = await readMatches(orderId);
    if (observed && observed.query_confirmed === true && Array.isArray(observed.matches)) {
      const ids = [...new Set(observed.matches.map(value => String(value ?? '').trim()))];
      if (ids.some(id => !CLAIM_ID.test(id))) {
        return { status: 'UI_DRIFT', reason: 'SAFE_T_EXACT_SEARCH_ID_UNREADABLE', pages_scanned: 1 };
      }
      if (ids.length > 1) {
        return { status: 'FAILED', reason: 'MULTIPLE_SAFE_T_CLAIMS_FOR_ORDER', pages_scanned: 1 };
      }
      if (ids.length === 1) {
        return { status: 'FOUND', external_id: ids[0], pages_scanned: 1 };
      }
    }
    if (attempt + 1 < maxChecks) await pause(350);
  }
  return { status: 'UI_DRIFT', reason: 'SAFE_T_EXACT_SEARCH_ABSENCE_UNVERIFIED', pages_scanned: 1 };
}


/**
 * A native exact-search miss is NOT evidence that a claim is absent. Re-open
 * the unfiltered official list before a bounded, read-only pagination check.
 * Only a positively located claim ID can proceed to detail verification.
 */
export async function resolveReadOnlySafeTDiscovery({
  exactSearch, resetToUnfilteredList, scanList,
}) {
  if (!exactSearch || typeof exactSearch.status !== "string") {
    throw new TypeError("exactSearch result required");
  }
  const ambiguousMiss = exactSearch.status === "UI_DRIFT" &&
    ["SAFE_T_EXACT_SEARCH_ABSENCE_UNVERIFIED", "SAFE_T_EXACT_SEARCH_CONTROL_UNAVAILABLE"].includes(exactSearch.reason);
  if (!ambiguousMiss) return exactSearch;
  if (typeof resetToUnfilteredList !== "function" || typeof scanList !== "function") {
    throw new TypeError("unfiltered read-only list callbacks required");
  }
  if (await resetToUnfilteredList() !== true) {
    return {status:"UI_DRIFT", reason:"SAFE_T_DISCOVERY_LIST_RESET_UNVERIFIED", pages_scanned:exactSearch.pages_scanned};
  }
  const scanned = await scanList();
  if (scanned?.status === "FOUND" && CLAIM_ID.test(scanned.external_id ?? "")) return scanned;
  if (scanned?.status === 'VERIFIED_LIST_NO_MATCH') return scanned;
  if (scanned?.status === "FAILED" || scanned?.status === "UI_DRIFT") return scanned;
  return {status:"UI_DRIFT", reason:"SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED",
    pages_scanned:Number.isSafeInteger(scanned?.pages_scanned) ? scanned.pages_scanned : exactSearch.pages_scanned};
}
