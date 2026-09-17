import test from 'node:test';
import assert from 'node:assert/strict';
import { buildOpenReturnForm, buildOlistReadinessExpression, buildXajaxExpression, classifyOlistPageState, createCdpRpc, createOlistXajaxClient, evaluateExistingReturn, decideOlistTargetState, fetchCdpJson, runAdapterCommand, withTimeout, verifyCreatedReturn } from '../scripts/amazon-returns/erp-sales-return-browser.mjs';

const origin = {
  id: 0,
  objOrigem: 2,
  idVenda: '101',
  idNotaFiscal: '202',
  numeroOrigem: '303',
  dataDevolucao: '2026-09-13',
  idDeposito: '404',
  idContato: '505',
  idUsuario: '606',
  valorFrete: '0.00',
  valorSeguro: '0.00',
  outrasDespesas: '0.00',
  valorDesconto: '0.00',
  valorImpostos: '0.00',
  itens: [{ id: 0, idProduto: '707', codigo: 'SKU-1', quantidadeOrigem: '2.0000', valorUnitario: '10.00', unidade: 'UN' }],
  origem: { idPedidoEcommerce: '702-1234567-1234567', totalVenda: '20.00' },
};

test('builds an open no-payment sales return without return-invoice fields', () => {
  const form = buildOpenReturnForm(origin, { amazon_order_id: '702-1234567-1234567', refund_at: '2026-09-12', items: [{ sku: 'SKU-1', quantity_refunded: 2 }] });
  assert.equal(form.id, 0);
  assert.equal(form.objOrigem, 2);
  assert.equal(form.idFormaPagamento, 0);
  assert.equal(form.situacao, 1);
  assert.equal(form.dataDevolucao, '2026-09-12');
  assert.equal(form.itens[0].quantidade, 2);
  assert.equal(form.idNotaFiscalEntrada, undefined);
  assert.equal(form.gerarNotaDevolucao, undefined);
});


test('uses only refunded quantity and marks a partial return instead of returning the full sale quantity', () => {
  const form = buildOpenReturnForm(origin, {
    amazon_order_id: '702-1234567-1234567',
    refund_at: '2026-09-12',
    items: [{ sku: 'SKU-1', quantity_refunded: 1 }],
  });
  assert.equal(form.itens.length, 1);
  assert.equal(form.itens[0].quantidade, 1);
  assert.equal(form.ehDevolucaoParcial, 'S');
});

test('blocks creation when a refunded SKU cannot be matched exactly to the ERP sale', () => {
  assert.throws(() => buildOpenReturnForm(origin, {
    amazon_order_id: '702-1234567-1234567',
    refund_at: '2026-09-12',
    items: [{ sku: 'SKU-X', quantity_refunded: 1 }],
  }), /refunded SKU/i);
});

test('treats an existing ERP return as authoritative and never requests another create', () => {
  assert.deepEqual(evaluateExistingReturn({ id: '16764', idNotaFiscal: '202' }, '202'), { status: 'ALREADY_EXISTS', external_id: '16764' });
});

test('requires read-back to prove created id, original invoice, SKU and refunded quantity', () => {
  const expected = [{ codigo: 'SKU-1', quantidade: 2 }];
  assert.equal(verifyCreatedReturn({ id: '991', idNotaFiscal: '202', itens: [{ codigo: 'SKU-1', quantidade: '2.0000' }] }, '991', '202', expected), true);
  assert.equal(verifyCreatedReturn({ id: '992', idNotaFiscal: '202', itens: [{ codigo: 'SKU-1', quantidade: 2 }] }, '991', '202', expected), false);
  assert.equal(verifyCreatedReturn({ id: '991', idNotaFiscal: '999', itens: [{ codigo: 'SKU-1', quantidade: 2 }] }, '991', '202', expected), false);
  assert.equal(verifyCreatedReturn({ id: '991', idNotaFiscal: '202', itens: [{ codigo: 'SKU-X', quantidade: 2 }] }, '991', '202', expected), false);
  assert.equal(verifyCreatedReturn({ id: '991', idNotaFiscal: '202', itens: [{ codigo: 'SKU-1', quantidade: 1 }] }, '991', '202', expected), false);
  assert.equal(verifyCreatedReturn({ id: '991', idNotaFiscal: '202' }, '991', '202', expected), false);
});

