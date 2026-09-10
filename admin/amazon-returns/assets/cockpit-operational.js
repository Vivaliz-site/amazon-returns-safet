function operatorResponsibility(c){
  if(c?.review_status==='OPEN'||c?.current_action==='HUMAN_REVIEW'||c?.current_action==='BLOCKED_REVIEW')return 'Sua decisão é necessária';
  if(['RECOVERED','CLOSED_LOSS','RECEIVED_OK'].includes(String(c?.state||'')))return 'Caso concluído';
  return 'Nenhuma ação sua é necessária';
}
function operatorStatus(c){
  const state=String(c?.state||''),action=String(c?.current_action||'');
  if(operatorResponsibility(c)==='Sua decisão é necessária')return 'Precisa da sua decisão';
  if(state==='RECOVERED')return 'Valor recuperado';
  if(state==='CLOSED_LOSS')return 'Encerrado sem recuperação';
  if(state==='RECEIVED_OK')return 'Devolução recebida';
  if(['CREDIT_PENDING','SAFE_T_APPROVED','APPEAL_APPROVED'].includes(state)||action==='CHECK_FINANCES')return 'Aguardando crédito da Amazon';
  if(['APPEAL_SUBMITTED','EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SAFE_T_SUBMITTED','SUPPORT_ESCALATION'].includes(state))return 'Aguardando resposta da Amazon';
  if(action==='SAFE_T_APPEAL')return 'Recurso será enviado pelo sistema';
  if(action==='SAFE_T_SUBMIT')return 'Ressarcimento será solicitado pelo sistema';
  if(action==='SAFE_T_EMAIL_REVIEW'||action==='SAFE_T_EMAIL_REPLY')return 'Sistema preparando contato com a Amazon';
  if(action==='SELLER_SUPPORT_OPEN'||action==='SELLER_SUPPORT_UPDATE')return 'Sistema tratando com o Suporte da Amazon';
  if(state==='IN_TRANSIT')return 'Aguardando devolução em transporte';
  if(state==='AWAITING_RETURN'||state==='NO_RETURN')return 'Aguardando devolução';
  if(c?.next_action_at||c?.eligibility_at)return 'Aguardando prazo para próxima providência';
  return stateLabel(state||'REFUND_DETECTED');
}
function operatorNextStep(c){
  if(operatorResponsibility(c)==='Sua decisão é necessária')return 'Revise somente a dúvida indicada; o restante continua sendo tratado automaticamente.';
  const action=String(c?.current_action||'');
  if(action==='SAFE_T_SUBMIT')return 'O sistema solicitará o ressarcimento à Amazon automaticamente.';
  if(action==='SAFE_T_APPEAL')return 'O sistema enviará o recurso automaticamente.';
  if(action==='CHECK_FINANCES')return 'O sistema verificará se o crédito da Amazon entrou.';
  if(action==='SAFE_T_EMAIL_REVIEW')return 'O sistema pedirá uma nova análise à Amazon.';
  if(action==='SAFE_T_EMAIL_REPLY')return 'O sistema responderá à Amazon com as informações necessárias.';
  if(action==='SELLER_SUPPORT_OPEN')return 'O sistema abrirá atendimento com o Suporte da Amazon.';
  if(action==='SELLER_SUPPORT_UPDATE')return 'O sistema atualizará o atendimento já aberto.';
  if(['RECOVERED','CLOSED_LOSS','RECEIVED_OK'].includes(String(c?.state||'')))return 'Nenhuma providência adicional está prevista para este caso.';
  const when=c?.next_action_at||c?.eligibility_at;
  if(when)return `O sistema continuará automaticamente em ${date(when)}.`;
  if(['APPEAL_SUBMITTED','EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SAFE_T_SUBMITTED','SUPPORT_ESCALATION'].includes(String(c?.state||'')))return 'Aguardar a resposta da Amazon; o sistema continuará acompanhando.';
  return 'O sistema continuará acompanhando o caso e executará a próxima providência quando houver condição para isso.';
}
function operatorAlert(c){
  if(operatorResponsibility(c)==='Sua decisão é necessária')return null;
  const action=String(c?.current_action||''),due=c?.next_action_at||c?.eligibility_at||c?.appeal_deadline_at;
  if(!due||!['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'].includes(action))return null;
  const dueAt=new Date(String(due).replace(' ','T')+'Z');if(Number.isNaN(dueAt.getTime())||dueAt.getTime()>=Date.now())return null;
  if(c?.last_external_write?.kind===action&&['PENDING','PROCESSING','SUCCEEDED','SUCCESS'].includes(String(c.last_external_write.status||'')))return null;
  return 'Providência automática atrasada';
}
function operationalDate(v){return v?date(v):null;}
function daysUntil(v){const parsed=parsedDate(v);if(!parsed)return null;const d=Math.ceil((parsed.getTime()-Date.now())/86400000);return d===0?'vence hoje':d>0?`faltam ${d} dia${d===1?'':'s'}`:`atrasado há ${Math.abs(d)} dia${Math.abs(d)===1?'':'s'}`;}
function operationalField(label,value,extra=''){
  if(value===null||value===undefined||value===''||value==='—')return null;
  const el=text('div','',`operational-field ${extra}`.trim());el.append(text('span',label,'muted'),text('strong',String(value)));return el;
}
function financialProgress(c){
  const expected=Number(c?.expected_reimbursement_amount||c?.refund_amount||0),recovered=Number(c?.reconciled_credit_amount||0);if(expected<=0)return null;
  const root=text('div','', 'recovery-progress'),percent=Math.max(0,Math.min(100,Math.round((recovered/expected)*100)));const bar=document.createElement('progress');bar.max=expected;bar.value=Math.max(0,Math.min(expected,recovered));bar.setAttribute('aria-label',`${percent}% do valor esperado já foi recuperado`);root.append(bar,text('span',`${percent}% recuperado`,'muted'));return root;
}
function appendAvailable(root,...nodes){for(const node of nodes)if(node)root.append(node);return root;}
function sectionBlock(title,className=''){
  const section=text('section','',`case-block ${className}`.trim());section.append(text('h3',title));return section;
}
function externalWriteSummary(c){
  const write=c?.last_external_write;if(!write)return null;
  const action=actionLabel(write.kind),status=String(write.status||'').toUpperCase(),at=write.updated_at||write.created_at;
  const when=at?` em ${date(at)}`:'';
  if(['PENDING','QUEUED'].includes(status))return `Última ação programada: ${action}${when}. Ela ainda aguarda execução.`;
  if(status==='PROCESSING')return `Última ação em execução: ${action}${when}.`;
  if(['FAILED','ERROR','DEAD','CANCELLED'].includes(status))return `A última tentativa de ${action.toLowerCase()} não foi concluída${when}. O sistema deve tentar novamente.`;
  if(['SUCCEEDED','SUCCESS','ALREADY_EXISTS'].includes(status))return `Última ação concluída: ${action}${when}.`;
  return `Última ação registrada: ${action}${when}.`;
}
function operatorLastAction(c){
  const writeSummary=externalWriteSummary(c);if(writeSummary)return writeSummary;
  if(c?.last_read_back?.occurred_at)return `O sistema consultou as informações mais recentes em ${date(c.last_read_back.occurred_at)}.`;
  return 'O sistema já registrou o caso e continuará acompanhando.';
}
function operatorWhatHappened(c){
  const amount=Number(c?.refund_amount||0);const pieces=[];
  if(c?.order_at)pieces.push(`O pedido foi realizado em ${date(c.order_at)}.`);
  if(amount>0)pieces.push(`O cliente recebeu um reembolso de ${brl(amount)}${c?.refund_at?` em ${date(c.refund_at)}`:''}.`);
  if(c?.customer_delivery_confirmed)pieces.push('O rastreio informa que o pedido foi entregue ao cliente.');
  if(c?.physical_status==='NOT_RECEIVED')pieces.push('A devolução física ainda não foi registrada como recebida pela loja.');
  if(c?.physical_status==='RECEIVED_OK')pieces.push('A devolução física foi recebida sem divergência.');
  if(c?.physical_status==='RECEIVED_DISCREPANT')pieces.push('A devolução física foi recebida com divergência.');
  if(c?.return_reason)pieces.push(`Motivo informado para a devolução: ${humanText(c.return_reason)}.`);
  return pieces.join(' ')||'Os dados do pedido, do reembolso e da devolução estão sendo acompanhados pelo sistema.';
}
function operationalTimelineTitle(item){
  const raw=String(item?.title||item?.category||'').toUpperCase().replace(/[^A-Z0-9]+/g,'_');
  if(raw.includes('ORDER_SYNCED'))return 'Pedido sincronizado';
  if(raw.includes('FINANCIAL_TRANSACTION_OBSERVED'))return 'Movimentação financeira identificada';
  if(raw.includes('SAFE_T_STATUS_OBSERVED'))return 'Situação da solicitação consultada na Amazon';
  if(raw.includes('REIMBURSEMENT'))return 'Ressarcimento identificado';
  return humanText(item?.title||item?.category||'Atualização do caso');
}
function condenseTimeline(items){
  const result=[],groups=new Map();
  for(const item of items||[]){
    const title=operationalTimelineTitle(item);const repeatable=title==='Pedido sincronizado'||title==='Movimentação financeira identificada';const incomingCount=Math.max(1,Number(item.repeat_count||1));
    if(!repeatable){result.push({...item,operational_title:title,repeat_count:incomingCount});continue;}
    const day=String(item.occurred_at||'').slice(0,10),key=`${title}|${day}`;
    const current=groups.get(key);
    if(current){current.repeat_count+=incomingCount;if(String(item.occurred_at||'')>String(current.occurred_at||''))Object.assign(current,item,{operational_title:title,repeat_count:current.repeat_count});}
    else{const copy={...item,operational_title:title,repeat_count:incomingCount};groups.set(key,copy);result.push(copy);}
  }
  return result.sort((a,b)=>String(a.occurred_at||'').localeCompare(String(b.occurred_at||'')));
}
function renderCondensedTimeline(items,historyMeta={}){
  const all=items||[],condensed=condenseTimeline(all),total=Number(historyMeta.total_events||all.length);const details=document.createElement('details');details.className='case-history-toggle';
  const summary=text('summary',`Ver histórico (${total} eventos em ${condensed.length} marcos)`);details.append(summary);
  const timeline=text('section','', 'timeline compact-timeline');
  for(const item of condensed){const card=text('article','', 'timeline-item');const label=item.repeat_count>1?`${item.operational_title} · ${item.repeat_count} verificações`:item.operational_title;const source=sourceLabel(item.source),meta=[date(item.occurred_at)];if(source&&source!=='—'&&source!=='Informação não disponível')meta.push(source);card.append(text('h4',label),text('div',meta.join(' · '),'muted'));const content=item.content||{};const body=content.narrative||content.message?.body||content.review_excerpt;if(body)card.append(text('p',humanText(body)));timeline.append(card);}
  details.append(timeline);return details;
}
function renderOperationalList(items){
  const list=document.querySelector('#case-list');list.replaceChildren();
  for(const c of items){
    const row=text('article','', 'case-row operational-case-row');
    const id=text('div','', 'case-row-id');id.append(text('strong',c.amazon_order_id||'Pedido sem número'),text('span',c.safe_t_id?`SAFE-T ${c.safe_t_id}`:'Sem SAFE-T','muted'));
    const status=text('div','', 'case-row-status');status.append(text('strong',operatorStatus(c)),text('span',physicalLabel(c.physical_status),'muted'));
    const finance=text('div','', 'case-row-finance');finance.append(text('strong',brl(c.outstanding_amount)),text('span','saldo ainda a recuperar','muted'));
    const responsibility=text('div',operatorResponsibility(c),'case-row-responsibility');
    if(operatorAlert(c))row.classList.add('case-overdue');
    row.append(id,status,finance,responsibility,button('Abrir',()=>openCase(c.id),'case-open'));list.append(row);
  }
}
function renderOperationalEvidence(c,timeline){
  const block=sectionBlock('Evidências','case-evidence');
  const trackers=Array.isArray(c.customer_tracking_ids)?c.customer_tracking_ids:[];const carriers=Array.isArray(c.customer_delivery_carriers)?c.customer_delivery_carriers:[];
  appendAvailable(block,operationalField('Rastreio do pedido original',trackers[0]||null),operationalField('Transportadora do pedido',carriers[0]||null),operationalField('Rastreio da devolução',c.return_tracking_id||null),operationalField('Transportadora da devolução',c.return_carrier||null),operationalField('Amazon RMA',c.amazon_rma_id||null),operationalField('NF de venda',c.sales_invoice_number||null));
  if(c.customer_delivery_confirmed&&c.physical_status==='NOT_RECEIVED')block.append(text('p','O rastreio informa entrega ao cliente, enquanto a devolução física ainda não foi recebida pela loja.','evidence-note'));
  const sources=[...new Set((timeline||[]).map(x=>sourceLabel(x.source)).filter(x=>x&&x!=='—'&&x!=='Informação não disponível'))];if(sources.length)block.append(text('p',`Fontes consultadas: ${sources.join(', ')}.`,'muted'));
  const details=document.createElement('details');details.className='evidence-details';details.append(text('summary','Ver evidências'));
  if(trackers.length)details.append(text('p',`Rastreio do pedido original: ${trackers.join(', ')}.`));
  if(c.return_tracking_id)details.append(text('p',`Rastreio da devolução: ${c.return_tracking_id}.`));
  if(c.amazon_rma_id)details.append(text('p',`Amazon RMA: ${c.amazon_rma_id}.`));
  if(c.safe_t_id)details.append(text('p',`Solicitação SAFE-T relacionada: ${c.safe_t_id}.`));
  if(c.sales_invoice_number)details.append(text('p',`Nota fiscal de venda: ${c.sales_invoice_number}.`));
  block.append(details);return block;
}
function renderOperationalCase(data,relatedCount=null){
  const c=data.case||{},history=data.history_summary||{},rawTimeline=data.timeline||[],timeline=rawTimeline.length?rawTimeline:(history.items||[]),summary=data.operator_summary||{},root=document.querySelector('#case-detail');root.replaceChildren();
  const head=text('header','', 'case-summary');head.append(text('div',`Pedido ${c.amazon_order_id||'—'}`,'case-order'),text('h2',summary.headline||operatorStatus(c)));
  const responsibilityKey=summary.responsibility||null;const responsibilityLabel=responsibilityKey==='USER'?'Sua decisão é necessária':responsibilityKey==='COMPLETE'?'Caso concluído':responsibilityKey==='SYSTEM'?'Sistema cuidando deste caso':operatorResponsibility({...c,review_status:data.current_review?.status});const responsibility=text('div',responsibilityLabel,'responsibility-banner');if(responsibilityKey==='USER'||responsibilityLabel==='Sua decisão é necessária')responsibility.classList.add('needs-user');head.append(responsibility);
  const alert=summary.overdue?'Providência automática atrasada':operatorAlert({...c,review_status:data.current_review?.status});if(alert)head.append(text('div',alert,'operational-alert'));
  root.append(head);
  const flow=sectionBlock('Situação do caso','case-flow');
  for(const [title,value] of [['O que aconteceu',summary.what_happened||operatorWhatHappened(c)],['O que o sistema fez',summary.last_action||operatorLastAction(c)],['O que acontece agora',summary.next_step||operatorNextStep({...c,review_status:data.current_review?.status})]]){const item=text('div','', 'flow-item');item.append(text('strong',title),text('p',value));flow.append(item);}root.append(flow,renderOperatorConversation(data.conversation||[]));
  const dates=sectionBlock('Datas importantes');const nextDate=c.next_action_at||c.eligibility_at;
  appendAvailable(dates,operationalField('Data do pedido',operationalDate(c.order_at)),operationalField('Reembolso concedido ao cliente',operationalDate(c.refund_at)),operationalField('Débito na conta da loja',operationalDate(c.seller_debit_at)),operationalField('Devolução recebida pela loja',operationalDate(c.physical_received_at)),operationalField('Entrega da devolução informada pela Amazon',c.return_delivery_date||null),operationalField('Última verificação',operationalDate(c.last_read_back?.occurred_at)),operationalField('Próxima providência',nextDate?`${date(nextDate)} · ${daysUntil(nextDate)}`:null),operationalField('Prazo para recurso',c.appeal_deadline_at&&!['APPEAL_SUBMITTED','APPEAL_APPROVED'].includes(String(c.state||''))?`${date(c.appeal_deadline_at)} · ${daysUntil(c.appeal_deadline_at)}`:null));if(dates.children.length>1)root.append(dates);
  const finance=sectionBlock('Valores','case-financial'),expected=Number(c.expected_reimbursement_amount||0);appendAvailable(finance,operationalField('Valor reembolsado ao cliente',Number(c.refund_amount)>0?brl(c.refund_amount):null),operationalField('Valor esperado do ressarcimento',expected>0?brl(expected):null),operationalField('Valor já recuperado',brl(c.reconciled_credit_amount)),operationalField('Saldo ainda a recuperar',brl(c.outstanding_amount),'emphasis'),financialProgress(c));root.append(finance);
  const product=sectionBlock('Produto e documentos');appendAvailable(product,operationalField('SKU',c.sku||null),operationalField('ASIN',c.asin||null),operationalField('Quantidade do pedido',c.quantity_ordered?String(c.quantity_ordered):null),operationalField('Quantidade reembolsada',c.quantity_refunded?String(c.quantity_refunded):null),operationalField('SAFE-T',c.safe_t_id||null),operationalField('NF de venda',c.sales_invoice_number||null),operationalField('Tipo de logística',programLabel(c.program)),operationalField('Ocorrências relacionadas',relatedCount&&relatedCount>1?String(relatedCount):null));root.append(product);
  root.append(renderOperationalEvidence(c,timeline));
  const mappedReason=c.current_reason?reasonLabel(c.current_reason):null,decisionReason=summary.decision_explanation||(mappedReason&&mappedReason!=='Informação não disponível'?mappedReason:'A decisão usa os dados atuais do pedido, reembolso, devolução e financeiro.');const reason=sectionBlock('Por que o sistema tomou esta decisão?');reason.append(text('p',decisionReason));if(Array.isArray(summary.decision_basis)&&summary.decision_basis.length){const ul=document.createElement('ul');for(const item of summary.decision_basis)ul.append(text('li',item));reason.append(ul);}root.append(reason);
  root.append(renderCondensedTimeline(timeline,history));
}
const operationalBuckets=new Set(['all','attention','overdue','amazon','credit','system','closed']);
const initialBucket=new URLSearchParams(location.search).get('bucket');
let operationalBucket=operationalBuckets.has(initialBucket)?initialBucket:'all';
syncUrl=function operationalSyncUrl(){const q=new URLSearchParams({...state.filters,page:String(state.page),view:state.view});if(operationalBucket!=='all')q.set('bucket',operationalBucket);history.replaceState(null,'',`${location.pathname}?${q}`)};
async function loadBucketCases(filters,bucket){
  if(bucket==='attention'){filters.set('action','HUMAN_REVIEW');return json(`/admin/amazon-returns/api/cases.php?${filters}`);}
  const collected=[];let page=1,total=0;
  do{const q=new URLSearchParams(filters);q.set('page',String(page));q.set('per_page','100');const part=await json(`/admin/amazon-returns/api/cases.php?${q}`);total=Number(part.total||0);collected.push(...(part.items||[]));page++;}while(collected.length<total&&page<=20);
  const terminal=new Set(['RECOVERED','CLOSED_LOSS','RECEIVED_OK']);
  const awaitingAmazon=new Set(['SAFE_T_SUBMITTED','APPEAL_SUBMITTED','EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SUPPORT_ESCALATION']);
  const creditPending=new Set(['CREDIT_PENDING','SAFE_T_APPROVED','APPEAL_APPROVED']);
  let items;
  if(bucket==='closed')items=collected.filter(c=>terminal.has(String(c.state||'')));
  else if(bucket==='overdue')items=collected.filter(c=>Boolean(operatorAlert(c)));
  else if(bucket==='amazon')items=collected.filter(c=>awaitingAmazon.has(String(c.state||'')));
  else if(bucket==='credit')items=collected.filter(c=>creditPending.has(String(c.state||''))||c.current_action==='CHECK_FINANCES');
  else items=collected.filter(c=>!terminal.has(String(c.state||''))&&operatorResponsibility(c)!=='Sua decisão é necessária');
  return {items,total:items.length,page:1,per_page:Math.max(items.length,1)};
}
loadCases=async function operationalLoadCases(){
  try{clearError();state.filters=readFilters();syncUrl();const q=new URLSearchParams({...state.filters,page:String(state.page),per_page:'50'});const j=operationalBucket==='all'?await json(`/admin/amazon-returns/api/cases.php?${q}`):await loadBucketCases(q,operationalBucket);document.querySelector('#result-count').textContent=`${j.total} casos`;renderOperationalList(j.items||[]);if(operationalBucket==='all'||operationalBucket==='attention')renderPager(j.total,j.per_page);else document.querySelector('#pager').replaceChildren();}
  catch(e){showError(e.message,loadCases);}
};
openCase=async function operationalOpenCase(caseId){
  try{clearError();const j=await json(`/admin/amazon-returns/api/case.php?case_id=${encodeURIComponent(caseId)}`);state.selectedCase=caseId;let relatedCount=null;try{if(j.case?.amazon_order_id){const related=await json(`/admin/amazon-returns/api/case.php?order_id=${encodeURIComponent(j.case.amazon_order_id)}`);relatedCount=Array.isArray(related.cases)?related.cases.length:null;}}catch(_e){}renderOperationalCase(j,relatedCount);}
  catch(e){showError(e.message,()=>openCase(caseId));}
};
for(const control of document.querySelectorAll('[data-quick-filter]')){control.setAttribute('aria-pressed',String(control.dataset.quickFilter===operationalBucket));control.addEventListener('click',()=>{operationalBucket=control.dataset.quickFilter||'all';for(const other of document.querySelectorAll('[data-quick-filter]'))other.setAttribute('aria-pressed',String(other===control));state.page=1;loadCases();});}