import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync(new URL('../admin/amazon-returns/assets/cockpit.js',import.meta.url),'utf8');
const presentationSource=fs.readFileSync(new URL('../admin/amazon-returns/assets/review-presentation.js',import.meta.url),'utf8');
const functionLine=name=>source.split('\n').find(line=>line.startsWith(`function ${name}(`));
const presentationFunctionLine=name=>presentationSource.split('\n').find(line=>line.startsWith(`function ${name}(`));

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
  assert.equal(controls['#review-scope'].value,'SIMILAR');
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
test('decision mode preserves approval rejection wait edit and exception audit semantics',()=>{
  const decisionLine=functionLine('decisionFor');
  assert.ok(decisionLine,'decisionFor helper is required');
  const controls={'#review-final-action':{value:'SAFE_T_APPEAL'},'#review-date-binding':{value:'APPEAL_DEADLINE'},'#review-scope':{value:'SIMILAR'}};
  const state={suggestion:{action:'SAFE_T_APPEAL',parameters:{date_binding:'APPEAL_DEADLINE'}}};
  const context={state,document:{querySelector:selector=>controls[selector]}};vm.createContext(context);vm.runInContext(decisionLine,context);
  assert.equal(context.decisionFor().decision_mode,'APPROVED');
  controls['#review-final-action'].value='CHECK_FINANCES';controls['#review-date-binding'].value='NONE';
  assert.equal(context.decisionFor().decision_mode,'REJECTED');
  controls['#review-final-action'].value='SAFE_T_APPEAL';controls['#review-date-binding'].value='NONE';
  assert.equal(context.decisionFor().decision_mode,'EDITED_APPROVED');
  controls['#review-final-action'].value='WAIT';controls['#review-date-binding'].value='PROMISED_DATE';
  assert.equal(context.decisionFor().decision_mode,'WAIT');
  controls['#review-scope'].value='CASE_ONLY';
  assert.equal(context.decisionFor().decision_mode,'EXCEPTION');
});

test('case-only changes prepare the exception and enable confirmation immediately',()=>{
  const controls={
    '#review-final-action':{value:'CLOSE_LOSS'},
    '#review-date-binding':{value:'APPEAL_DEADLINE'},
    '#review-scope':{value:'CASE_ONLY'},
    '#review-confirm':{disabled:true},
  };
  const state={reviewControlsDirty:true,reviewDecision:null,suggestion:null};
  let renderedExceptionOnly=false;
  const context={
    state,
    document:{querySelector:selector=>controls[selector]},
    renderImpact:(_data,exceptionOnly)=>{renderedExceptionOnly=exceptionOnly;},
    updateReviewDecisionSummary:()=>{},
  };
  vm.createContext(context);
  vm.runInContext(functionLine('decisionFor'),context);
  vm.runInContext(presentationFunctionLine('prepareCaseOnlyReview'),context);
  assert.equal(context.prepareCaseOnlyReview(),true);
  assert.equal(state.reviewDecision?.decision_mode,'EXCEPTION');
  assert.equal(controls['#review-confirm'].disabled,false);
  assert.equal(renderedExceptionOnly,true);
});

test('review display text never exposes the reported English AI phrases',()=>{
  const helper=presentationFunctionLine('reviewDisplayText');
  assert.ok(helper,'reviewDisplayText helper is required');
  const mapsPrelude=source.split('\n').filter(line=>/^const (actionLabels|stateLabels|reasonLabels|physicalLabels|programLabels|statusLabels|sourceLabels|miscLabels)=/.test(line)).join('\n');
  const human=functionLine('humanText');
  const context={};
  vm.createContext(context);
  vm.runInContext(`${mapsPrelude}\n${human}\n${presentationSource.split('\n').find(line=>line.startsWith('const reviewPtBrExact='))}\n${presentationSource.split('\n').find(line=>line.startsWith('function reviewLooksEnglish('))}\n${helper}`,context);
  const rationale='The state is Em atendimento no Suporte ao Vendedor with a promised date and an appeal deadline. Since there are active wait conditions with explicit dates and policy review is required, we must wait until the promised date before taking further action.';
  const uncertainty='Promised date has not yet been reached';
  const policy='Policy eligible is currently false';
  const rendered=[context.reviewDisplayText(rationale,'Texto alternativo em português.'),context.reviewDisplayText(uncertainty,'Texto alternativo em português.'),context.reviewDisplayText(policy,'Texto alternativo em português.')];
  for(const text of rendered){
    assert.ok(text.length>0,'localized text must not be empty');
    assert.doesNotMatch(text,/\b(the|with|promised|deadline|since|there|conditions|policy|has|not|yet|been|reached|currently|false)\b/i);
  }
});
