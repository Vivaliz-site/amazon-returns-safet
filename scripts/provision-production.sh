#!/usr/bin/env bash
set -Eeuo pipefail

[[ "$(id -u)" -eq 0 ]] || { echo 'run as root' >&2; exit 2; }
exec 9>/run/lock/amazon-returns-provision.lock
flock -n 9 || { echo 'production_provision_locked=1' >&2; exit 75; }
repo="${AMAZON_RETURNS_REPO:-/home/ubuntu/amazon-returns-safet}"
root="${AMAZON_RETURNS_DEPLOY_ROOT:-/home/ubuntu/amazon-returns-deploy}"
source_env="${AMAZON_RETURNS_SOURCE_ENV:-}"
source_db="${AMAZON_RETURNS_SOURCE_DB:-shopvivaliz}"
target_db='amazon_returns_safet'
target_user='amazon_returns_app'
shared="$root/shared"
releases="$root/releases"
env_file="$shared/.env"
rotation_request="$root/admin-password-rotation-request"
sha="$(runuser -u ubuntu -- git -C "$repo" rev-parse --short=12 HEAD)"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
release="$releases/$stamp-$sha"
previous_release="$(readlink -f "$root/current" 2>/dev/null || true)"
worker_quiesced=0
quiesce_started=0
release_activated=0
safet_service_was_running=0
browser_timer_was_active=0
browser_timer_was_enabled=0
restore_previous_release_on_failure() {
    local rc=$?
    if [[ "$quiesce_started" -eq 1 ]]; then
        systemctl thaw amazon-returns-safet.service >/dev/null 2>&1 || true
        systemctl thaw amazon-returns-seller-central-browser.service >/dev/null 2>&1 || true
        systemctl stop amazon-returns-seller-central-browser.timer >/dev/null 2>&1 || true
        if [[ "$release_activated" -eq 1 && -n "$previous_release" && -d "$previous_release" ]]; then
            ln -sfn "releases/$(basename "$previous_release")" "$root/current.rollback"
            mv -Tf "$root/current.rollback" "$root/current"
        fi
        systemctl daemon-reload >/dev/null 2>&1 || true
        if [[ "$safet_service_was_running" -eq 1 ]]; then
            if [[ "$release_activated" -eq 1 ]]; then
                systemctl restart amazon-returns-safet.service >/dev/null 2>&1 || true
            else
                systemctl start amazon-returns-safet.service >/dev/null 2>&1 || true
            fi
        else
            systemctl stop amazon-returns-safet.service >/dev/null 2>&1 || true
        fi
        if [[ "$browser_timer_was_enabled" -eq 1 ]]; then
            systemctl enable amazon-returns-seller-central-browser.timer >/dev/null 2>&1 || true
        else
            systemctl disable amazon-returns-seller-central-browser.timer >/dev/null 2>&1 || true
        fi
        if [[ "$browser_timer_was_active" -eq 1 ]]; then
            systemctl start amazon-returns-seller-central-browser.timer >/dev/null 2>&1 || true
        else
            systemctl stop amazon-returns-seller-central-browser.timer >/dev/null 2>&1 || true
        fi
    fi
    exit "$rc"
}
trap restore_previous_release_on_failure EXIT

[[ -d "$repo/.git" ]] || { echo 'target repository missing' >&2; exit 2; }
install -d -o ubuntu -g www-data -m 0750 "$root" "$releases"
install -d -o root -g www-data -m 0770 "$shared"
install -d -o www-data -g www-data -m 0750 "$shared/evidence"
install -d -o root -g root -m 0700 "$shared/private"
install -d -o ubuntu -g www-data -m 0750 "$release"
rsync -a --delete --exclude=.git --exclude=.env "$repo/" "$release/"
printf '%s\n' "$(runuser -u ubuntu -- git -C "$repo" rev-parse HEAD)" > "$release/.release-sha"

copy_source_key() {
    local key="$1" destination="$2"
    grep -m1 -E "^${key}=" "$source_env" >> "$destination" || true
}

