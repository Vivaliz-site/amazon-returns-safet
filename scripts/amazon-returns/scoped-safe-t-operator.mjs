/**
 * SAFE-T scoped operator core. Importing does NOT start a worker or change flags.
 * The trusted production adapter is required for real provider operations.
 */
const SCOPES = Object.freeze({
  3556: Object.freeze({
    version: 'safet-d45-pr3556-scoped-v1',
    caseIds: Object.freeze([1263, 1314, 8803, 13210, 51429, 101669, 101670, 101671]),
    expiresAt: '2026-11-09T23:59:00Z',
  }),
  3673: Object.freeze({
    version: 'safet-d45-pr3673-scoped-v1',
    caseIds: Object.freeze([42471, 51429, 101669, 101670, 101671]),
    expiresAt: '2026-11-30T23:59:00Z',
  }),
});
const EXPECTED_ACTIONS = Object.freeze({
  SAFE_T_SUBMIT: true,
  SAFE_T_APPEAL: false,
  SAFE_T_EMAIL_REVIEW: false,
  SAFE_T_EMAIL_REPLY: false,
  SELLER_SUPPORT_OPEN: false,
  SELLER_SUPPORT_UPDATE: false,
  ERP_SALES_RETURN_CREATE: false,
});
const CLAIM_ID = /^\d{5}-\d{5}-\d{7}$/;
const CONFIRMED_INITIATORS = new Set(['AMAZON_INITIATED', 'AMAZON_CUSTOMER_SERVICE']);
const DAY_MS = 86400000;

export class ScopedSafeTError extends Error {
  constructor(code) { super(code); this.name = 'ScopedSafeTError'; this.code = code; }
}
function requireGate(condition, code) {
  if (!condition) throw new ScopedSafeTError(code);
}
function identicalSet(actual, expected) {
  return Array.isArray(actual) && actual.length === expected.length &&
    actual.every(Number.isSafeInteger) && new Set(actual).size === actual.length &&
    expected.every(id => actual.includes(id));
}
function utcTime(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}Z?$/.test(value)) return NaN;
  const normalized = value.replace(' ', 'T').replace(/Z?$/, 'Z');
  const ms = Date.parse(normalized);
  return Number.isFinite(ms) && new Date(ms).toISOString().slice(0, 19) === normalized.slice(0, 19) ? ms : NaN;
}
function validateGrant(snapshot, scope, grantNumber, nowMs) {
  requireGate(snapshot?.verifiedBy === 'canonical-github-owner-approval-verifier' &&
    snapshot?.owner === 'shopvivaliz-human' && snapshot?.approvedOnHead === true &&
    snapshot?.merged === true && snapshot?.grantNumber === grantNumber &&
    snapshot?.grant?.operation === 'activate_scoped_safe_t_opening' &&
    snapshot?.grant?.resource === 'amazon-returns:safet:marina' &&
    snapshot?.grant?.principal === 'amazon-returns-safet-service' &&
    snapshot?.grant?.host === 'marina-a1-4c24g' &&
    snapshot?.grant?.tenant_id === 1 && snapshot?.grant?.amazon_connection_id === 1 &&
    snapshot?.grant?.one_use === true &&
    snapshot?.grant?.expires_at_utc === scope.expiresAt &&
    identicalSet(snapshot?.grant?.allowed_case_ids, scope.caseIds) &&
    Array.isArray(snapshot?.grant?.allowed_write_actions) &&
    snapshot.grant.allowed_write_actions.length === 1 &&
    snapshot.grant.allowed_write_actions[0] === 'SAFE_T_SUBMIT',
    'OWNER_GRANT_NOT_VERIFIED');
  requireGate(nowMs < Date.parse(scope.expiresAt), 'OWNER_GRANT_EXPIRED');
}
function assessLocalCase(item, nowMs) {
  if (!item || !Number.isSafeInteger(item.id)) return 'CASE_MISSING';
  if (typeof item.safe_t_id === 'string' && item.safe_t_id.trim()) return 'EXISTING_SAFE_T';
  if (item.support_case_id != null && String(item.support_case_id).trim()) return 'EXISTING_SUPPORT_REQUIRES_REVIEW';
  if (!CONFIRMED_INITIATORS.has(item.refund_initiator)) return 'AMAZON_INITIATOR_UNVERIFIED';
  if (!['FBA_ONSITE', 'DELIVERY_BY_AMAZON'].includes(item.program)) return 'PROGRAM_UNVERIFIED';
  const refundMs = utcTime(item.refund_at);
  if (!Number.isFinite(refundMs)) return 'REFUND_TIME_UNVERIFIED';
  const age = (nowMs - refundMs) / DAY_MS;
  if (age < 45) return 'BEFORE_D45';
  if (age >= 90) return 'AFTER_D90';
  // A carrier scan is not proof that the seller received the item.
  // Keep the independent warehouse nonreceipt check mandatory before any write.
  if (item.quantity_received !== 0 || !['NOT_RECEIVED', 'IN_TRANSIT', 'CARRIER_DELIVERED_PENDING_PHYSICAL'].includes(item.physical_status))
    return 'WAREHOUSE_RECEIPT_NOT_CLEARED';
  if (!Number.isFinite(Number(item.refund_amount)) || !Number.isFinite(Number(item.reconciled_credit_amount)) ||
    Number(item.refund_amount) <= 0 || Number(item.refund_amount) <= Number(item.reconciled_credit_amount))
    return 'OUTSTANDING_DEBIT_UNVERIFIED';
  return null;
}
function requireAdapter(adapter) {
  for (const method of ['verifyCanonicalOwnerGrant', 'verifyServicePrincipal', 'verifyCrashRollbackProvisioned', 'verifyScopedWorkerIsolation', 'acquireSingleWriterLease',
    'readCaseSnapshot', 'verifyWarehouseNonreceipt', 'verifyFinancesDebit',
    'verifyAmazonInitiated', 'verifyOfficialEligibility', 'verifyNoExternalClaimOrSupport',
    'consumeOneUseGrant', 'executeViaExistingScopedWorker', 'verifyAcceptedClaim',
    'restoreGlobalKillSwitch', 'verifyGlobalWritesDisabled', 'releaseSingleWriterLease']) {
    requireGate(typeof adapter?.[method] === 'function', 'CERTIFIED_ADAPTER_REQUIRED');
  }
}
/**
 * Production adapter MUST independently authenticate GitHub owner approval,
 * service principal, real Seller Central/Finances/warehouse proof, a durable
 * single-writer fenced lease, atomic one-use grant, and crash-safe rollback.
 * A mock adapter and passing unit tests are NOT operational certification.
 * This module has no standalone CLI, auto-start or enabled write channel.
 */