test('preflights the ERP return registry twice and never saves when a return already exists without an invoice', async () => {
  const calls = [];
  const client = {
    async findExistingReturn(invoiceId) { calls.push(['find', invoiceId]); return { id: '880', idNotaFiscal: '202', idNotaFiscalEntrada: null }; },
    async loadOrigin() { calls.push(['origin']); throw new Error('origin must not be loaded after existing return'); },
    async validate() { calls.push(['validate']); },
    async save() { calls.push(['save']); },
    async readBack() { calls.push(['readback']); },
  };
  const { executeSalesReturn } = await import('../scripts/amazon-returns/erp-sales-return-browser.mjs');
  const result = await executeSalesReturn(client, { amazon_order_id: '702-1234567-1234567', original_invoice_id: '202', refund_at: '2026-09-12', items: [{ sku: 'SKU-1', quantity_refunded: 2 }] });
  assert.deepEqual(result, { status: 'ALREADY_EXISTS', submitted: false, external_id: '880', retry_safe: true });
  assert.deepEqual(calls, [['find', '202']]);
});

test('validates, rechecks, saves once and requires read-back before accepting a new return', async () => {
  const calls = [];
  let findCount = 0;
  const client = {
    async findExistingReturn(invoiceId) { calls.push(['find', invoiceId]); findCount += 1; return null; },
    async loadOrigin(invoiceId) { calls.push(['origin', invoiceId]); return origin; },
    async validate(form) { calls.push(['validate', form.idFormaPagamento, form.situacao]); return { ok: true }; },
    async save(id, form) { calls.push(['save', id, form.idFormaPagamento, form.situacao]); return { id: '991' }; },
    async readBack(id) { calls.push(['readback', id]); return { id: '991', idNotaFiscal: '202', idNotaFiscalEntrada: null, itens: [{ codigo: 'SKU-1', quantidade: 2 }] }; },
  };
  const { executeSalesReturn } = await import('../scripts/amazon-returns/erp-sales-return-browser.mjs');
  const result = await executeSalesReturn(client, { amazon_order_id: '702-1234567-1234567', original_invoice_id: '202', refund_at: '2026-09-12', items: [{ sku: 'SKU-1', quantity_refunded: 2 }] });
  assert.equal(findCount, 2);
  assert.deepEqual(result, { status: 'ACCEPTED', submitted: true, external_id: '991', retry_safe: true });
  assert.deepEqual(calls.map(x => x[0]), ['find', 'origin', 'validate', 'find', 'save', 'readback']);
});


test('maps only the verified Olist sales-return XAJAX operations and never invokes return-NF generation', async () => {
  const calls = [];
  const rpc = async (method, args) => {
    calls.push([method, args]);
    if (method === 'pesquisarNotasFiscaisParaDevolucao') return [{ id: 202, numero: '303', idDevolucao: 880 }];
    if (method === 'obterDadosOrigemInclusao') return origin;
    if (method === 'validar') return { ok: true };
    if (method === 'salvar') return { id: 991 };
    if (method === 'obter') return { id: 991, idNotaFiscal: 202, itens: [{ codigo: 'SKU-1', quantidade: 2 }] };
    throw new Error('unexpected method ' + method);
  };
  const client = createOlistXajaxClient(rpc);
  assert.deepEqual(await client.findExistingReturn('202', '303'), { id: '880', idNotaFiscal: '202' });
  assert.equal((await client.loadOrigin('202')).idNotaFiscal, '202');
  await client.validate({ id: 0 });
  assert.deepEqual(await client.save(0, { id: 0 }), { id: 991 });
  assert.equal((await client.readBack('991')).id, 991);
  assert.deepEqual(calls.map(([name]) => name), ['pesquisarNotasFiscaisParaDevolucao', 'obterDadosOrigemInclusao', 'validar', 'salvar', 'obter']);
  assert.equal(calls.some(([name]) => name === 'gerarNotaDevolucao'), false);
});


test('CDP expression whitelist permits only observed sales-return methods and rejects NF generation', () => {
  const expr = buildXajaxExpression('salvar', [0, { idFormaPagamento: 0 }]);
  assert.match(expr, /devolucaoVenda/);
  assert.match(expr, /idFormaPagamento/);
  assert.throws(() => buildXajaxExpression('gerarNotaDevolucao', [991]), /not allowed/i);
  assert.throws(() => buildXajaxExpression('excluir', [991]), /not allowed/i);
});


test('CDP expression supports the generated global XAJAX wrappers used by the Olist page', async () => {
  const expr = buildXajaxExpression('obter', [991]);
  const calls = [];
  const window = {
    xajax_venda_devolucaoVenda_obter(...args) {
      calls.push(args);
      return { id: args[0], idNotaFiscal: 202 };
    },
  };
  const result = await Function('window', 'return ' + expr)(window);
  assert.deepEqual(result, { id: 991, idNotaFiscal: 202 });
  assert.deepEqual(calls, [[991]]);
});

