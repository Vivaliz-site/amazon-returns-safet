const CLAIM_ID = /^\d{5}-\d{5}-\d{7}$/;

/** Read-only SAFE-T discovery. An unverified list never proves absence. */
export async function scanSafeTClaimPages({ readPage, advancePage, maxPages = 25 }) {
  if (typeof readPage !== 'function' || typeof advancePage !== 'function') {
    throw new TypeError('readPage and advancePage are required');
  }
  if (!Number.isInteger(maxPages) || maxPages < 1 || maxPages > 25) {
    throw new RangeError('maxPages must be between 1 and 25');
  }
  const seen = new Set();
  for (let pageIndex = 0; pageIndex < maxPages; pageIndex++) {
    const current = await readPage();
    if (!current || typeof current.signature !== 'string' || !current.signature || !Array.isArray(current.matches)) {
      return { status: 'UI_DRIFT', reason: 'SAFE_T_DISCOVERY_LIST_UNREADABLE', pages_scanned: pageIndex + 1 };
    }
    if (seen.has(current.signature)) {
      return { status: 'UI_DRIFT', reason: 'SAFE_T_DISCOVERY_PAGE_REPEATED', pages_scanned: pageIndex + 1 };
    }
    seen.add(current.signature);
    const ids = [...new Set(current.matches.map(value => String(value || '').trim()))];
    if (ids.some(id => !CLAIM_ID.test(id))) {
      return { status: 'UI_DRIFT', reason: 'SAFE_T_DISCOVERY_ID_UNREADABLE', pages_scanned: pageIndex + 1 };
    }
    if (ids.length > 1) {
      return { status: 'FAILED', reason: 'MULTIPLE_SAFE_T_CLAIMS_FOR_ORDER', pages_scanned: pageIndex + 1 };
    }
    if (ids.length === 1) {
      return { status: 'FOUND', external_id: ids[0], pages_scanned: pageIndex + 1 };
    }
    if (pageIndex + 1 >= maxPages) break;
    const moved = await advancePage(current.signature);
    if (moved !== true) {
      return { status: 'UI_DRIFT', reason: 'SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED', pages_scanned: pageIndex + 1 };
    }
  }
  return { status: 'UI_DRIFT', reason: 'SAFE_T_DISCOVERY_PAGE_LIMIT_REACHED', pages_scanned: maxPages };
}
