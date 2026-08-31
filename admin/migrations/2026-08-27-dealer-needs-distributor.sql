-- Every dealer answers to a distributor.
--
-- Two changes, and the order matters: the foreign key has to stop nulling the
-- column before the column can refuse nulls.
--
--   1. ON DELETE SET NULL would leave a dealer answering to nobody the moment a
--      distributor was deleted, which is the thing this rule forbids. RESTRICT
--      makes the database refuse that deletion instead — the admin refuses it
--      first, with a message, but the database is what makes it true.
--   2. distributor_id becomes NOT NULL.
--
-- Run the check first. If it returns any rows, give those dealers a distributor
-- before running the rest — the ALTER will fail otherwise, which is the correct
-- outcome rather than a silent guess at who they belong to.

SELECT id, dealer_code, full_name
  FROM dealers
 WHERE distributor_id IS NULL;

ALTER TABLE dealers DROP FOREIGN KEY fk_dealer_distributor;

ALTER TABLE dealers MODIFY distributor_id int(10) unsigned NOT NULL;

ALTER TABLE dealers
  ADD CONSTRAINT fk_dealer_distributor
      FOREIGN KEY (distributor_id) REFERENCES distributors (id) ON DELETE RESTRICT;
