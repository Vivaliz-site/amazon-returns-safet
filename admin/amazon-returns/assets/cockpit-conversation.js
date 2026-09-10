const conversationChannelLabels={ALL:'Todas',SAFE_T:'SAFE-T',EMAIL:'E-mail',SELLER_SUPPORT:'Suporte'};
const conversationActorLabels={SELLER:'Nossa mensagem',AMAZON:'Amazon'};

function conversationTime(message){
  if(message?.occurred_at)return date(message.occurred_at);
  if(message?.observed_at)return `Identificada na consulta de ${date(message.observed_at)}`;
  return 'Data não disponível';
}

function conversationBody(message){
  const body=String(message?.body||'').trim();
  if(body.length<=900)return text('p',body,'conversation-body');
  const wrap=text('div','', 'conversation-long');
  wrap.append(text('p',`${body.slice(0,600).trim()}…`,'conversation-preview'));
  const details=document.createElement('details');
  details.append(text('summary','Ver mensagem completa'),text('p',body,'conversation-body'));
  wrap.append(details);return wrap;
}

function conversationCard(message){
  const actor=conversationActorLabels[String(message?.actor||'').toUpperCase()]||'Mensagem';
  const channel=conversationChannelLabels[String(message?.channel||'').toUpperCase()]||'Outro canal';
  const card=text('article','',`conversation-card actor-${String(message?.actor||'unknown').toLowerCase()}`);
  const header=text('header','', 'conversation-card-header');
  header.append(text('strong',actor),text('span',channel,'conversation-channel'));
  card.append(header,text('div',conversationTime(message),'muted conversation-time'));
  if(message?.subject)card.append(text('div',String(message.subject),'conversation-subject'));
  card.append(conversationBody(message));
  return card;
}
function renderOperatorConversation(messages){
  const root=text('section','', 'case-block amazon-conversation');
  root.append(text('h3','Conversa com a Amazon'));
  const valid=(messages||[]).filter(message=>message&&String(message.body||'').trim());
  if(!valid.length){
    root.append(text('p','Nenhuma mensagem SAFE-T, e-mail ou atendimento foi registrada para este caso até o momento.','muted'));
    return root;
  }
  root.append(text('p',`${valid.length} mensagem${valid.length===1?'':'s'} registrada${valid.length===1?'':'s'} neste caso.`,'muted'));
  const controls=text('div','', 'conversation-filters');
  const list=text('div','', 'conversation-list');
  const channels=['ALL',...new Set(valid.map(message=>String(message.channel||'').toUpperCase()).filter(Boolean))];
  let selected='ALL';
  const render=()=>{
    list.replaceChildren();
    for(const message of valid){
      if(selected!=='ALL'&&String(message.channel||'').toUpperCase()!==selected)continue;
      list.append(conversationCard(message));
    }
  };
  for(const channel of channels){
    const control=button(conversationChannelLabels[channel]||channel,()=>{
      selected=channel;
      for(const other of controls.querySelectorAll('button'))other.setAttribute('aria-pressed',String(other===control));
      render();
    });
    control.setAttribute('aria-pressed',String(channel==='ALL'));
    control.dataset.conversationChannel=channel;
    controls.append(control);
  }
  root.append(controls,list);render();return root;
}