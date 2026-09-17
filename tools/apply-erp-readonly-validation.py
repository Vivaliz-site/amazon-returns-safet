from pathlib import Path

def replace_once(path, old, new):
    p = Path(path)
    text = p.read_text()
    if text.count(old) != 1:
        raise SystemExit(f'{path}: expected exactly one marker, got {text.count(old)}')
    p.write_text(text.replace(old, new, 1))

browser = 'scripts/amazon-returns/erp-sales-return-browser.mjs'
validate_fn = '''export async function validateSalesReturnCreate(client, command = {}) {
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

'''
replace_once(browser, 'export async function executeSalesReturn(client, command = {}) {', validate_fn + 'export async function executeSalesReturn(client, command = {}) {')
replace_once(browser, "  if (action === 'CREATE') return executeSalesReturn(client, command);", "  if (action === 'CREATE') return executeSalesReturn(client, command);\n  if (action === 'VALIDATE_CREATE') return validateSalesReturnCreate(client, command);")

gateway = 'includes/amazon-returns/ErpSalesReturnGateway.php'
preflight = '''    public function preflightCreate(array $command): bool
    {
        $sale=is_array($command['original_sale']??null)?$command['original_sale']:[];
        $orderId=trim((string)($command['amazon_order_id']??''));
        $invoiceId=trim((string)($sale['invoice_id']??''));
        $refundAt=substr(trim((string)($command['refund_at']??'')),0,10);
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$orderId)!==1 || preg_match('/^[0-9]+$/D',$invoiceId)!==1 || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$refundAt)!==1)return false;
        $result=$this->run([
            'action'=>'VALIDATE_CREATE','amazon_order_id'=>$orderId,
            'original_invoice_id'=>$invoiceId,
            'original_invoice_number'=>trim((string)($sale['invoice_number']??'')),
            'refund_at'=>$refundAt,
            'items'=>is_array($command['items']??null)?$command['items']:[],
        ]);
        $status=strtoupper(trim((string)($result['status']??'')));
        if($status==='READY')return true;
        if(in_array($status,['NOT_READY','ALREADY_EXISTS'],true))return false;
        throw new RuntimeException('ERP sales return create preflight was not confirmed: '.($status!==''?$status:'UNKNOWN').'.');
    }

'''
replace_once(gateway, "    public function probeExisting(string $originalInvoiceId,string $originalInvoiceNumber=''): ?string", preflight + "    public function probeExisting(string $originalInvoiceId,string $originalInvoiceNumber=''): ?string")

canary = 'scripts/amazon-returns/erp-sales-return-canary.php'
canary_block = '''        $writeReady=$browserGateway->preflightCreate([
            'amazon_order_id'=>(string)$checked['order_id'],
            'original_sale'=>[
                'invoice_id'=>(string)$checked['original_invoice_id'],
                'invoice_number'=>(string)$checked['original_invoice_number'],
            ],
            'refund_at'=>(string)$checked['refund_at'],
            'items'=>$checked['items'],
        ]);
        if(!$writeReady){
            $rejected['ERP_CREATE_PREFLIGHT_NOT_READY']=($rejected['ERP_CREATE_PREFLIGHT_NOT_READY']??0)+1;
            continue;
        }
'''
replace_once(canary, '        $candidate=$checked;$candidateCases=$cases;break;', canary_block + '        $candidate=$checked;$candidateCases=$cases;break;')

gateway_test = 'tests/erp-sales-return-browser-gateway-test.php'
replace_once(gateway_test, "    if(($payload['action']??'')==='CREATE')return ['status'=>'ACCEPTED','submitted'=>true,'external_id'=>'991','retry_safe'=>true];", "    if(($payload['action']??'')==='CREATE')return ['status'=>'ACCEPTED','submitted'=>true,'external_id'=>'991','retry_safe'=>true];\n    if(($payload['action']??'')==='VALIDATE_CREATE')return ['status'=>'READY','submitted'=>false,'external_id'=>null,'retry_safe'=>true];")
marker = "bgSame('2026-09-12',$calls[0]['refund_at']??null,'Gateway must send the refund date only.');"
replace_once(gateway_test, marker, marker + "\n$ready=$gateway->preflightCreate(['amazon_order_id'=>'702-1234567-1234567','refund_at'=>'2026-09-12 10:00:00','original_sale'=>['invoice_id'=>'202','invoice_number'=>'303'],'items'=>[['sku'=>'SKU-1','quantity_refunded'=>1]]]);\nbgSame(true,$ready,'Gateway create preflight must confirm a write-safe candidate without writing.');")

canary_test = 'tests/erp-sales-return-canary-test.php'
replace_once(canary_test, "'writeAllowedForOrderCases','verifyExternalReadBack'", "'writeAllowedForOrderCases','preflightCreate','verifyExternalReadBack'")

Path('tests/erp-sales-return-browser-create-preflight.test.mjs').write_text('''import test from 'node:test';
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
''')
