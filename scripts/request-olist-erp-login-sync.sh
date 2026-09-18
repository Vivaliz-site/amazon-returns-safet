#!/usr/bin/env bash
set -euo pipefail

REPO="${OLIST_LOGIN_SYNC_REPO:-Vivaliz-site/amazon-returns-safet}"
WORKFLOW="${OLIST_LOGIN_SYNC_WORKFLOW:-olist-login-envelope.yml}"
INBOX_DIR="${OLIST_LOGIN_SYNC_INBOX_DIR:-/home/ubuntu/.amazon-returns-secrets-inbox}"
INBOX_FILE="$INBOX_DIR/olist-erp-browser-login.env"
VALIDATOR="$(cd "$(dirname "$0")" && pwd)/validate-olist-erp-login-env.py"

[[ "$(id -u)" -ne 0 ]] || { echo "olist_login_request=failed reason=must_run_unprivileged" >&2; exit 77; }
command -v gh >/dev/null || { echo "olist_login_request=failed reason=gh_missing" >&2; exit 69; }
command -v openssl >/dev/null || { echo "olist_login_request=failed reason=openssl_missing" >&2; exit 69; }
command -v jq >/dev/null || { echo "olist_login_request=failed reason=jq_missing" >&2; exit 69; }
[[ -x "$VALIDATOR" ]] || { echo "olist_login_request=failed reason=validator_missing" >&2; exit 69; }

umask 077
tmp="$(mktemp -d "${TMPDIR:-/tmp}/olist-login-sync.XXXXXX")"
cleanup() { rm -rf "$tmp"; }
trap cleanup EXIT HUP INT TERM

openssl req -x509 -newkey rsa:4096 -sha256 -nodes \
  -subj "/CN=olist-login-sync" \
  -days 1 \
  -keyout "$tmp/recipient.key" \
  -out "$tmp/recipient.crt" \
  >/dev/null 2>&1

cert_b64="$(base64 -w0 "$tmp/recipient.crt")"
request_id="$(date -u +%Y%m%dT%H%M%SZ)-$$-$(openssl rand -hex 6)"
start_epoch="$(date -u +%s)"

gh workflow run "$WORKFLOW" \
  --repo "$REPO" \
  --ref main \
  -f "request_id=$request_id" \
  -f "recipient_cert_b64=$cert_b64"

run_id=""
for _ in $(seq 1 30); do
  json="$(gh api -H 'Accept: application/vnd.github+json' \
    "/repos/$REPO/actions/workflows/$WORKFLOW/runs?event=workflow_dispatch&branch=main&per_page=10")"
  run_id="$(jq -r --argjson start "$start_epoch" --arg title "Olist Login Envelope $request_id" '
    [.workflow_runs[]
      | select((.created_at | fromdateiso8601) >= $start)
      | select(.head_branch == "main")
      | select(.display_title == $title)]
    | sort_by(.created_at)
    | last
    | .id // empty
  ' <<<"$json")"
  [[ -n "$run_id" ]] && break
  sleep 2
done
[[ -n "$run_id" ]] || { echo "olist_login_request=failed reason=workflow_run_not_found" >&2; exit 70; }

gh run watch "$run_id" --repo "$REPO" --exit-status >/dev/null

mkdir "$tmp/artifact"
gh run download "$run_id" \
  --repo "$REPO" \
  --name olist-login-envelope \
  --dir "$tmp/artifact"

cms="$tmp/artifact/olist-login.cms"
[[ -s "$cms" && ! -L "$cms" ]] || { echo "olist_login_request=failed reason=artifact_missing" >&2; exit 65; }

openssl cms -decrypt -binary -inform DER \
  -in "$cms" \
  -recip "$tmp/recipient.crt" \
  -inkey "$tmp/recipient.key" \
  -out "$tmp/olist-erp-browser-login.env"

"$VALIDATOR" "$tmp/olist-erp-browser-login.env" >/dev/null

if [[ -e "$INBOX_DIR" ]]; then
  [[ -d "$INBOX_DIR" && ! -L "$INBOX_DIR" ]] || { echo "olist_login_request=failed reason=invalid_inbox_dir" >&2; exit 65; }
  chmod 0700 "$INBOX_DIR"
else
  install -d -m 0700 "$INBOX_DIR"
fi

staged="$INBOX_DIR/.olist-erp-browser-login.env.$$"
install -m 0600 "$tmp/olist-erp-browser-login.env" "$staged"
mv -f "$staged" "$INBOX_FILE"

echo "olist_login_request=staged run_id=$run_id"
