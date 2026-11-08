import fs from 'node:fs';
import path from 'node:path';

/**
 * Verify the Linux FLOCK on the actual browser-cycle file descriptor inherited
 * from the managed runner. A caller-controlled environment flag alone is
 * never authority to attach to the shared Seller Central browser.
 */
export function managedCycleLockHeld({
  profile = process.env.SELLER_CENTRAL_PROFILE,
  override = process.env.SELLER_CENTRAL_CYCLE_LOCK_FILE,
} = {}) {
  try {
    if (typeof profile !== 'string' || !path.isAbsolute(profile)) return false;
    const canonical = path.join(fs.realpathSync(profile), 'seller-central-cycle.lock');
    // Production must use the canonical profile lock, not a caller-chosen file.
    if (override && path.resolve(override) !== canonical) return false;
    const holder = fs.fstatSync(9);
    const expected = fs.statSync(canonical);
    if (!holder.isFile() || holder.dev !== expected.dev || holder.ino !== expected.ino) return false;
    const info = fs.readFileSync('/proc/self/fdinfo/9', 'utf8');
    return /^lock:\s*\d+:\s+FLOCK\s+ADVISORY\s+WRITE\b/m.test(info);
  } catch {
    return false;
  }
}
