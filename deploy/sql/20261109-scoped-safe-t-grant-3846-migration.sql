-- STAGED ONLY. Applies owner-approved #3846 renewal to an existing MySQL
-- scoped-grant ledger (the original CREATE TABLE IF NOT EXISTS does not
-- update an already-installed CHECK constraint).
-- No automatic execution. Require owner approval on this PR, MySQL backup,
-- isolated restore rehearsal and database writer lease before DBA application.
-- Never reset status/consumed_at/quarantined_at, and never replay #3556.
-- Must run once via the canonical schema-migration ledger; check that the
-- existing constraint is exactly chk_scoped_grant_known before applying.
ALTER TABLE `amazon_return_scoped_grant_consumptions`
  DROP CHECK `chk_scoped_grant_known`,
  ADD CONSTRAINT `chk_scoped_grant_known`
    CHECK (`grant_number` IN (3556,3673,3846));
