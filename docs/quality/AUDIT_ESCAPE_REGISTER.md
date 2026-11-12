# Registro Global de AUDIT_ESCAPE

Este registro é a memória institucional de defeitos encontrados após uma auditoria que deveriam ter sido detectados pelo escopo declarado.

## Regras
- Não registrar secrets, credenciais, dados pessoais ou conteúdo sensível.
- Cada entrada deve descrever a **classe de falha**, não apenas o caso isolado.
- A entrada só pode ser marcada como encerrada após causa funcional, causa do falso-negativo, correção/prevenção, busca por equivalentes e reauditoria.
- Toda auditoria extrema futura deve revisar as classes abaixo e exercitar as que forem aplicáveis ao projeto/release auditado.
- Quando uma classe for sistêmica, propague a prevenção para os demais repositórios aplicáveis.

## Template

| ID | Data | Projeto/Release | Classe de falha | Causa funcional | Causa do falso-negativo | Superfícies equivalentes | Prevenção adicionada | Projetos aplicáveis | Evidência de reauditoria | Status |
|---|---|---|---|---|---|---|---|---|---|---|
| ESC-YYYY-NNN | YYYY-MM-DD | repo / SHA | classe | causa | por que a auditoria não detectou | rotas/estados/jobs equivalentes | teste/gate/observabilidade/regra | repos | links/artefatos/SHA | OPEN/CLOSED |

## Entradas

| ESC-2026-001 | 2026-11-12 | Vivaliz-site/amazon-returns-safet / 8765ac3 | asset JavaScript corrigido sem invalidação de cache | tags de versão fixas continuaram apontando para URLs cacheáveis após alteração de `cockpit.js`/`cockpit-operational.js` | a auditoria confirmou o conteúdo servido no servidor, mas não comparou a URL versionada do asset no HTML nem repetiu a jornada em uma sessão com cache prévio | todos os scripts JS do cockpit referenciados por `index.php` e telas administrativas com `max-age` | contrato Node exige versão específica por entrega e rejeita os identificadores antigos; reauditoria deve abrir uma sessão nova e verificar URL, conteúdo e jornada | Amazon Returns SAFE-T e demais painéis web com assets versionados | `tests/cockpit-closed-loss-and-view-url.test.mjs`; contratos PHP do cockpit; E2E pós-deploy pendente até o release final | OPEN |
