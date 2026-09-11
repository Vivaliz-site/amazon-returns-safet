function recommendationExplanation(suggestion){
  const rationale=humanText(suggestion?.rationale||'');
  if(rationale&&rationale!=='—'&&rationale!=='Informação não disponível')return rationale;
  return `Com os dados disponíveis, a ação mais segura é: ${actionLabel(suggestion?.action)}.`;
}

function renderSuggestion(suggestion){
  const root=document.querySelector('#review-suggestion');
  root.replaceChildren();
  const section=text('section','', 'review-section recommendation');
  section.append(text('h3','Recomendação'));
  if(!suggestion){
    section.append(text('p','Ainda não há recomendação gerada. Clique em “Gerar recomendação” para analisar este caso.','muted'));
    root.append(section,renderReviewLearningImpact());
    return;
  }
  section.append(
    field('Ação recomendada',actionLabel(suggestion.action)),
    field('Grau de certeza',reviewConfidence(suggestion.confidence)),
    text('p',recommendationExplanation(suggestion))
  );
  const uncertainties=(suggestion.uncertainties||[])
    .map(reasonLabel)
    .filter(value=>value&&value!=='—'&&value!=='Informação não disponível');
  if(uncertainties.length){
    section.append(text('p',`O que ainda precisa de atenção: ${uncertainties.join(' · ')}`,'muted'));
  }
  root.append(section,renderReviewLearningImpact());
}
