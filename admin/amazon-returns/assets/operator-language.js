function recommendationExplanation(suggestion){
  const action=actionLabel(suggestion?.action);
  return `Com os dados disponíveis, a ação mais segura é: ${action}.`;
}

function renderSuggestion(suggestion){
  const root=document.querySelector('#review-suggestion');
  root.replaceChildren();
  const section=text('section','', 'review-section recommendation');
  section.append(text('h3','Recomendação'));
  if(!suggestion){
    section.append(text('p','Ainda não há recomendação gerada. Clique em “Gerar recomendação” para analisar este caso.','muted'));
    root.append(section);
    return;
  }
  section.append(
    field('Ação recomendada',actionLabel(suggestion.action)),
    field('Grau de certeza',suggestion.confidence==null?'—':`${Math.round(Number(suggestion.confidence)*100)}%`),
    text('p',recommendationExplanation(suggestion))
  );
  if((suggestion.uncertainties||[]).length){
    section.append(text('p',`O que ainda precisa de atenção: ${suggestion.uncertainties.map(reasonLabel).join(' · ')}`,'muted'));
  }
  root.append(section);
}
