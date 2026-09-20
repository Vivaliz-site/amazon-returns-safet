import assert from 'node:assert/strict';
import fs from 'node:fs';

const worker = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');

assert.ok(worker.includes('async function safeTSubreasonShape(cdp, expected)'),
  'SAFE-T subreason drift must expose a dedicated structural DOM shape collector.');
for (const key of [
  'documents_total',
  'dropdowns_total',
  'hinted_dropdowns',
  'shadow_dropdowns',
  'trigger_candidates',
  'expanded_dropdowns',
  'direct_option_nodes',
  'deep_option_nodes',
  'exact_expected_direct_matches',
  'exact_expected_deep_matches',
  'role_option_nodes',
]) {
  assert.ok(worker.includes(key), 'SAFE-T structural shape must include allowlisted numeric/boolean key: ' + key);
}
assert.ok(worker.includes('normalizeSafeTSubreasonShape'),
  'SAFE-T structural diagnostics must pass through an explicit allowlist normalizer before logging.');
assert.ok(worker.includes('safe_t_subreason_shape: normalizeSafeTSubreasonShape(data.safe_t_subreason_shape)'),
  'Worker logs must expose only normalized SAFE-T subreason shape metadata.');
assert.ok(worker.includes("reason: 'SAFE_T_SUBREASON_OPTION_MISSING'"),
  'Contract must remain attached to the existing fail-closed subreason drift reason.');
assert.ok(!worker.includes('safe_t_subreason_shape: data.safe_t_subreason_shape'),
  'Worker must never log an arbitrary unnormalized SAFE-T shape object.');

console.log('safe-t-subreason-shape-contract: OK');
