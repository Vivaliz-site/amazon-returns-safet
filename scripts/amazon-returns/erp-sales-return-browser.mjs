const ORDER_RE = /^\d{3}-\d{7}-\d{7}$/;
const ISO_DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

const text = value => String(value ?? '').trim();
const normalizedText = value => text(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const numericId = value => /^\d+$/.test(text(value)) ? text(value) : '';

export function buildOpenReturnForm(origin, command = {}) {
  if (!origin || typeof origin !== 'object') throw new TypeError('ERP return origin is required.');
  const orderId = text(command.amazon_order_id);
  if (!ORDER_RE.test(orderId)) throw new TypeError('Amazon order ID is invalid.');
  const invoiceId = numericId(origin.idNotaFiscal);
  if (!invoiceId) throw new TypeError('ERP original sale invoice ID is required.');
  const originOrder = text(origin?.origem?.idPedidoEcommerce);
  if (ORDER_RE.test(originOrder) && originOrder !== orderId) throw new Error('ERP origin belongs to another Amazon order.');
  const refundDate = text(command.refund_at).slice(0, 10);
  const date = ISO_DATE_RE.test(refundDate) ? refundDate : text(origin.dataDevolucao).slice(0, 10);
  if (!ISO_DATE_RE.test(date)) throw new TypeError('ERP sales return date is required.');
  const requestedItems = Array.isArray(command.items) ? command.items : [];
  if (requestedItems.length === 0) throw new TypeError('ERP sales return requires refunded items.');
  const requestedBySku = new Map();
  for (const item of requestedItems) {
    const sku = text(item?.sku);
    const quantity = Number(item?.quantity_refunded ?? 0);
    if (!sku || !Number.isFinite(quantity) || quantity <= 0) throw new TypeError('ERP sales return refunded SKU/quantity is invalid.');
    requestedBySku.set(sku, (requestedBySku.get(sku) ?? 0) + quantity);
  }
  const originItems = Array.isArray(origin.itens) ? origin.itens : [];
  const positiveOriginItems = originItems.filter(item => Number(item?.quantidadeOrigem ?? 0) > 0);
  const items = [];
  let partial = false;
  for (const [sku, quantity] of requestedBySku) {
    const matches = originItems.filter(item => text(item?.codigo) === sku);
    const source = matches.length === 1
      ? matches[0]
      : requestedBySku.size === 1 && positiveOriginItems.length === 1
        ? positiveOriginItems[0]
        : null;
    if (!source) throw new Error(`Refunded SKU ${sku} cannot be matched exactly to the ERP sale.`);
    const available = Number(source?.quantidadeOrigem ?? 0);
    if (!Number.isFinite(available) || available <= 0 || quantity > available) {
      throw new Error(`Refunded SKU ${sku} quantity exceeds the ERP sale quantity.`);
    }
    if (quantity < available) partial = true;
    items.push({ ...source, quantidade: quantity });
  }
  if (items.length !== positiveOriginItems.length) partial = true;
  if (items.length === 0) throw new TypeError('ERP sales return requires items.');
  const form = structuredClone(origin);
  delete form.idNotaFiscalEntrada;
  delete form.gerarNotaDevolucao;
  form.id = 0;
  form.objOrigem = 2;
  form.idFormaPagamento = 0;
  form.situacao = 1;
  form.dataDevolucao = date;
  form.itens = items;
  form.ehDevolucaoParcial = partial ? 'S' : 'N';
  return form;
}

export function evaluateExistingReturn(existing, originalInvoiceId) {
  if (!existing || typeof existing !== 'object') return null;
  const id = numericId(existing.id);
  if (!id) return null;
  if (numericId(existing.idNotaFiscal) !== numericId(originalInvoiceId)) return null;
  return { status: 'ALREADY_EXISTS', external_id: id };
}

function itemQuantityMap(items) {
  if (!Array.isArray(items) || items.length === 0) return null;
  const map = new Map();
  for (const item of items) {
    const sku = text(item?.codigo ?? item?.sku);
    const quantity = Number(item?.quantidade ?? item?.quantity_refunded ?? item?.quantity ?? 0);
    if (!sku || !Number.isFinite(quantity) || quantity <= 0) return null;
    map.set(sku, (map.get(sku) ?? 0) + quantity);
  }
  return map;
}

export function verifyCreatedReturn(readBack, candidateId, originalInvoiceId, expectedItems = []) {
  if (!readBack || typeof readBack !== 'object') return false;
  if (numericId(readBack.id) !== numericId(candidateId)) return false;
  if (numericId(readBack.idNotaFiscal) !== numericId(originalInvoiceId)) return false;
  const expected = itemQuantityMap(expectedItems);
  const actual = itemQuantityMap(readBack.itens);
  if (!expected || !actual || expected.size !== actual.size) return false;
  for (const [sku, quantity] of expected) {
    if (!actual.has(sku) || Math.abs(actual.get(sku) - quantity) > 1e-9) return false;
  }
  return true;
}

const ALLOWED_XAJAX_METHODS = new Set([
  'pesquisarNotasFiscaisParaDevolucao',
  'obterDadosOrigemInclusao',
  'validar',
  'salvar',
  'obter',
]);

export function buildXajaxExpression(method, args = []) {
  if (!ALLOWED_XAJAX_METHODS.has(method)) throw new Error(`Olist XAJAX method not allowed: ${method}`);
  if (!Array.isArray(args)) throw new TypeError('Olist XAJAX arguments must be an array.');
  const encodedArgs = JSON.stringify(args);
  const xajaxName = `xajax_venda_devolucaoVenda_${method}`;
  const dottedName = `venda.devolucaoVenda.${method}`;
  const body = `const args=${encodedArgs};const nested=window.xajax?.venda?.devolucaoVenda?.${method};if(typeof nested==='function')return await nested(...args);const generated=window[${JSON.stringify(xajaxName)}];if(typeof generated==='function')return await generated(...args);const call=window.xajax?.call;if(typeof call==='function')return await call.call(window.xajax,${JSON.stringify(dottedName)},{parameters:args});throw new Error('Olist XAJAX method ${method} is unavailable.');`;
  if (method === 'validar') {
    return `(async()=>{try{${body}}catch(error){return {__olist_validation_error:{name:String(error?.name||''),message:String(error?.message||error||'').slice(0,300)}};}})()`;
  }
  return `(async()=>{${body}})()`;
}

export function buildOlistReadinessExpression() {
  return `({url:window.location?.href || location.href,ready:!!(window.xajax?.venda?.devolucaoVenda || window.xajax_venda_devolucaoVenda_salvar || window.xajax?.call)})`;
}

export function classifyOlistPageState(url, ready) {
  let parsed;
  try { parsed = new URL(String(url ?? '')); } catch { return 'UI_DRIFT'; }
  if (parsed.hostname === 'erp.olist.com' && parsed.pathname.startsWith('/devolucoes_vendas') && ready === true) return 'READY';
  if (parsed.hostname === 'accounts.tiny.com.br' || parsed.hostname === 'id.olist.com') return 'AUTH_REQUIRED';
  return 'UI_DRIFT';
}

export function createCdpRpc(session) {
  if (!session || typeof session.evaluate !== 'function') throw new TypeError('CDP page session is required.');
  return async (method, args = []) => session.evaluate(buildXajaxExpression(method, args));
}

export function createOlistXajaxClient(rpc) {
  if (typeof rpc !== 'function') throw new TypeError('Olist XAJAX RPC function is required.');
  return {
    async findExistingReturn(originalInvoiceId, originalInvoiceNumber = '') {
      const invoiceId = numericId(originalInvoiceId);
      if (!invoiceId) throw new TypeError('ERP original sale invoice ID is required.');
      const search = text(originalInvoiceNumber) || invoiceId;
      const candidates = await rpc('pesquisarNotasFiscaisParaDevolucao', [search, false]);
      if (!Array.isArray(candidates)) return null;
      const source = candidates.find(row => numericId(row?.id) === invoiceId);
      const returnId = numericId(source?.idDevolucao);
      return returnId ? { id: returnId, idNotaFiscal: invoiceId } : null;
    },
    async loadOrigin(originalInvoiceId) {
      const invoiceId = numericId(originalInvoiceId);
      if (!invoiceId) throw new TypeError('ERP original sale invoice ID is required.');
      return rpc('obterDadosOrigemInclusao', [2, Number(invoiceId)]);
    },
    async validate(form) {
      const result = await rpc('validar', [form]);
      if (result?.__olist_validation_error) {
        const message = text(result.__olist_validation_error?.message) || 'Olist sales-return validation failed.';
        throw new Error(message);
      }
      return result;
    },
    async save(id, form) { return rpc('salvar', [Number(id), form]); },
    async readBack(id) {
      const returnId = numericId(id);
      if (!returnId) throw new TypeError('ERP sales return ID is required.');
      return rpc('obter', [Number(returnId)]);
    },
  };
}

export async function validateSalesReturnCreate(client, command = {}) {
  if (!client || typeof client !== 'object') throw new TypeError('ERP browser client is required.');
  const orderId = text(command.amazon_order_id);
  if (!ORDER_RE.test(orderId)) throw new TypeError('Amazon order ID is invalid.');
  const originalInvoiceId = numericId(command.original_invoice_id);
  if (!originalInvoiceId) throw new TypeError('ERP original sale invoice ID is required.');
  const existing = evaluateExistingReturn(await client.findExistingReturn(originalInvoiceId, command.original_invoice_number), originalInvoiceId);
  if (existing) return { ...existing, submitted: false, retry_safe: true };
  try {
    const origin = await client.loadOrigin(originalInvoiceId);
    const form = buildOpenReturnForm(origin, command);
    await client.validate(form);
  } catch {
    return { status: 'NOT_READY', submitted: false, external_id: null, retry_safe: true };
  }
  return { status: 'READY', submitted: false, external_id: null, retry_safe: true };
}

export async function executeSalesReturn(client, command = {}) {
  if (!client || typeof client !== 'object') throw new TypeError('ERP browser client is required.');
  const orderId = text(command.amazon_order_id);
  if (!ORDER_RE.test(orderId)) throw new TypeError('Amazon order ID is invalid.');
  const originalInvoiceId = numericId(command.original_invoice_id);
  if (!originalInvoiceId) throw new TypeError('ERP original sale invoice ID is required.');

  const existing = evaluateExistingReturn(await client.findExistingReturn(originalInvoiceId, command.original_invoice_number), originalInvoiceId);
  if (existing) return { ...existing, submitted: false, retry_safe: true };

  const origin = await client.loadOrigin(originalInvoiceId);
  let form;
  try {
    form = buildOpenReturnForm(origin, command);
  } catch (error) {
    const message = text(error?.message);
    if (message.includes('cannot be matched exactly') || message.includes('quantity exceeds')) {
      return { status: 'ITEM_MAPPING_FAILED', submitted: false, external_id: null, retry_safe: true };
    }
    throw error;
  }
  try {
    await client.validate(form);
  } catch (error) {
    if (normalizedText(error?.message).includes('numero do endereco do cliente nao informado')) {
      return { status: 'ADDRESS_NUMBER_REQUIRED', submitted: false, external_id: null, retry_safe: true };
    }
    throw error;
  }

  const raceExisting = evaluateExistingReturn(await client.findExistingReturn(originalInvoiceId, command.original_invoice_number), originalInvoiceId);
  if (raceExisting) return { ...raceExisting, submitted: false, retry_safe: true };

  const created = await client.save(0, form);
  const candidateId = numericId(created?.id ?? created?.erp_sales_return_id);
  if (!candidateId) return { status: 'FAILED', submitted: false, external_id: null, retry_safe: false, reason: 'ERP_SALES_RETURN_ID_MISSING' };
  const readBack = await client.readBack(candidateId);
  if (!verifyCreatedReturn(readBack, candidateId, originalInvoiceId, form.itens)) {
    return { status: 'FAILED', submitted: false, external_id: null, retry_safe: false, reason: 'ERP_SALES_RETURN_READBACK_NOT_CONFIRMED' };
  }
  return { status: 'ACCEPTED', submitted: true, external_id: candidateId, retry_safe: true };
}

export async function runAdapterCommand(command, client) {
  const action = text(command?.action).toUpperCase();
  if (action === 'CREATE') return executeSalesReturn(client, command);
  if (action === 'VALIDATE_CREATE') return validateSalesReturnCreate(client, command);
  if (action === 'PREFLIGHT') {
    const originalInvoiceId = numericId(command?.original_invoice_id);
    if (!originalInvoiceId) throw new TypeError('ERP original sale invoice ID is required.');
    const existing = evaluateExistingReturn(
      await client.findExistingReturn(originalInvoiceId, command?.original_invoice_number),
      originalInvoiceId,
    );
    return existing
      ? { status: 'FOUND', submitted: false, external_id: existing.external_id, retry_safe: true }
      : { status: 'NOT_FOUND', submitted: false, external_id: null, retry_safe: true };
  }
  if (action === 'READBACK') {
    const id = numericId(command?.external_id ?? command?.erp_sales_return_id);
    if (!id) throw new TypeError('ERP sales return ID is required.');
    const record = await client.readBack(id);
    return record
      ? { status: 'FOUND', submitted: false, external_id: id, record }
      : { status: 'NOT_FOUND', submitted: false, external_id: id, record: null };
  }
  throw new Error(`Unsupported ERP browser action: ${action || 'EMPTY'}`);
}

class OlistAdapterError extends Error {
  constructor(code, message) { super(message); this.name = 'OlistAdapterError'; this.code = code; }
}

export function withTimeout(promise, ms, code='UI_DRIFT', message='Olist browser operation timed out.') {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new OlistAdapterError(code, message)), ms);
    Promise.resolve(promise).then(
      value => { clearTimeout(timer); resolve(value); },
      error => { clearTimeout(timer); reject(error); },
    );
  });
}

