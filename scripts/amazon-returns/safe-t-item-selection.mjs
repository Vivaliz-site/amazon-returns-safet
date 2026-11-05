/**
 * Fail-closed selection for the Amazon SAFE-T quantity checkbox.
 * This helper never clicks Next, Submit, Send or any external write action.
 * A click is not a successful selection until the checkbox itself confirms it.
 */
const STATE_EXPRESSION = `JSON.stringify((()=>{
  const hosts=[...document.querySelectorAll('kat-checkbox.QuantityCheckbox')];
  if(hosts.length===0)return {status:'MISSING'};
  if(hosts.length!==1)return {status:'UNVERIFIED'};
  const host=hosts[0];
  const inner=host.shadowRoot?.querySelector('input[type="checkbox"],[role="checkbox"],button,.checkbox');
  if(!inner)return {status:'UNVERIFIED'};
  const target=inner;
  try{target.scrollIntoView({block:'center',inline:'center'})}catch{}
  const rect=target.getBoundingClientRect();
  const style=getComputedStyle(target);
  const hstyle=getComputedStyle(host);
  const visible=rect.width>0&&rect.height>0&&target.getClientRects().length>0
    &&style.display!=='none'&&style.visibility!=='hidden'&&hstyle.display!=='none'
    &&hstyle.visibility!=='hidden'&&host.getAttribute('aria-hidden')!=='true';
  const enabled=!host.disabled&&!target.disabled&&!host.hasAttribute('disabled')
    &&!target.hasAttribute('disabled')&&host.getAttribute('aria-disabled')!=='true'
    &&target.getAttribute('aria-disabled')!=='true';
  if(!visible||!enabled)return {status:'UNVERIFIED'};
  const signals=[];
  if(typeof host.checked==='boolean')signals.push(host.checked);
  if(typeof target.checked==='boolean')signals.push(target.checked);
  for(const item of [host,target]){
    const aria=item.getAttribute('aria-checked');
    if(aria==='true')signals.push(true);
    if(aria==='false')signals.push(false);
  }
  if(signals.length===0||signals.some(flag=>flag!==signals[0])){
    return {status:'UNVERIFIED'};
  }
  const x=rect.left+rect.width/2,y=rect.top+rect.height/2;
  if(!Number.isFinite(x)||!Number.isFinite(y)||x<0||y<0||x>=innerWidth||y>=innerHeight){
    return {status:'UNVERIFIED'};
  }
  return {status:'READY',checked:signals[0],point:{x,y}};
})())`;

async function readState(cdp) {
  try {
    const raw=await cdp.evaluate(STATE_EXPRESSION);
    const parsed=JSON.parse(raw||'{}');
    return parsed && typeof parsed==='object' ? parsed : {status:'UNVERIFIED'};
  } catch {
    return {status:'UNVERIFIED'};
  }
}

/**
 * @param {object} cdp - Existing authenticated canonical worker CDP session.
 * @returns {Promise<'SELECTED'|'MISSING'|'UNVERIFIED'>}
 */
export async function selectSafeTItemWithReadback(cdp, {pause=ms=>new Promise(r=>setTimeout(r,ms))}={}) {
  const initial=await readState(cdp);
  if(initial.status==='MISSING')return 'MISSING';
  if(initial.status!=='READY'||typeof initial.checked!=='boolean')return 'UNVERIFIED';
  if(initial.checked)return 'SELECTED';

  // First try the same KAT event path as the existing worker.
  if(await cdp.clickKat('kat-checkbox.QuantityCheckbox')!==true)return 'UNVERIFIED';
  await pause(450);
  const afterSynthetic=await readState(cdp);
  if(afterSynthetic.status==='READY'&&afterSynthetic.checked===true)return 'SELECTED';
  if(afterSynthetic.status!=='READY'||afterSynthetic.checked!==false)return 'UNVERIFIED';

  // A synthetic DOM click sometimes does not trigger the KAT component state.
  // Only one trusted mouse click may be retried, with a fresh unique visible
  // target and only when readback explicitly remains unchecked.
  const {x,y}=afterSynthetic.point||{};
  if(!Number.isFinite(x)||!Number.isFinite(y))return 'UNVERIFIED';
  await cdp.send('Input.dispatchMouseEvent',{type:'mouseMoved',x,y,button:'none'});
  await cdp.send('Input.dispatchMouseEvent',{type:'mousePressed',x,y,button:'left',clickCount:1});
  await cdp.send('Input.dispatchMouseEvent',{type:'mouseReleased',x,y,button:'left',clickCount:1});
  await pause(450);
  const afterTrusted=await readState(cdp);
  return afterTrusted.status==='READY'&&afterTrusted.checked===true?'SELECTED':'UNVERIFIED';
}
