-- --------------------------------------------------------------------------
-- Manifold Clean Energy — two-stage payments.
--
-- Before: one application fee (3,500) that could be paid in any number of
-- instalments, and `complete` meant that fee was settled.
-- After:  two fixed transfers per application — a booking amount with the
-- application and a delivery amount when the unit is handed over — each with
-- its own proof and its own admin decision. `complete` now means both are
-- verified.
--
-- Safe to run once against an existing database. Take a backup first:
--   mysqldump -u root manifold > manifold-before-two-stage.sql
--   mysql -u root manifold < admin/migrations/2026-08-15-two-stage-payments.sql
--
-- Existing rows are mapped like this:
--   payment_pending → booking_pending   nothing paid yet
--   payment_review  → booking_review    a receipt is waiting to be checked
--   complete        → delivery_pending  the old fee was the booking amount, so
--                                       these applicants now owe the delivery
--                                       amount and keep their booking credit
-- --------------------------------------------------------------------------

SET @old_time_zone = @@time_zone;

-- --------------------------------------------------------------------------
-- 1. New columns on applications.
-- --------------------------------------------------------------------------
ALTER TABLE `applications`
  ADD COLUMN `booking_amount`   decimal(10,2) NOT NULL DEFAULT 3500.00 AFTER `payment_amount`,
  ADD COLUMN `delivery_amount`  decimal(10,2) NOT NULL DEFAULT 0.00    AFTER `booking_amount`,
  ADD COLUMN `booking_paid_at`  datetime DEFAULT NULL                  AFTER `delivery_amount`,
  ADD COLUMN `delivery_paid_at` datetime DEFAULT NULL                  AFTER `booking_paid_at`,
  ADD KEY `ix_app_booking_paid` (`booking_paid_at`);

-- The old fee becomes the booking amount; the delivery amount comes from the
-- published price list for that product.
UPDATE `applications`
   SET `booking_amount`  = `payment_amount`,
       `delivery_amount` = CASE `product` WHEN 'tuktuk' THEN 24000.00 ELSE 16500.00 END;

-- An application that has never had a payment verified has not been quoted
-- anything it acted on, so it takes the published booking amount for its
-- product. Anyone who already paid the old fee keeps the figure they paid.
UPDATE `applications` a
   SET a.`booking_amount` = CASE a.`product` WHEN 'tuktuk' THEN 6000.00 ELSE 3500.00 END
 WHERE NOT EXISTS (
         SELECT 1 FROM `payments` p
          WHERE p.`application_id` = a.`id` AND p.`status` = 'verified'
       );

-- --------------------------------------------------------------------------
-- 2. Every existing transfer belongs to the booking stage.
-- --------------------------------------------------------------------------
ALTER TABLE `payments`
  ADD COLUMN `stage` enum('booking','delivery') NOT NULL DEFAULT 'booking' AFTER `application_id`,
  ADD KEY `ix_pay_stage` (`application_id`,`stage`,`status`);

UPDATE `payments` SET `stage` = 'booking';

-- --------------------------------------------------------------------------
-- 3. Widen the status enum, map the old values, then narrow it again.
-- --------------------------------------------------------------------------
ALTER TABLE `applications`
  MODIFY `status` enum('payment_pending','payment_review','booking_pending','booking_review',
                       'delivery_pending','delivery_review','complete','rejected')
  NOT NULL DEFAULT 'booking_pending';

-- an applicant who had settled the old fee has paid their booking amount
UPDATE `applications`
   SET `booking_paid_at` = COALESCE(`payment_verified_at`, `completed_at`, `updated_at`)
 WHERE `status` = 'complete';

UPDATE `applications` SET `status` = 'booking_pending'  WHERE `status` = 'payment_pending';
UPDATE `applications` SET `status` = 'booking_review'   WHERE `status` = 'payment_review';
UPDATE `applications` SET `status` = 'delivery_pending', `completed_at` = NULL WHERE `status` = 'complete';

ALTER TABLE `applications`
  MODIFY `status` enum('booking_pending','booking_review','delivery_pending','delivery_review',
                       'complete','rejected')
  NOT NULL DEFAULT 'booking_pending';

-- --------------------------------------------------------------------------
-- 4. The audit trail keeps its history as written; only new rows use the new
--    vocabulary. `status_log` is varchar, so nothing to change there.
-- --------------------------------------------------------------------------

SET time_zone = @old_time_zone;
