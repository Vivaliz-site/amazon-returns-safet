# SAFE-T scoped operator core — certification gate (2026-11-07)

Status: **CORE IMPLEMENTED / 19 TESTS GREEN / PRODUCTION OPERATOR NOT CERTIFIED**.
This document supersedes **no** existing owner approval or Seller Central policy.

## Scope and existing grants

The owner approved two independent PRs in `Vivaliz-site/site-shopvivaliz`.
Their immutable authorizations must be verified from authenticated GitHub
review + merge, not from a supplied boolean or a checked-in JSON alone.

| Grant | Scoped cases | Exclusive expiry (UTC) |
| --- | --- | --- |
| #3556 | 1263,1314,8803,13210,51429,101669,101670,101671 | 2026-11-09 23:59 |
| #3673 | 42471,51429,101669,101670,101671 | 2026-11-30 23:59 |

Both refer to `activate_scoped_safe_t_opening`, Marina, service principal
`amazon-returns-safet-service`, tenant 1, connection 1, one-use and
`SAFE_T_SUBMIT` **alone**; the other six actions are always disabled.
Do not mix canaries, expand grants, reuse an already consumed grant or
re-submit case #8803 (existing external SAFE-T 64204-51742-6364222).

## What the new core actually implements

`scripts/amazon-returns/scoped-safe-t-operator.mjs` is an import-only,
fail-closed state machine: no CLI, systemd service, worker, timer, outbound
HTTP, database mutation, login automation, write-profile selection or kill
switch change is added by importing this module.

The exported `activateScopedSafeTOpening` requires a **trusted production
adapter** implementing, before any provider call:

1. Independent verification of `shopvivaliz-human` APPROVED on the correct
   HEAD, PR merged, canonical owner grant and exact version/expiry/case set.
2. Proof of the real Marina service principal, effective RBAC, and a
   separately installed crash-safe kill-switch rollback mechanism.
3. Exclusive single-writer lease with positive monotonic fencing and
   evidence that only the **existing** scoped worker can write.
4. Snapshot of every case in the approved canary (tenant/connection 1/1),
   rechecked before submission: D+45..89, supported program, confirmed
   Amazon-initiated refund, zero received quantity/NOT_RECEIVED, positive
   uncompensated debit, no SAFE-T or support requiring review.
5. **Independent live provider** evidence for warehouse nonreceipt, SP-API
   Finances debit, Amazon initiator, official Seller Central eligibility and
   absence of claim/support for the exact order. An empty filtered UI page
   does **not** certify absence; UNKNOWN initiator never passes.
6. Atomic durable one-use consumption **before** provider side effects.
   Any ambiguous result stops without automatic resubmission.
7. Bounded per-case invocation using the existing worker, an accepted
   external claim ID, independent post-submit readback, and full rollback
   verification in `finally`; a separate systemd crash/reboot fail-safe
   is mandatory because `finally` cannot survive power loss.

The adapter is **not installed or connected** in this PR. Synthetic
callbacks in tests are NOT a production authorization mechanism. A caller
must not claim the module is an operationally certified executor until
the production adapter has independently implemented all checks above.

## Deployment and validation gates

- The module is inert by default; the existing global kill switch remains
  **ON**, all seven write gates stay **OFF**.
- Tests: `node --test tests/scoped-safe-t-operator.test.mjs` and the
  already-existing `php tests/scoped-safe-t-pr3556-profile-test.php`.
- Before activation, implement and review the official adapter,
  independently verify source of each external attestation and GitHub
  provenance, design one-use atomic storage and crash recovery, prove
  single-writer contention and restore-on-SIGKILL/host reboot.
- Only after tests, CI, approved protected integration, immutable deploy,
  real UI Seller Central E2E and per-case no-duplicate evidence can the
  privileged service run a scoped one-use canary.
- Never change `/home/ubuntu/amazon-returns-deploy/current` in place.
  Never activate the existing default worker by merely selecting a
  write-profile JSON. Never treat health HTTP 200 as operational approval.

**Current verdict: NOT CERTIFIED FOR EXTERNAL SAFE-T WRITES.**
Record all later evidence in existing issue #532 without secrets or PII.

**Lease quarantine invariant:** if restoring or verifying the global kill
switch fails, the executor MUST NOT release the exclusive lease; it throws
`KILL_SWITCH_RESTORE_UNVERIFIED`. Independent crash recovery must then repair
the switch and reconcile the claim before a lease can be safely released.

## 2026-11-09 — renovacao #3846 e perfil PHP (apenas preparacao)

O grant #3846 foi aprovado e mesclado separadamente e renova somente a
canary de #3556. O nucleo Node o reconhecia, mas a configuracao PHP
rejeitava a versao ainda nao cadastrada. O perfil versionado
`safet-d45-pr3846-renewal-of-3556-v1` preserva oito IDs exatos, somente
`SAFE_T_SUBMIT` e nenhum novo canal. O vencimento de #3556 permanece
inalterado. O arquivo e a configuracao PHP nao selecionam perfil de
producao nem concedem permissao para desligar o kill switch.

Validacao: `php tests/scoped-safe-t-pr3846-profile-test.php` com cenarios
sinteticos de bloqueio, allowlist exata e acoes falsas. Sem adaptador
certificado, comprovacao independente de Amazon/warehouse/Finances,
lease/fencing, consumo duravel one-use e restore crash-safe do kill switch,
**nenhuma submissao e autorizada**. O status permanece NAO_APTO.
