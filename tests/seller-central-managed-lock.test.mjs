import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const moduleUrl = new URL('../scripts/amazon-returns/seller-central-managed-lock.mjs', import.meta.url).href;
const evaluate = "import {managedCycleLockHeld} from " + JSON.stringify(moduleUrl) + ";console.log(managedCycleLockHeld())";

test('a spoofed managed flag without inherited locked FD9 is denied', () => {
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'safet-lock-negative-'));
  try {
    const result = spawnSync('node',['--input-type=module','-e',evaluate],{
      env:{...process.env,SELLER_CENTRAL_PROFILE:profile,SELLER_CENTRAL_ELIGIBILITY_MANAGED:'1'},
      encoding:'utf8',
    });
    assert.equal(result.status,0,result.stderr);
    assert.equal(result.stdout.trim(),'false');
  } finally { fs.rmSync(profile,{recursive:true,force:true}); }
});

test('only actual inherited FLOCK on canonical profile FD9 is accepted', () => {
  const profile=fs.mkdtempSync(path.join(os.tmpdir(), 'safet-lock-positive-'));
  try{
    const lockFile=path.join(profile,'seller-central-cycle.lock');
    const script='exec 9>"$1"; flock -n 9; SELLER_CENTRAL_PROFILE="$2" node --input-type=module -e "$3"';
    const result=spawnSync('bash',['-c',script,'bash',lockFile,profile,evaluate],{
      encoding:'utf8',
    });
    assert.equal(result.status,0,result.stderr);
    assert.equal(result.stdout.trim(),'true');
  } finally { fs.rmSync(profile,{recursive:true,force:true}); }
});

test('an override that does not point to the canonical browser lock is denied',()=>{
  const profile=fs.mkdtempSync(path.join(os.tmpdir(), 'safet-lock-mismatch-'));
  try{
    const other=path.join(profile,'other.lock');
    const script='exec 9>"$1"; flock -n 9; SELLER_CENTRAL_PROFILE="$2" SELLER_CENTRAL_CYCLE_LOCK_FILE="$1" node --input-type=module -e "$3"';
    const result=spawnSync('bash',['-c',script,'bash',other,profile,evaluate],{encoding:'utf8'});
    assert.equal(result.status,0,result.stderr);
    assert.equal(result.stdout.trim(),'false');
  } finally { fs.rmSync(profile,{recursive:true,force:true}); }
});
