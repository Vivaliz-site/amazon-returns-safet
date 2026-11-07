import test from 'node:test';
import assert from 'node:assert/strict';
import { verifyApprovedOwnerGrant, OwnerGrantVerificationError } from '../scripts/amazon-returns/scoped-safe-t-github-grant-verifier.mjs';

const NOW=Date.parse('2026-11-07T12:00:00Z');
const TOKEN='synthetic-readonly-token';
const SHA='c'.repeat(40),MERGE='e'.repeat(40);
const scopes={
  3556:{blob:'f340e8ce4be185bc1b8dd21b4e61269bc11afbd3',ids:[1263,1314,8803,13210,51429,101669,101670,101671],expiry:'2026-11-09T23:59:00Z'},
  3673:{blob:'dfaee8e4a6dcb98a41e9087025835de36a07d5e1',ids:[42471,51429,101669,101670,101671],expiry:'2026-11-30T23:59:00Z'},
};
const guards=['owner_approved_head_and_merged_pr','exact_host_tenant_connection_and_case_allowlist',
  'warehouse_physical_receipt_absent','refunded_by_amazon_confirmed','seller_financial_credit_unrecovered',
  'official_seller_central_eligibility_confirmed','no_existing_safe_t_or_duplicate_support',
  'external_claim_readback_and_idempotency','lease_fencing_and_rollback'];
const grant=n=>({schema:'SHOPVIVALIZ_OWNER_PR_GRANT_V1',repository:'Vivaliz-site/site-shopvivaliz',
  pr_number:n,resource:'amazon-returns:safet:marina',principal:'amazon-returns-safet-service',
  host:'marina-a1-4c24g',target_repository:'Vivaliz-site/amazon-returns-safet',
  capabilities:['safe_t_submit'],operation:'activate_scoped_safe_t_opening',
  tenant_id:1,amazon_connection_id:1,allowed_case_ids:[...scopes[n].ids],
  allowed_write_actions:['SAFE_T_SUBMIT'],one_use:true,
  expires_at_utc:scopes[n].expiry,mandatory_guards:[...guards]});
const pr=n=>({number:n,base:{ref:'main',repo:{full_name:'Vivaliz-site/site-shopvivaliz'}},
  head:{repo:{full_name:'Vivaliz-site/site-shopvivaliz'},sha:SHA},
  state:'closed',merged:true,merge_commit_sha:MERGE,user:{login:'fredmourao-ai'},
  merged_at:'2026-11-05T14:00:00Z'});
const review=()=>({id:17,user:{login:'shopvivaliz-human'},state:'APPROVED',
  commit_id:SHA,submitted_at:'2026-11-05T13:50:00Z'});
function make(n,change=()=>{}){
  const data={pr:pr(n),reviews:[review()],blob:scopes[n].blob,grant:grant(n),status:200};
  change(data);
  const calls=[];
  const fetchImpl=async (href,opts)=>{
    const u=new URL(href);calls.push(u.pathname+u.search);
    assert.equal(opts.headers.Authorization,'Bearer '+TOKEN);
    if(data.status!==200) return new Response('{}',{status:data.status});
    if(u.pathname.endsWith('/pulls/'+n))return Response.json(data.pr);
    if(u.pathname.endsWith('/pulls/'+n+'/reviews'))return Response.json(data.reviews);
    if(u.pathname.endsWith('/owner-approvals/'+n+'.json'))
      return Response.json({type:'file',sha:data.blob,encoding:'base64',content:Buffer.from(JSON.stringify(data.grant)).toString('base64')});
    throw new Error('Unexpected network request');
  };
  return {data,calls,fetchImpl};
}
const run=(n,b,nowMs=NOW)=>verifyApprovedOwnerGrant(n,{fetchImpl:b.fetchImpl,token:TOKEN,nowMs});
const fails=(fn,code)=>assert.rejects(fn,error=>error instanceof OwnerGrantVerificationError && (typeof code==='function' ? code(error) : error.code===code));

