-- Commission vouchers, and the R&F role that pays them.
--
-- A voucher is a claim for commission a partner has earned and not been paid.
-- It travels up the chain — dealer, distributor, R&F, office — and the money
-- comes back down through R&F. See CLIENT-FLOW.md §10.

-- R&F signs in at the same door as the office and lands somewhere else, so the
-- account table needs to say which they are.
ALTER TABLE admin_users
  ADD COLUMN role enum('admin','rf') NOT NULL DEFAULT 'admin' AFTER email;

CREATE TABLE IF NOT EXISTS commission_vouchers (
  id              int(10) unsigned NOT NULL AUTO_INCREMENT,
  -- who is claiming. A bundle is raised by a distributor and carries their own
  -- claim as well as their dealers', which is why it is not a separate kind.
  party_type      enum('dealer','distributor') NOT NULL,
  party_id        int(10) unsigned NOT NULL,
  -- a dealer voucher joins its distributor's bundle; a bundle has none
  parent_id       int(10) unsigned DEFAULT NULL,
  is_bundle       tinyint(1) NOT NULL DEFAULT 0,
  -- the Friday this belongs to, so a run can be repeated without duplicating
  cycle_date      date NOT NULL,
  status          enum('with_distributor','bundled','with_rf','with_admin',
                       'funded','paid','rejected','cancelled') NOT NULL DEFAULT 'with_distributor',
  -- frozen when raised: what the lines added up to at that moment
  amount          decimal(12,2) NOT NULL DEFAULT 0.00,
  reject_reason   varchar(255) DEFAULT NULL,
  payment_reference varchar(120) DEFAULT NULL,
  raised_at       datetime NOT NULL DEFAULT current_timestamp(),
  decided_at      datetime DEFAULT NULL,
  paid_at         datetime DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_voucher_party (party_type, party_id, status),
  KEY idx_voucher_parent (parent_id),
  KEY idx_voucher_cycle (cycle_date),
  CONSTRAINT fk_voucher_parent FOREIGN KEY (parent_id) REFERENCES commission_vouchers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which sales a voucher is made of. This is what stops a sale being claimed
-- twice: a claimed application already has a line, and the query that builds a
-- voucher skips anything that does.
CREATE TABLE IF NOT EXISTS commission_voucher_lines (
  id             int(10) unsigned NOT NULL AUTO_INCREMENT,
  voucher_id     int(10) unsigned NOT NULL,
  application_id int(10) unsigned NOT NULL,
  amount         decimal(10,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_line_voucher_app (voucher_id, application_id),
  KEY idx_line_application (application_id),
  CONSTRAINT fk_line_voucher FOREIGN KEY (voucher_id) REFERENCES commission_vouchers (id) ON DELETE CASCADE,
  CONSTRAINT fk_line_application FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every state change, so a disputed payment has a history and not just a
-- current state.
CREATE TABLE IF NOT EXISTS commission_voucher_events (
  id          int(10) unsigned NOT NULL AUTO_INCREMENT,
  voucher_id  int(10) unsigned NOT NULL,
  from_status varchar(30) DEFAULT NULL,
  to_status   varchar(30) NOT NULL,
  actor       varchar(60) NOT NULL,
  note        varchar(255) DEFAULT NULL,
  created_at  datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_event_voucher (voucher_id),
  CONSTRAINT fk_event_voucher FOREIGN KEY (voucher_id) REFERENCES commission_vouchers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the payout rows R&F writes when it pays a line point back at the voucher
ALTER TABLE dealer_payouts      ADD COLUMN voucher_id int(10) unsigned DEFAULT NULL;
ALTER TABLE distributor_payouts ADD COLUMN voucher_id int(10) unsigned DEFAULT NULL;
