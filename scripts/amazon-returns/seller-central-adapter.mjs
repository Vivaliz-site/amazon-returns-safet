#!/usr/bin/env node
import { createHash } from 'node:crypto';

const ALLOWED_ACTIONS = new Set(['SAFE_T_READ','SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_READ','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE']);
const ALLOWED_STATUSES = new Set(['ACCEPTED','BLOCKED_UNTIL','ALREADY_EXISTS','AUTH_REQUIRED','HUMAN_CHALLENGE','UI_DRIFT','NOT_FOUND','FAILED']);
const KNOWN_UI_CONTRACTS = new Set(['safet-v1','help-v1']);

function sha(value) { return createHash('sha256').update(value).digest('hex'); }
function bool(value) { return value === true; }
function cleanText(value, max = 500) { return String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, max); }

export function evaluateSellerCentral(input) {
  const action = cleanText(input?.action, 64).toUpperCase();
  if (!ALLOWED_ACTIONS.has(action)) return result('FAILED', { reason: 'UNSUPPORTED_ACTION', retry_safe: false });
  const snapshot = input?.snapshot && typeof input.snapshot === 'object' ? input.snapshot : {};
  const uiContract = cleanText(snapshot.ui_contract, 32);
  if (!bool(snapshot.authenticated)) return result('AUTH_REQUIRED', { reason: 'SESSION_NOT_AUTHENTICATED', retry_safe: false });
  if (bool(snapshot.mfa_required)) return result('AUTH_REQUIRED', { reason: 'MFA_REQUIRED', retry_safe: false });
  if (bool(snapshot.captcha_present)) return result('HUMAN_CHALLENGE', { reason: 'CAPTCHA_PRESENT', retry_safe: false });
  if (!KNOWN_UI_CONTRACTS.has(uiContract)) return result('UI_DRIFT', { reason: 'UNKNOWN_UI_CONTRACT', retry_safe: false, ui_contract: uiContract });

  if (action === 'SAFE_T_SUBMIT' && cleanText(snapshot.existing_claim_id, 80)) {
    return result('ALREADY_EXISTS', { external_id: cleanText(snapshot.existing_claim_id, 80), reason: 'CLAIM_ALREADY_EXISTS', retry_safe: true, evidence: evidence(snapshot) });
  }
  if (action === 'SELLER_SUPPORT_OPEN' && cleanText(snapshot.existing_support_case_id, 80)) {
    return result('ALREADY_EXISTS', { external_id: cleanText(snapshot.existing_support_case_id, 80), reason: 'SUPPORT_CASE_ALREADY_EXISTS', retry_safe: true, evidence: evidence(snapshot) });
  }
  if (action === 'SAFE_T_SUBMIT') {
    const eligibility = snapshot.eligibility && typeof snapshot.eligibility === 'object' ? snapshot.eligibility : {};
    if (eligibility.allowed !== true) {
      return result('BLOCKED_UNTIL', {
        reason: cleanText(eligibility.reason || 'SELLER_CENTRAL_BLOCKED', 500),
        block_reason: cleanText(eligibility.reason || 'SELLER_CENTRAL_BLOCKED', 500),
        next_allowed_at: cleanText(eligibility.next_allowed_at, 64) || null,
        retry_safe: true,
        evidence: evidence(snapshot),
      });
    }
  }

  const requestedDryRun = input?.dry_run !== false;
  const writeFlags = input?.write_flags && typeof input.write_flags === 'object' ? input.write_flags : {};
  const writeAction = !action.endsWith('_READ');
  const writeEnabled = writeFlags[action] === true;
  const effectiveDryRun = requestedDryRun || (writeAction && !writeEnabled);
  return result('ACCEPTED', {
    reason: effectiveDryRun ? 'DRY_RUN_OR_WRITE_FLAG_OFF' : 'PRECONDITIONS_ACCEPTED',
    dry_run: effectiveDryRun,
    submitted: false,
    would_write: writeAction,
    retry_safe: true,
    evidence: evidence(snapshot),
  });
}

function evidence(snapshot) {
  const safe = {
    ui_contract: cleanText(snapshot.ui_contract, 32),
    current_url: cleanText(snapshot.current_url, 300),
    existing_claim_id: cleanText(snapshot.existing_claim_id, 80) || null,
    existing_support_case_id: cleanText(snapshot.existing_support_case_id, 80) || null,
    eligibility: snapshot.eligibility && typeof snapshot.eligibility === 'object' ? {
      allowed: snapshot.eligibility.allowed === true,
      next_allowed_at: cleanText(snapshot.eligibility.next_allowed_at, 64) || null,
      reason_hash: snapshot.eligibility.reason ? sha(cleanText(snapshot.eligibility.reason, 1000)) : null,
    } : null,
  };
  return { snapshot_sha256: sha(JSON.stringify(safe)), ...safe };
}

