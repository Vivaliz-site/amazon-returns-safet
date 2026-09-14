import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { fileURLToPath } from 'node:url';
import fs from 'node:fs';

const helper = fileURLToPath(new URL('../scripts/amazon-returns/prune-seller-central-cdp-targets.mjs', import.meta.url));
const runner = fileURLToPath(new URL('../scripts/amazon-returns/run-seller-central-daily.sh', import.meta.url));

function target(id, type, url) {
  return { id, type, url };
}

test('startup pruning keeps one blank page and closes restored Seller Central targets', async () => {
  let targets = [
    target('blank-1', 'page', 'about:blank'),
    target('help-1', 'page', 'https://sellercentral.amazon.com.br/help/center'),
    target('chat-1', 'page', 'https://sellercentral.amazon.com.br/hill/website/chat?formType=submit'),
    target('safet-1', 'page', 'https://sellercentral.amazon.com.br/safet-claims'),
    target('omnibox-1', 'browser_ui', 'chrome://omnibox-popup.top-chrome/'),
  ];
  const closed = [];
  const server = http.createServer((req, res) => {
    if (req.url === '/json/list') {
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify(targets));
      return;
    }
    if (req.url?.startsWith('/json/close/')) {
      const id = decodeURIComponent(req.url.slice('/json/close/'.length));
      closed.push(id);
      targets = targets.filter(item => item.id !== id);
      res.writeHead(200, { 'content-type': 'text/plain' });
      res.end('Target is closing');
      return;
    }
    res.writeHead(404).end();
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const child = spawn(process.execPath, [helper], {
    env: { ...process.env, SELLER_CENTRAL_CDP_URL: `http://127.0.0.1:${server.address().port}` },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let output = '';
  child.stdout.on('data', chunk => { output += chunk; });
  child.stderr.on('data', chunk => { output += chunk; });
  const [code] = await once(child, 'close');
  await new Promise(resolve => server.close(resolve));
  assert.equal(code, 0, output);
  assert.deepEqual(new Set(closed), new Set(['help-1', 'chat-1', 'safet-1', 'omnibox-1']));
  assert.deepEqual(targets, [target('blank-1', 'page', 'about:blank')]);
  assert.match(output, /seller_central_stale_targets_closed=4/);
});

test('daily runner prunes restored targets before auth and draining work', () => {
  const source = fs.readFileSync(runner, 'utf8');
  const prune = source.indexOf('prune-seller-central-cdp-targets.mjs');
  const auth = source.indexOf('seller-central-safe-t-read-worker.mjs" --auth-check');
  const writeDrain = source.indexOf('seller-central-bridge-worker.mjs" "$bridge_mode"');
  assert.notEqual(prune, -1, 'runner must invoke startup target pruning');
  assert.ok(prune < auth, 'startup pruning must run before authentication');
  assert.ok(prune < writeDrain, 'startup pruning must run before bridge writes');
});
