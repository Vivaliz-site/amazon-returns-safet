/**
 * Independent time gate for the final Seller Central submit click.
 * D+45 is only an internal review trigger for orders on/after 2026-04-21.
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
  const orderAt = String(job?.case?.order_at ?? '');
  const refundAt = String(job?.case?.refund_at ?? '');
  const orderMillis = utcTimestamp(orderAt);
  const refundMillis = utcTimestamp(refundAt);
  if (orderMillis === null) return { allowed: false, reason: 'ORDER_AT_UNVERIFIED' };
  if (refundMillis === null) return { allowed: false, reason: 'REFUND_AT_UNVERIFIED' };
  const days = orderAt.slice(0, 10) >= '2026-04-21' ? 60 : 45;
  const notBeforeMillis = refundMillis + days * 86400000;
  const nextAllowedAt = new Date(notBeforeMillis).toISOString().slice(0, 19).replace('T', ' ');
  return nowMillis >= notBeforeMillis
    ? { allowed: true, reason: 'EXTERNAL_WINDOW_REACHED', next_allowed_at: nextAllowedAt }
    : { allowed: false, reason: 'SAFE_T_D' + days + '_EXTERNAL_WINDOW_PENDING', next_allowed_at: nextAllowedAt };
}
