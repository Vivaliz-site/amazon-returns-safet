<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/AdminAuth.php';
require_once __DIR__ . '/../../includes/Csrf.php';
SvAmazonReturnsAdminAuth::requireLogin(false);
$csrf=SvAmazonReturnsCsrf::token('amazon_returns_intake');
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Registrar devolução Amazon</title>
<style>:root{font-family:Inter,system-ui,sans-serif;color:#17202a;background:#f4f6f8}*{box-sizing:border-box}body{margin:0}.wrap{max-width:760px;margin:auto;padding:18px}.panel{background:#fff;border:1px solid #e4e7ea;border-radius:14px;padding:18px;margin-bottom:14px}label{font-weight:700;display:block;margin:14px 0 6px}input,select,textarea,button{width:100%;font:inherit;padding:12px;border:1px solid #cfd4da;border-radius:10px}button{background:#17202a;color:#fff;font-weight:800;cursor:pointer;min-height:44px}button:disabled{opacity:.55;cursor:wait}.row{display:grid;grid-template-columns:1fr auto;gap:8px}.row button{width:auto}.item{border:1px solid #e4e7ea;border-radius:10px;padding:12px;margin:8px 0;cursor:pointer}.item.active{border-color:#17202a;background:#f8fafc}.muted{color:#667085}.ok{color:#067647;font-weight:700}.err{color:#b42318;font-weight:700}.back{color:#17202a}.hidden{display:none!important}.preview-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:10px 0 4px}.preview-field{padding:10px;border-radius:10px;background:#f7f8fa}.preview-field span{display:block;color:#667085;font-size:.88rem;margin-bottom:3px}.preview-field strong{overflow-wrap:anywhere}@media(max-width:560px){.wrap{padding:10px}.panel{padding:14px}.row,.preview-grid{grid-template-columns:1fr}.row button{width:100%}}</style></head><body><main class="wrap">
<p><a class="back" href="/admin/amazon-returns/">← Devoluções Amazon</a></p><h1>Registrar devolução recebida</h1>
<section class="panel"><label for="lookup-query">Localizar devolução</label><div class="row"><input id="lookup-query" maxlength="96" placeholder="Pedido Amazon, NF de venda ou TBR" autocomplete="off"><button type="button" id="search">Localizar</button></div><div class="muted">Informe o pedido Amazon, NF de venda ou TBR / rastreio da devolução. Se ainda não estiver no aplicativo, o sistema consulta as fontes disponíveis neste momento.</div><div id="items"></div><p id="message"></p></section>
<form class="panel hidden" id="form" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="operation_id" id="operation"><input type="hidden" name="case_id" id="case_id">
<h2>Confira a devolução</h2><div id="intake-preview" class="preview-grid" aria-live="polite"></div>
<label for="quantity">Quantidade correta recebida</label><input id="quantity" name="quantity_correct" type="number" min="0" step="1" value="1" required><div class="muted">Se chegou produto diferente ou embalagem vazia, informe 0. Em devolução parcial, informe somente a quantidade correta que chegou.</div>
<label for="condition">Como o produto chegou?</label><select id="condition" name="condition" required><option value="OK">Íntegro</option><option value="DAMAGED">Danificado</option><option value="USED">Usado</option><option value="WRONG_ITEM">Produto diferente</option><option value="INCOMPLETE">Incompleto</option><option value="EMPTY_PACKAGE">Embalagem vazia</option></select>
<label for="note">Observação</label><textarea id="note" name="note" rows="4" maxlength="2000" placeholder="Descreva somente o que você observou na conferência."></textarea>
<label for="photos">Fotos</label><input id="photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple><div class="muted">Obrigatórias quando houver divergência. Máximo de 6 fotos, 8 MB cada.</div>
<button type="submit" id="submit-receipt">Confirmar recebimento</button></form></main>
<script>
const csrf=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const operation=document.querySelector('#operation');const msg=document.querySelector('#message');const caseId=document.querySelector('#case_id');
const searchButton=document.querySelector('#search');const submitButton=document.querySelector('#submit-receipt');const form=document.querySelector('#form');
const preview=document.querySelector('#intake-preview');const lookupInput=document.querySelector('#lookup-query');const uuid=()=>crypto.randomUUID();operation.value=uuid();
const intakeStatusLabels={REFUND_DETECTED:'Reembolso identificado',AWAITING_RETURN:'Aguardando devolução',IN_TRANSIT:'Em transporte',CARRIER_DELIVERED_PENDING_PHYSICAL:'Transportadora indica entrega; conferência pendente',RECEIVED_OK:'Recebido sem divergência',RECEIVED_DISCREPANT:'Recebido com divergência',SAFE_T_ELIGIBLE:'Pode solicitar ressarcimento',SAFE_T_READY:'Pronto para solicitar ressarcimento',SAFE_T_SUBMITTED:'Ressarcimento solicitado',SAFE_T_APPROVED:'Ressarcimento aprovado',SAFE_T_DENIED:'Ressarcimento negado',CREDIT_PENDING:'Aguardando crédito',RECOVERED:'Valor recuperado',POLICY_REVIEW_REQUIRED:'Em análise',BLOCKED_REVIEW:'Precisa de decisão'};
function humanIntakeStatus(value){return intakeStatusLabels[String(value||'')]||'Situação em atualização';}
function friendlyIntakeError(value){const raw=String(value||'');if(/CSRF/i.test(raw))return 'Sua sessão precisa ser atualizada. Recarregue a página e tente novamente.';if(/Banco indisponível/i.test(raw))return 'O sistema está temporariamente indisponível. Tente novamente em instantes.';if(/^[A-Z0-9_:-]+$/.test(raw)||/[a-z]+_[a-z0-9_]+/i.test(raw))return 'Não foi possível concluir esta ação. Tente novamente.';return raw||'Não foi possível concluir esta ação.';}
function setMsg(t,ok=false){msg.textContent=t;msg.className=ok?'ok':'err';}
function expectedQuantity(c){return Number(c.quantity_refunded||0)>0?Number(c.quantity_refunded):Math.max(1,Number(c.quantity_ordered||0));}
function previewField(label,value){if(value===null||value===undefined||value==='')return null;const el=document.createElement('div');el.className='preview-field';const k=document.createElement('span');k.textContent=label;const v=document.createElement('strong');v.textContent=String(value);el.append(k,v);return el;}
function returnTracking(c){const values=Array.isArray(c.return_tracking_ids)?c.return_tracking_ids:[];return c.return_tracking_id||values[0]||null;}
function productIdentifiers(c){return [['SKU',c.sku],['ASIN',c.asin]].filter(([,value])=>value).map(([label,value])=>`${label} ${value}`).join(' · ')||c.amazon_order_item_id;}
function choose(c,el){
  caseId.value=String(c.id);document.querySelectorAll('.item').forEach(x=>x.classList.remove('active'));el.classList.add('active');
  const total=expectedQuantity(c);const pending=Math.max(0,total-Number(c.quantity_received||0));document.querySelector('#quantity').max=String(pending);document.querySelector('#quantity').value=String(Math.min(1,pending));
  preview.replaceChildren();
  const fields=[['Pedido',c.amazon_order_id],['TBR / rastreio da devolução',returnTracking(c)],['Descrição do produto',c.product_title||null],['SKU / ASIN',productIdentifiers(c)],['Situação',humanIntakeStatus(c.state)],['Reembolso ao cliente',Number(c.refund_amount||0)>0?new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(c.refund_amount)):null],['Quantidade a conferir',pending]];
  for(const [label,value] of fields){const field=previewField(label,value);if(field)preview.append(field);}
  form.classList.remove('hidden');form.scrollIntoView({behavior:'smooth',block:'start'});
}
function resetSelection(){caseId.value='';preview.replaceChildren();form.classList.add('hidden');document.querySelector('#items').replaceChildren();}
async function findReturn(){
  msg.textContent='';resetSelection();const query=lookupInput.value.trim();if(!query){setMsg('Informe o pedido Amazon, NF de venda ou TBR / rastreio da devolução.');return;}
  searchButton.disabled=true;searchButton.textContent='Localizando…';
  try{
    const r=await fetch('/admin/amazon-returns/api/intake-lookup.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'content-type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({query:query,csrf_token:csrf})});
    const j=await r.json().catch(()=>({}));const box=document.querySelector('#items');if(!r.ok||!j.success){setMsg(friendlyIntakeError(j.error));return;}
    if(!(j.cases||[]).length){setMsg('Nenhuma devolução encontrada. Confira o pedido, a NF ou o TBR e tente novamente.');return;}
    for(const c of j.cases){const el=document.createElement('div');el.className='item';el.tabIndex=0;const total=expectedQuantity(c);const tbr=returnTracking(c);const product=c.product_title||productIdentifiers(c);const ids=c.product_title?` · ${productIdentifiers(c)}`:'';el.textContent=`Pedido ${c.amazon_order_id} · ${tbr?`TBR ${tbr} · `:''}${product}${ids} · ${humanIntakeStatus(c.state)} · recebido ${Number(c.quantity_received||0)}/${total}`;el.addEventListener('click',()=>choose(c,el));el.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();choose(c,el);}});box.append(el);}
    if(j.cases.length===1)box.firstElementChild.click();
    if(j.synced)setMsg('Devolução localizada nas fontes da Amazon e disponibilizada para conferência.',true);
    else if(j.cases.length>1)setMsg('Encontramos mais de um item. Selecione a devolução correta antes de confirmar.',true);
  }catch(err){setMsg(friendlyIntakeError(err.message));}
  finally{searchButton.disabled=false;searchButton.textContent='Localizar';}
}
searchButton.addEventListener('click',findReturn);lookupInput.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();findReturn();}});
document.querySelector('#condition').addEventListener('change',event=>{const discrepancy=event.target.value!=='OK';document.querySelector('#photos').required=discrepancy;if(['WRONG_ITEM','EMPTY_PACKAGE'].includes(event.target.value))document.querySelector('#quantity').value='0';});
form.addEventListener('submit',async event=>{
  event.preventDefault();if(!caseId.value){setMsg('Selecione a devolução recebida.');return;}const condition=document.querySelector('#condition').value;if(condition!=='OK'&&!document.querySelector('#photos').files.length){setMsg('Anexe ao menos uma foto da divergência.');return;}
  const fd=new FormData(form);submitButton.disabled=true;const originalLabel=submitButton.textContent;submitButton.textContent='Registrando…';
  try{const r=await fetch('/admin/amazon-returns/api/intake.php',{method:'POST',body:fd,headers:{'X-CSRF-Token':csrf},credentials:'same-origin'});const j=await r.json().catch(()=>({}));if(!r.ok||!j.success)throw new Error(friendlyIntakeError(j.error));setMsg(j.duplicate?'Este recebimento já estava registrado; nenhuma informação foi duplicada.':'Recebimento registrado com sucesso.',true);operation.value=uuid();}
  catch(err){setMsg(friendlyIntakeError(err.message));}
  finally{submitButton.disabled=false;submitButton.textContent=originalLabel;}
});
</script></body></html>