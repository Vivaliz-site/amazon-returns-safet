/**
 * Owner-review P2 #3830: bounded automatic SAFE-T activation core.
 * Import-only. No CLI, scheduler, service, browser, credentials or writes.
 * This is NOT an installed production adapter or an authorization by itself.
 */
export const D45_AUTO_SCOPE = Object.freeze({
  grantNumber: 3830,
  operation: 'activate_d45_safe_t_automation',
  resource: 'amazon-returns:safet:marina',
  host: 'marina-a1-4c24g',
  principal: 'amazon-returns-safet-service',
  tenantId: 1,
  connectionId: 1,
  expiresAt: '2026-11-30T23:59:00Z',
  minAgeDays: 45,
  maxAgeDaysExclusive: 90,
  maxTotal: 20,
  maxPerUtcDay: 5,
  excludedCaseIds: Object.freeze([8803]),
  allowedPrograms: Object.freeze(['FBA_ONSITE', 'DELIVERY_BY_AMAZON']),
});
export const SAFE_T_ONLY = Object.freeze({
  SAFE_T_SUBMIT: true,
  SAFE_T_APPEAL: false,
  SAFE_T_EMAIL_REVIEW: false,
  SAFE_T_EMAIL_REPLY: false,
  SELLER_SUPPORT_OPEN: false,
  SELLER_SUPPORT_UPDATE: false,
  ERP_SALES_RETURN_CREATE: false,
});
const CLAIM_ID = /^\d{5}-\d{5}-\d{7}$/;
const ORDER_ID = /^\d{3}-\d{7}-\d{7}$/;
const MS_DAY = 86400000;
const APPROVED_INITIATORS = new Set(['AMAZON_INITIATED', 'AMAZON_CUSTOMER_SERVICE']);
const REQUIRED = Object.freeze([
  'verifyCanonicalOwnerGrant', 'verifyRestrictedPrincipal',
  'verifyCrashRollbackWatchdog', 'verifyExistingWorkerIsolation',
  'verifyGlobalWritesDisabled', 'acquireExclusiveWriterLease',
  'listCandidateCases', 'readCase', 'verifyWarehouseNonreceipt',
  'verifyFinancesUnrecoveredDebit', 'verifyAmazonRefundInitiator',
  'verifyNoExternalClaimOrSupport', 'readOfficialEligibility',
  'recordOfficialWait', 'verifyOrActivateOneUseLedger',
  'reserveDurableSubmissionAttempt', 'submitThroughExistingWorker',
  'verifyExternalClaimReadback', 'quarantineIndeterminateAttempt',
  'restoreGlobalKillSwitch', 'releaseExclusiveWriterLease',
]);

