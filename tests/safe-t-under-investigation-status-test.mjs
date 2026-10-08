import { parseSafeTStatus } from '../scripts/amazon-returns/safe-t-status-parser.mjs';

const parsed = parseSafeTStatus(
  'Claim details: 82095-48363-5534433\nUnder investigation\nOrder ID: 702-3172035-4814644',
  { safe_t_id: '82095-48363-5534433', order_id: '702-3172035-4814644' },
);
if (parsed.claim_status !== 'PENDING') {
  throw new Error(`expected PENDING for Under investigation, got ${parsed.claim_status}`);
}
console.log('safe-t-under-investigation-status-test: OK');
