import assert from 'node:assert/strict';
import test from 'node:test';
import path from 'node:path';
import { resolveEvidenceDir } from '../scripts/amazon-returns/TrackingEvidence.mjs';

test('resolveEvidenceDir respects SELLER_CENTRAL_EVIDENCE_DIR when set', () => {
  const origEvidence = process.env.SELLER_CENTRAL_EVIDENCE_DIR;
  const origProfile = process.env.SELLER_CENTRAL_PROFILE;
  try {
    process.env.SELLER_CENTRAL_EVIDENCE_DIR = '/custom/evidence/dir';
    delete process.env.SELLER_CENTRAL_PROFILE;
    assert.equal(resolveEvidenceDir(), '/custom/evidence/dir');
  } finally {
    if (origEvidence !== undefined) process.env.SELLER_CENTRAL_EVIDENCE_DIR = origEvidence;
    else delete process.env.SELLER_CENTRAL_EVIDENCE_DIR;
    if (origProfile !== undefined) process.env.SELLER_CENTRAL_PROFILE = origProfile;
    else delete process.env.SELLER_CENTRAL_PROFILE;
  }
});

test('resolveEvidenceDir defaults to profile parent evidence dir when profile is set', () => {
  const origEvidence = process.env.SELLER_CENTRAL_EVIDENCE_DIR;
  const origProfile = process.env.SELLER_CENTRAL_PROFILE;
  try {
    delete process.env.SELLER_CENTRAL_EVIDENCE_DIR;
    process.env.SELLER_CENTRAL_PROFILE = '/home/ubuntu/app/profile';
    const resolved = resolveEvidenceDir();
    assert.equal(resolved, path.join('/home/ubuntu/app', 'evidence'));
  } finally {
    if (origEvidence !== undefined) process.env.SELLER_CENTRAL_EVIDENCE_DIR = origEvidence;
    else delete process.env.SELLER_CENTRAL_EVIDENCE_DIR;
    if (origProfile !== undefined) process.env.SELLER_CENTRAL_PROFILE = origProfile;
    else delete process.env.SELLER_CENTRAL_PROFILE;
  }
});

test('resolveEvidenceDir does not hardcode Windows C: drive when environment unset', () => {
  const origEvidence = process.env.SELLER_CENTRAL_EVIDENCE_DIR;
  const origProfile = process.env.SELLER_CENTRAL_PROFILE;
  try {
    delete process.env.SELLER_CENTRAL_EVIDENCE_DIR;
    delete process.env.SELLER_CENTRAL_PROFILE;
    const resolved = resolveEvidenceDir();
    assert.equal(resolved, path.join(process.cwd(), 'evidence'));
    if (process.platform !== 'win32') {
      assert.ok(!resolved.startsWith('C:'), 'Must not start with C: on non-Windows platforms');
    }
  } finally {
    if (origEvidence !== undefined) process.env.SELLER_CENTRAL_EVIDENCE_DIR = origEvidence;
    else delete process.env.SELLER_CENTRAL_EVIDENCE_DIR;
    if (origProfile !== undefined) process.env.SELLER_CENTRAL_PROFILE = origProfile;
    else delete process.env.SELLER_CENTRAL_PROFILE;
  }
});
