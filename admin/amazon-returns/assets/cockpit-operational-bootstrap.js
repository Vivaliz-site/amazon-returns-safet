operatorAlert=function operationalAlert(c){
  if(operatorResponsibility(c)==='Sua decisão é necessária')return null;
  const action=String(c?.current_action||'');
  if(!['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'].includes(action))return null;
  const completedStates=new Set(['APPEAL_SUBMITTED','APPEAL_APPROVED','EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SAFE_T_SUBMITTED','SAFE_T_APPROVED','SUPPORT_ESCALATION','RECOVERED','CLOSED_LOSS']);
  if(completedStates.has(String(c?.state||'')))return null;
  let due=null;
  if(action==='SAFE_T_APPEAL')due=c?.appeal_deadline_at||c?.next_action_at;
  else if(action==='SAFE_T_SUBMIT')due=c?.eligibility_at||c?.next_action_at;
  else due=c?.next_action_at;
  if(!due)return null;
  const dueAt=new Date(String(due).replace(' ','T')+'Z');
  if(Number.isNaN(dueAt.getTime())||dueAt.getTime()>=Date.now())return null;
  if(c?.last_external_write?.kind===action&&['PENDING','PROCESSING','SUCCEEDED','SUCCESS'].includes(String(c.last_external_write.status||'')))return null;
  return 'Providência automática atrasada';
};
const baseOperationalRenderCase=renderOperationalCase;
renderOperationalCase=function auditedOperationalRenderCase(data,relatedCount=null){
  baseOperationalRenderCase(data,relatedCount);
  const c=data?.case||{};const stateName=String(c.state||'');
  const appealAlreadyHandled=['APPEAL_SUBMITTED','APPEAL_APPROVED','EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SUPPORT_ESCALATION','RECOVERED','CLOSED_LOSS'].includes(stateName);
  if(appealAlreadyHandled){
    for(const field of document.querySelectorAll('#case-detail .operational-field')){
      if(field.querySelector('.muted')?.textContent==='Prazo para recurso')field.remove();
    }
    if(['APPEAL_SUBMITTED','EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING'].includes(stateName)){
      const flow=document.querySelector('#case-detail .case-flow');if(flow){const note=text('div','', 'flow-item');note.append(text('strong','Prazo do recurso'),text('p','O recurso já foi enviado; agora o sistema aguarda a resposta da Amazon.'));flow.append(note);}
    }
  }
  const checked=c.last_read_back?.occurred_at;
  if(checked){const checkedAt=new Date(String(checked).replace(' ','T')+'Z');if(!Number.isNaN(checkedAt.getTime())&&Date.now()-checkedAt.getTime()>36*60*60*1000){document.querySelector('#case-detail .case-summary')?.append(text('div','Dados podem estar desatualizados: a última verificação ocorreu há mais de 36 horas.','stale-data-note'));}}
};
const baseOperationalLoadBucketCases=loadBucketCases;
loadBucketCases=async function auditedLoadBucketCases(filters,bucket){
  if(bucket==='attention'){
    const q=new URLSearchParams(filters);q.delete('action');q.set('review_status','OPEN');q.set('per_page','100');
    return json(`/admin/amazon-returns/api/cases.php?${q}`);
  }
  return baseOperationalLoadBucketCases(filters,bucket);
};
function syncOperationalChrome(){
  const hide=state.view!=='cases';
  document.querySelector('.quick-filters')?.classList.toggle('hidden',state.view!=='cases');
  document.querySelector('#operational-overview')?.classList.toggle('hidden',hide);
}
const baseOperationalSelectView=selectView;
selectView=function operationalSelectView(view){baseOperationalSelectView(view);syncOperationalChrome();};
async function loadOperationalOverview(){
  const root=document.querySelector('#operational-overview');if(!root)return;
  try{
    const summary=await json('/admin/amazon-returns/api/summary.php');
    const all=[];let page=1,total=0;
    do{const part=await json(`/admin/amazon-returns/api/cases.php?page=${page}&per_page=100`);total=Number(part.total||0);all.push(...(part.items||[]));page++;}while(all.length<total&&page<=20);
    const terminal=new Set(['RECOVERED','CLOSED_LOSS','RECEIVED_OK']);
    const attention=all.filter(c=>operatorResponsibility(c)==='Sua decisão é necessária').length;
    const closed=all.filter(c=>terminal.has(String(c.state||''))).length;
    const system=Math.max(0,all.length-attention-closed);
    root.replaceChildren();
    root.append(text('strong',`${Number(summary.total_cases||all.length)} casos · ${brl(summary.money?.at_risk||0)} em aberto · ${brl(summary.money?.recovered||0)} recuperados`));
    const breakdown=text('div','', 'overview-breakdown');
    for(const label of [`${attention} precisam da sua atenção`,`${system} sendo tratados pelo sistema`,`${closed} concluídos`])breakdown.append(text('span',label,'overview-chip'));
    root.append(breakdown);
  }catch(_e){root.replaceChildren(text('span','Resumo operacional indisponível no momento. A lista de casos continua disponível.','muted'));}
}
syncOperationalChrome();
loadOperationalOverview();
if(state.view==='cases')loadCases();