function result(status, extra = {}) {
  if (!ALLOWED_STATUSES.has(status)) throw new Error(`invalid status ${status}`);
  return { status, external_id: null, block_reason: null, next_allowed_at: null, dry_run: true, submitted: false, retry_safe: false, ...extra };
}

async function fetchBridgeSnapshot(bridgeUrl, input) {
  try {
    const response = await fetch(bridgeUrl, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        operation: 'snapshot',
        action: cleanText(input.action, 64).toUpperCase(),
        case: input.case && typeof input.case === 'object' ? input.case : {},
        precondition: 'READ_ONLY_PREFLIGHT',
      }),
      signal: AbortSignal.timeout(60000),
    });
    const data = await response.json();
    if (!response.ok || data?.status !== 'ACCEPTED' || !data?.snapshot || typeof data.snapshot !== 'object') {
      return { error: result('FAILED', { reason: 'BROWSER_BRIDGE_SNAPSHOT_INVALID', retry_safe: false }) };
    }
    return { snapshot: data.snapshot };
  } catch (error) {
    return { error: result('FAILED', { reason: 'BROWSER_BRIDGE_SNAPSHOT_FAILURE', error_class: error?.name || 'Error', retry_safe: false }) };
  }
}

async function executeBridgeWrite(bridgeUrl, input, preflight) {
  try {
    const response = await fetch(bridgeUrl, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        action: cleanText(input.action, 64).toUpperCase(),
        case: input.case && typeof input.case === 'object' ? input.case : {},
        expected_snapshot_sha256: preflight?.evidence?.snapshot_sha256 ?? null,
        precondition: 'READ_BACK_BEFORE_AND_AFTER_WRITE',
      }),
      signal: AbortSignal.timeout(60000),
    });
    const data = await response.json();
    const status = cleanText(data?.status, 32).toUpperCase();
    if (!response.ok || !ALLOWED_STATUSES.has(status)) {
      return result('FAILED', { reason: 'BROWSER_BRIDGE_INVALID_RESPONSE', dry_run: false, submitted: false, retry_safe: false, evidence: preflight.evidence });
    }
    const externalId = cleanText(data?.external_id, 100) || null;
    const submitted = data?.submitted === true;
    if (submitted && !externalId) {
      return result('FAILED', { reason: 'WRITE_WITHOUT_READBACK_ID', dry_run: false, submitted: false, retry_safe: false, evidence: preflight.evidence });
    }
    return result(status, {
      reason: cleanText(data?.reason, 500) || 'BROWSER_BRIDGE_RESULT',
      external_id: externalId,
      block_reason: cleanText(data?.block_reason, 500) || null,
      next_allowed_at: cleanText(data?.next_allowed_at, 64) || null,
      dry_run: false,
      submitted,
      retry_safe: submitted ? true : data?.retry_safe === true,
      evidence: data?.evidence && typeof data.evidence === 'object' ? data.evidence : preflight.evidence,
    });
  } catch (error) {
    return result('FAILED', { reason: 'BROWSER_BRIDGE_TRANSPORT_FAILURE', error_class: error?.name || 'Error', dry_run: false, submitted: false, retry_safe: false, evidence: preflight.evidence });
  }
}

async function main() {
  let raw = '';
  for await (const chunk of process.stdin) raw += chunk;
  try {
    const input = JSON.parse(raw || '{}');
    const bridgeUrl = cleanText(process.env.SELLER_CENTRAL_BROWSER_BRIDGE_URL, 1000);
    const hasSnapshot = input?.snapshot && typeof input.snapshot === 'object' && Object.keys(input.snapshot).length > 0;
    if (!hasSnapshot && bridgeUrl) {
      const preflight = await fetchBridgeSnapshot(bridgeUrl, input);
      if (preflight.error) {
        process.stdout.write(JSON.stringify(preflight.error));
        return;
      }
      input.snapshot = preflight.snapshot;
    }
    let evaluated = evaluateSellerCentral(input);
    if (evaluated.status === 'ACCEPTED' && evaluated.would_write === true && evaluated.dry_run === false) {
      if (!bridgeUrl) {
        evaluated = result('FAILED', { reason: 'BROWSER_BRIDGE_UNAVAILABLE', dry_run: false, submitted: false, retry_safe: false, evidence: evaluated.evidence });
      } else {
        evaluated = await executeBridgeWrite(bridgeUrl, input, evaluated);
      }
    }
    process.stdout.write(JSON.stringify(evaluated));
  } catch (error) {
    process.stdout.write(JSON.stringify(result('FAILED', { reason: 'INVALID_INPUT', error_class: error?.name || 'Error' })));
    process.exitCode = 1;
  }
}

if (import.meta.url === `file://${process.argv[1]}`) await main();
