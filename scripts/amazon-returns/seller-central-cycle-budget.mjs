/**
 * Bound a canonical Seller Central browser cycle before its 30-minute systemd
 * one-shot timeout.  This is a cooperative stopping point BETWEEN jobs:
 * no in-flight read/write, acknowledgement or lease is interrupted.
 */
export const MAX_CYCLE_MS = 20 * 60 * 1000;
const MIN_JOB_HEADROOM_MS = 30 * 1000;

export function resolveCycleDeadline(raw, nowMillis = Date.now()) {
  if (!Number.isSafeInteger(nowMillis) || nowMillis < 0) {
    throw new Error('Invalid Seller Central cycle deadline clock');
  }
  if (raw === undefined || raw === null || raw === '') {
    return nowMillis + MAX_CYCLE_MS;
  }
  if (typeof raw !== 'string' || !/^[1-9][0-9]{12}$/.test(raw)) {
    throw new Error('Invalid Seller Central cycle deadline format');
  }
  const deadline = Number(raw);
  if (!Number.isSafeInteger(deadline) || deadline > nowMillis + MAX_CYCLE_MS) {
    throw new Error('Invalid Seller Central cycle deadline range');
  }
  // A shared, already-expired deadline never gets extended by the next worker.
  return deadline;
}

export function withinCycleBudget(deadline, nowMillis = Date.now()) {
  if (!Number.isSafeInteger(deadline) || !Number.isSafeInteger(nowMillis)) {
    throw new Error('Invalid Seller Central cycle deadline');
  }
  return deadline - nowMillis > MIN_JOB_HEADROOM_MS;
}
