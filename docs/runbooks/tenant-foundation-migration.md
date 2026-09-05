> Policy correction (2026-09-05): the former global 75-day rule is withdrawn. Use docs/superpowers/specs/2026-09-05-policy-modes-45-60.md; periods and action routes depend on program, order date and evidence.

# Runbook — migração da fundação multi-tenant

## Objetivo e escopo

Este procedimento migra a instalação Amazon Returns / SAFE-T já isolada para ownership explícito por tenant e conexão Amazon. Ele cobre somente o tenant ShopVivaliz existente e seus 37 casos confirmados.

A migração não habilita nenhum write externo. `SAFE_T_SUBMIT`, `SAFE_T_APPEAL`, `SAFE_T_EMAIL_REVIEW` e Seller Support devem permanecer desligados durante todo o processo.

## Invariantes de aceite

A mudança só é aceita quando todas estas evidências forem verdadeiras:

- origem e destino têm `37/37` casos;
- `ownership_nulls=0`;
- `cross_tenant_mismatch_count=0`;
- `processing_jobs=0`;
- `write_flags_disabled=true`;
- `pre_migration_hash` é igual a `post_migration_hash`;
- the applicable 45/60-day matrix permanece válido para todas as políticas ativas deste tenant;
- shadow audit retorna `mismatch_count=0`;
- health, daemon, Gmail, SP-API e as duas bridges respondem sem erro;
- um `SAFE_T_READ` real conclui sem job abandonado em `PROCESSING`.

## Variáveis operacionais

Execute como root na VM `shopvivaliz-free-a1`:

```bash
set -Eeuo pipefail
repo=/home/ubuntu/amazon-returns-safet
deploy=/home/ubuntu/amazon-returns-deploy
env_file="$deploy/shared/.env"
target_db=amazon_returns_safet
source_db=shopvivaliz
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup="$deploy/shared/private/tenant-foundation-$stamp.sql.gz"
backup_hash="$backup.sha256"
verification="$deploy/shared/private/tenant-foundation-verification-$stamp.txt"
preflight="$deploy/shared/private/tenant-foundation-preflight-$stamp.json"
previous_release="$(readlink -f "$deploy/current")"
release="$(find "$deploy/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -nr | head -1 | cut -d' ' -f2-)"
```

Confirme que `release` aponta para o candidato gerado pelo provisionamento, não para um release antigo.

## 1. Pré-condições

```bash
systemctl is-active amazon-returns-safet.service
systemctl is-enabled amazon-returns-safet.service
systemctl is-active shopvivaliz-amazon-returns.service || true
systemctl is-enabled shopvivaliz-amazon-returns.service || true
mysql --protocol=socket -uroot -Nse "SELECT COUNT(*) FROM amazon_return_cases" "$target_db"
```

Esperado: serviço novo ativo/habilitado, serviço antigo inativo/desabilitado e exatamente 37 casos no banco isolado.

Confirme que os quatro write flags estão desligados sem exibir o restante do ambiente:

```bash
for key in \
  AMAZON_RETURNS_SAFE_T_WRITE \
  AMAZON_RETURNS_APPEAL_WRITE \
  AMAZON_RETURNS_EMAIL_REVIEW_WRITE \
  AMAZON_RETURNS_SUPPORT_WRITE; do
  value="$(awk -F= -v wanted="$key" '$1==wanted{sub(/^[^=]*=/,"");print;exit}' "$env_file")"
  printf '%s=%s\n' "$key" "${value:-0}"
done
```

Interrompa se algum valor não for `0`, `false`, `no` ou `off`.

## 2. Testes do release candidato

```bash
for test in "$release"/tests/*.php; do php "$test"; done
php "$release/scripts/audit-tenant-sql.php"
find "$release/includes" "$release/api" "$release/admin" "$release/workers" "$release/scripts" \
  -name '*.php' -print0 | xargs -0 -n1 php -l
node --check "$release/scripts/amazon-returns/safe-t-status-parser.mjs"
node --check "$release/scripts/amazon-returns/seller-central-safe-t-read-worker.mjs"
node --check "$release/scripts/amazon-returns/seller-central-bridge-worker.mjs"
bash -n "$release/scripts/install-service.sh"
bash -n "$release/scripts/provision-production.sh"
bash -n "$release/scripts/verify-migration.sh"
```

