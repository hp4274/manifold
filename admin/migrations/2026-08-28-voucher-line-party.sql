-- A voucher line belongs to a party, not just to a sale.
--
-- One completed sale owes two people: the dealer their commission and that
-- dealer's distributor the override. Keying the line by application alone made
-- whichever of them claimed first block the other, so the second was quietly
-- never paid. The line carries who it is for, and the unique key is the three
-- of them together — which is also what makes double-claiming impossible at the
-- database rather than only in the query that builds a voucher.

ALTER TABLE commission_voucher_lines
  ADD COLUMN party_type enum('dealer','distributor') NOT NULL DEFAULT 'dealer' AFTER voucher_id,
  ADD COLUMN party_id   int(10) unsigned NOT NULL DEFAULT 0 AFTER party_type;

UPDATE commission_voucher_lines l
   JOIN commission_vouchers v ON v.id = l.voucher_id
    SET l.party_type = v.party_type, l.party_id = v.party_id;

-- the old key is what the voucher foreign key leans on, so give that key
-- something else to stand on before taking it away
ALTER TABLE commission_voucher_lines ADD KEY idx_line_voucher (voucher_id);

ALTER TABLE commission_voucher_lines
  DROP INDEX uq_line_voucher_app,
  ADD UNIQUE KEY uq_line_party_app (application_id, party_type, party_id);
