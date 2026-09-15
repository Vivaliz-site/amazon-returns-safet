# Estado da Auditoria

**Status:** NÃO APTO

Auditoria formal executada segundo `EXTREME_AUDIT_PROTOCOL.md` e o overlay específico do projeto.

## Última auditoria válida
- Data: 2026-09-15
- Commit/SHA auditado: `bbd0ec11405717cafdc7b817b009d9745b88fbd8`
- Release/ambiente comprovado: produção `/home/ubuntu/amazon-returns-deploy/current` aponta para release do mesmo SHA `bbd0ec114057...`
- Veredito: **NÃO APTO PARA CERTIFICAÇÃO**
- Confiança do veredito: alta
- Stop-the-line: rotina operacional Seller Central agendada encontra-se em estado `failed`

## Evidência executada
- suíte PHP completa passou;
- auditoria SQL tenant-scoped passou;
- teste de isolamento tenant passou;
- testes Node passaram;
- teste TOTP e suíte de continuidade Python passaram;
- lint PHP, `node --check`, `bash -n` e varredura de chaves/SSH inseguro passaram;
- GitHub Actions `Amazon Returns CI` do SHA auditado concluiu com sucesso;
- `amazon-returns-safet.service` está `active/running` em produção e executa o mesmo SHA auditado;
- o limite externo de recuperação D+90 está codificado/testado; D+75 é um limite de canal direto SAFE-T com fallback para Seller Support, não o encerramento global do caso.

## Achados materiais
### P1 — rotina Seller Central periódica falhando
`amazon-returns-seller-central-browser.service` está em `failed` desde o ciclo de 2026-09-14 23:01 UTC. A execução terminou com `Seller Central CDP did not become ready` e status 75/TEMPFAIL. O timer executa duas vezes por dia.

Um teste isolado com o mesmo binário Chromium, perfil temporário e CDP em `127.0.0.1:9225` ficou pronto com sucesso. Portanto browser/binário/porta são capazes de funcionar; a causa específica do ciclo produtivo ainda requer correlação com perfil/configuração protegida. Não foi feita alteração especulativa.

Impacto: leituras/descobertas dependentes do Seller Central podem deixar de ocorrer no prazo esperado. Em um sistema de recuperação financeira com janelas temporais, isso é bloqueador de certificação.

### P1 — verificação de dados live incompleta nesta execução
A verificação tenant/live de banco é root-only e o ambiente atual bloqueou elevação (`no new privileges`). O daemon e CI forneceram evidência forte, mas contagens atuais de casos/outbox/dead letters e zero `PROCESSING` não foram novamente comprovadas diretamente nesta auditoria.

## Invariantes verificados
| Invariante | Resultado |
| --- | --- |
| isolamento tenant/connection | PASS em testes/SQL audit |
| D+90 como limite externo de recuperação | PASS |
| D+75 não encerra recuperação global | PASS; fallback Seller Support |
| CI do SHA de produção | PASS |
| daemon principal ativo no SHA auditado | PASS |
| ciclo Seller Central periódico | **FAIL** |
| zero jobs/casos presos em produção | NÃO REVALIDADO diretamente |

## Risco residual
Alto para casos cuja observação/ação depende do Seller Central enquanto o timer permanecer falhando. Não existe evidência suficiente para afirmar cobertura ponta a ponta de todos os casos ativos nesta execução.

## Dívida de evidência / saída do NO-GO
1. corrigir e provar o próximo ciclo Seller Central;
2. executar `verify-live-tenant-foundation.sh` com privilégio autorizado;
3. comprovar zero `PROCESSING` vencido, dead letters e pendências sem próximo passo;
4. auditar caso a caso todos os casos ativos dentro do limite de 90 dias;
5. reexecutar o Gate Final de Completude.

## Regra de validade
Esta auditoria cobre somente o SHA/release registrado. Mudança material ou correção do achado operacional exige reauditoria proporcional ao risco.