for(const n of [3556,3673]){
  test('authenticated exact head approval, merged and pinned grant for #'+n,async()=>{
    const b=make(n);const actual=await run(n,b);
    assert.equal(actual.verifiedBy,'canonical-github-owner-approval-verifier');
    assert.equal(actual.owner,'shopvivaliz-human');assert.equal(actual.grantNumber,n);
    assert.deepEqual(actual.grant.allowed_case_ids,scopes[n].ids);
    assert.equal(b.calls.length,3);
    assert(b.calls[2].includes('?ref='+MERGE));
  });
}
test('unknown grant/expired grant/missing credential fail before any HTTP',async()=>{
  const b=make(3556);
  await fails(()=>run(999,b),'GRANT_NUMBER_UNKNOWN');
  await fails(()=>run(3556,b,Date.parse(scopes[3556].expiry)),'GRANT_EXPIRED');
  await fails(()=>verifyApprovedOwnerGrant(3556,{fetchImpl:b.fetchImpl,token:''}),
    'GITHUB_READONLY_CREDENTIAL_REQUIRED');
  assert.equal(b.calls.length,0);
});
test('GitHub errors and network timeouts fail closed',async()=>{
  const b=make(3556,x=>x.status=401);await fails(()=>run(3556,b),'GITHUB_RESPONSE_NOT_AUTHENTICATED_OR_COMPLETE');
  const fail=make(3556);fail.fetchImpl=async()=>{throw Error('token secret not printed')};
  await fails(()=>run(3556,fail),'GITHUB_TRANSPORT_FAILURE');
});
test('PR must have current merged owner head and known author',async()=>{
  for(const change of [
    x=>x.pr.merged=false,x=>x.pr.state='open',x=>x.pr.user.login='other',
    x=>x.pr.base.repo.full_name='other/repo',x=>x.pr.base.ref='staging',x=>x.pr.head.repo.full_name='other/repo',
    x=>x.pr.head.sha='short',x=>x.pr.merge_commit_sha='short',x=>x.pr.merged_at=null
  ]){
    const b=make(3556,x=>change(x));
    await fails(()=>run(3556,b),'OWNER_PR_NOT_MERGED');
  }
});
test('owner grant merged into noncanonical base must not authorize SAFE-T',async()=>{
  const b=make(3556,x=>x.pr.base.ref='staging');
  await fails(()=>run(3556,b),'OWNER_PR_NOT_MERGED');
});
test('review must be latest explicit approval on exact head before merge',async()=>{
  for(const change of [
    x=>x.reviews[0].state='DISMISSED',
    x=>x.reviews[0].state='COMMENTED',
    x=>x.reviews[0].commit_id='b'.repeat(40),
    x=>x.reviews[0].user.login='fredmourao-ai',
    x=>x.reviews[0].submitted_at='2026-11-06T12:00:00Z',
    x=>x.reviews.push({...review(),id:18,state:'CHANGES_REQUESTED',submitted_at:'2026-11-05T13:55:00Z'})
  ]){
    const b=make(3556,x=>change(x));
    await fails(()=>run(3556,b),'OWNER_HEAD_NOT_APPROVED');
  }
});
test('review history pagination does not silently accept incompleteness',async()=>{
  const b=make(3556,x=>x.reviews=Array(100).fill(null).map((_,i)=>({...review(),id:i+1})));
  await fails(()=>run(3556,b),'REVIEW_HISTORY_INCOMPLETE');
});
test('cannot swap pinned canonical grant blob, even if JSON looks valid',async()=>{
  const b=make(3556,x=>x.blob=scopes[3673].blob);
  await fails(()=>run(3556,b),'CANONICAL_GRANT_BLOB_MISMATCH');
});
test('cannot broaden/narrow/mix case allowlists',async()=>{
  for(const change of [
    x=>x.grant.allowed_case_ids.push(42471),x=>x.grant.allowed_case_ids.pop(),
    x=>x.grant.allowed_case_ids[1]=x.grant.allowed_case_ids[0]
  ]){
    const b=make(3556,x=>change(x));
    await fails(()=>run(3556,b),'GRANT_SCOPE_INVALID');
  }
});
test('no grant with changed scope or write actions or expiration',async()=>{
  for(const change of [
    x=>x.grant.operation='other',x=>x.grant.host='other',x=>x.grant.tenant_id=2,
    x=>x.grant.principal='root',x=>x.grant.allowed_write_actions.push('SELLER_SUPPORT_OPEN'),
    x=>x.grant.one_use=false,x=>x.grant.expires_at_utc='2099-01-01T00:00:00Z',
    x=>x.grant.mandatory_guards.pop()
  ]){
    const b=make(3673,x=>change(x));
    await fails(()=>run(3673,b),x=>x.code==='GRANT_SCOPE_INVALID'||x.code==='GRANT_GUARDS_ALTERED');
  }
});
test('expired owner PR with stale head approval cannot be accepted by later credentials',async()=>{
  const b=make(3556,x=>x.reviews.push({...review(),id:20,state:'DISMISSED',submitted_at:'2026-11-05T13:59:00Z'}));
  await fails(()=>run(3556,b),'OWNER_HEAD_NOT_APPROVED');
});
