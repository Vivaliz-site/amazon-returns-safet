import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(new URL('../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs', import.meta.url), 'utf8');
const start = source.indexOf('const advancePage = async previousSignature => {');
const end = source.indexOf('if (clicked !== true) return false;', start);
assert.ok(start >= 0 && end > start, 'bounded pagination browser action must exist');
const fragment = source.slice(start, end);
const tick = String.fromCharCode(96);
const begin = fragment.indexOf('cdp.evaluate(' + tick);
assert.ok(begin >= 0, 'read-only pagination expression must be present');
const scriptStart = begin + ('cdp.evaluate(' + tick).length;
const scriptEnd = fragment.indexOf(tick, scriptStart);
const expression = fragment.slice(scriptStart, scriptEnd);

function control(label, disabled = false) {
  let clicks = 0;
  return {
    tagName: 'BUTTON',
    disabled,
    hasAttribute: name => name === 'disabled' && disabled,
    getAttribute: name => name === 'aria-label' ? label : null,
    innerText: '',
    click: () => { clicks++; },
    get clicks() { return clicks; },
  };
}

function run(controls) {
  const container = { querySelectorAll: selector => selector === '*' ? [] : controls };
  const document = { querySelectorAll: selector => selector.includes('kat-pagination') ? [container] : [] };
  return new Function('document', 'return ' + expression)(document);
}

test('only an enabled pagination Next control can be clicked', () => {
  const enabled = control('Next page');
  assert.equal(run([enabled]), true);
  assert.equal(enabled.clicks, 1);
  const disabled = control('Next page', true);
  assert.equal(run([disabled]), false);
  assert.equal(disabled.clicks, 0);
  assert.equal(run([]), false);
});

test('unknown or ambiguous navigation never triggers browser action', () => {
  const submit = control('Submit');
  assert.equal(run([submit]), false);
  assert.equal(submit.clicks, 0);
  const first = control('Next page');
  const second = control('Next page');
  assert.equal(run([first, second]), false);
  assert.equal(first.clicks, 0);
  assert.equal(second.clicks, 0);
});
