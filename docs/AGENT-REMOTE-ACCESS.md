# Acesso remoto canônico dos agentes — Amazon Returns SAFE-T

**Estado vigente: 2026-11-01.** Leitura obrigatória antes de ações sobre VM, serviço, deploy, banco, UI Seller Central, logs ou recuperação. As normas atuais em `Vivaliz-site/site-shopvivaliz@main` prevalecem sobre qualquer registro histórico deste repositório.

## VMs operacionais e identidade

- `marina-a1-4c24g` (OCI ESPOSA): **produção web, Amazon Returns, deploy, MySQL e workers**. Endereço privado VCN `10.80.1.116`; endereço Tailscale verificado em registros `100.118.170.46`. Confirmar identidade e acesso vivo antes de qualquer uso.
- `always-free-arm-1787907847-26` (OCI PRINCIPAL): **backend, controlador MCP central, navegador de agentes e relay corporativo**. Privado `10.0.1.38`; endereço Tailscale conhecido `100.66.174.74`.
- `shopvivaliz-free-a1`: **VM antiga excluída**. **NUNCA** acessar, monitorar, criar sessão, executar SSH, deploy, workflow ou recriar esse host. Endereços e aliases históricos não são destinos substitutos da Marina.
- `Fred-Win` e `KOCEPSV`: estações de apoio autorizadas somente na função documentada; não substituem o navegador de agentes da backend.

## Ordem de acesso e controles

1. **MCP ShopVivaliz Remote Control / controlador** para capacidades expressamente provisionadas. O MCP central reconhece a Marina, mas uma listagem não prova `route_enabled=true` nem autoriza escrita. A via independente Marina deve ser validada separadamente; não confundir sua disponibilidade com RDC.
2. **Remote Desktop Commander 1/2 diretamente na Marina** quando a conta, dispositivo e permissão real forem confirmados pela listagem vigente. A conexão atualmente conhecida atua como `ubuntu`, não como root implícito; qualquer `sudo` precisa de autorização efetiva e controles próprios. Nunca usar conexão para outro dispositivo/conta como fallback para contornar recusa.
3. **SSH privado / Tailscale por rota previamente autenticada, com chave do host pinada e identidade/lease específicos**. Não presumir que o usuário legado `shopvivaliz-agent`, chave histórica ou IP privado entre tenancies estejam habilitados. Não fazer SSH público, login root ou senhas por tentativa.
4. **Interface gráfica real** para Seller Central e qualquer auditoria de UI; o navegador corporativo de agentes deve executar **somente na backend** `always-free-arm-1787907847-26`, com sessão/perfil, MFA, lease e controles oficiais. API, SQL e screenshots isolados complementam, mas não substituem o E2E visual.
5. **GitHub Actions/OCI Bastion e control plane** destinam-se a bootstrap/recuperação oficialmente permitidos, nunca bypass de RBAC, recusa de ferramenta, MFA, SSH ou proteção da branch.

## Proteções para Amazon Returns SAFE-T

- Primeira tentativa operacional de elegibilidade/abertura em **D+45 após reembolso ao comprador**, preservando a exigência de elegibilidade **real** na interface Amazon por programa e caso. O calendário interno não modifica as condições do provedor.
- Antes de qualquer SAFE-T: verificar pedido, devolução/recebimento físico, crédito ao vendedor, elegibilidade, existência de claim, idempotência e readback. **Não** enviar claim duplicada, apoio, recurso ou movimento no ERP sem gates e autorização aplicáveis.
- Manter kill switch, sete canais externos, outbox, locks e leases sem alteração por simples auditoria. Não usar uma operação de leitura para habilitar escrita.
- Senha, usuário, TOTP, cookies, chaves e tokens provisionados são **dados protegidos**, não devem ser copiados entre perfis, registrados em logs ou solicitados novamente sem comprovar falha por via autorizada.
- Produção usa releases imutáveis. Nunca editar `current/` ou release ativa diretamente; seguir PR, checks, merge autorizado, deploy canônico e readback de SHA.

## Fontes de verdade

- `Vivaliz-site/site-shopvivaliz/docs/knowledge/atendimento-chatgpt-bootstrap.md`
- `Vivaliz-site/site-shopvivaliz/docs/knowledge/host-access.md`
- `Vivaliz-site/site-shopvivaliz/docs/knowledge/agent-rules.md`
- `Vivaliz-site/site-shopvivaliz/docs/knowledge/current-topology-and-access-2026-10-29.md`
- `Vivaliz-site/amazon-returns-safet/AGENTS.md`
- `Vivaliz-site/amazon-returns-safet/docs/runbooks/shopvivaliz-d45-operational-policy.md`

Em divergência, consultar `main` atualizado dos documentos superiores e validar o runtime antes de agir. Não simular grant, sessão, E2E, SHA publicado ou aprovação.
