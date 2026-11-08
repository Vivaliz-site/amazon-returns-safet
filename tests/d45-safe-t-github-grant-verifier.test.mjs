import test from 'node:test';
import assert from 'node:assert/strict';
import { verifyApprovedOwnerGrant, OwnerGrantVerificationError }
  from '../scripts/amazon-returns/scoped-safe-t-github-grant-verifier.mjs';
import { verifyD45AutoGrant } from '../scripts/amazon-returns/d45-safe-t-auto-activation.mjs';

const SHA='2deb06ee6093d76f90da5a287c7bc75108565255';
const MERGE='28dfcf64b7d3fc2018707274b00f6e4cbcd1ee2b';
const BLOB='08dc564e79a42e537efc21bd014e26d97ba0a901';
const TOKEN='synthetic-readonly-token';
const NOW=Date.parse('2026-11-08T12:46:00Z');
const GRANT={
  "schema": "SHOPVIVALIZ_OWNER_PR_GRANT_V1",
  "repository": "Vivaliz-site/site-shopvivaliz",
  "pr_number": 3830,
  "resource": "amazon-returns:safet:marina",
  "principal": "amazon-returns-safet-service",
  "host": "marina-a1-4c24g",
  "target_repository": "Vivaliz-site/amazon-returns-safet",
  "capabilities": [
    "safe_t_submit"
  ],
  "operation": "activate_d45_safe_t_automation",
  "tenant_id": 1,
  "amazon_connection_id": 1,
  "one_use": true,
  "expires_at_utc": "2026-11-30T23:59:00Z",
  "allowed_write_actions": [
    "SAFE_T_SUBMIT"
  ],
  "other_write_actions_denied": [
    "SAFE_T_APPEAL",
    "SAFE_T_EMAIL_REVIEW",
    "SAFE_T_EMAIL_REPLY",
    "SELLER_SUPPORT_OPEN",
    "SELLER_SUPPORT_UPDATE",
    "ERP_SALES_RETURN_CREATE"
  ],
  "automatic_future_cases": true,
  "min_days_since_refund": 45,
  "max_days_since_refund_exclusive": 90,
  "max_new_claims_total": 20,
  "max_new_claims_per_utc_day": 5,
  "excluded_case_ids": [
    8803
  ],
  "existing_grants_unmodified": [
    3556,
    3673
  ],
  "activation_requires_certified_production_adapter": true,
  "mandatory_guards": [
    "owner_approved_head_and_merged_pr",
    "exact_host_tenant_connection_and_service_principal",
    "fba_onsite_or_delivery_by_amazon_program_only",
    "first_official_eligibility_check_at_d45",
    "amazon_provider_specific_wait_and_eligibility_honored",
    "no_new_opening_on_or_after_d90",
    "warehouse_physical_receipt_absent",
    "refunded_by_amazon_confirmed",
    "seller_financial_credit_unrecovered",
    "official_seller_central_eligibility_confirmed",
    "no_existing_safe_t_or_duplicate_support",
    "external_claim_readback_and_idempotency",
    "per_case_durable_fencing_and_lease_single_writer",
    "one_use_activation_and_max_claim_counts_durable",
    "kill_switch_on_by_default_and_restored_even_on_crash",
    "certified_immutable_deploy_and_real_ui_e2e"
  ]
};
const originalPR=()=>({
  number:3830,
  base:{ref:'main',repo:{full_name:'Vivaliz-site/site-shopvivaliz'}},
  head:{sha:SHA,repo:{full_name:'Vivaliz-site/site-shopvivaliz'}},
  state:'closed',merged:true,merged_by:{login:'shopvivaliz-human'},
  user:{login:'fredmourao-ai'},merge_commit_sha:MERGE,
  merged_at:'2026-11-08T12:45:53Z'
});
const review=()=>({
  id:90001,user:{login:'shopvivaliz-human'},state:'APPROVED',
  commit_id:SHA,submitted_at:'2026-11-08T12:43:16Z'
});
function rig(change=()=>{}){
  const state={pr:originalPR(),reviews:[review()],grant:structuredClone(GRANT),
    sha:BLOB,mainSha:BLOB,status:200,calls:[]};
  change(state);
  const fetchImpl=async(url,opts)=>{
    const u=new URL(url);state.calls.push(u.pathname+u.search);
    assert.equal(opts.headers.Authorization,'Bearer '+TOKEN);
    if(state.status!==200)return Response.json({}, {status:state.status});
    if(u.pathname.endsWith('/pulls/3830'))return Response.json(state.pr);
    if(u.pathname.endsWith('/pulls/3830/reviews'))return Response.json(state.reviews);
    if(u.pathname.endsWith('/owner-approvals/3830.json')){
      if(u.searchParams.get('ref')==='main')
        return Response.json({type:'file',sha:state.mainSha});
      return Response.json({type:'file',sha:state.sha,encoding:'base64',
        content:Buffer.from(JSON.stringify(state.grant)).toString('base64')});
    }
    throw Error('Unexpected GitHub API path');
  };
  return {state,fetchImpl};
}
const run=(b,nowMs=NOW)=>verifyApprovedOwnerGrant(3830,{
  fetchImpl:b.fetchImpl,token:TOKEN,nowMs
});
const fails=(cb,code)=>assert.rejects(cb,e=>e instanceof OwnerGrantVerificationError&&e.code===code);

