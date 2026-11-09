# ShopVivaliz: first opening at D+45, resumption on Amazon's requested date

## Owner-approved operating rule (2026-09-05)
First operational SAFE-T eligibility/opening attempt is D+45, measured from the confirmed Amazon refund to the buyer. A later seller-debit timestamp does not delay this opening. Do not replace the initial D+45 attempt with another internal waiting period.
If Amazon requests a wait, store the exact requested date and the response evidence. Resume or reopen in the appropriate existing channel on that date, after refreshing and reconciling the financial evidence. Never create a duplicate claim just to restart a timer.
A day-only Brazilian date starts at 00:00 America/Sao_Paulo; an explicit instruction to return *after* a date resumes the following day. Relative calendar-day waits use the original response timestamp, not each polling time. Ambiguous dates and unsupported business-day calculations require human review.

## External eligibility is independently verified
The first D+45 attempt must open the **official eligibility check in Seller Central**, not assume the claim has been accepted. Amazon may impose a different program-specific submission window. That official rule remains binding and can be consulted using the original references below; the owner's first-attempt schedule does not rewrite Amazon's terms.
If the official eligibility check refuses submission, record BLOCKED_UNTIL, the original evidence and any date actually supplied by Amazon; wait and resume on that date without creating duplicates. Never bypass an eligibility block, CAPTCHA, authentication, a disabled submit button or the channel write gates. A draft/pending outbox item is not a claim submitted to Amazon.

## Automatic administrative close at D+90 (owner instruction 2026-10-11)

At or after 90 elapsed UTC days from the *customer refund timestamp* `refund_at`,
the routine scheduler closes unresolved internal recovery cases as `CLOSED_LOSS`,
with terminal reason `OWNER_ADMINISTRATIVE_CLOSURE_AFTER_90D`. It does **not**
credit the seller, mark the case `RECOVERED`, erase a partial credit, cancel an
Amazon SAFE-T/support ticket, or overwrite the Amazon correspondence.

Do **not** close `RECEIVED_OK`, any case with an explicitly confirmed physical
receipt (including warehouse `PHYSICAL_RECEIVED` evidence), already recovered or
closed cases, or cases with an in-flight outbox task (`PENDING`/`PROCESSING`).
In-flight cases remain eligible for a subsequent scheduler pass after the task
finishes. Cases without a confirmed refund timestamp are not aged by guesswork.

Each closure and its original state, cutoff, refundable amount, already
reconciled credit and unrecovered balance are persisted atomically in
`CASE_CLOSED_ADMIN_90D` (`INTERNAL`) with a scoped, deterministic idempotency
key. Concurrent delayed Gmail/support/financial state updates must not reopen a
previously closed `CLOSED_LOSS` case. All source and evidence histories remain.

This is an *administrative loss closure*, not an Amazon eligibility rule and not
an authorization to submit or retry claims after their official deadlines.
Existing D+45 opening, external eligibility and writing controls remain unchanged.

## Verificação de novos SAFE-T: descartar reembolsos a partir de D+90 (09/11/2026)

Por instrução expressa do proprietário, **não colocar em novas buscas
`SAFE_T_DISCOVERY` reembolsos cujo `refund_at` confirmado complete
90 dias UTC ou mais**. O mesmo corte vale para a reconciliação diária de
reembolsos posterior ao último SAFE-T. Tarefas `SAFE_T_DISCOVERY` antigas
ainda `PENDING`, ou `PROCESSING` somente após o lease vencer, devem ser
marcadas `SUPERSEDED` com motivo `REFUND_D90_DISCOVERY_EXPIRED`,
preservando a causa de `UI_DRIFT` anterior na outbox e todas as evidências
históricas. Nunca presumir idade quando `refund_at` for desconhecido,
inválido ou ausente: continuar com classificação apropriada, sem
reivindicação externa baseada em suposição.

**Importante:** a exclusão afeta a busca de **novas reivindicações**;
não equivale a cancelar um SAFE-T já aberto. `SAFE_T_READ` de um
`safe_t_id` conhecido, acompanhamento de recurso ou chamado existente,
reconciliação financeira e conferência fiscal no ERP continuam independentes,
inclusive quando ultrapassarem D+90. Não dar baixa contábil fictícia nem
executar movimento externo por causa deste filtro.

