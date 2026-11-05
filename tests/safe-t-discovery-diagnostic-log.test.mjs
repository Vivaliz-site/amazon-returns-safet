import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const file = new URL('../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs', import.meta.url);
function capture(event, data) {
  const source = fs.readFileSync(file, 'utf8');
  const begin = source.indexOf('function safeReadLogReason(');
  const logAt = source.indexOf('function log(event, data = {}) {');
  const end = source.indexOf('\nasync function runOnce()', logAt);
  assert.ok(logAt >= 0 && end > logAt, 'worker must retain the read-only result logger');
  const lines = [];
  const context = { process: { stdout: { write: line => lines.push(JSON.parse(line)) } }, Date };
  vm.runInNewContext(source.slice(begin >= 0 && begin < logAt ? begin : logAt, end) + '\nlog', context)(event, data);
  assert.equal(lines.length, 1);
  return lines[0];
}

test('UI drift logs only a bounded machine-readable reason and page count', () => {
  const record = capture('job_result', {
    job_id: 3704227, action: 'SAFE_T_DISCOVERY', status: 'UI_DRIFT',
    reason: 'SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED', pages_scanned: 2,
    case: { order_id: '999-0000000-0000000' },
    evidence: { body: 'DO_NOT_LOG_PRIVATE_TEXT' }, token: 'DO_NOT_LOG_SECRET',
  });
  assert.equal(record.reason, 'SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED');
  assert.equal(record.pages_scanned, 2);
  assert.equal(record.status, 'UI_DRIFT');
  assert.ok(!JSON.stringify(record).includes('DO_NOT_LOG'));
  assert.ok(!JSON.stringify(record).includes('999-0000000'));
});

test('malformed reason and page count never reach logs', () => {
  const record = capture('job_result', {
    action: 'SAFE_T_DISCOVERY', status: 'UI_DRIFT',
    reason: 'TOKEN=private\nOR customer@example.com', pages_scanned: 500,
  });
  assert.equal(record.reason, null);
  assert.equal(record.pages_scanned, null);
});

test('inbound job_received does not log untrusted reason', () => {
  const record = capture('job_received', { action: 'SAFE_T_DISCOVERY', reason: 'SAFE_T_FAKE', pages_scanned: 4 });
  assert.equal(record.reason, null);
  assert.equal(record.pages_scanned, null);
});