export function decideOlistTargetState(states) {
  if (states.includes('READY')) return 'READY';
  if (states.includes('AUTH_REQUIRED')) return 'AUTH_REQUIRED';
  return 'UI_DRIFT';
}

export const OLIST_CDP_CONNECT_TIMEOUT_MS = 2500;
export const OLIST_CDP_COMMAND_TIMEOUT_MS = 15000;

class CdpPageSession {
  constructor(webSocketUrl) { this.webSocketUrl = webSocketUrl; this.socket = null; this.nextId = 1; this.pending = new Map(); }
  async connect() {
    if (this.socket?.readyState === WebSocket.OPEN) return;
    this.socket = new WebSocket(this.webSocketUrl);
    await new Promise((resolve, reject) => {
      const onOpen = () => { cleanup(); resolve(); };
      const onError = () => { cleanup(); reject(new OlistAdapterError('BROWSER_UNAVAILABLE', 'Unable to connect to Olist browser CDP.')); };
      const cleanup = () => { this.socket.removeEventListener('open', onOpen); this.socket.removeEventListener('error', onError); };
      this.socket.addEventListener('open', onOpen);
      this.socket.addEventListener('error', onError);
    });
    this.socket.addEventListener('message', event => {
      let message;
      try { message = JSON.parse(String(event.data)); } catch { return; }
      if (!message.id || !this.pending.has(message.id)) return;
      const { resolve, reject } = this.pending.get(message.id); this.pending.delete(message.id);
      if (message.error) reject(new Error(text(message.error.message) || 'CDP command failed.')); else resolve(message.result ?? {});
    });
  }
  async send(method, params = {}) {
    await withTimeout(this.connect(), OLIST_CDP_CONNECT_TIMEOUT_MS, 'UI_DRIFT', 'Olist browser CDP target connection timed out.');
    const id = this.nextId++;
    const response = new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      this.socket.send(JSON.stringify({ id, method, params }));
    });
    try {
      return await withTimeout(response, OLIST_CDP_COMMAND_TIMEOUT_MS, 'UI_DRIFT', 'Olist browser CDP target did not respond.');
    } finally {
      this.pending.delete(id);
    }
  }
  async evaluate(expression) {
    const result = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) {
      const description = text(result.exceptionDetails?.exception?.description || result.exceptionDetails?.text || 'Olist page command failed.');
      throw new Error(description.slice(0, 500));
    }
    const remote = result.result ?? {};
    if (remote.subtype === 'error') throw new Error(text(remote.description || 'Olist page command failed.').slice(0, 500));
    return remote.value;
  }
  close() { try { this.socket?.close(); } catch {} }
}

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const OLIST_URL = 'https://erp.olist.com/devolucoes_vendas#list';

