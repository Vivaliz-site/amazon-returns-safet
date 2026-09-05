# Email review evidence and independent channel gates

This patch preserves D+75 and does not enable an external write.

- Recognize the actual internal-review response patterns observed in the existing seller-support messages as WAIT, not a reason to duplicate a support case or review request.
- A support survey or generic resolution message remains HUMAN_REVIEW; it is not financial credit.
- Validate the actual sender mailbox domain exactly (including legitimate Amazon subdomains), rejecting lookalike suffixes and unrelated display-name addresses. Domain validation is not a replacement for mail authentication.
- SAFE_T_EMAIL_REVIEW remains controlled by AMAZON_RETURNS_EMAIL_REVIEW_WRITE.
- SAFE_T_EMAIL_REPLY now requires its own AMAZON_RETURNS_EMAIL_REPLY_WRITE, default false.
- Existing terminal loss approval remains explicitly gated. No payment state is inferred from an email.

Tests cover redacted real response patterns, lookalike sender rejection, ambiguous survey text, and independent channel enabling in both directions.
The original runtime flag test explicitly enables the new reply flag; no positive/negative assertions were removed.
All 44 PHP test files, SQL audit 59 files and changed-source syntax checks passed on the pre-PR13 base.

Before enabling either email channel, correlate the real sent thread and verify that a duplicate request will not be sent. A historical sent review without a new response should WAIT.