Nenhuma alteração de banco ou release é permitida se um comando falhar.

## 3. Dry-run obrigatório

```bash
sudo env AMAZON_RETURNS_ENV_FILE="$env_file" \
  php "$release/scripts/migrate-single-tenant-to-multitenant.php" --dry-run \
  | tee "$preflight"
```

O JSON deve apontar o tenant `shopvivaliz`, a conexão `amazon-br-primary`, os oito conjuntos de dados existentes e nenhuma credencial. Dry-run não substitui backup.

## 4. Backup verificável

```bash
sudo mysqldump --protocol=socket -uroot \
  --single-transaction --routines --triggers "$target_db" \
  | gzip -9 > "$backup"
sha256sum "$backup" | tee "$backup_hash"
test -s "$backup"
gzip -t "$backup"
```

Registre o caminho do backup, o SHA-256, o release anterior e o release candidato no ticket operacional. O backup deve ficar em diretório root-only.

## 5. Janela de manutenção e aplicação

Pare o worker somente depois de um backup válido:

```bash
sudo systemctl stop amazon-returns-safet.service
sudo systemctl is-active amazon-returns-safet.service || true
```

Confirme que nenhuma fila está em processamento:

```bash
mysql --protocol=socket -uroot -Nse \
  "SELECT COUNT(*) FROM amazon_return_outbox WHERE status='PROCESSING'" \
  "$target_db"
```

O resultado deve ser zero. Em seguida, aplique uma única vez:

```bash
sudo env AMAZON_RETURNS_ENV_FILE="$env_file" \
  php "$release/scripts/migrate-single-tenant-to-multitenant.php" --apply
```

O comando deve terminar com `valid=true`. Uma repetição é permitida somente para provar idempotência e deve produzir o mesmo tenant, conexão, contagens e ownership.

## 6. Verificação pós-migração antes do deploy

```bash
sudo env \
  AMAZON_RETURNS_ENV_FILE="$env_file" \
  AMAZON_RETURNS_SOURCE_DB="$source_db" \
  AMAZON_RETURNS_TARGET_DB="$target_db" \
  AMAZON_RETURNS_TENANT_SLUG=shopvivaliz \
  AMAZON_RETURNS_CONNECTION_KEY=amazon-br-primary \
  AMAZON_RETURNS_EXPECTED_CASES=37 \
  AMAZON_RETURNS_VERIFICATION_OUTPUT="$verification" \
  "$release/scripts/verify-migration.sh"
```

Valide o relatório sem editar seus valores:

```bash
grep -Fx 'tenant_count=1' "$verification"
grep -Fx 'connection_count=1' "$verification"
grep -Fx 'source_current_cases=37' "$verification"
grep -Fx 'target_current_cases=37' "$verification"
grep -Fx 'ownership_nulls=0' "$verification"
grep -Fx 'cross_tenant_mismatch_count=0' "$verification"
grep -Fx 'processing_jobs=0' "$verification"
grep -Fx 'write_flags_disabled=true' "$verification"
grep -Fx 'migration_verification=ok' "$verification"
```

Compare explicitamente os hashes agregados:

```bash
pre_hash="$(awk -F= '$1=="pre_migration_hash"{print $2}' "$verification")"
post_hash="$(awk -F= '$1=="post_migration_hash"{print $2}' "$verification")"
test -n "$pre_hash"
test "$pre_hash" = "$post_hash"
```

## 7. Ativação do release

Somente após a verificação anterior:

```bash
sudo ln -sfn "releases/$(basename "$release")" "$deploy/current.next"
sudo mv -Tf "$deploy/current.next" "$deploy/current"
sudo systemctl daemon-reload
sudo systemctl restart amazon-returns-safet.service
sudo systemctl is-active amazon-returns-safet.service
sudo systemctl is-enabled amazon-returns-safet.service
```

Não altere os quatro write flags durante a ativação.

## 8. Smoke tests de produção

```bash
curl --fail --silent --show-error https://returns.shopvivaliz.com.br/api/health.php
sudo env AMAZON_RETURNS_ENV_FILE="$env_file" \
  php "$deploy/current/workers/amazon-returns/daemon.php" --once
journalctl -u amazon-returns-safet.service --since '-10 minutes' --no-pager
```