## Excluir novas verificacoes SAFE-T apos D+90 (proprietario, 09/11/2026)

Na verificacao automatica de SAFE-T, usar apenas o instante comprovado do
reembolso ao comprador (`refund_at`) como referencia. A partir de 90 dias
UTC completos, **nao enfileirar ou continuar as leituras de descoberta
(`SAFE_T_DISCOVERY`) e de status (`SAFE_T_READ`)** daquele reembolso.
Tarefas `PENDING` e `PROCESSING` com lease realmente expirado passam a
`SUPERSEDED`, com causa e diagnostico anterior preservados. Nao cancelar
a execucao com lease ativo nem supor expiracao sem `refund_at` valido.

Esta exclusao e **da rotina de verificacao**, nao uma regra externa da
Amazon, nao cancela a reivindicacao existente nem recurso, atendimento,
conciliacao financeira ou devolucao contabil. Nao apagar eventos e nao
registrar credito ficticio. Ao encerrar casos administrativamente por D+90,
**preservar a exclusao obrigatoria de recebimento fisico confirmado**:
`RECEIVED_OK` ou evento `PHYSICAL_RECEIVED` da origem `WAREHOUSE`
nao podem ser fechados automaticamente como perda.

## Channel and evidence safeguards
Use SAFE-T only for applicable seller-fulfilled claims. Lost/damaged shipment, standard FBA, physical damage, existing appeal, active support and email reply each require their appropriate channel and evidence; D+45 alone is not authorization to use the wrong channel.
An existing claim is resumed, not submitted as a new duplicate. A valid appeal window is required. If Amazon asks the seller to wait beyond the official internal SAFE-T appeal deadline, the internal appeal deadline preempts that wait and the existing SAFE-T is appealed while the window is still open. If that deadline is missing, fail closed to human review; if it already expired during the conflicting wait, record the missed-window conflict instead of pretending an internal appeal is still available.
The automatic second-stage review email is allowed only after the internal SAFE-T appeal was actually denied (`APPEAL_DENIED_FINAL` or equivalent explicit appeal-denied evidence). A historical case that simply missed the internal appeal window is human review, not an automatic second-stage email. A review-email response uses its existing thread. An existing support case is updated instead of opening another.
No date, approval, rejection, email promise or successful request is proof of recovered money. Only reconciled real financial credit closes financial recovery.

## Storage and acceptance
The versioned key is RETURN_NOT_RECEIVED_D45_REFUND_V2, scoped to the shopvivaliz tenant. Legacy rule values remain in SUPERSEDED records; another tenant is not changed.
Source refresh and reconciliation are separately recorded and linked. New API failure or stale financial evidence removes resumption clearance.
next_action_at exposes the requested date; idempotency derives from the original response/date rather than the polling clock.
All external channel flags remain OFF until their production acceptance gate passes. Configuration/tests passing does not prove an external request has been sent.

## Primary references (checked 2026-09-05)
https://sellercentral.amazon.com.br/seller-forums/discussions/t/21dbbb44-5916-4a1c-8dfa-87aef8c5aab3
https://sellercentral.amazon.com.br/seller-forums/discussions/t/374d2692-2b56-411e-b497-b37d7cb1229b
https://sellercentral.amazon.com.br/help/hub/reference/GQG3WU8KETHHJN25
## Exact D+45 opening blockers
When Amazon has refunded the customer and D+45 is reached, create the SAFE-T opening regardless of the transport label or whether the package is marked returning, lost, refused, damaged, delivered by carrier, or unknown.
A new SAFE-T opening is suppressed only when either (a) the seller has already received the full reconciled reimbursement, or (b) the seller explicitly recorded physical arrival through the application intake routine. The authoritative physical-arrival evidence is `PHYSICAL_RECEIVED` from source `WAREHOUSE`; tracking or marketplace status alone is not seller confirmation.
Partial credit does not suppress SAFE-T. A promise of future reimbursement does not suppress the initial D+45 opening. Missing `seller_debit_at` does not add a third blocker after the policy engine has already established D+45 eligibility.
