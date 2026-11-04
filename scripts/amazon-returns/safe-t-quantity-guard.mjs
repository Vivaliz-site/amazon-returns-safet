/**
 * Strict, fail-closed quantity of refunded units still outstanding.
 * This is a defense-in-depth guard. Amazon, warehouse and credit proof
 * still require separate server-side verification.
 */
export function safeTUnreceivedQuantity(caseData) {
  const refunded = caseData?.quantity_refunded;
  const received = caseData?.quantity_received;
  if (!Number.isSafeInteger(refunded) || refunded <= 0) {
    return { allowed: false, reason: 'SAFE_T_REFUNDED_QUANTITY_UNVERIFIED' };
  }
  if (!Number.isSafeInteger(received) || received < 0) {
    return { allowed: false, reason: 'SAFE_T_RECEIVED_QUANTITY_UNVERIFIED' };
  }
  const remaining = refunded - received;
  if (remaining <= 0) {
    return { allowed: false, reason: 'SAFE_T_NO_UNRECEIVED_REFUNDED_UNITS' };
  }
  return { allowed: true, quantity: remaining };
}
