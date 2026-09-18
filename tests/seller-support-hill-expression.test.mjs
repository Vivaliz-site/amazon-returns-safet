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

test('Chat write reads popup case ID before secondary support search', () => {
  const fnStart = source.indexOf('async function contactSupportAndReadBack(cdp, job)');
  assert.notEqual(fnStart, -1, 'contactSupportAndReadBack must exist');
  const fnEnd = source.indexOf('\nasync function fillGeneralSupportIssue', fnStart);
  assert.notEqual(fnEnd, -1, 'contactSupportAndReadBack must terminate');
  const fnSource = source.slice(fnStart, fnEnd);
  const popupReadback = fnSource.indexOf('hillPopupSupportCaseId()');
  const secondarySearch = fnSource.indexOf('findSupportCase');
  assert.notEqual(popupReadback, -1, 'popup readback must be used');
  assert.notEqual(secondarySearch, -1, 'secondary search fallback must remain');
  assert.ok(popupReadback < secondarySearch, 'popup case ID must be trusted before delayed search');
});

test('Chat popup readback excludes a popup that already existed before the write', () => {
  const fnStart = source.indexOf('async function contactSupportAndReadBack(cdp, job)');
  assert.notEqual(fnStart, -1);
  const fnEnd = source.indexOf('\nasync function fillGeneralSupportIssue', fnStart);
  const fnSource = source.slice(fnStart, fnEnd);
  const before = fnSource.indexOf('const popupCaseIdBeforeWrite = await hillPopupSupportCaseId()');
  const write = fnSource.indexOf('submitHillEmail');
  const after = fnSource.indexOf('hillPopupSupportCaseId(popupCaseIdBeforeWrite ? [popupCaseIdBeforeWrite] : [])');
  assert.ok(before >= 0 && write > before && after > write, 'pre-existing popup must be captured before write and excluded after write');
});
