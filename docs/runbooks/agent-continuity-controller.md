# Global Agent Continuity Controller runbook

## Purpose and safety boundary

The controller makes interrupted agent work discoverable and resumable without treating a chat session as the source of truth. A task is complete only at `MERGED + VERIFIED`, or at an explicit external blocker. Initial rollout is audit-only and `auto_dispatch=false`.

Never use this controller to run `git reset --hard`, `git clean`, force checkout, force push, or to overwrite an existing worktree. Never put credentials, cookies, private keys, TOTP seeds, message bodies, or other secrets in the config, ledger, logs, Resume Packets, commits, or PRs.

## Phase A - preflight and installation

1. Confirm the deployed repository is healthy and the intended configuration exists at `/etc/agent-continuity/config.json`.
2. Keep the ledger under `/var/lib/agent-continuity` and controller-created worktrees under `/srv/continuity`.
3. Validate the configuration without printing it:

```bash
python3 -m json.tool /etc/agent-continuity/config.json >/dev/null
sudo ./scripts/install-continuity-controller.sh
systemctl status agent-continuity-controller.timer --no-pager
```

The installer does not create or overwrite the configuration file. The service runs as the dedicated `agent-continuity` user and the timer cadence is 30 minutes.

## Phase B - first read-only audit

Run one explicit pass before enabling any automatic dispatch:

```bash
CONTINUITY_CONFIG=/etc/agent-continuity/config.json \
  python3 -m tools.continuity.cli reconcile --audit-only --json
CONTINUITY_CONFIG=/etc/agent-continuity/config.json \
  python3 -m tools.continuity.cli queue --json
```

For each finding, record repository, host, path, branch, HEAD, changed/staged/untracked files, exclusive commits, upstream, PR/CI state, last activity, classification, priority, and next action. Audit-only may write evidence to the SQLite ledger; it must not mutate Git refs, working files, branches, stashes, or worktrees.

## Phase C - ORPHAN_UNKNOWN triage

`ORPHAN_UNKNOWN` means there is recoverable evidence but not enough proof to assign ownership. Do not edit it yet. Inspect only bounded metadata:

```bash
git -C <worktree> status --porcelain=v2 --branch
git -C <worktree> log --oneline --decorate -20
git -C <worktree> diff --stat
git -C <worktree> stash list
```

When ownership and objective are established, associate the existing path without moving or copying files:

```bash
CONTINUITY_CONFIG=/etc/agent-continuity/config.json \
  python3 -m tools.continuity.cli associate ORPHAN-... TASK-YYYYMMDD-NNN \
  --objective "continue the recovered work" --json
```

The original orphan record is retained as `SUPERSEDED`; the new task points to the same branch and worktree and enters `NEEDS_RESUME`.

## Phase D - inspect and resume

Inspect the deterministic, redacted Resume Packet before claim:

```bash
CONTINUITY_CONFIG=/etc/agent-continuity/config.json \
  python3 -m tools.continuity.cli resume-packet TASK-YYYYMMDD-NNN
```

For manual recovery, claim one task and renew its lease while working:

```bash
CONTINUITY_CONFIG=/etc/agent-continuity/config.json \
  python3 -m tools.continuity.cli claim TASK-YYYYMMDD-NNN \
  --agent codex --session CHAT-UNIQUE-SESSION --lease-seconds 1800
CONTINUITY_CONFIG=/etc/agent-continuity/config.json \
  python3 -m tools.continuity.cli heartbeat TASK-YYYYMMDD-NNN \
  --session CHAT-UNIQUE-SESSION --lease-seconds 1800
```

Continue in the existing worktree and branch. Validate before commit/push, follow PR checks through merge, and persist post-merge verification. A lost worker or expired session returns to `NEEDS_RESUME`; it is never treated as completion.

## Phase E - controlled automation and rollback

Keep `auto_dispatch=false` until lease concurrency, audit non-mutation, redaction, worktree isolation, and a disposable interruption/recovery pilot are all green. Preferred agent order is Codex, ChatGPT, Claude, then Gemini. A fallback uses the same task, branch, and worktree.

The example configuration keeps every agent disabled. Enable an agent only after its command has been tested to accept the Resume Packet on stdin and to operate inside the controller-selected worktree. Automatic dispatch is intended for controller-owned, service-writable isolated worktrees; recovered legacy worktrees with incompatible ownership or paths remain manual until their permissions are deliberately reconciled. Do not add credentials or login arguments to agent command arrays.

To disable scheduling without deleting recovery state:

```bash
sudo systemctl disable --now agent-continuity-controller.timer
sudo systemctl stop agent-continuity-controller.service || true
```

Do not delete `/var/lib/agent-continuity`, `/srv/continuity`, the SQLite ledger, task branches, or recovered worktrees during rollback. Re-enable only after the cause is understood:

```bash
sudo systemctl enable --now agent-continuity-controller.timer
sudo systemctl start agent-continuity-controller.service
```

## Completion checklist

A controller-owned implementation is complete only when its validated head is merged, CI is green, the exact merge is deployed through the existing auto-gate, the service/timer state is verified, the initial audit is classified, and the disposable interruption/recovery pilot passes. `auto_dispatch=true` is a separate safety gate and must not be enabled merely because unit tests pass.