test('readiness accepts generated Olist XAJAX wrappers without requiring a nested xajax object', async () => {
  const window = { location: { href: 'https://erp.olist.com/devolucoes_vendas#list' }, xajax_venda_devolucaoVenda_salvar() {} };
  const result = await Function('window', 'return ' + buildOlistReadinessExpression())(window);
  assert.equal(result.ready, true);
});

test('CLI command contract creates through the guarded workflow and exposes read-back separately', async () => {
  let finds = 0;
  const client = {
    async findExistingReturn() { finds += 1; return null; },
    async loadOrigin() { return origin; },
    async validate() { return { ok: true }; },
    async save() { return { id: 991 }; },
    async readBack(id) { return { id: Number(id), idNotaFiscal: 202, itens: [{ codigo: 'SKU-1', quantidade: 1 }] }; },
  };
  const create = await runAdapterCommand({
    action: 'CREATE', amazon_order_id: '702-1234567-1234567', original_invoice_id: '202',
    original_invoice_number: '303', refund_at: '2026-09-12', items: [{ sku: 'SKU-1', quantity_refunded: 1 }],
  }, client);
  assert.equal(create.status, 'ACCEPTED');
  assert.equal(create.external_id, '991');
  assert.equal(finds, 2);
  const read = await runAdapterCommand({ action: 'READBACK', external_id: '991' }, client);
  assert.equal(read.status, 'FOUND');
  assert.equal(read.record.id, 991);
  await assert.rejects(() => runAdapterCommand({ action: 'GENERATE_RETURN_INVOICE', external_id: '991' }, client), /unsupported/i);
});


test('CDP RPC executes the whitelisted XAJAX expression through the attached page session', async () => {
  const expressions = [];
  const session = { async evaluate(expression) { expressions.push(expression); return { id: 991 }; } };
  const rpc = createCdpRpc(session);
  assert.deepEqual(await rpc('obter', [991]), { id: 991 });
  assert.match(expressions[0], /devolucaoVenda/);
  await assert.rejects(() => rpc('gerarNotaDevolucao', [991]), /not allowed/i);
});


test('classifies Olist browser readiness without treating the login page as ERP-ready', () => {
  assert.equal(classifyOlistPageState('https://erp.olist.com/devolucoes_vendas#list', true), 'READY');
  assert.equal(classifyOlistPageState('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth', false), 'AUTH_REQUIRED');
  assert.equal(classifyOlistPageState('https://erp.olist.com/devolucoes_vendas#list', false), 'UI_DRIFT');
});


test('CDP network failures are classified as browser unavailable instead of generic write failure', async () => {
  const failingFetch = async () => { throw new TypeError('fetch failed'); };
  await assert.rejects(
    () => fetchCdpJson('http://127.0.0.1:9226/json', {}, failingFetch),
    error => error?.code === 'BROWSER_UNAVAILABLE' && /CDP/i.test(error.message)
  );
});


test('a real ready ERP target wins over stale/auth targets, while auth wins when no ERP target is ready', () => {
  assert.equal(decideOlistTargetState(['UI_DRIFT', 'AUTH_REQUIRED', 'READY']), 'READY');
  assert.equal(decideOlistTargetState(['UI_DRIFT', 'AUTH_REQUIRED']), 'AUTH_REQUIRED');
  assert.equal(decideOlistTargetState(['UI_DRIFT']), 'UI_DRIFT');
});

test('CDP target calls have a bounded timeout so stale browser targets cannot hang the worker', async () => {
  await assert.rejects(
    () => withTimeout(new Promise(() => {}), 10, 'UI_DRIFT', 'stale target'),
    error => error?.code === 'UI_DRIFT' && /stale target/i.test(error.message)
  );
});


test('CDP XAJAX commands have a longer bounded timeout than target connection setup', async () => {
  const adapter = await import('../scripts/amazon-returns/erp-sales-return-browser.mjs');
  assert.equal(adapter.OLIST_CDP_CONNECT_TIMEOUT_MS, 2500);
  assert.ok(adapter.OLIST_CDP_COMMAND_TIMEOUT_MS >= 10000, 'XAJAX timeout must cover observed multi-second ERP calls');
  assert.ok(adapter.OLIST_CDP_COMMAND_TIMEOUT_MS <= 30000, 'XAJAX timeout must remain bounded');
  assert.ok(adapter.OLIST_CDP_COMMAND_TIMEOUT_MS > adapter.OLIST_CDP_CONNECT_TIMEOUT_MS);
});
