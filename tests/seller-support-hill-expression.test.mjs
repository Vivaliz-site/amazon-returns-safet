import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../scripts/amazon-returns/seller-central-bridge-worker.mjs', import.meta.url), 'utf8');

test('clickHillChat embedded CDP expression parses', () => {
  const fnStart = source.indexOf('async function clickHillChat(cdp)');
  assert.notEqual(fnStart, -1, 'clickHillChat must exist');
  const tail = source.slice(fnStart);
  const marker = 'const point = await cdp.evaluate(`';
  const exprStart = tail.indexOf(marker);
  assert.notEqual(exprStart, -1, 'clickHillChat must evaluate an embedded expression');
  const bodyStart = exprStart + marker.length;
  const bodyEnd = tail.indexOf('`);', bodyStart);
  assert.notEqual(bodyEnd, -1, 'embedded expression must terminate');
  const expression = tail.slice(bodyStart, bodyEnd);
  assert.doesNotThrow(() => new Function('return (' + expression + ');'));
});
test('Hill chat popup URL yields authoritative case ID', () => {
  const fnStart = source.indexOf('function supportCaseIdFromHillTargetUrl(value)');
  assert.notEqual(fnStart, -1, 'popup case-id parser must exist');
  const fnEndMarker = '\n}\n\nasync function hillPopupSupportCaseId';
  const fnEnd = source.indexOf(fnEndMarker, fnStart);
  assert.notEqual(fnEnd, -1, 'popup case-id parser must terminate');
  const fnSource = source.slice(fnStart, fnEnd + 2);
  const parse = new Function(fnSource + '; return supportCaseIdFromHillTargetUrl;')();
  assert.equal(
    parse('https://sellercentral.amazon.com.br/hill/website/chat?foo=1&caseID=22144687151'),
    '22144687151'
  );
  assert.equal(parse('https://sellercentral.amazon.com.br/hill/website/chat?caseID=bad'), '');
  assert.equal(parse('https://sellercentral.amazon.com.br/help/center?caseID=22144687151'), '');
});

test('Hill popup baseline captures all existing case IDs and excludes them after write', () => {
  assert.ok(source.includes('async function hillPopupSupportCaseIds()'));
  assert.ok(source.includes('const ids = await hillPopupSupportCaseIds()'));
  assert.ok(source.includes('if (!excluded.has(caseId)) return caseId'));
});

test('Chat readback baselines every popup and verifies job identity before accepting a case ID', () => {
  const fnStart = source.indexOf('async function contactSupportAndReadBack(cdp, job)');
  assert.notEqual(fnStart, -1);
  const fnEnd = source.indexOf('\nasync function fillGeneralSupportIssue', fnStart);
  assert.notEqual(fnEnd, -1);
  const fnSource = source.slice(fnStart, fnEnd);
  const before = fnSource.indexOf('const popupCaseIdsBeforeWrite = await hillPopupSupportCaseIds()');
  const write = fnSource.indexOf('submitHillEmail');
  const popup = fnSource.indexOf('hillPopupSupportCaseId(excludedPopupCaseIds)');
  const identity = fnSource.indexOf('supportCaseMatchesJob(cdp, job, popupCaseId)');
  const fallback = fnSource.indexOf('findSupportCase');
  assert.ok(before >= 0 && write > before, 'all pre-write popup case IDs must be captured before write');
  assert.ok(popup > write, 'popup readback must happen after write');
  assert.ok(identity > popup, 'popup case ID must match current job identity');
  assert.ok(fallback > identity, 'deterministic history lookup remains the final fallback');
});

test('Support case identity verifier reads ViewCase and checks order and SAFE-T identity', () => {
  const start = source.indexOf('async function supportCaseMatchesJob(cdp, job, caseId)');
  const end = source.indexOf('\nasync function contactSupportAndReadBack', start);
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  const fnSource = source.slice(start, end);
  for (const needle of ['ViewCase?caseId=', 'job.case?.order_id', 'job.case?.safe_t_id', 'needles.some']) {
    assert.ok(fnSource.includes(needle), 'missing identity guard: ' + needle);
  }
});

test('Direct case creation rejects a readback ID that does not match the current job', () => {
  const start = source.indexOf('async function submitDirectSupportCaseAndReadBack(cdp, job, narrative)');
  const end = source.indexOf('\nasync function openGeneralSupportRoute', start);
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  const fnSource = source.slice(start, end);
  const current = fnSource.indexOf('currentSupportCaseId(cdp)');
  const identity = fnSource.indexOf('supportCaseMatchesJob(cdp, job, caseId)');
  const fallback = fnSource.indexOf('findSupportCase');
  assert.ok(current >= 0 && identity > current && fallback > identity);
});
