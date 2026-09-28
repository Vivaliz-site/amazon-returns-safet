# Acesso canônico aos hosts ShopVivaliz
Fonte central: `Vivaliz-site/site-shopvivaliz/docs/knowledge/host-access.md`. Este arquivo não contém segredos.

## Hosts
- `shopvivaliz-free-a1` — produção web/deploy — `10.0.1.112`.
- `always-free-arm-1787907847-26` — backend/controller/browser — `10.0.1.38`.
- Fred-Win / `LAPTOP-NIG4IFUU` — reverse SSH no backend `127.0.0.1:2222`.
- KOCEPSV / `DESKTOP-KOCEPSV` — reverse SSH no backend `127.0.0.1:2223`.

## Estado 2026-09-28
- Linux controller + target de produção: bootstrap comprovado.
- Fred-Win `2222`: PASS recente.
- KOCEPSV `2223`: rota canônica, ainda não comprovada operacionalmente. Tratar como indisponível até validação fresca.

## Regras operacionais
- Navegador de agente somente no backend; nunca em Fred-Win/KOCEPSV.
- Shell Linux por SSH privado/Tailscale e identidade dedicada. SSH público, senha interativa e root público proibidos.
- OCI Bastion/control plane auditável é bootstrap/recovery quando não houver rota privada.
- RustDesk self-hosted é GUI principal; Desktop Commander apenas contingência.
- Windows runtime por reverse SSH `2222/2223`; relays `5557/5558` apenas bootstrap/recovery.
- Antes de operar, provar `hostname`, `whoami`/`id`, `pwd` e `git status --porcelain` quando aplicável.
- Produção é imutável: não editar `/home/ubuntu/shopvivaliz-deploy/current/` nem release ativa.
- Remote Control MCP: controller loopback `127.0.0.1:5580`; endpoint nunca público; GitHub não é transporte/queue/heartbeat normal em runtime.
- Não documentar valores de chaves, senhas, tokens, cookies, OTP/TOTP ou secrets.
