/** Read-only interpretation of the official SAFE-T eligibility screen. */
const ORDER_ID=/^\d{3}-\d{7}-\d{7}$/;
const CLAIM_ID=/^\d{5}-\d{5}-\d{7}$/;

export function classifySafeTEligibility({
  orderId,
  existingClaim='',
  itemCount=0,
  selectableCount=0,
  pageText='',
  exactOrderChecked=false,
}={}) {
  if(typeof orderId!=='string'||!ORDER_ID.test(orderId)) {
    return {status:'FAILED',reason:'ORDER_ID_INVALID'};
  }
  if(exactOrderChecked!==true) {
    return {status:'UI_DRIFT',reason:'SAFE_T_ELIGIBILITY_ORDER_UNVERIFIED'};
  }
  const claim=String(existingClaim||'').trim();
  if(claim) {
    if(!CLAIM_ID.test(claim)) return {status:'UI_DRIFT',reason:'SAFE_T_EXISTING_CLAIM_ID_UNVERIFIED'};
    return {status:'ALREADY_EXISTS',reason:'SAFE_T_EXISTING_CLAIM_FOUND',external_id:claim};
  }
  const text=String(pageText||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  if(text.includes('excedeu 75 dias')||text.includes('exceeded 75 days')) {
    return {status:'NOT_ELIGIBLE',reason:'SAFE_T_OFFICIAL_75_DAY_WINDOW'};
  }
  if(!Number.isInteger(itemCount)||itemCount<0||itemCount>100) {
    return {status:'UI_DRIFT',reason:'SAFE_T_ELIGIBILITY_ITEM_COUNT_INVALID'};
  }
  if(itemCount===1) {
    if(selectableCount!==1) return {status:'UI_DRIFT',reason:'SAFE_T_ITEM_NOT_SELECTABLE'};
    return {status:'ELIGIBLE',reason:'SAFE_T_OFFICIAL_ITEM_PRESENT'};
  }
  if(itemCount>1) return {status:'REVIEW_REQUIRED',reason:'SAFE_T_MULTIPLE_ITEMS_REQUIRE_SELECTION'};
  return {status:'UI_DRIFT',reason:'SAFE_T_ELIGIBILITY_NOT_CONFIRMED'};
}