export async function fetchCdpJson(url, options = {}, fetchFn = fetch) {
  let response;
  try {
    response = await fetchFn(url, options);
  } catch {
    throw new OlistAdapterError('BROWSER_UNAVAILABLE', 'Olist browser CDP is unavailable.');
  }
  if (!response.ok) throw new OlistAdapterError('BROWSER_UNAVAILABLE', `Olist browser CDP returned HTTP ${response.status}.`);
  try {
    return await response.json();
  } catch {
    throw new OlistAdapterError('BROWSER_UNAVAILABLE', 'Olist browser CDP returned an invalid response.');
  }
}

async function getOlistTargets(cdpBase) {
  const base = text(cdpBase).replace(/\/$/, '');
  if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new OlistAdapterError('BROWSER_CONFIG_INVALID', 'Olist browser CDP must be loopback-only.');
  let targets = await fetchCdpJson(`${base}/json`);
  let candidates = targets.filter(row => row?.type === 'page' && (
    /^https:\/\/erp\.olist\.com\/devolucoes_vendas/.test(text(row.url))
    || /^(https:\/\/accounts\.tiny\.com\.br\/|https:\/\/id\.olist\.com\/)/.test(text(row.url))
  ) && row?.webSocketDebuggerUrl);
  if (candidates.length === 0) {
    const target = await fetchCdpJson(`${base}/json/new?${encodeURIComponent(OLIST_URL)}`, { method: 'PUT' });
    await sleep(500);
    if (target?.webSocketDebuggerUrl) candidates = [target];
  }
  if (candidates.length === 0) throw new OlistAdapterError('BROWSER_UNAVAILABLE', 'Olist browser page target is unavailable.');
  return candidates;
}