ensure_env_key() {
    local key="$1" value="$2" tmp
    if grep -q -E "^${key}=" "$env_file"; then return 0; fi
    tmp="$(mktemp "$shared/.env.identity.XXXXXX")"
    cp "$env_file" "$tmp"
    printf '%s=%s\n' "$key" "$value" >> "$tmp"
    install -o root -g www-data -m 0640 "$tmp" "$env_file"
    rm -f "$tmp"
}

set_env_key() {
    local key="$1" value="$2" tmp
    tmp="$(mktemp "$shared/.env.update.XXXXXX")"
    awk -F= -v key="$key" -v value="$value" '''
        BEGIN { updated=0 }
        $1 == key { print key "=" value; updated=1; next }
        { print }
        END { if (!updated) print key "=" value }
    ''' "$env_file" > "$tmp"
    install -o root -g www-data -m 0640 "$tmp" "$env_file"
    rm -f "$tmp"
}

if [[ ! -f "$env_file" ]]; then
    [[ -n "$source_env" && -r "$source_env" ]] || { echo 'source environment required for first provision' >&2; exit 2; }
    env_tmp="$(mktemp "$shared/.env.XXXXXX")"
    for key in \
        AMAZON_LWA_CLIENT_ID AMAZON_LWA_CLIENT_SECRET AMAZON_LWA_REFRESH_TOKEN \
        GMAIL_OAUTH_REFRESH_TOKEN GOOGLE_OAUTH_CLIENT_ID GOOGLE_OAUTH_CLIENT_SECRET GOOGLE_OAUTH_REFRESH_TOKEN \
        SELLER_CENTRAL_BRIDGE_TOKEN; do
        copy_source_key "$key" "$env_tmp"
    done
    db_pass="$(openssl rand -hex 24)"
    admin_password="$(openssl rand -base64 24 | tr -d '\n')"
    admin_hash="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$admin_password")"
    printf '%s\n' \
        'AMAZON_RETURNS_ENABLED=1' \
        'AMAZON_RETURNS_MODE=production' \
        'AMAZON_RETURNS_GMAIL_INGEST=1' \
        'AMAZON_RETURNS_SAFE_T_WRITE=0' \
        'AMAZON_RETURNS_APPEAL_WRITE=0' \
        'AMAZON_RETURNS_EMAIL_REVIEW_WRITE=0' \
        'AMAZON_RETURNS_EMAIL_REPLY_WRITE=0' \
        'AMAZON_RETURNS_SUPPORT_WRITE=0' \
        'AMAZON_RETURNS_POLICY_MONITOR=0' >> "$env_tmp"
    printf '%s\n' \
        'AMAZON_RETURNS_DB_HOST=127.0.0.1' \
        'AMAZON_RETURNS_DB_PORT=3306' \
        "AMAZON_RETURNS_DB_NAME=$target_db" \
        "AMAZON_RETURNS_DB_USER=$target_user" \
        "AMAZON_RETURNS_DB_PASS=$db_pass" \
        'AMAZON_RETURNS_DB_CHARSET=utf8mb4' \
        'AMAZON_MARKETPLACE_ID=A2Q3Y263D00KWC' \
        'AMAZON_RETURNS_ADMIN_USERNAME=fred' \
        "AMAZON_RETURNS_ADMIN_PASSWORD_HASH=$admin_hash" >> "$env_tmp"
    install -o root -g www-data -m 0640 "$env_tmp" "$env_file"
    rm -f "$env_tmp"
    printf '%s' "$admin_password" > "$shared/private/admin-bootstrap-password"
    chmod 0600 "$shared/private/admin-bootstrap-password"
else
    db_pass="$(awk -F= '$1=="AMAZON_RETURNS_DB_PASS"{sub(/^[^=]*=/,""); print; exit}' "$env_file")"
    [[ -n "$db_pass" ]] || { echo 'target DB password missing from env' >&2; exit 2; }
fi

