#!/usr/bin/env bash
set -Eeuo pipefail

[[ "$(id -u)" -eq 0 ]] || { echo 'run as root' >&2; exit 2; }
repo="${AMAZON_RETURNS_REPO:-/home/ubuntu/amazon-returns-safet}"
root="${AMAZON_RETURNS_DEPLOY_ROOT:-/home/ubuntu/amazon-returns-deploy}"
source_env="${AMAZON_RETURNS_SOURCE_ENV:-}"
source_db="${AMAZON_RETURNS_SOURCE_DB:-shopvivaliz}"
target_db='amazon_returns_safet'
target_user='amazon_returns_app'
shared="$root/shared"
releases="$root/releases"
env_file="$shared/.env"
sha="$(git -C "$repo" rev-parse --short=12 HEAD)"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
release="$releases/$stamp-$sha"

[[ -d "$repo/.git" ]] || { echo 'target repository missing' >&2; exit 2; }
install -d -o ubuntu -g www-data -m 0750 "$root" "$releases"
install -d -o root -g www-data -m 0750 "$shared" "$shared/evidence" "$shared/private"
install -d -o ubuntu -g www-data -m 0750 "$release"
rsync -a --delete --exclude=.git --exclude=.env "$repo/" "$release/"
printf '%s\n' "$(git -C "$repo" rev-parse HEAD)" > "$release/.release-sha"
ln -sfn "releases/$(basename "$release")" "$root/current.next"
mv -Tf "$root/current.next" "$root/current"

copy_source_key() {
    local key="$1" destination="$2"
    grep -m1 -E "^${key}=" "$source_env" >> "$destination" || true
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

mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$target_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$target_user'@'localhost' IDENTIFIED BY '$db_pass';
ALTER USER '$target_user'@'localhost' IDENTIFIED BY '$db_pass';
GRANT ALL PRIVILEGES ON \`$target_db\`.* TO '$target_user'@'localhost';
FLUSH PRIVILEGES;
SQL
AMAZON_RETURNS_ENV_FILE="$env_file" php -r '
require $argv[1]."/includes/Database.php";
require $argv[1]."/includes/amazon-returns/Runtime.php";
$db=amazon_returns_require_pdo();
SvAmazonReturnsRuntime::bootstrap($db);
' "$root/current"

if [[ "${AMAZON_RETURNS_IMPORT_SOURCE:-0}" == 1 ]]; then
    [[ -n "$source_env" && -r "$source_env" ]] || { echo 'source environment required for source import' >&2; exit 2; }
    tables=(
        amazon_return_overrides amazon_return_source_cursors amazon_return_dead_letters
        amazon_return_outbox amazon_return_evidence amazon_return_events
        amazon_return_policies amazon_return_cases
    )
    for table in "${tables[@]}"; do
        mysql --protocol=socket -uroot "$target_db" -e "TRUNCATE TABLE \`$table\`"
    done
    mysqldump --protocol=socket -uroot --single-transaction --quick --skip-lock-tables \
        --no-create-info --skip-triggers --complete-insert --hex-blob \
        "$source_db" "${tables[@]}" | mysql --protocol=socket -uroot "$target_db"

    AMAZON_RETURNS_ENV_FILE="$env_file" php -r '
require $argv[1]."/includes/Database.php";
require $argv[1]."/includes/amazon-returns/Runtime.php";
$db=amazon_returns_require_pdo();
SvAmazonReturnsRuntime::bootstrap($db);
' "$root/current"
    AMAZON_RETURNS_SOURCE_DB="$source_db" AMAZON_RETURNS_TARGET_DB="$target_db" "$root/current/scripts/verify-migration.sh"
fi
install -m 0644 "$root/current/deploy/apache/returns-http.conf" /etc/apache2/sites-available/amazon-returns-safet.conf
a2enmod ssl rewrite >/dev/null
a2ensite amazon-returns-safet.conf >/dev/null
apache2ctl configtest
systemctl reload apache2

if [[ ! -s /etc/letsencrypt/live/returns.shopvivaliz.com.br/fullchain.pem ]]; then
    : "${CLOUDFLARE_DNS_API_TOKEN:?Cloudflare DNS token is required for first TLS issuance}"
    if ! certbot plugins 2>/dev/null | grep -q 'dns-cloudflare'; then
        apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y certbot python3-certbot-dns-cloudflare >/dev/null
    fi
    cf_credentials="$(mktemp)"
    printf 'dns_cloudflare_api_token = %s\n' "$CLOUDFLARE_DNS_API_TOKEN" > "$cf_credentials"
    chmod 0600 "$cf_credentials"
    certbot certonly --dns-cloudflare \
        --dns-cloudflare-credentials "$cf_credentials" \
        --dns-cloudflare-propagation-seconds 30 \
        -d returns.shopvivaliz.com.br \
        --non-interactive --agree-tos --register-unsafely-without-email
    rm -f "$cf_credentials"
fi

install -m 0644 "$root/current/deploy/apache/returns.shopvivaliz.com.br.conf" /etc/apache2/sites-available/amazon-returns-safet.conf
apache2ctl configtest
systemctl reload apache2

install -m 0644 "$root/current/deploy/systemd/amazon-returns-safet.service" /etc/systemd/system/amazon-returns-safet.service
systemctl daemon-reload
systemctl enable amazon-returns-safet.service >/dev/null
systemctl restart amazon-returns-safet.service
systemctl is-active --quiet amazon-returns-safet.service

install -m 0644 "$root/current/deploy/systemd/amazon-returns-deploy.service" /etc/systemd/system/amazon-returns-deploy.service
install -m 0644 "$root/current/deploy/systemd/amazon-returns-deploy.timer" /etc/systemd/system/amazon-returns-deploy.timer
systemctl daemon-reload
systemctl enable --now amazon-returns-deploy.timer >/dev/null

find "$releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
    | sort -nr | tail -n +6 | cut -d' ' -f2- \
    | while IFS= read -r old; do [[ "$(readlink -f "$root/current")" == "$old" ]] || rm -rf -- "$old"; done

echo 'amazon_returns_provisioned=true'
