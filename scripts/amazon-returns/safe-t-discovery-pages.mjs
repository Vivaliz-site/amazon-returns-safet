const CLAIM_ID = /^\d{5}-\d{5}-\d{7}$/;

function usDateStart(value) {
  if (typeof value !== 'string' || !/^\d{2}\/\d{2}\/\d{4}$/.test(value)) return NaN;
  const [month, day, year] = value.split('/').map(Number);
  const utc = Date.UTC(year, month - 1, day);
  const d = new Date(utc);
  return d.getUTCFullYear() === year && d.getUTCMonth() + 1 === month &&
    d.getUTCDate() === day ? utc : NaN;
}

/** The official list itself was read completely (independent of the case refund date). */
export function completeClaimListView(coverage) {
  if (!coverage || typeof coverage !== 'object') return false;
  const start = usDateStart(coverage.start_date);
  const end = usDateStart(coverage.end_date);
  const count = coverage.total;
  return coverage.host === 'sellercentral.amazon.com.br' &&
    coverage.view === 'all' && coverage.claim_type === 'ALL' &&
    coverage.fulfillment === 'ALL' && coverage.date_filter_type === 'CLAIM_DATE' &&
    coverage.date_filter_days === 90 && coverage.page_size === 100 &&
    coverage.page_start_key === 0 &&
    Number.isSafeInteger(count) && count >= 1 && count <= 100 &&
    coverage.range_start === 1 && coverage.range_end === count &&
    coverage.rendered === count && coverage.parsed_orders === count &&
    coverage.parsed_claims === count && coverage.unique_claims === count &&
    Number.isFinite(start) && Number.isFinite(end) && start <= end;
}

/**
 * Exhaustive read-only page evidence: never grants SAFE-T submission.
 * A no-match is only meaningful when the case refund date is known and
 * falls inside the listed claim-date window.
 */
export function completeClaimListCoverage(coverage, refundAt) {
  if (!completeClaimListView(coverage) || typeof refundAt !== 'string' ||
      !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(refundAt)) return false;
  const refunded = Date.parse(refundAt.replace(' ', 'T') + 'Z');
  const start = usDateStart(coverage.start_date);
  const end = usDateStart(coverage.end_date);
  return Number.isFinite(refunded) && refunded >= start && refunded < end + 86400000;
}

/**
 * Diagnostic-only labels for the EXISTING coverage predicate. Never logs
 * order IDs, claim IDs, raw dates, page text or credentials. It does not
 * change the verdict, retry policy, eligibility or write authorizations.
 */
export function coverageMismatchCodes(coverage, refundAt) {
  if (!coverage || typeof coverage !== 'object') return ['COVERAGE_UNREADABLE'];
  const failures = [];
  const check = (label, ok) => { if (!ok) failures.push(label); };
  check('HOST', coverage.host === 'sellercentral.amazon.com.br');
  check('VIEW', coverage.view === 'all');
  check('CLAIM_TYPE', coverage.claim_type === 'ALL');
  check('FULFILLMENT', coverage.fulfillment === 'ALL');
  check('DATE_FILTER_TYPE', coverage.date_filter_type === 'CLAIM_DATE');
  check('DATE_FILTER_DAYS', coverage.date_filter_days === 90);
  check('PAGE_SIZE', coverage.page_size === 100);
  check('PAGE_START', coverage.page_start_key === 0);
  const count = coverage.total;
  check('LIST_TOTAL', Number.isSafeInteger(count) && count >= 1 && count <= 100);
  check('LIST_RANGE_START', coverage.range_start === 1);
  check('LIST_RANGE_END', coverage.range_end === count);
  check('LIST_RENDERED', coverage.rendered === count);
  check('LIST_PARSED_ORDERS', coverage.parsed_orders === count);
  check('LIST_PARSED_CLAIMS', coverage.parsed_claims === count);
  check('LIST_UNIQUE_CLAIMS', coverage.unique_claims === count);
  const start = usDateStart(coverage.start_date);
  const end = usDateStart(coverage.end_date);
  check('DATE_RANGE_FORMAT', Number.isFinite(start) && Number.isFinite(end) && start <= end);
  const refundFormat = typeof refundAt === 'string' &&
    /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(refundAt);
  check('REFUND_AT_FORMAT', refundFormat);
  const refunded = refundFormat ? Date.parse(refundAt.replace(' ', 'T') + 'Z') : NaN;
  if (refundFormat && Number.isFinite(start) && Number.isFinite(end)) {
    check('REFUND_OUTSIDE_WINDOW', Number.isFinite(refunded) &&
      refunded >= start && refunded < end + 86400000);
  }
  return failures;
}

/** Read-only SAFE-T discovery. An unverified list never proves absence. */
export async function scanSafeTClaimPages({ readPage, advancePage, maxPages = 25, refundAt = null }) {
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
    if (completeClaimListCoverage(current.coverage, refundAt)) {
      return { status: 'VERIFIED_LIST_NO_MATCH',
        reason: 'OFFICIAL_90_DAY_COMPLETE_LIST_NO_MATCH',
        pages_scanned: pageIndex + 1, coverage: current.coverage };
    }
    // Full list read, but this case's refund is unknown or outside the window:
    // no absence is asserted and no further pages exist. Terminal read.
    if (completeClaimListView(current.coverage)) {
      return { status: 'LIST_OBSERVED_REFUND_NOT_IN_WINDOW',
        reason: 'SAFE_T_DISCOVERY_REFUND_NOT_IN_LISTED_WINDOW',
        pages_scanned: pageIndex + 1, coverage: current.coverage };
    }
    if (pageIndex + 1 >= maxPages) break;
    const moved = await advancePage(current.signature);
    if (moved !== true) {
      return { status: 'UI_DRIFT', reason: 'SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED', pages_scanned: pageIndex + 1 };
    }
  }
  return { status: 'UI_DRIFT', reason: 'SAFE_T_DISCOVERY_PAGE_LIMIT_REACHED', pages_scanned: maxPages };
}
