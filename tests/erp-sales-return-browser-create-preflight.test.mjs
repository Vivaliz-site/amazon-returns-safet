import test from 'node:test';
import assert from 'node:assert/strict';
import { runAdapterCommand } from '../scripts/amazon-returns/erp-sales-return-browser.mjs';
const origin = { id: 0, objOrigem: 2, idNotaFiscal: '202', dataDevolucao: '2026-09-13', itens: [{ id: 0, idProduto: '707', codigo: 'SKU-1', quantidadeOrigem: '2.0000', valorUnitario: '10.00', unidade: 'UN' }], origem: { idPedidoEcommerce: '702-1234567-1234567' } };
const command = { action: 'VALIDATE_CREATE', amazon_order_id: '702-1234567-1234567', original_invoice_id: '202', original_invoice_number: '303', refund_at: '2026-09-12', items: [{ sku: 'SKU-1', quantity_refunded: 1 }] };
test('validates a sales-return candidate without ever saving it', async () => {
  const calls = [];
  const client = { async findExistingReturn() { calls.push('find'); return null; }, async loadOrigin() { calls.push('origin'); return origin; }, async validate() { calls.push('validate'); return { ok: true }; }, async save() { calls.push('save'); throw new Error('must not save during preflight'); }, async readBack() { calls.push('readback'); throw new Error('must not read back during preflight'); } };
  assert.deepEqual(await runAdapterCommand(command, client), { status: 'READY', submitted: false, external_id: null, retry_safe: true });
  assert.deepEqual(calls, ['find', 'origin', 'validate']);
});
test('turns ERP form or validation rejection into a retry-safe not-ready result', async () => {
  const client = { async findExistingReturn() { return null; }, async loadOrigin() { return origin; }, async validate() { throw new Error('address missing'); }, async save() { throw new Error('must not save during preflight'); } };
  const result = await runAdapterCommand(command, client);
  assert.equal(result.status, 'NOT_READY'); assert.equal(result.submitted, false); assert.equal(result.retry_safe, true);
});
