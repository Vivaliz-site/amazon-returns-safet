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

| ESC-2026-001 | 2026-11-12 | Vivaliz-site/amazon-returns-safet / PR #668 (`827a175`) | asset JavaScript corrigido sem invalidação de cache | tags fixas continuaram apontando para URLs cacheáveis após alteração do cockpit | a auditoria confirmou o conteúdo servido no servidor, mas não comparou a URL versionada no HTML nem repetiu a jornada em sessão com cache prévio | todos os scripts JS e CSS do cockpit referenciados por `index.php` | hash SHA-256 do arquivo no parâmetro `v`, contratos de cache e reauditoria com sessão nova verificando URL/conteúdo/jornada | Amazon Returns SAFE-T e demais painéis web com assets versionados | PRs #668/#670; contratos `cockpit-closed-loss-and-view-url`, `cockpit-operational-audit` e `resolved-review-ui-guard`; pós-deploy ainda pendente | OPEN |
