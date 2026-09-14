#!/usr/bin/env node

const CDP_BASE = process.env.SELLER_CENTRAL_CDP_URL || 'http://127.0.0.1:9225';
const timeout = ms => AbortSignal.timeout(ms);

async function listTargets() {
  const response = await fetch(`${CDP_BASE}/json/list`, { signal: timeout(5000) });
  if (!response.ok) throw new Error(`CDP target list failed (${response.status})`);
  const targets = await response.json();
  if (!Array.isArray(targets)) throw new Error('CDP target list contract invalid');
  return targets;
}

async function createBlankTarget() {
  const response = await fetch(`${CDP_BASE}/json/new?${encodeURIComponent('about:blank')}`, {
    method: 'PUT',
    signal: timeout(5000),
  });
  if (!response.ok) throw new Error(`CDP blank target creation failed (${response.status})`);
  const target = await response.json();
  if (!target?.id) throw new Error('CDP blank target missing id');
  return target;
}

async function closeTarget(id) {
  const targetId = String(id || '').trim();
  if (!targetId) return false;
  try {
    const response = await fetch(`${CDP_BASE}/json/close/${encodeURIComponent(targetId)}`, {
      signal: timeout(2500),
    });
    return response.ok;
  } catch {
    return false;
  }
}

const original = await listTargets();
let keeper = original.find(target => target?.type === 'page' && target?.url === 'about:blank');
if (!keeper) keeper = await createBlankTarget();

let closed = 0;
for (const target of original) {
  if (!target?.id || target.id === keeper.id) continue;
  if (!['page', 'browser_ui'].includes(String(target.type || ''))) continue;
  if (await closeTarget(target.id)) closed++;
}

const remaining = await listTargets();
const interactive = remaining.filter(target => ['page', 'browser_ui'].includes(String(target?.type || '')));
if (!interactive.some(target => target?.id === keeper.id)) {
  throw new Error('CDP blank keeper target disappeared during pruning');
}

process.stdout.write(`seller_central_stale_targets_closed=${closed}\n`);
process.stdout.write(`seller_central_targets_remaining=${interactive.length}\n`);