export class D45AutoActivationError extends Error {
  constructor(code) { super(code); this.name = 'D45AutoActivationError'; this.code = code; }
}
function must(value, code) { if (!value) throw new D45AutoActivationError(code); }
function utcMilliseconds(raw) {
  if (typeof raw !== 'string' ||
      !/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\dZ?$/.test(raw)) return NaN;
  const normalized = raw.replace(' ', 'T').replace(/Z?$/, 'Z');
  const ms = Date.parse(normalized);
  return Number.isFinite(ms) && new Date(ms).toISOString().slice(0,19) === normalized.slice(0,19)
    ? ms : NaN;
}
function exactArray(items, expected) {
  return Array.isArray(items) && items.length === expected.length &&
    new Set(items).size === expected.length && expected.every(x => items.includes(x));
}
export function verifyD45AutoGrant(verification, nowMs) {
  const g = verification?.grant;
  must(verification?.verifiedBy === 'canonical-github-owner-approval-verifier' &&
    verification?.owner === 'shopvivaliz-human' &&
    verification?.approvedOnHead === true && verification?.merged === true &&
    verification?.grantNumber === D45_AUTO_SCOPE.grantNumber &&
    g?.schema === 'SHOPVIVALIZ_OWNER_PR_GRANT_V1' &&
    g?.repository === 'Vivaliz-site/site-shopvivaliz' &&
    g?.pr_number === D45_AUTO_SCOPE.grantNumber &&
    g?.operation === D45_AUTO_SCOPE.operation &&
    g?.resource === D45_AUTO_SCOPE.resource &&
    g?.principal === D45_AUTO_SCOPE.principal &&
    g?.host === D45_AUTO_SCOPE.host &&
    g?.target_repository === 'Vivaliz-site/amazon-returns-safet' &&
    g?.tenant_id === D45_AUTO_SCOPE.tenantId &&
    g?.amazon_connection_id === D45_AUTO_SCOPE.connectionId &&
    g?.one_use === true && g?.automatic_future_cases === true &&
    g?.min_days_since_refund === D45_AUTO_SCOPE.minAgeDays &&
    g?.max_days_since_refund_exclusive === D45_AUTO_SCOPE.maxAgeDaysExclusive &&
    g?.max_new_claims_total === D45_AUTO_SCOPE.maxTotal &&
    g?.max_new_claims_per_utc_day === D45_AUTO_SCOPE.maxPerUtcDay &&
    g?.expires_at_utc === D45_AUTO_SCOPE.expiresAt &&
    exactArray(g?.capabilities, ['safe_t_submit']) &&
    exactArray(g?.allowed_write_actions, ['SAFE_T_SUBMIT']) &&
    exactArray(g?.other_write_actions_denied,
      Object.keys(SAFE_T_ONLY).filter(x => x !== 'SAFE_T_SUBMIT')) &&
    exactArray(g?.excluded_case_ids, [8803]) &&
    g?.activation_requires_certified_production_adapter === true,
    'OWNER_D45_GRANT_UNVERIFIED');
  must(Number.isFinite(nowMs) && nowMs < Date.parse(D45_AUTO_SCOPE.expiresAt),
    'OWNER_D45_GRANT_EXPIRED');
  return true;
}

/** Local preflight only: does not infer provider eligibility or claim absence. */
export function assessD45Candidate(c, nowMs) {
  if (!Number.isFinite(nowMs) || !c || !Number.isSafeInteger(c.id) || c.id <= 0)
    return 'CASE_ID_UNVERIFIED';
  if (D45_AUTO_SCOPE.excludedCaseIds.includes(c.id)) return 'EXCLUDED_EXISTING_CLAIM';
  if (c.tenant_id !== 1 || c.amazon_connection_id !== 1) return 'WRONG_TENANT_OR_CONNECTION';
  if (!ORDER_ID.test(c.amazon_order_id ?? '')) return 'ORDER_ID_UNVERIFIED';
  if ((c.safe_t_id != null && String(c.safe_t_id).trim()) ||
      (c.support_case_id != null && String(c.support_case_id).trim()))
    return 'EXISTING_CLAIM_OR_SUPPORT';
  if (!D45_AUTO_SCOPE.allowedPrograms.includes(c.program) ||
      c.physical_status === 'RECEIVED_DISCREPANT') return 'PROGRAM_REQUIRES_REVIEW';
  if (!APPROVED_INITIATORS.has(c.refund_initiator)) return 'REFUND_INITIATOR_UNVERIFIED';
  const refundedAt = utcMilliseconds(c.refund_at);
  if (!Number.isFinite(refundedAt)) return 'REFUND_TIMESTAMP_UNVERIFIED';
  const age = (nowMs - refundedAt) / MS_DAY;
  if (age < 45) return 'BEFORE_D45';
  if (age >= 90) return 'ON_OR_AFTER_D90';
  if (c.physical_status !== 'NOT_RECEIVED' || c.quantity_received !== 0)
    return 'WAREHOUSE_RECEIPT_UNVERIFIED';
  const original = Number(c.refund_amount);
  const credited = Number(c.reconciled_credit_amount);
  if (c.refund_amount == null || c.reconciled_credit_amount == null ||
      !Number.isFinite(original) || !Number.isFinite(credited) ||
      original <= 0 || credited < 0 || credited >= original)
    return 'UNRECOVERED_DEBIT_UNVERIFIED';
  return null;
}

function assertAdapter(adapter) {
  for (const method of REQUIRED)
    must(typeof adapter?.[method] === 'function', 'CERTIFIED_PRODUCTION_ADAPTER_REQUIRED');
}
function isLease(lease) {
  return typeof lease?.id === 'string' && lease.id.length > 0 &&
    Number.isSafeInteger(lease.fencing) && lease.fencing > 0;
}
function isActivation(activation) {
  return activation?.grantNumber === 3830 && activation?.status === 'ACTIVE' &&
    typeof activation?.id === 'string' && activation.id.length > 0 &&
    activation?.principal === D45_AUTO_SCOPE.principal;
}

