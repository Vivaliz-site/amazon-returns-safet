/**
 * D+45 owner opening preflight. Amazon Seller Central eligibility and
 * the final submission/readback safeguards remain mandatory.
 */
function utcTimestamp(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)) return null;
  const normalized = value.replace(' ', 'T') + 'Z';
  const millis = Date.parse(normalized);
  return Number.isFinite(millis) && new Date(millis).toISOString().slice(0, 19) === normalized.slice(0, 19)
    ? millis : null;
}

export function safeTExternalWindow(job, nowMillis = Date.now()) {
  const program = String(job?.case?.program ?? '').trim().toUpperCase();
  if (program !== 'FBA_ONSITE' && program !== 'DELIVERY_BY_AMAZON') {
    return { allowed: program !== '' && program !== 'UNKNOWN', reason: 'PROGRAM_UNVERIFIED' };
  }
  const refundMillis = utcTimestamp(String(job?.case?.refund_at ?? ''));
  if (refundMillis === null) return { allowed: false, reason: 'REFUND_AT_UNVERIFIED' };
  const notBeforeMillis = refundMillis + 45 * 86400000;
  const notAfterMillis = refundMillis + 90 * 86400000;
  // A queued opening can become stale: refuse any first attempt at or after D+90.
  if (nowMillis >= notAfterMillis) {
    return { allowed: false, reason: 'D90_OPENING_EXPIRED', retry_safe: false };
  }
  const nextAllowedAt = new Date(notBeforeMillis).toISOString().slice(0, 19).replace('T', ' ');
  return nowMillis >= notBeforeMillis
    ? { allowed: true, reason: 'D45_OPENING_REACHED', next_allowed_at: nextAllowedAt }
    : { allowed: false, reason: 'D45_OPENING_PENDING', next_allowed_at: nextAllowedAt };
}
