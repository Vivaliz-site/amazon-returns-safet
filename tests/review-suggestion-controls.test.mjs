import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync(new URL('../admin/amazon-returns/assets/cockpit.js',import.meta.url),'utf8');
const functionLine=name=>source.split('\n').find(line=>line.startsWith(`function ${name}(`));

function harness(dirty){
  const controls={'#review-final-action':{value:'WAIT'},'#review-date-binding':{value:'NONE'}};
  const state={reviewControlsDirty:dirty,suggestion:{action:'SAFE_T_APPEAL',parameters:{date_binding:'APPEAL_DEADLINE'}}};
  const context={state,document:{querySelector:selector=>controls[selector]},updateReviewDecisionSummary:()=>{}};
  vm.createContext(context);
  vm.runInContext(functionLine('applySuggestionToDecision'),context);
  return {context,controls};
}

test('automatic recommendation preserves an operator choice already edited',()=>{
  const {context,controls}=harness(true);
  context.applySuggestionToDecision();
  assert.equal(controls['#review-final-action'].value,'WAIT');
  assert.equal(controls['#review-date-binding'].value,'NONE');
});

test('automatic recommendation prefills untouched controls',()=>{
  const {context,controls}=harness(false);
  context.applySuggestionToDecision();
  assert.equal(controls['#review-final-action'].value,'SAFE_T_APPEAL');
  assert.equal(controls['#review-date-binding'].value,'APPEAL_DEADLINE');
});