/**
 * Executes at most maxPerUtcDay submissions in a cycle. A trusted external
 * adapter MUST implement the official GitHub verifier, service RBAC,
 * transactionally durable one-use activation and daily/total attempt budget,
 * browser FLOCK, per-case uniqueness, all independent provider evidence,
 * and crash-safe kill-switch restoration. Mock tests are NOT certification.
 * A non-ELIGIBLE official provider response is always a non-writing result.
 */
export async function activateD45AutomaticSafeT({adapter, now = () => Date.now()}) {
  assertAdapter(adapter);
  const started = now();
  must(Number.isFinite(started), 'CLOCK_UNVERIFIED');
  verifyD45AutoGrant(await adapter.verifyCanonicalOwnerGrant(3830), started);
  must(await adapter.verifyRestrictedPrincipal(D45_AUTO_SCOPE.principal) === true,
    'RESTRICTED_PRINCIPAL_NOT_ACTIVE');
  must(await adapter.verifyGlobalWritesDisabled() === true, 'GLOBAL_WRITES_NOT_DISABLED');
  must(await adapter.verifyCrashRollbackWatchdog() === true, 'CRASH_WATCHDOG_UNVERIFIED');
  must(await adapter.verifyExistingWorkerIsolation(SAFE_T_ONLY) === true,
    'WORKER_SINGLE_WRITER_UNVERIFIED');
  const lease = await adapter.acquireExclusiveWriterLease({grantNumber:3830,host:D45_AUTO_SCOPE.host});
  must(isLease(lease), 'EXCLUSIVE_LEASE_FENCING_REQUIRED');
  try {
    verifyD45AutoGrant(await adapter.verifyCanonicalOwnerGrant(3830), now());
    const candidates = await adapter.listCandidateCases({tenantId:1,connectionId:1,fencing:lease.fencing});
    must(Array.isArray(candidates) && candidates.length <= 500 &&
      candidates.every(c => Number.isSafeInteger(c?.id)) &&
      new Set(candidates.map(c => c.id)).size === candidates.length,
      'CANDIDATE_BATCH_UNVERIFIED');
    const eligible = [];
    const verdicts = [];
    for (const candidate of candidates) {
      const reason = assessD45Candidate(candidate,now());
      if (reason) { verdicts.push({caseId:candidate.id,status:'SKIPPED',reason}); continue; }
      const input = {caseId:candidate.id,orderId:candidate.amazon_order_id,fencing:lease.fencing};
      const independentProofs = [
        'verifyWarehouseNonreceipt', 'verifyFinancesUnrecoveredDebit',
        'verifyAmazonRefundInitiator', 'verifyNoExternalClaimOrSupport',
      ];
      let passed = true;
      for (const method of independentProofs) {
        if ((await adapter[method](input)) !== true) { passed = false; break; }
      }
      if (!passed) { verdicts.push({caseId:candidate.id,status:'SKIPPED',reason:'PROVIDER_PROOF_UNVERIFIED'}); continue; }
      const official = await adapter.readOfficialEligibility(input);
      if (official?.status === 'BLOCKED_UNTIL') {
        const until = utcMilliseconds(official.nextAllowedAt);
        must(Number.isFinite(until) && until > now() &&
          official.orderId === input.orderId, 'OFFICIAL_WAIT_DATE_UNVERIFIED');
        await adapter.recordOfficialWait({...input, nextAllowedAt:official.nextAllowedAt});
        verdicts.push({caseId:candidate.id,status:'WAIT_PROVIDER',reason:'OFFICIAL_BLOCKED_UNTIL'});
        continue;
      }
      if (official?.status !== 'ELIGIBLE' || official.orderId !== input.orderId ||
          official.verifiedBy !== 'authenticated-seller-central') {
        verdicts.push({caseId:candidate.id,status:'SKIPPED',reason:'OFFICIAL_ELIGIBILITY_UNVERIFIED'});
        continue;
      }
      eligible.push({caseId:candidate.id,orderId:candidate.amazon_order_id});
    }
    if (!eligible.length) return {status:'NO_CERTIFIED_PROVIDER_ELIGIBILITY',verdicts,submitted:[]};
    verifyD45AutoGrant(await adapter.verifyCanonicalOwnerGrant(3830),now());
    const activation = await adapter.verifyOrActivateOneUseLedger({
      grantNumber:3830,principal:D45_AUTO_SCOPE.principal,fencing:lease.fencing,
    });
    must(isActivation(activation), 'DURABLE_ACTIVATION_LEDGER_UNVERIFIED');
    const submitted = [];
    for (const c of eligible.slice(0,D45_AUTO_SCOPE.maxPerUtcDay)) {
      verifyD45AutoGrant(await adapter.verifyCanonicalOwnerGrant(3830),now());
      const fresh = await adapter.readCase({caseId:c.caseId,tenantId:1,connectionId:1,fencing:lease.fencing});
      must(assessD45Candidate(fresh,now()) === null &&
        fresh.amazon_order_id === c.orderId, 'CASE_CHANGED_BEFORE_SUBMIT');
      const input={caseId:c.caseId,orderId:c.orderId,fencing:lease.fencing};
      for (const method of ['verifyWarehouseNonreceipt','verifyFinancesUnrecoveredDebit',
        'verifyAmazonRefundInitiator','verifyNoExternalClaimOrSupport'])
        must(await adapter[method](input) === true,'EXTERNAL_PROOF_STALE');
      const official = await adapter.readOfficialEligibility(input);
      must(official?.status === 'ELIGIBLE' && official.orderId === c.orderId &&
        official.verifiedBy === 'authenticated-seller-central',
        'OFFICIAL_ELIGIBILITY_STALE');
      must(await adapter.verifyGlobalWritesDisabled() === true,'GLOBAL_WRITES_NOT_DISABLED');
      const reserve = await adapter.reserveDurableSubmissionAttempt({
        ...input,grantNumber:3830,activationId:activation.id,
        dayUtc:new Date(now()).toISOString().slice(0,10),
        maxPerUtcDay:D45_AUTO_SCOPE.maxPerUtcDay,maxTotal:D45_AUTO_SCOPE.maxTotal,
      });
      if (reserve?.status === 'NO_CAPACITY' || reserve?.status === 'ALREADY_ATTEMPTED') {
        verdicts.push({caseId:c.caseId,status:'SKIPPED',reason:reserve.status});
        continue;
      }
      must(reserve?.status === 'RESERVED' && typeof reserve?.id === 'string' &&
        reserve.id.length > 0, 'DURABLE_ATTEMPT_RESERVATION_REQUIRED');
      // Reservation precedes the irreversible click. Unknown result is not retried.
      let result;
      try {
        result = await adapter.submitThroughExistingWorker({
          ...input,grantNumber:3830,activationId:activation.id,attemptId:reserve.id,
          action:'SAFE_T_SUBMIT',allowedActions:SAFE_T_ONLY,
        });
        must(result?.status === 'ACCEPTED' && CLAIM_ID.test(result.externalId ?? ''),
          'SAFE_T_EXTERNAL_ID_UNVERIFIED');
        must(await adapter.verifyExternalClaimReadback({...input,externalId:result.externalId}) === true,
          'SAFE_T_PROVIDER_READBACK_UNVERIFIED');
      } catch (error) {
        await adapter.quarantineIndeterminateAttempt({
          ...input,grantNumber:3830,attemptId:reserve.id,
          reason: error?.code ?? 'UNKNOWN_EXTERNAL_RESULT',
        });
        throw error;
      }
      submitted.push({caseId:c.caseId,externalId:result.externalId});
    }
    return {status:submitted.length?'ACCEPTED_WITH_READBACK':'NO_NEW_SUBMISSIONS',submitted,verdicts};
  } finally {
    let restored = false;
    try {
      await adapter.restoreGlobalKillSwitch({grantNumber:3830,fencing:lease.fencing});
      restored=(await adapter.verifyGlobalWritesDisabled()) === true;
    } catch { restored = false; }
    // On unknown restore, keep the lease to prevent a second writer.
    if (!restored) throw new D45AutoActivationError('KILL_SWITCH_RESTORE_UNVERIFIED');
    await adapter.releaseExclusiveWriterLease({id:lease.id,fencing:lease.fencing});
  }
}
