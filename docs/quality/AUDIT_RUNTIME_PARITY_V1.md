# AUDIT_RUNTIME_PARITY_V1 — Regra Global de Auditoria de Operação Real

Esta regra é **obrigatória e inseparável** de `AUDIT_POLICY.md` e `docs/quality/EXTREME_AUDIT_PROTOCOL.md` em toda auditoria formal, validação de release ou declaração de sistema pronto/apto. Ela impede falso positivo em que um fluxo funciona localmente, mas falha no ambiente realmente publicado.

## 1. Regra de equivalência operacional
Uma tela carregada, healthcheck verde, HTTP 200, teste unitário/integrado ou fluxo local bem-sucedido não certifica o comportamento publicado. Para cada operação aplicável, execute `operação → cobertura local → cobertura staging/preview/publicada → execução real → persistência/efeito → evidência`. Operações incluem, conforme o domínio: Create/Read/Update/Delete/Archive/Restore/Cancel/Reopen/Retry/Undo/Approve/Reject/Confirm/Send/Charge/Refund/Appeal/Reconcile/Import/Export/Deploy/Rollback. Fluxo crítico presente localmente e ausente da homologação do release é **DÍVIDA DE EVIDÊNCIA** e bloqueia `APTO`.

## 2. UI real é obrigatória quando existe UI
Toda operação crítica ou de alteração de estado deve ser exercitada pela UI real no navegador contra o mesmo release/ambiente certificado. API, SQL, fixtures e scripts podem preparar dados ou verificar efeitos, mas não substituem a ação do operador. Cubra dados novos e registros já existentes/legados quando a diferença puder alterar o comportamento. Após mutação: confirme feedback, recarregue, navegue para fora e retorne, confirme persistência na UI, confirme efeito durável quando autorizado e valide histórico/reconciliação quando aplicável.

## 3. Gate fatal de navegador e servidor
Qualquer evento inesperado reprova o fluxo até investigação: HTTP 5xx da aplicação; `pageerror`; `requestfailed`; `console.error`; tela de erro de framework/proxy/servidor, inclusive equivalente a `This page couldn't load`; navegação/Server Action que termina em erro. Exceção só em allowlist estreita, versionada e justificada. Resposta 4xx só é sucesso em teste negativo que prove ser o comportamento esperado.

## 4. Paridade local × ambiente publicado
Antes do veredito, inventarie testes/fluxos locais e compare com os realmente executados em staging/preview/publicação. É proibido certificar usando subconjunto “representativo” que omita operação crítica. A homologação deve confirmar que o SHA/build/digest esperado é o servido pelo ambiente; sem isso, `VERSÃO EM PRODUÇÃO NÃO COMPROVADA`.

## 5. Evidência mínima por fluxo
Registre quando aplicável: SHA/release/build e ambiente; entidade; passos; antes/depois; screenshot/trace; rede/status HTTP; logs/correlation ID; persistência/efeito externo; teste correspondente; reexecução contraditória. “Não encontrei erro” sem trilha não prova correção.

## 6. Projetos sem UI
API-only, workers, pipelines e automações aplicam a mesma regra pela interface operacional canônica: endpoint real, fila, scheduler, webhook, CLI operacional ou job publicado. Mock isolado/chamada interna não substitui caminho produção-equivalente. Valide entrada → persistência → processamento → efeito → confirmação → reconciliação, incluindo timeout/retry/idempotência/duplicação/restart/falha parcial quando aplicáveis.

## 7. AUDIT_ESCAPE
Defeito manual descoberto após auditoria e que deveria estar no escopo é `AUDIT_ESCAPE`: reproduza; encontre causa funcional e causa do falso negativo; identifique a classe de falha; procure equivalentes; atualize a regra global quando sistêmico; reaudite a classe nos demais projetos aplicáveis; invalide certificações incompatíveis até revalidação.

## 8. Gate de conclusão
Não pode haver `APTO` com operação crítica não executada no release certificado; cobertura crítica local sem evidência publicada; 5xx/pageerror/requestfailed/console.error inesperado sem resolução; mutação sem confirmação pós-reload/revisita; efeito externo sem confirmação/reconciliação; versão publicada não comprovada; área crítica `NÃO VALIDADO`.

## 9. Reauditoria contraditória
Após correções, repita fluxos tentando quebrá-los com outro registro/estado e edge/failure path. O objetivo é tentar provar que a conclusão de correção está errada.

**Marker de governança:** `AUDIT_RUNTIME_PARITY_V1`
