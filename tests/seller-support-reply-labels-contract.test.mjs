import fs from 'node:fs';
import assert from 'node:assert/strict';

const worker = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');

const updateStart = worker.indexOf('async function supportUpdate');
assert.ok(updateStart >= 0, 'supportUpdate must exist');
const updateEnd = worker.indexOf('\nasync function ', updateStart + 1);
const supportUpdate = worker.slice(updateStart, updateEnd > updateStart ? updateEnd : undefined);

for (const label of ['Send', 'Send message', 'Reply', 'Enviar', 'Enviar mensagem', 'Responder']) {
  assert.ok(
    supportUpdate.includes(`'${label}'`),
    `supportUpdate must recognize Seller Central reply action label: ${label}`,
  );
}
assert.ok(worker.includes("querySelectorAll?.('kat-button,button')"), 'trusted reply click helper must inspect visible button hosts across shadow roots.');
assert.ok(worker.includes('async function supportCaseContainsText(cdp, caseId, needle)'), 'Seller Support updates must verify existing replies through authenticated ViewCase readback.');
assert.ok(worker.includes('async clickButtonTrustedByText(labels)'), 'Seller Support send must use a trusted top-level pointer helper.');
assert.ok(supportUpdate.includes('await supportCaseContainsText(cdp, caseId, narrative.slice(0, 240))'), 'supportUpdate must dedupe against authoritative case content before sending.');
assert.ok(supportUpdate.includes('await cdp.clickButtonTrustedByText(sendLabels)'), 'supportUpdate must send with a trusted CDP pointer click.');
assert.ok(supportUpdate.includes('await waitForSupportCaseText(cdp, caseId, narrative.slice(0, 240))'), 'supportUpdate must confirm the reply through ViewCase after sending.');
assert.ok(
  supportUpdate.includes('const selector = await ensureSupportReplyComposer(cdp);'),
  'supportUpdate must open/locate the real reply composer before writing text.',
);
assert.ok(
  worker.includes("placeholder.includes('feedback')"),
  'reply composer detection must exclude the unrelated Seller Central feedback textarea.',
);
assert.ok(
  worker.includes("const triggerLabels=['Reply','Responder']"),
  'reply composer transition must recognize the observed Reply/Responder action.',
);

console.log('seller-support-reply-labels-contract-test: OK');
