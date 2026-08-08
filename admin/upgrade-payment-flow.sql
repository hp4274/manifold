-- ==========================================================================
-- Payment-first application flow.
--
-- The applicant now pays as soon as the form is submitted: no admin approval
-- step before payment. Statuses collapse to
--   payment_pending → payment_review → complete   (or rejected)
--
-- Run once:  mysql -u root -p manifold < upgrade-payment-flow.sql
-- ==========================================================================

USE `manifold`;

-- 1. Widen the column so old and new values both fit ------------------------
ALTER TABLE `applications`
  MODIFY `status` ENUM('new','pending','confirmed','payment_pending','complete','rejected','payment_review')
  NOT NULL DEFAULT 'payment_pending';

-- 2. Everything waiting on money becomes payment_pending --------------------
UPDATE `applications` SET `status` = 'payment_pending'
 WHERE `status` IN ('new','pending','confirmed');

-- Anything that had already uploaded a receipt is waiting on verification
UPDATE `applications` SET `status` = 'payment_review'
 WHERE `payment_proof_path` IS NOT NULL
   AND `payment_verified_at` IS NULL
   AND `status` = 'payment_pending';

-- 3. Drop the retired values ------------------------------------------------
ALTER TABLE `applications`
  MODIFY `status` ENUM('payment_pending','payment_review','complete','rejected')
  NOT NULL DEFAULT 'payment_pending';

-- 4. Payment bookkeeping ----------------------------------------------------
ALTER TABLE `applications`
  ADD COLUMN `payment_amount`        DECIMAL(10,2) NOT NULL DEFAULT 3500.00 AFTER `terms_accepted`,
  ADD COLUMN `payment_rejected_at`   DATETIME     NULL AFTER `payment_verified_at`,
  ADD COLUMN `payment_reject_reason` VARCHAR(255) NULL AFTER `payment_rejected_at`,
  ADD COLUMN `reminded_at`           DATETIME     NULL AFTER `payment_reject_reason`,
  ADD COLUMN `reminder_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `reminded_at`;