test('P2 #3830 requires actual merged and exact SHA-approved human grant',async()=>{
  const b=rig();const verified=await run(b);
  assert.equal(verified.verifiedBy,'canonical-github-owner-approval-verifier');
  assert.equal(verified.owner,'shopvivaliz-human');
  assert.equal(verified.approvedOnHead,true);
  assert.equal(verified.grantNumber,3830);
  assert.equal(verifyD45AutoGrant(verified,NOW),true);
  assert.equal(b.state.calls.length,4);
  assert(b.state.calls[2].includes('?ref='+MERGE));
  assert(b.state.calls[3].includes('?ref=main'));
});
test('an open grant, wrong merged_by or unapproved SHA fails before grant read',async()=>{
  for(const change of [
    s=>s.pr.merged=false,s=>s.pr.merged_by.login='fredmourao-ai',
    s=>s.reviews[0].commit_id='c'.repeat(40),
    s=>s.reviews[0].state='DISMISSED',s=>s.pr.head.sha='short'
  ]){
    const b=rig(change);
    await assert.rejects(()=>run(b),e=>e instanceof OwnerGrantVerificationError);
    assert(b.state.calls.length<4);
  }
});
test('live grant removal/revision on main revokes P2 activation',async()=>{
  const b=rig(s=>s.mainSha='a'.repeat(40));
  await fails(()=>run(b),'OWNER_GRANT_REVOKED_OR_CHANGED');
});
test('merge-commit grant blob must be exactly the approved SHA',async()=>{
  const b=rig(s=>s.sha='a'.repeat(40));
  await fails(()=>run(b),'CANONICAL_GRANT_BLOB_MISMATCH');
});
test('scope broadening or policy shift is always denied',async()=>{
  const changes=[
    s=>s.grant.allowed_write_actions.push('SAFE_T_APPEAL'),
    s=>s.grant.excluded_case_ids=[],
    s=>s.grant.host='another-host',
    s=>s.grant.max_new_claims_total=21,
    s=>s.grant.max_new_claims_per_utc_day=6,
    s=>s.grant.operation='activate_scoped_safe_t_opening',
    s=>s.grant.min_days_since_refund=60,
    s=>s.grant.max_days_since_refund_exclusive=100,
    s=>s.grant.automatic_future_cases=false,
    s=>s.grant.tenant_id=2,
  ];
  for(const change of changes){
    const b=rig(change);
    await fails(()=>run(b),'GRANT_SCOPE_INVALID');
  }
});
test('mandatory guards are immutable and complete',async()=>{
  const b=rig(s=>s.grant.mandatory_guards.pop());
  await fails(()=>run(b),'GRANT_GUARDS_ALTERED');
});
test('review revocation takes precedence over old approval',async()=>{
  const b=rig(s=>s.reviews.push({...review(),id:90002,state:'CHANGES_REQUESTED',submitted_at:'2026-11-08T12:44:00Z'}));
  await fails(()=>run(b),'OWNER_HEAD_NOT_APPROVED');
});
test('expired P2 grant cannot use stored approval',async()=>{
  const b=rig();
  await fails(()=>run(b,Date.parse('2026-11-30T23:59:00Z')),'GRANT_EXPIRED');
  assert.equal(b.state.calls.length,0);
});
test('missing authenticated GitHub read token or transport denial blocks activation',async()=>{
  const b=rig();
  await fails(()=>verifyApprovedOwnerGrant(3830,{fetchImpl:b.fetchImpl,token:'',nowMs:NOW}),
    'GITHUB_READONLY_CREDENTIAL_REQUIRED');
  assert.equal(b.state.calls.length,0);
  const noauth=rig(s=>s.status=401);
  await fails(()=>run(noauth),'GITHUB_RESPONSE_NOT_AUTHENTICATED_OR_COMPLETE');
});