if [[ -e "$rotation_request" ]]; then
    [[ -f "$rotation_request" && ! -L "$rotation_request" ]] || { echo 'invalid admin password rotation request type' >&2; exit 2; }
    [[ "$(stat -c '%U' "$rotation_request")" == 'ubuntu' && "$(stat -c '%a' "$rotation_request")" == '600' ]] || { echo 'admin password rotation request must be ubuntu-owned mode 600' >&2; exit 2; }
    rotation_password="$(cat "$rotation_request")"
    [[ -n "$rotation_password" ]] || { echo 'admin password rotation request is empty' >&2; exit 2; }
    rotation_hash="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$rotation_password")"
    set_env_key 'AMAZON_RETURNS_ADMIN_USERNAME' 'Fred'
    set_env_key 'AMAZON_RETURNS_ADMIN_PASSWORD_HASH' "$rotation_hash"
    unset rotation_password rotation_hash
    rm -f -- "$rotation_request"
    echo 'admin_password_rotation=applied'
fi

ensure_env_key 'AMAZON_RETURNS_TENANT_SLUG' 'shopvivaliz'
ensure_env_key 'AMAZON_RETURNS_TENANT_NAME' 'ShopVivaliz'
ensure_env_key 'AMAZON_RETURNS_CONNECTION_KEY' 'amazon-br-primary'
ensure_env_key 'AMAZON_RETURNS_CONNECTION_LABEL' 'Amazon Brasil principal'
ensure_env_key 'AMAZON_SP_API_REGION' 'NA'
ensure_env_key 'AMAZON_MARKETPLACE_ID' 'A2Q3Y263D00KWC'
ensure_env_key 'AMAZON_RETURNS_REVIEW_AI_MODEL' 'gpt-5.6-terra'
set_env_key 'AMAZON_RETURNS_LEARNED_RULE_EXECUTION' '1'
set_env_key 'AMAZON_RETURNS_REVIEW_NOTIFY_EMAIL' 'fredmourao@gmail.com'

tenant_slug="$(awk -F= '$1=="AMAZON_RETURNS_TENANT_SLUG"{sub(/^[^=]*=/,"");print;exit}' "$env_file")"
connection_key="$(awk -F= '$1=="AMAZON_RETURNS_CONNECTION_KEY"{sub(/^[^=]*=/,"");print;exit}' "$env_file")"
[[ "$tenant_slug" =~ ^[a-z0-9][a-z0-9-]{0,95}$ ]] || { echo 'invalid tenant slug before provisioning' >&2; exit 2; }
[[ "$connection_key" =~ ^[a-z0-9][a-z0-9-]{0,95}$ ]] || { echo 'invalid connection key before provisioning' >&2; exit 2; }

mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$target_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$target_user'@'localhost' IDENTIFIED BY '$db_pass';
ALTER USER '$target_user'@'localhost' IDENTIFIED BY '$db_pass';
GRANT ALL PRIVILEGES ON \`$target_db\`.* TO '$target_user'@'localhost';
FLUSH PRIVILEGES;
SQL
for test in "$release"/tests/*.php; do php "$test" >/dev/null; done
php "$release/scripts/audit-tenant-sql.php" >/dev/null
find "$release/includes" "$release/api" "$release/admin" "$release/workers" "$release/scripts" \
    -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

case_table_exists="$(mysql --protocol=socket -uroot -Nse \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$target_db' AND table_name='amazon_return_cases'")"
[[ "$case_table_exists" -eq 1 ]] || {
    echo 'empty_database_requires_onboarding=true' >&2
    echo 'No existing Amazon Returns cases were found; use the approved onboarding workflow.' >&2
    exit 4
}
tenant_column_exists="$(mysql --protocol=socket -uroot -Nse \
    "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$target_db' AND table_name='amazon_return_cases' AND column_name='tenant_id'")"
expected_cases="$(mysql --protocol=socket -uroot -Nse \
    "SELECT COUNT(*) FROM amazon_return_cases" "$target_db")"
[[ "$expected_cases" =~ ^[0-9]+$ ]] || { echo 'invalid live case count for migration' >&2; exit 2; }
if [[ "$expected_cases" -eq 0 ]]; then
    echo 'empty_database_requires_onboarding=true' >&2
    echo 'No existing Amazon Returns cases were found; use the approved onboarding workflow.' >&2
    exit 4
fi
if [[ "$tenant_column_exists" -eq 0 ]]; then
    AMAZON_RETURNS_ENV_FILE="$env_file" \
        php "$release/scripts/migrate-single-tenant-to-multitenant.php" --dry-run
    backup_file="$shared/private/tenant-foundation-$stamp.sql.gz"
    verification_file="$shared/private/tenant-foundation-verification-$stamp.txt"
    dry_run_cmd="sudo env AMAZON_RETURNS_ENV_FILE=$env_file php $release/scripts/migrate-single-tenant-to-multitenant.php --dry-run"
    backup_cmd="sudo mysqldump --protocol=socket -uroot --single-transaction --routines --triggers $target_db | gzip -9 > $backup_file"
    apply_cmd="sudo env AMAZON_RETURNS_ENV_FILE=$env_file php $release/scripts/migrate-single-tenant-to-multitenant.php --apply"
    printf -v verify_cmd 'sudo env AMAZON_RETURNS_ENV_FILE=%q AMAZON_RETURNS_SOURCE_DB=%q AMAZON_RETURNS_TARGET_DB=%q AMAZON_RETURNS_EXPECTED_CASES=%q AMAZON_RETURNS_VERIFICATION_OUTPUT=%q %q' \
        "$env_file" "$source_db" "$target_db" "$expected_cases" "$verification_file" "$release/scripts/verify-migration.sh"
    rollback_target="${previous_release:-<previous-release>}"
    rollback_cmd="sudo systemctl stop amazon-returns-safet.service && sudo gunzip -c $backup_file | sudo mysql --protocol=socket -uroot $target_db && sudo ln -sfn releases/$(basename "$rollback_target") $root/current && sudo systemctl start amazon-returns-safet.service"
    printf 'dry_run_cmd=%s\n' "$dry_run_cmd"
    printf 'backup_cmd=%s\n' "$backup_cmd"
    printf 'apply_cmd=%s\n' "$apply_cmd"
    printf 'verify_cmd=%s\n' "$verify_cmd"
    printf 'rollback_cmd=%s\n' "$rollback_cmd"
    echo 'migration_preflight_required=true'
    echo 'No release symlink or service was changed.'
    exit 3
fi

AMAZON_RETURNS_ENV_FILE="$env_file" php -r '
$release=$argv[1];
require $release."/includes/Database.php";
require $release."/includes/amazon-returns/Runtime.php";
require $release."/includes/amazon-returns/TenantRegistry.php";
$db=amazon_returns_require_pdo();
$config=new SvAmazonReturnsConfig();
$context=SvAmazonTenantRegistry::resolveCurrent($db,$config);
$result=SvAmazonReturnsRuntime::bootstrap($db,$context);
if(($result["status"]??"")!=="OK")throw new RuntimeException("bootstrap failed");
' "$release"

systemctl is-active --quiet amazon-returns-safet.service 2>/dev/null && safet_service_was_running=1 || true
systemctl is-active --quiet amazon-returns-seller-central-browser.timer 2>/dev/null && browser_timer_was_active=1 || true
systemctl is-enabled --quiet amazon-returns-seller-central-browser.timer 2>/dev/null && browser_timer_was_enabled=1 || true
quiesce_started=1
"$release/scripts/quiesce-production-workers.sh" "$target_db" "$tenant_slug" "$connection_key"
worker_quiesced=1

verification_file="$shared/private/live-tenant-verification-$stamp.txt"
AMAZON_RETURNS_ENV_FILE="$env_file" \
AMAZON_RETURNS_TARGET_DB="$target_db" \
AMAZON_RETURNS_EXPECTED_CASES="$expected_cases" \
AMAZON_RETURNS_VERIFICATION_OUTPUT="$verification_file" \
    "$release/scripts/verify-live-tenant-foundation.sh"
grep -q '^live_tenant_verification=ok$' "$verification_file"

chown -R root:www-data "$release"
chmod -R go-w "$release"

ln -sfn "releases/$(basename "$release")" "$root/current.next"
mv -Tf "$root/current.next" "$root/current"
release_activated=1

a2enmod ssl rewrite >/dev/null
a2ensite amazon-returns-safet.conf >/dev/null

if [[ ! -s /etc/letsencrypt/live/returns.shopvivaliz.com.br/fullchain.pem ]]; then
    install -m 0644 "$root/current/deploy/apache/returns-http.conf" /etc/apache2/sites-available/amazon-returns-safet.conf
    apache2ctl configtest
    systemctl reload apache2
    cf_token="${CLOUDFLARE_DNS_API_TOKEN:-}"
    if [[ -z "$cf_token" && -n "${CLOUDFLARE_DNS_API_TOKEN_FILE:-}" && -r "$CLOUDFLARE_DNS_API_TOKEN_FILE" ]]; then
        cf_token="$(cat "$CLOUDFLARE_DNS_API_TOKEN_FILE")"
    fi
    [[ -n "$cf_token" ]] || { echo 'Cloudflare DNS token is required for first TLS issuance' >&2; exit 2; }
    if ! certbot plugins 2>/dev/null | grep -q 'dns-cloudflare'; then
        apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y certbot python3-certbot-dns-cloudflare >/dev/null
    fi
    cf_credentials="$shared/private/cloudflare-certbot.ini"
    printf 'dns_cloudflare_api_token = %s\n' "$cf_token" > "$cf_credentials"
    chmod 0600 "$cf_credentials"
    certbot certonly --dns-cloudflare \
        --dns-cloudflare-credentials "$cf_credentials" \
        --dns-cloudflare-propagation-seconds 30 \
        -d returns.shopvivaliz.com.br \
        --non-interactive --agree-tos --register-unsafely-without-email
fi

install -m 0644 "$root/current/deploy/apache/returns.shopvivaliz.com.br.conf" /etc/apache2/sites-available/amazon-returns-safet.conf
apache2ctl configtest
systemctl reload apache2

install -m 0644 "$root/current/deploy/systemd/amazon-returns-safet.service" /etc/systemd/system/amazon-returns-safet.service
install -m 0644 "$root/current/deploy/systemd/amazon-returns-seller-central-browser.service" /etc/systemd/system/amazon-returns-seller-central-browser.service
install -m 0644 "$root/current/deploy/systemd/amazon-returns-seller-central-auth-check.service" /etc/systemd/system/amazon-returns-seller-central-auth-check.service
install -m 0644 "$root/current/deploy/systemd/amazon-returns-erp-canary-discovery.service" /etc/systemd/system/amazon-returns-erp-canary-discovery.service
install -m 0644 "$root/current/deploy/systemd/amazon-returns-erp-canary-execute.service" /etc/systemd/system/amazon-returns-erp-canary-execute.service
install -m 0644 "$root/current/deploy/systemd/amazon-returns-seller-central-browser.timer" /etc/systemd/system/amazon-returns-seller-central-browser.timer
systemctl daemon-reload
systemctl enable amazon-returns-safet.service >/dev/null
systemctl restart amazon-returns-safet.service
systemctl is-active --quiet amazon-returns-safet.service

browser_root="$shared/seller-central-browser"
browser_env="$shared/seller-central-browser.env"
browser_runtime_ready=1
[[ -s "$browser_env" ]] || browser_runtime_ready=0
[[ -s "$browser_root/amazon.username" ]] || browser_runtime_ready=0
[[ -s "$browser_root/amazon.password" ]] || browser_runtime_ready=0
browser_executable=""
browser_token_file=""
browser_token_ready=0
if [[ "$browser_runtime_ready" -eq 1 ]]; then
    browser_executable="$(awk -F= '$1=="SELLER_CENTRAL_BROWSER"{sub(/^[^=]*=/,""); print; exit}' "$browser_env")"
    browser_token_file="$(awk -F= '$1=="SELLER_CENTRAL_BRIDGE_TOKEN_FILE"{sub(/^[^=]*=/,""); print; exit}' "$browser_env")"
    [[ -n "$browser_executable" && -x "$browser_executable" ]] || browser_runtime_ready=0
    if [[ -n "$browser_token_file" && -s "$browser_token_file" ]]; then
        awk 'length($0)>=32{ok=1; exit} END{exit ok?0:1}' "$browser_token_file" && browser_token_ready=1 || true
    fi
    if [[ "$browser_token_ready" -eq 0 ]]; then
        awk -F= '$1=="SELLER_CENTRAL_BRIDGE_TOKEN"{sub(/^[^=]*=/,""); if(length($0)>=32) ok=1} END{exit ok?0:1}' "$env_file" && browser_token_ready=1 || true
    fi
    [[ "$browser_token_ready" -eq 1 ]] || browser_runtime_ready=0
fi
browser_timer_enabled_now=0
systemctl is-enabled --quiet amazon-returns-seller-central-browser.timer 2>/dev/null && browser_timer_enabled_now=1 || true
if [[ "$browser_runtime_ready" -eq 1 ]]; then
    browser_auth_ready=1
    if [[ "$browser_timer_enabled_now" -eq 0 ]]; then
        systemctl start amazon-returns-seller-central-auth-check.service || browser_auth_ready=0
    fi
    if [[ "$browser_auth_ready" -eq 1 ]]; then
        systemctl enable --now amazon-returns-seller-central-browser.timer >/dev/null
        echo 'seller_central_browser_timer=enabled'
    else
        systemctl disable --now amazon-returns-seller-central-browser.timer >/dev/null 2>&1 || true
        echo 'seller_central_browser_timer=disabled_auth_check_failed'
    fi
else
    systemctl disable --now amazon-returns-seller-central-browser.timer >/dev/null 2>&1 || true
    echo 'seller_central_browser_timer=disabled_missing_prerequisites'
fi

"$root/current/scripts/ensure-auto-deploy-source.sh"

install -m 0644 "$root/current/deploy/systemd/amazon-returns-deploy.service" /etc/systemd/system/amazon-returns-deploy.service
install -m 0644 "$root/current/deploy/systemd/amazon-returns-deploy.timer" /etc/systemd/system/amazon-returns-deploy.timer
systemctl daemon-reload
systemctl enable --now amazon-returns-deploy.timer >/dev/null

"$root/current/scripts/provision-olist-erp-browser-host.sh" --source-root "$root/current" --enable-service
systemctl is-active --quiet amazon-returns-olist-erp-browser.service
echo 'olist_erp_browser_service=enabled'
if systemctl start amazon-returns-erp-canary-discovery.service; then
    echo 'erp_canary_discovery=started'
else
    echo 'erp_canary_discovery=failed'
fi
erp_canary_execute_marker="/home/ubuntu/amazon-returns-deploy/shared/erp-canary-execute-once"
if [[ -f "$erp_canary_execute_marker" ]]; then
    rm -f -- "$erp_canary_execute_marker"
    if systemctl start amazon-returns-erp-canary-execute.service; then
        echo erp_canary_execute=completed
    else
        echo erp_canary_execute=failed
    fi
else
    echo erp_canary_execute=skipped_not_armed
fi

worker_quiesced=0
quiesce_started=0
release_activated=0
trap - EXIT

find "$releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
    | sort -nr | tail -n +6 | cut -d' ' -f2- \
    | while IFS= read -r old; do [[ "$(readlink -f "$root/current")" == "$old" ]] || rm -rf -- "$old"; done

echo 'amazon_returns_provisioned=true'
