# ShopVivaliz: first opening at D+45, resumption on Amazon's requested date

## Owner-approved operating rule (2026-09-05)
First operational opening is at D+45, measured from the recorded seller debit with the existing refund-date fallback. Do not silently substitute D+60 or D+75 for this first attempt.
If Amazon requests a wait, store the exact requested date and the response evidence. Resume or reopen in the appropriate existing channel on that date, after refreshing and reconciling the financial evidence. Never create a duplicate claim just to restart a timer.
A day-only Brazilian date starts at 00:00 America/Sao_Paulo; an explicit instruction to return *after* a date resumes the following day. Relative calendar-day waits use the original response timestamp, not each polling time. Ambiguous dates and unsupported business-day calculations require human review.

## External policy is not rewritten
Amazon's announcement describes 60 days after customer refund for applicable FBA Onsite/DBA orders from 2026-04-21, and 45 days for older orders. That evidence remains distinct from this owner's D+45 first-attempt instruction.
The Amazon eligibility check must still run. Record BLOCKED_UNTIL and the date actually returned; never bypass an eligibility block, CAPTCHA or authentication requirement.

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
