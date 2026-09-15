(()=>{
  'use strict';
  const reviewOrder=['Qual decisão precisa ser tomada?','O que aconteceu','O que já foi verificado','Mensagens trocadas','Impacto financeiro e prazo'];
  function reorderReviewFacts(){
    const meta=document.querySelector('#review-meta');if(!meta)return;
    const sections=[...meta.querySelectorAll(':scope > .review-section')];if(sections.length<2)return;
    const titleOf=section=>section.querySelector('h3')?.textContent?.trim()||'';
    const byTitle=new Map(sections.map(section=>[titleOf(section),section]));
    for(const title of reviewOrder){const section=byTitle.get(title);if(section)meta.append(section);}
  }
  const observer=new MutationObserver(reorderReviewFacts);
  const start=()=>{const main=document.querySelector('main');if(main)observer.observe(main,{childList:true,subtree:true});reorderReviewFacts();};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
