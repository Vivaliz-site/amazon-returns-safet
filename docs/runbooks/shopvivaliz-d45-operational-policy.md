# ShopVivaliz: operational SAFE-T opening at D+45

Approved by the owner on 2026-09-05. This is the application's operational rule, not a representation that Amazon guarantees SAFE-T acceptance after 45 days.

- Start the first eligible attempt at D+45, from the existing seller-debit timestamp (refund timestamp fallback). A missing return is not a physical receipt.
- Preserve STANDARD, DBA and FBA Onsite classification; ordinary FBA uses its own recovery path.
- Preserve Amazon's actual eligibility response, wait-until date and deadline. A preflight block cannot be bypassed, ignored or retried as duplicate submissions.
- First denial: analyze, then appeal inside the existing SAFE-T. Denied appeal: analyze and request detailed email review. Analyze that reply before responding or escalating.
- Never wait past an existing appeal deadline merely because another message promises a future credit.
- Only actual released reconciled credit can mark RECOVERED. Damaged/partial receipt cannot become RECEIVED_OK.
- No external writes are enabled by deploying this rule.

`RETURN_NOT_RECEIVED_D45_V1` supersedes the legacy D+75 records for this tenant only. Legacy identifiers, amounts, dates, sources and hashes remain stored. Policy metadata is immutable; a changed rule requires a new key/version.

External evidence is separate: Amazon's announcement for some DBA/FBA Onsite orders from 2026-04-21 describes 60 days. Do not rewrite that announcement as 45 days. Respect the actual eligibility check while retaining the owner's D+45 operational starting point.