Valide, sem imprimir tokens, os dois endpoints de bridge com o método heartbeat usando a credencial protegida já instalada. Ambos devem retornar HTTP 200, tenant e conexão esperados.

Execute o shadow audit:

```bash
sudo env \
  AMAZON_RETURNS_SOURCE_DB="$source_db" \
  AMAZON_RETURNS_TARGET_DB="$target_db" \
  AMAZON_RETURNS_TARGET_TENANT_SLUG=shopvivaliz \
  AMAZON_RETURNS_TARGET_CONNECTION_KEY=amazon-br-primary \
  php "$deploy/current/scripts/shadow-audit.php" \
  | tee "$deploy/shared/private/shadow-after-tenant-$stamp.json"
```

Aceite somente `source_open_cases=37`, `target_open_cases=37` e `mismatch_count=0`.

Depois, execute um lote real e somente de leitura `SAFE_T_READ`. Confirme que cada job termina como `SUCCEEDED` ou volta a `PENDING` com uma razão explícita e que nenhum job fica preso em `PROCESSING` além do lease.

```bash
mysql --protocol=socket -uroot --table "$target_db" -e \
  "SELECT kind,status,COUNT(*) total,MIN(available_at) oldest FROM amazon_return_outbox GROUP BY kind,status ORDER BY kind,status"
```

A autenticação do Seller Central não é requisito para Reports, SP-API, Gmail ou Finances; eventual `AUTH_REQUIRED` deve pausar somente a ação browser-only daquele tenant.

## 9. Critérios obrigatórios de interrupção

Interrompa e não troque o release se ocorrer qualquer condição:

- dry-run ou testes retornam código diferente de zero;
- contagem diferente de 37 na origem ou destino;
- backup ausente, vazio ou com `gzip -t` inválido;
- `ownership_nulls` maior que zero;
- `cross_tenant_mismatch_count` maior que zero;
- `processing_jobs` maior que zero;
- hashes pré/pós diferentes;
- política ativa diferente de the applicable 45/60-day matrix no tenant atual;
- algum write flag habilitado;
- shadow audit diferente de zero mismatch;
- health, daemon, SP-API, Gmail ou bridge com erro não explicado;
- qualquer evidência de acesso cruzado entre tenants.

## 10. Rollback

Rollback é obrigatório se a aplicação falhar após a migração e a causa não puder ser corrigida sem alterar dados.

```bash
sudo systemctl stop amazon-returns-safet.service
sudo mysql --protocol=socket -uroot -e \
  "DROP DATABASE IF EXISTS \`$target_db\`; CREATE DATABASE \`$target_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo gunzip -c "$backup" | sudo mysql --protocol=socket -uroot "$target_db"
sudo ln -sfn "releases/$(basename "$previous_release")" "$deploy/current.next"
sudo mv -Tf "$deploy/current.next" "$deploy/current"
sudo systemctl daemon-reload
sudo systemctl start amazon-returns-safet.service
sudo systemctl is-active amazon-returns-safet.service
sha256sum -c "$backup_hash"
```

Depois do rollback:

```bash
mysql --protocol=socket -uroot -Nse "SELECT COUNT(*) FROM amazon_return_cases" "$target_db"
curl --fail --silent --show-error https://returns.shopvivaliz.com.br/api/health.php
```

Exija novamente 37 casos, serviço saudável, write flags desligados e ausência de jobs presos. Preserve o backup, o relatório de verificação, logs e os hashes para auditoria.

## 11. Registro de aceite

O registro final deve conter:

- commit e release implantados;
- release anterior;
- SHA-256 do backup;
- relatório completo de migração;
- `37/37`, ownership e mismatch;
- hashes pré, pós e de ownership;
- resultado dos testes, lint, smoke, shadow e `SAFE_T_READ`;
- estado dos serviços novo e antigo;
- confirmação de todos os writes externos desligados;
- decisão de aceitar ou executar rollback, com responsável e horário UTC.

A habilitação futura de `SAFE_T_SUBMIT`, recurso, revisão por e-mail, Seller Support e reconciliação é uma sequência independente. Cada canal requer teste de produção, read-back, idempotência e aceite próprio antes do próximo.
