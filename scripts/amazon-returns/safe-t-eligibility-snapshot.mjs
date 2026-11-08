/**
 * Observe the exact SAFE-T order result inside same-origin nested iframes.
 * A result from more than one document is ambiguous, never an approval.
 * Only read browser state: no Next, Submit, Send, or outbox action.
 */
export async function safeTEligibilitySnapshot(cdp, orderId) {
  const query = 'JSON.stringify((()=>{'
    + 'const id=' + JSON.stringify(orderId) + ';'
    + 'const docs=[];'
    + 'const visit=d=>{if(!d||docs.includes(d))return;docs.push(d);'
    + 'for(const f of d.querySelectorAll("iframe")){try{visit(f.contentDocument)}catch{}}};'
    + 'visit(document);'
    + 'const matches=docs.map(d=>{'
    + 'const body=d.body?.innerText||"";'
    + 'const inputs=[...d.querySelectorAll("kat-input,input")].map(h=>'
    + 'h.tagName==="KAT-INPUT"?h.shadowRoot?.querySelector("input"):h).filter(Boolean);'
    + 'const orderMatched=body.includes(id)||inputs.some(i=>i.value===id);'
    + 'const existingClaim=d.querySelector("kat-link.ClaimAlreadyExists")?.getAttribute("label")||"";'
    + 'const items=[...d.querySelectorAll("kat-checkbox.QuantityCheckbox")];'
    + 'const selectableCount=items.filter(h=>{'
    + 'const r=h.getBoundingClientRect();'
    + 'const inner=h.shadowRoot?.querySelector("input[type=checkbox],[role=checkbox]");'
    + 'return r.width>0&&r.height>0&&h.getClientRects().length>0'
    + '&&!h.disabled&&!h.hasAttribute("disabled")'
    + '&&h.getAttribute("aria-disabled")!=="true"'
    + '&&inner&&!inner.disabled&&!inner.hasAttribute("disabled");'
    + '}).length;'
    + 'return {orderMatched,existingClaim,itemCount:items.length,selectableCount,'
    + 'pageText:body.slice(0,12000)};'
    + '});'
    + 'const relevant=matches.filter(m=>m.orderMatched||m.existingClaim||m.itemCount>0);'
    + 'return relevant.length===1?relevant[0]:{ambiguous:true};'
    + '})())';
  try {
    const raw = await cdp.evaluate(query);
    const result = JSON.parse(raw || '{}');
    if (!result || typeof result !== 'object' || result.ambiguous === true) {
      return {ambiguous:true};
    }
    return {
      orderMatched: result.orderMatched === true,
      existingClaim: typeof result.existingClaim === 'string' ? result.existingClaim : '',
      itemCount: Number.isInteger(result.itemCount) ? result.itemCount : -1,
      selectableCount: Number.isInteger(result.selectableCount) ? result.selectableCount : -1,
      pageText: typeof result.pageText === 'string' ? result.pageText : '',
    };
  } catch {
    return {ambiguous:true};
  }
}
