/**
 * Authenticated GitHub owner approval verifier for SAFE-T grants #3556/#3673.
 * No persistent writes, no Amazon submission, no token in output.
 */
const BASE='https://api.github.com/repos/Vivaliz-site/site-shopvivaliz';
const GRANTS=Object.freeze({
  3556: Object.freeze({blob:'f340e8ce4be185bc1b8dd21b4e61269bc11afbd3',ids:[1263,1314,8803,13210,51429,101669,101670,101671],expiry:'2026-11-09T23:59:00Z'}),
  3673: Object.freeze({blob:'dfaee8e4a6dcb98a41e9087025835de36a07d5e1',ids:[42471,51429,101669,101670,101671],expiry:'2026-11-30T23:59:00Z'}),
});
export class OwnerGrantVerificationError extends Error {
  constructor(code){ super(code);this.name='OwnerGrantVerificationError';this.code=code; }
}
function expect(ok,code){if(!ok)throw new OwnerGrantVerificationError(code);}
function exactArray(raw,expected){return Array.isArray(raw)&&raw.length===expected.length&&new Set(raw).size===expected.length&&expected.every(id=>raw.includes(id));}
function validCommit(s){return typeof s==='string'&&/^[0-9a-f]{40}$/.test(s);}
async function githubJson(path,{fetchImpl,token}){
  let response;
  try {
    response=await fetchImpl(BASE+path,{method:'GET',redirect:'error',
      headers:{'Accept':'application/vnd.github+json','Authorization':'Bearer '+token,'X-GitHub-Api-Version':'2022-11-28'}});
  }catch{
    throw new OwnerGrantVerificationError('GITHUB_TRANSPORT_FAILURE');
  }
  expect(response?.status===200,'GITHUB_RESPONSE_NOT_AUTHENTICATED_OR_COMPLETE');
  try {return await response.json();}catch{throw new OwnerGrantVerificationError('GITHUB_INVALID_RESPONSE');}
}
function normalizeGrant(data,n,expected){
  expect(data&&typeof data==='object'&&!Array.isArray(data),'GRANT_MISSING');
  expect(data.schema==='SHOPVIVALIZ_OWNER_PR_GRANT_V1'&&data.repository==='Vivaliz-site/site-shopvivaliz'&&
    data.pr_number===n&&data.resource==='amazon-returns:safet:marina'&&
    data.principal==='amazon-returns-safet-service'&&data.host==='marina-a1-4c24g'&&
    data.target_repository==='Vivaliz-site/amazon-returns-safet'&&
    data.operation==='activate_scoped_safe_t_opening'&&data.tenant_id===1&&
    data.amazon_connection_id===1&&data.one_use===true&&data.expires_at_utc===expected.expiry&&
    exactArray(data.capabilities,['safe_t_submit'])&&
    exactArray(data.allowed_write_actions,['SAFE_T_SUBMIT'])&&
    exactArray(data.allowed_case_ids,expected.ids),'GRANT_SCOPE_INVALID');
  const required=['owner_approved_head_and_merged_pr','exact_host_tenant_connection_and_case_allowlist',
    'warehouse_physical_receipt_absent','refunded_by_amazon_confirmed',
    'seller_financial_credit_unrecovered','official_seller_central_eligibility_confirmed',
    'no_existing_safe_t_or_duplicate_support','external_claim_readback_and_idempotency','lease_fencing_and_rollback'];
  expect(exactArray(data.mandatory_guards,required),'GRANT_GUARDS_ALTERED');
  return data;
}
/**
 * Must run under the restricted production principal with a read-only
 * GitHub credential. An injected fetch is ONLY for deterministic tests.
 * GitHub must report the PR merged and the latest human review APPROVED
 * at the exact head commit. The approved grant blob is read at the merge
 * commit, never from an untrusted branch, env variable or the caller.
 */
export async function verifyApprovedOwnerGrant(grantNumber,{
  fetchImpl=globalThis.fetch, token=process.env.SHOPVIVALIZ_OWNER_GRANT_GITHUB_TOKEN,
  nowMs=Date.now(),
}={}){
  const expected=GRANTS[grantNumber];
  expect(Boolean(expected),'GRANT_NUMBER_UNKNOWN');
  expect(typeof token==='string'&&token.length>=12,'GITHUB_READONLY_CREDENTIAL_REQUIRED');
  expect(typeof fetchImpl==='function','GITHUB_CLIENT_REQUIRED');
  expect(Number.isFinite(nowMs)&&nowMs<Date.parse(expected.expiry),'GRANT_EXPIRED');
  const p=await githubJson('/pulls/'+grantNumber,{fetchImpl,token});
  expect(p?.number===grantNumber&&p?.base?.repo?.full_name==='Vivaliz-site/site-shopvivaliz'&&p?.base?.ref==='main'&&
    p?.head?.repo?.full_name==='Vivaliz-site/site-shopvivaliz'&&
    p?.state==='closed'&&p?.merged===true&&validCommit(p.head.sha)&&
    validCommit(p.merge_commit_sha)&&
    p?.user?.login==='fredmourao-ai'&&typeof p?.merged_at==='string'&&
    Number.isFinite(Date.parse(p.merged_at)),'OWNER_PR_NOT_MERGED');
  let approved=null,previous=null,fullPages=0;
  for(let page=1;page<=10;page++){
    const list=await githubJson('/pulls/'+grantNumber+'/reviews?per_page=100&page='+page,{fetchImpl,token});
    expect(Array.isArray(list)&&list.length<=100,'REVIEW_HISTORY_INCOMPLETE');
    for(const review of list){
      if(review?.user?.login!=='shopvivaliz-human')continue;
      expect(typeof review?.submitted_at==='string'&&Number.isFinite(Date.parse(review.submitted_at)),
        'REVIEW_TIMESTAMP_UNVERIFIED');
      const key=Date.parse(review.submitted_at);
      // GitHub returns one review id per submission; ties must also be deterministic.
      expect(Number.isSafeInteger(review.id)&&review.id>0,'REVIEW_ID_UNVERIFIED');
      const ordinal=[key,review.id];
      if(previous===null||ordinal[0]>previous[0]||(ordinal[0]===previous[0]&&ordinal[1]>previous[1])){
        previous=ordinal;
        approved=review;
      }
    }
    if(list.length<100)break;
    fullPages++;
    if(page===10)throw new OwnerGrantVerificationError('REVIEW_HISTORY_INCOMPLETE');
  }
  expect(approved?.state==='APPROVED'&&approved?.commit_id===p.head.sha&&
    Date.parse(approved.submitted_at)<=Date.parse(p.merged_at),'OWNER_HEAD_NOT_APPROVED');
  const obj=await githubJson('/contents/security/owner-approvals/'+grantNumber+'.json?ref='+p.merge_commit_sha,{fetchImpl,token});
  expect(obj?.type==='file'&&obj?.encoding==='base64'&&obj?.sha===expected.blob&&
    typeof obj.content==='string'&&obj.content.length>0,'CANONICAL_GRANT_BLOB_MISMATCH');
  let raw;
  try{raw=JSON.parse(Buffer.from(obj.content.replace(/\s+/g,''),'base64').toString('utf8'));}
  catch{throw new OwnerGrantVerificationError('GRANT_JSON_INVALID');}
  const grant=normalizeGrant(raw,grantNumber,expected);
  return {verifiedBy:'canonical-github-owner-approval-verifier',owner:'shopvivaliz-human',
    approvedOnHead:true,merged:true,grantNumber,grant};
}
