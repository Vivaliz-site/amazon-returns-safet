# Stale resolved review UI guard

Problem: a review can be resolved automatically after new evidence changes the deterministic action, while an already-open browser panel can still display the old review as actionable.

Required behavior:
- only `OPEN` reviews are actionable through the review detail endpoint;
- non-open review reads return HTTP 409 with `REVIEW_NOT_OPEN`;
- case detail never labels historical resolved/decided reviews as `current_review`;
- cockpit closes a stale review panel and refreshes the review queue and pending count.

Regression target: order `701-0630116-9129834` moved from human review to `CHECK_FINANCES / CLASSIC_FBA_SEPARATE_REIMBURSEMENT_ROUTE` after Gmail evidence reconciliation, so its old review must disappear automatically.