export async function activateScopedSafeTOpening({ grantNumber, adapter, now = () => Date.now() }) {
  const scope = SCOPES[grantNumber];
  requireGate(Boolean(scope), 'UNKNOWN_GRANT');
  requireAdapter(adapter);
  const startMs = now();
  requireGate(Number.isFinite(startMs), 'CLOCK_UNVERIFIED');
  const owner = await adapter.verifyCanonicalOwnerGrant(grantNumber);
  validateGrant(owner, scope, grantNumber, startMs);
  requireGate(await adapter.verifyServicePrincipal({ host: 'marina-a1-4c24g', principal: 'amazon-returns-safet-service' }) === true,
    'SERVICE_PRINCIPAL_UNVERIFIED');
  requireGate(await adapter.verifyGlobalWritesDisabled() === true, 'GLOBAL_WRITES_NOT_DISABLED');
  requireGate(await adapter.verifyCrashRollbackProvisioned({ grantNumber, profile: scope.version }) === true, 'CRASH_ROLLBACK_NOT_PROVISIONED');
  requireGate(await adapter.verifyScopedWorkerIsolation({ grantNumber, allowedActions: EXPECTED_ACTIONS }) === true, 'SINGLE_WORKER_ISOLATION_UNVERIFIED');
  const lock = await adapter.acquireSingleWriterLease({ grantNumber, scope: scope.version });
  requireGate(lock && Number.isSafeInteger(lock.fencing) && lock.fencing > 0 && typeof lock.id === 'string' && lock.id.length > 0,
    'EXCLUSIVE_LEASE_REQUIRED');
  try {
    const latest = await adapter.verifyCanonicalOwnerGrant(grantNumber);
    validateGrant(latest, scope, grantNumber, now());
    const cases = await adapter.readCaseSnapshot({ tenantId: 1, connectionId: 1, caseIds: [...scope.caseIds], fencing: lock.fencing });
    requireGate(Array.isArray(cases) && identicalSet(cases.map(c => c.id), scope.caseIds), 'CANARY_SNAPSHOT_MISMATCH');
    const verdicts = [];
    for (const item of cases) {
      const reason = assessLocalCase(item, now());
      if (reason) { verdicts.push({ caseId: item.id, status: 'SKIPPED', reason }); continue; }
      const params = { caseId: item.id, tenantId: 1, connectionId: 1, fencing: lock.fencing };
      let externalReady = true;
      for (const method of ['verifyWarehouseNonreceipt', 'verifyFinancesDebit', 'verifyAmazonInitiated',
        'verifyOfficialEligibility', 'verifyNoExternalClaimOrSupport']) {
        if ((await adapter[method](params)) !== true) { externalReady = false; break; }
      }
      if (!externalReady) { verdicts.push({ caseId: item.id, status: 'SKIPPED', reason: 'OFFICIAL_PROOF_UNVERIFIED' }); continue; }
      verdicts.push({ caseId: item.id, status: 'PREFLIGHT_PASSED' });
    }
    const ready = verdicts.filter(x => x.status === 'PREFLIGHT_PASSED');
    if (ready.length === 0) return { status: 'NO_AUTHORIZED_SUBMISSIONS', verdicts };
    requireGate(await adapter.consumeOneUseGrant({ grantNumber, fencing: lock.fencing }) === true, 'GRANT_ALREADY_CONSUMED');
    const results = [];
    for (const row of ready) {
      requireGate(now() < Date.parse(scope.expiresAt), 'OWNER_GRANT_EXPIRED');
      const input = { grantNumber, profile: scope.version, caseId: row.caseId,
        caseIds: [...scope.caseIds], action: 'SAFE_T_SUBMIT', fencing: lock.fencing };
      // Re-read and revalidate just before the irreversible provider click.
      const fresh = await adapter.readCaseSnapshot({ tenantId: 1, connectionId: 1, caseIds: [row.caseId], fencing: lock.fencing });
      requireGate(Array.isArray(fresh) && fresh.length === 1 && fresh[0]?.id === row.caseId &&
        assessLocalCase(fresh[0], now()) === null, 'CASE_CHANGED_BEFORE_SUBMIT');
      for (const method of ['verifyWarehouseNonreceipt', 'verifyFinancesDebit', 'verifyAmazonInitiated',
        'verifyOfficialEligibility', 'verifyNoExternalClaimOrSupport']) {
        requireGate(await adapter[method]({ caseId: row.caseId, tenantId: 1, connectionId: 1, fencing: lock.fencing }) === true,
          'OFFICIAL_PROOF_STALE');
      }
      requireGate(await adapter.verifyGlobalWritesDisabled() === true, 'UNSCOPED_WRITES_DETECTED');
      const attempt = await adapter.executeViaExistingScopedWorker(input);
      requireGate(attempt?.status === 'ACCEPTED' && CLAIM_ID.test(attempt?.externalId ?? ''), 'SUBMIT_READBACK_UNVERIFIED');
      requireGate(await adapter.verifyAcceptedClaim({ ...input, externalId: attempt.externalId }) === true,
        'EXTERNAL_CLAIM_NOT_CONFIRMED');
      results.push({ caseId: row.caseId, externalId: attempt.externalId });
    }
    return { status: 'ACCEPTED_WITH_READBACK', results, verdicts };
  } finally {
    // If global writes cannot be proven disabled, DO NOT release ownership:
    // releasing the lease could admit a second writer while writes remain ON.
    // An independent crash/reboot watchdog must quarantine/recover this state.
    let restored = false;
    try {
      await adapter.restoreGlobalKillSwitch({ grantNumber, fencing: lock.fencing });
      restored = (await adapter.verifyGlobalWritesDisabled()) === true;
    } catch {
      restored = false;
    }
    if (!restored) throw new ScopedSafeTError('KILL_SWITCH_RESTORE_UNVERIFIED');
    await adapter.releaseSingleWriterLease({ id: lock.id, fencing: lock.fencing });
  }
}
export { SCOPES, EXPECTED_ACTIONS, assessLocalCase };
