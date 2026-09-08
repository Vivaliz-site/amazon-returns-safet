(()=>{
  'use strict';

  const reviewOrder=['O que aconteceu','O que já foi verificado','Por que preciso da sua decisão?','Mensagens trocadas'];

  function makeTimelineCollapsible(){
    const detail=document.querySelector('#case-detail');
    if(!detail)return;
    const timeline=detail.querySelector('.timeline');
    if(!timeline || timeline.closest('details.timeline-details'))return;
    const count=timeline.querySelectorAll('.timeline-item').length;
    const details=document.createElement('details');
    details.className='timeline-details';
    const summary=document.createElement('summary');
    const label=()=>details.open?`Ocultar histórico (${count})`:`Ver histórico (${count})`;
    summary.textContent=label();
    const heading=timeline.querySelector(':scope > h3');
    if(heading)heading.remove();
    timeline.before(details);
    details.append(summary,timeline);
    details.addEventListener('toggle',()=>{summary.textContent=label();});
  }

  function reorderReviewFacts(){
    const meta=document.querySelector('#review-meta');
    if(!meta)return;
    const sections=[...meta.querySelectorAll(':scope > .review-section')];
    if(sections.length<2)return;
    const titleOf=section=>section.querySelector('h3')?.textContent?.trim()||'';
    const current=sections.map(titleOf).filter(title=>reviewOrder.includes(title));
    const desired=reviewOrder.filter(title=>current.includes(title));
    if(current.join('|')===desired.join('|'))return;
    const byTitle=new Map(sections.map(section=>[titleOf(section),section]));
    for(const title of desired){const section=byTitle.get(title);if(section)meta.append(section);}
  }

  function apply(){
    makeTimelineCollapsible();
    reorderReviewFacts();
  }

  const observer=new MutationObserver(apply);
  const start=()=>{
    const main=document.querySelector('main');
    if(main)observer.observe(main,{childList:true,subtree:true});
    apply();
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
  else start();
})();
