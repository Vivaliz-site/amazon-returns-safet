import fs from 'node:fs';
import assert from 'node:assert/strict';

const worker = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');

const updateStart = worker.indexOf('async function supportUpdate');
assert.ok(updateStart >= 0, 'supportUpdate must exist');
const updateEnd = worker.indexOf('\nasync function ', updateStart + 1);
const supportUpdate = worker.slice(updateStart, updateEnd > updateStart ? updateEnd : undefined);

for (const label of ['Send', 'Send message', 'Submit', 'Enviar', 'Enviar mensagem', 'Enviar resposta']) {
  assert.ok(
    supportUpdate.includes(`'${label}'`),
    `supportUpdate must recognize explicit Seller Central send action label: ${label}`,
  );
}
assert.ok(!supportUpdate.includes("sendLabels=['Send','Send message','Reply'"), 'Reply/Responder composer triggers must never count as final send actions.');
assert.ok(worker.includes("const selector='kat-button,button,kat-link,[role=\"button\"],input[type=\"submit\"],input[type=\"button\"]'"), 'trusted reply click helper must inspect semantic button controls across deep roots.');
assert.ok(worker.includes("host.tagName==='KAT-LINK'"), 'trusted reply click helper must support Seller Central KAT links rendered as buttons.');
assert.ok(worker.includes("host.getAttribute?.('value')") && worker.includes("host.getAttribute?.('title')"), 'trusted reply click helper must recognize explicit send labels exposed through value/title attributes.');
assert.ok(worker.includes("host.getAttribute?.('aria-disabled')==='true'"), 'trusted reply click helper must reject aria-disabled controls.');
assert.ok(worker.includes('control.getClientRects().length===0'), 'trusted reply click helper must reject non-rendered controls.');
assert.ok(worker.includes("querySelectorAll?.('iframe')") && worker.includes('scan(frame.contentDocument)'), 'trusted reply click helper must traverse same-origin iframes, including those nested under shadow DOM.');
assert.ok(worker.includes('found.length===1'), 'trusted reply click helper must fail closed unless exactly one send target is visible.');
assert.ok(worker.includes("const buttonSelector='kat-button,button,kat-link,[role=\"button\"],input[type=\"submit\"],input[type=\"button\"]'"), 'support evidence must snapshot the same semantic control family used for trusted sends.');
assert.ok(worker.includes('async function supportCaseContainsText(cdp, caseId, needle)'), 'Seller Support updates must verify existing replies through authenticated ViewCase readback.');
assert.ok(worker.includes('async clickButtonTrustedByText(labels)'), 'Seller Support send must use a trusted top-level pointer helper.');
assert.ok(supportUpdate.includes('await supportCaseContainsText(cdp, caseId, narrative.slice(0, 240))'), 'supportUpdate must dedupe against authoritative case content before sending.');
assert.ok(supportUpdate.includes('await cdp.clickButtonTrustedByText(sendLabels)'), 'supportUpdate must send with a trusted CDP pointer click.');
assert.ok(supportUpdate.includes('await waitForSupportCaseText(cdp, caseId, narrative.slice(0, 240))'), 'supportUpdate must confirm the reply through ViewCase after sending.');
assert.ok(
  supportUpdate.includes('const composerReady = await ensureSupportReplyComposer(cdp);'),
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

assert.ok(
  worker.includes('await cdp.clickButtonTrustedByText(triggerLabels)'),
  'reply composer transition must use the trusted deep-root pointer helper for Reply/Responder.',
);
assert.ok(
  worker.includes("querySelectorAll?.('iframe')") && worker.includes('if(e.shadowRoot)scan(e.shadowRoot)'),
  'reply composer field discovery must traverse nested same-origin iframe and shadow roots.',
);
assert.ok(
  worker.includes('async fillDeepSupportTextarea(value)'),
  'Seller Support replies must provide a dedicated trusted deep-root textarea writer.',
);
assert.ok(
  supportUpdate.includes('await cdp.fillDeepSupportTextarea(narrative)'),
  'supportUpdate must write the reply through the deep-root trusted textarea writer.',
);
assert.ok(
  !supportUpdate.includes("await cdp.setKat('kat-textarea', narrative)"),
  'supportUpdate must not assume the reply textarea is in the top document.',
);

console.log('seller-support-reply-labels-contract-test: OK');
