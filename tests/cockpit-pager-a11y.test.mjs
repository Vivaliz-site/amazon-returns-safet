import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const js=readFileSync(new URL('../admin/amazon-returns/assets/cockpit.js',import.meta.url),'utf8');
const php=readFileSync(new URL('../admin/amazon-returns/index.php',import.meta.url),'utf8');

function makePager(page) {
  const start=js.indexOf('function renderPager(');
  const end=js.indexOf('async function loadCases(',start);
  assert.ok(start>=0&&end>start,'a paginação real deve existir');
  const pager={children:[],replaceChildren(){this.children=[];},append(...children){this.children.push(...children);}};
  let calls=0;
  const element=(tag,value='')=>({
    tag,textContent:value,disabled:false,attrs:{},
    setAttribute(name,value){this.attrs[name]=value;},
  });
  const context={
    state:{page},
    document:{querySelector(selector){assert.equal(selector,'#pager');return pager;}},
    button(label,handler){return {...element('button',label),click:handler};},
    text:element,
    loadCases(){calls++;},
  };
  vm.createContext(context);
  vm.runInContext(js.slice(start,end),context);
  return {pager,context,calls:()=>calls};
}

test('paginação anuncia alterações sem depender da visão',()=>{
  const tag=php.match(/<div id="pager"[^>]*>/)?.[0]||'';
  assert.match(tag,/aria-live="polite"/);
  assert.match(tag,/aria-atomic="true"/);
});

test('navegação marca limites e anuncia destino de cada página',()=>{
  const {pager,context,calls}=makePager(1);
  context.renderPager(120,50);
  const [previous,status,next]=pager.children;
  assert.equal(status.textContent,'1 / 3');
  assert.equal(previous.disabled,true);
  assert.equal(next.disabled,false);
  assert.match(next.attrs['aria-label']||'',/página 2 de 3/i);
  next.click();
  assert.equal(context.state.page,2);
  assert.equal(calls(),1);
  context.renderPager(120,50);
  assert.equal(pager.children[0].disabled,false);
  assert.match(pager.children[0].attrs['aria-label']||'',/página 1 de 3/i);
  context.state.page=3;
  context.renderPager(120,50);
  assert.equal(pager.children[2].disabled,true);
  assert.equal(pager.children[0].disabled,false);
});

test('resultado vazio mantém contador estável e botões sem ação',()=>{
  const {pager,context}=makePager(1);
  context.renderPager(0,50);
  assert.equal(pager.children[1].textContent,'1 / 1');
  assert.equal(pager.children[0].disabled,true);
  assert.equal(pager.children[2].disabled,true);
});
