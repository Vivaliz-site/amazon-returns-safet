const reviewPtBrExact={
  'The state is Em atendimento no Suporte ao Vendedor with a promised date and an appeal deadline. Since there are active wait conditions with explicit dates and policy review is required, we must wait until the promised date before taking further action.':'O caso está em atendimento no Suporte ao Vendedor, com uma data prometida e um prazo para recurso. Como existem condições de espera ativas com datas definidas e ainda há uma revisão necessária, devemos aguardar até a data prometida antes de tomar outra providência.',
  'Promised date has not yet been reached':'A data prometida pela Amazon ainda não foi atingida.',
  'Policy eligible is currently false':'O caso ainda não está elegível pela política aplicável.',
  'Amazon gave explicit date':'A Amazon informou uma data explícita para aguardar.'
};
function reviewLooksEnglish(value){const text=String(value||'').toLowerCase();const hits=text.match(/\b(the|state|with|promised|deadline|since|there|conditions|policy|required|must|wait|until|before|taking|further|action|has|not|yet|been|reached|currently|false|true|customer|refund|review|eligible)\b/g)||[];return hits.length>=2;}
function reviewDisplayText(value,fallback=''){const raw=String(value||'').replace(/\s+/g,' ').trim();if(!raw)return fallback;const exact=reviewPtBrExact[raw];if(exact)return exact;const normalized=humanText(raw);if(!normalized||normalized==='—'||normalized==='Informação não disponível')return fallback;if(reviewLooksEnglish(normalized))return fallback;return normalized;}
function normalizeReviewSuggestion(suggestion){if(!suggestion)return suggestion;const action=actionLabel(suggestion.action);const rationaleFallback=`Com os dados disponíveis, a ação recomendada é: ${action}.`;const uncertaintyFallback='Ainda existe uma condição que precisa ser confirmada antes de prosseguir.';return {...suggestion,rationale:reviewDisplayText(suggestion.rationale,rationaleFallback),uncertainties:(suggestion.uncertainties||[]).map(value=>reviewDisplayText(value,uncertaintyFallback)).filter(Boolean)};}
function prepareCaseOnlyReview(){if(document.querySelector('#review-scope')?.value!=='CASE_ONLY')return false;state.reviewDecision=decisionFor();renderImpact({},true);const confirm=document.querySelector('#review-confirm');if(confirm)confirm.disabled=false;updateReviewDecisionSummary();return true;}

const baseRenderSuggestion=renderSuggestion;
renderSuggestion=function(suggestion){return baseRenderSuggestion(normalizeReviewSuggestion(suggestion));};
for(const id of ['#review-final-action','#review-date-binding','#review-scope'])document.querySelector(id)?.addEventListener('change',()=>prepareCaseOnlyReview());
