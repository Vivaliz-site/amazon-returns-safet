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
test('a new review resets action date and scope to safe defaults',()=>{
  const controls={
    '#review-final-action':{value:'SAFE_T_APPEAL'},
    '#review-date-binding':{value:'APPEAL_DEADLINE'},
    '#review-scope':{value:'SIMILAR'},
    '#review-suggest':{disabled:true},
  };
  const state={reviewControlsDirty:true};
  const context={state,document:{querySelector:selector=>controls[selector]},updateReviewDecisionSummary:()=>{}};
  vm.createContext(context);
  const resetLine=functionLine('resetReviewControls');
  assert.ok(resetLine,'resetReviewControls helper is required');
  vm.runInContext(resetLine,context);
  context.resetReviewControls();
  assert.equal(controls['#review-final-action'].value,'CHECK_FINANCES');
  assert.equal(controls['#review-date-binding'].value,'NONE');
  assert.equal(controls['#review-scope'].value,'CASE_ONLY');
  assert.equal(state.reviewControlsDirty,false);
});
test('late recommendation from review A cannot mutate review B',async()=>{
  let resolvePost;
  const button={disabled:false};
  const state={selectedReview:1,expected_version:1,suggestion:null,reviewDecision:null};
  const context={state,suggestionRequestGeneration:0,document:{querySelector:selector=>selector==='#review-suggest'?button:{classList:{add(){}}}},csrf:()=>'',postJson:()=>new Promise(resolve=>{resolvePost=resolve;}),renderSuggestion:()=>{},applySuggestionToDecision:()=>{},resetReviewPreview:()=>{},showError:()=>{},friendlyError:value=>value,openReview:async()=>{},loadReviews:async()=>{},loadSummary:async()=>{}};
  vm.createContext(context);
  const suggestLine=source.split('\n').find(line=>line.startsWith('async function suggestReview('));
  assert.ok(suggestLine,'suggestReview function is required');
  vm.runInContext(suggestLine,context);
  const pending=context.suggestReview();
  state.selectedReview=2;
  state.expected_version=7;
  state.suggestion={action:'WAIT',parameters:{date_binding:'NONE'}};
  resolvePost({status:200,ok:true,data:{version:2,suggestion_available:true,suggestion:{action:'SAFE_T_APPEAL',parameters:{date_binding:'APPEAL_DEADLINE'}}}});
  await pending;
  assert.equal(state.selectedReview,2);
  assert.equal(state.expected_version,7);
  assert.equal(state.suggestion.action,'WAIT');
});