async function createLiveOlistClient(cdpBase) {
  const deadline = Date.now() + 15000;
  let lastState = 'UI_DRIFT';
  while (Date.now() < deadline) {
    const targets = await getOlistTargets(cdpBase);
    const states = [];
    for (const target of targets) {
      const session = new CdpPageSession(target.webSocketDebuggerUrl);
      const state = await session.evaluate(buildOlistReadinessExpression()).catch(() => null);
      if (state?.url) {
        const classified = classifyOlistPageState(state.url, state.ready === true);
        states.push(classified);
        if (classified === 'READY') return { client: createOlistXajaxClient(createCdpRpc(session)), session };
      }
      session.close();
    }
    lastState = decideOlistTargetState(states);
    if (lastState === 'AUTH_REQUIRED') throw new OlistAdapterError('AUTH_REQUIRED', 'Olist browser authentication is required.');
    await sleep(400);
  }
  throw new OlistAdapterError(lastState, 'Olist sales-return page is not ready.');
}

async function readStdinJson() {
  let raw = '';
  for await (const chunk of process.stdin) raw += chunk;
  const parsed = JSON.parse(raw || '{}');
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new TypeError('ERP browser command must be a JSON object.');
  return parsed;
}

async function main() {
  let live;
  try {
    const command = await readStdinJson();
    live = await createLiveOlistClient(process.env.OLIST_ERP_CDP_URL || 'http://127.0.0.1:9226');
    const result = await runAdapterCommand(command, live.client);
    process.stdout.write(JSON.stringify(result));
  } catch (error) {
    const code = error instanceof OlistAdapterError ? error.code : 'FAILED';
    process.stdout.write(JSON.stringify({ status: code, submitted: false, external_id: null, retry_safe: code !== 'FAILED' }));
  } finally {
    live?.session?.close();
  }
}

if (process.argv[1] && /erp-sales-return-browser\.mjs$/.test(process.argv[1])) await main();
