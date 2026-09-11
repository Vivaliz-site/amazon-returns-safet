(()=>{
  'use strict';
  const CACHE_KEY='amazonReturns:lastGoodSummary:v1';
  const CACHE_TTL_MS=20*60*1000;
  const statusCopy={NORMAL:'Operando normalmente',DEGRADED:'Sistema requer atenção',USER_ACTION_REQUIRED:'Sua intervenção é necessária'};
  const connectorCopy={OK:'Funcionando',DEGRADED:'Requer atenção',NOT_REQUIRED:'Sob demanda',UNKNOWN:'Sem confirmação recente'};
  const connectorNames={amazon:'Amazon / financeiro',gmail:'E-mail Amazon',seller_central:'Seller Central'};
  const problemCopy={unclassified:'Casos ainda sem situação definida',eligible_without_action:'Casos prontos para tratamento automático',expired_without_treatment:'Prazo vencido sem providência concluída',credit_without_reconciliation:'Crédito identificado e ainda não conciliado',dead_letters:'Ações automáticas que esgotaram as tentativas',connector_amazon:'Conexão com a Amazon requer atenção',connector_gmail:'Conexão de e-mail requer atenção',connector_seller_central:'Seller Central requer atenção'};
  const breakdownCopy={at_risk:'Em risco',eligible_now:'Já elegível',safe_t_submitted:'SAFE-T em análise',denied:'Negado',appeal:'Em recurso',support:'Em atendimento',approved_awaiting_credit:'Aguardando crédito',recovered:'Recuperado',loss:'Encerrado como perda'};
  const deadlineKindCopy={APPEAL_DEADLINE:'Prazo para recurso',NEXT_ACTION:'Próxima providência automática',ELIGIBILITY:'Data de elegibilidade'};
  const stateCopy={SAFE_T_ELIGIBLE:'Pode solicitar ressarcimento',SAFE_T_READY:'Pronto para solicitar ressarcimento',SAFE_T_SUBMITTED:'Ressarcimento solicitado',SAFE_T_APPROVED:'Ressarcimento aprovado',SAFE_T_DENIED:'Ressarcimento negado',APPEAL_REQUIRED:'Recurso necessário',APPEAL_SUBMITTED:'Recurso enviado',APPEAL_APPROVED:'Recurso aprovado',CREDIT_PENDING:'Aguardando crédito',SUPPORT_ESCALATION:'Em atendimento com a Amazon',AWAITING_RETURN:'Aguardando devolução',IN_TRANSIT:'Devolução em transporte',POLICY_REVIEW_REQUIRED:'Situação em análise'};
  const brl=v=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v||0));
  function node(tag,value,className=''){const n=document.createElement(tag);if(value!==undefined)n.textContent=String(value);if(className)n.className=className;return n;}
  function root(id){return document.getElementById(id);}
  function replace(id,...children){const r=root(id);if(r)r.replaceChildren(...children);return r;}
  function formatDate(value){if(!value)return 'Sem confirmação recente';const d=new Date(value);return Number.isNaN(d.getTime())?'Sem confirmação recente':d.toLocaleString('pt-BR');}
  function saveLastGood(payload){try{sessionStorage.setItem(CACHE_KEY,JSON.stringify({saved_at:Date.now(),payload}));}catch{}}
  function readLastGood(){try{const parsed=JSON.parse(sessionStorage.getItem(CACHE_KEY)||'null');if(!parsed||Date.now()-Number(parsed.saved_at||0)>CACHE_TTL_MS)return null;return parsed.payload||null;}catch{return null;}}
  function renderAutonomy(data,stale){
    const status=String(data.operator_status||'DEGRADED');
    const box=node('section',undefined,`autonomy-card status-${status.toLowerCase()}`);
    box.append(node('h2',statusCopy[status]||'Sistema requer atenção'));
    const human=Number(data.human_action_count||0),problems=Number(data.operational_problem_count||0);
    let message=human>0?`${human} ${human===1?'decisão depende':'decisões dependem'} de você.`:'Nenhuma ação sua é necessária.';
    if(human===0&&problems>0)message=`Você não precisa agir. O sistema identificou ${problems} ${problems===1?'problema operacional':'problemas operacionais'} e está tratando ou tentando novamente.`;
    box.append(node('p',message));
    const meta=node('div',undefined,'autonomy-meta');
    meta.append(node('span',`${Number(data.automatic_work_count||0)} sendo tratados pelo sistema`));
    meta.append(node('span',`${Number(data.concluded_count||0)} concluídos`));
    meta.append(node('span',`Último ciclo bem-sucedido: ${formatDate(data.last_successful_cycle_at)}`));
    meta.append(node('span',`Dados atualizados: ${formatDate(data.as_of||data.checked_at)}`));
    box.append(meta);
    if(stale)box.prepend(node('div',`Últimos dados disponíveis, atualizados às ${formatDate(data.as_of||data.checked_at)}. A atualização mais recente falhou e será tentada novamente.`,'stale-summary'));
    replace('autonomy-status',box);
  }
  function renderUserWork(data){
    const section=node('div');section.append(node('h2','Precisa de você?'));
    const count=Number(data.human_action_count||0);
    if(count===0)section.append(node('p','Nenhuma ação sua é necessária.','healthy-zero'));
    else{section.append(node('p',`${count} ${count===1?'caso precisa':'casos precisam'} da sua decisão.`));const b=node('button','Ver revisões','summary-action');b.type='button';b.addEventListener('click',()=>document.querySelector('[data-view="reviews"]')?.click());section.append(b);}
    replace('user-work',section);
  }
  function renderAutomation(data){
    const section=node('div');section.append(node('h2','Sistema tratando agora'));
    const count=Number(data.automatic_work_count||0);
    section.append(node('p',count>0?`${count} casos seguem em tratamento ou acompanhamento automático.`:'Nenhum caso automático pendente no momento.','summary-lead'));
    const list=node('div',undefined,'automation-preview');
    for(const item of data.automation_preview||[]){
      const row=node('article',undefined,'summary-row');
      row.append(node('strong',String(item.amazon_order_id||'Pedido')));
      row.append(node('span',stateCopy[String(item.state||'')]||'Acompanhamento automático'));
      row.append(node('span',brl(item.outstanding_amount),'summary-amount'));
      const next=item.next_action_at||item.appeal_deadline_at||item.eligibility_at;
      if(next)row.append(node('small',`Próxima referência: ${formatDate(next)}`));
      list.append(row);
    }
    if(list.childElementCount)section.append(list);
    replace('automation-work',section);
  }
  function renderMoney(data){
    const items=[['at_risk','Em risco'],['awaiting_credit','Aguardando crédito'],['in_dispute','Em disputa'],['recovered','Recuperado']];
    const cards=[];
    for(const [key,label] of items){const card=node('article',undefined,'money-card');card.append(node('span',label,'muted'),node('strong',brl(data.money?.[key])));cards.push(card);}
    replace('money-headlines',...cards);
    const details=root('money-breakdown-items');if(details){details.replaceChildren();const breakdown=data.money?.breakdown||{};for(const [key,label] of Object.entries(breakdownCopy)){const row=node('div',undefined,'breakdown-row');row.append(node('span',label),node('strong',brl(breakdown[key])));details.append(row);}}
  }
  function renderProblems(data){
    const section=node('div');section.append(node('h2','Saúde operacional'));
    const list=data.operational_problems||[];
    if(!list.length)section.append(node('p','Nenhum problema operacional identificado.','healthy-zero'));
    for(const item of list){const row=node('article',undefined,`problem-row problem-${String(item.status||'').toLowerCase()}`);row.append(node('strong',problemCopy[item.key]||'Problema operacional'));const affected=Number(item.affected_cases||0);if(affected>0)row.append(node('span',`${affected} ${affected===1?'caso afetado':'casos afetados'}`));if(Number(item.affected_amount||0)>0)row.append(node('span',brl(item.affected_amount),'summary-amount'));row.append(node('small',item.owner==='USER'?'Responsável: você':'Responsável: sistema'));section.append(row);}
    replace('operational-problems',section);
  }
  function deadlineBucket(value){
    const d=new Date(value),now=new Date();if(Number.isNaN(d.getTime()))return null;
    const start=new Date(now.getFullYear(),now.getMonth(),now.getDate());
    const target=new Date(d.getFullYear(),d.getMonth(),d.getDate());
    const days=Math.round((target-start)/86400000);
    if(d.getTime()<now.getTime())return 'Atrasado';
    if(days===0)return 'Hoje';if(days===1)return 'Amanhã';if(days<=7)return 'Próximos 7 dias';return null;
  }
  function renderDeadlines(data){
    const section=node('div');section.append(node('h2','Prazos importantes'));
    let shown=0;
    for(const item of data.deadlines||[]){const bucket=deadlineBucket(item.due_at);if(!bucket)continue;shown++;const row=node('article',undefined,`deadline-row ${bucket==='Atrasado'?'problem-overdue':''}`);const meaning=deadlineKindCopy[String(item.due_kind||'')]||'Prazo do caso';row.append(node('strong',bucket),node('span',String(item.amazon_order_id||'Pedido')),node('span',meaning,'deadline-meaning'),node('span',brl(item.outstanding_amount),'summary-amount'),node('small',formatDate(item.due_at)));section.append(row);}
    if(!shown)section.append(node('p','Nenhum prazo crítico nos próximos 7 dias.','healthy-zero'));
    replace('deadline-list',section);
  }
  function renderConnectors(data){
    const section=node('div');section.append(node('h2','Conexões do sistema'));
    for(const [key,name] of Object.entries(connectorNames)){const info=data.connectors?.[key]||{status:'UNKNOWN'};const row=node('div',undefined,'connector-row');row.append(node('strong',name),node('span',connectorCopy[String(info.status||'UNKNOWN')]||'Sem confirmação recente',`connector-status status-${String(info.status||'UNKNOWN').toLowerCase()}`));if(info.observed_at)row.append(node('small',`Última confirmação: ${formatDate(info.observed_at)}`));section.append(row);}
    replace('connector-health',section);
  }
  function render(data,stale=false){
    renderAutonomy(data,stale);renderUserWork(data);renderAutomation(data);renderMoney(data);renderProblems(data);renderDeadlines(data);renderConnectors(data);
    const pending=Number(data.human_action_count??data.pending_reviews??0);const count=root('review-count');if(count)count.textContent=String(pending);const alert=root('review-alert');if(alert){alert.textContent=pending===1?'1 caso precisa da sua decisão':`${pending} casos precisam da sua decisão`;alert.classList.toggle('hidden',pending===0);}
  }
  function renderUnavailable(){
    replace('autonomy-status',node('div','Resumo operacional indisponível no momento. Não foi possível confirmar se existem pendências.','autonomy-card status-degraded'));
    for(const id of ['user-work','automation-work','money-headlines','operational-problems','deadline-list','connector-health'])replace(id,node('p','Dados indisponíveis no momento.','muted'));
    const details=root('money-breakdown-items');if(details)details.replaceChildren(node('p','Dados indisponíveis no momento.','muted'));
  }
  async function load(){
    try{
      const response=await fetch('/admin/amazon-returns/api/summary.php',{credentials:'same-origin',cache:'no-store'});
      const data=await response.json().catch(()=>({}));
      if(!response.ok||data.success===false)throw new Error('summary unavailable');
      render(data,false);saveLastGood(data);return data;
    }catch(error){
      const cached=readLastGood();if(cached){render(cached,true);return cached;}
      renderUnavailable();throw error;
    }
  }
  window.AmazonReturnsSummary={load,render,readLastGood};
})();
