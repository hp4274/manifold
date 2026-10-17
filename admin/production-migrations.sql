-- Run in phpMyAdmin (Hostinger) on the production database. Safe to re-run: every
-- ALTER checks information_schema first and does nothing if it was already applied.

-- 1) per-parent caps on direct children
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'principal_cf' AND COLUMN_NAME = 'max_children') = 0,
  'ALTER TABLE `principal_cf` ADD COLUMN `max_children` int(10) unsigned DEFAULT NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zonal_cf' AND COLUMN_NAME = 'max_children') = 0,
  'ALTER TABLE `zonal_cf` ADD COLUMN `max_children` int(10) unsigned DEFAULT NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'distributors' AND COLUMN_NAME = 'max_children') = 0,
  'ALTER TABLE `distributors` ADD COLUMN `max_children` int(10) unsigned DEFAULT NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES ('default_max_zonals', '0'), ('default_max_distributors', '0');

-- 2) part payments on stock orders
CREATE TABLE IF NOT EXISTS `stock_order_payments` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id`   int(10) unsigned NOT NULL,
  `amount`     decimal(14,2) NOT NULL,
  `reference`  varchar(120) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `paid_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_payment_order` (`order_id`),
  CONSTRAINT `fk_order_payment_order` FOREIGN KEY (`order_id`) REFERENCES `stock_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO stock_order_payments (order_id, amount, reference, proof_path, paid_at)
SELECT o.id, o.total_amount, o.reference, o.proof_path, o.requested_at FROM stock_orders o
 WHERE NOT EXISTS (SELECT 1 FROM stock_order_payments p WHERE p.order_id = o.id);

-- 3) per-partner commission override
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'principal_cf' AND COLUMN_NAME = 'commission_rate') = 0,
  'ALTER TABLE `principal_cf` ADD COLUMN `commission_rate` decimal(5,2) DEFAULT NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zonal_cf' AND COLUMN_NAME = 'commission_rate') = 0,
  'ALTER TABLE `zonal_cf` ADD COLUMN `commission_rate` decimal(5,2) DEFAULT NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'distributors' AND COLUMN_NAME = 'commission_rate') = 0,
  'ALTER TABLE `distributors` ADD COLUMN `commission_rate` decimal(5,2) DEFAULT NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dealers' AND COLUMN_NAME = 'commission_rate') = 0,
  'ALTER TABLE `dealers` ADD COLUMN `commission_rate` decimal(5,2) DEFAULT NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 4) customer referral programme removed. New applications no longer get a
--    referral_code, so every new row writes '' and the unique key would refuse
--    the second one. Drop only that key. The columns and their reward history
--    stay on production.
SET @q = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications' AND INDEX_NAME = 'uq_app_referral') > 0,
  'ALTER TABLE `applications` DROP INDEX `uq_app_referral`', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 4b) OPTIONAL, later, once nobody needs the old reward history. Not run by default.
-- ALTER TABLE `applications` DROP FOREIGN KEY `fk_app_referrer`;
-- ALTER TABLE `applications` DROP FOREIGN KEY `fk_app_reward_admin`;
-- ALTER TABLE `applications`
--   DROP INDEX `ix_app_referred_by`,
--   DROP INDEX `ix_app_reward_status`,
--   DROP INDEX `fk_app_reward_admin`,
--   DROP COLUMN `referral_code`,
--   DROP COLUMN `referred_by_code`,
--   DROP COLUMN `referred_by_id`,
--   DROP COLUMN `referral_reward`,
--   DROP COLUMN `referral_reward_status`,
--   DROP COLUMN `referral_reward_sent_at`,
--   DROP COLUMN `referral_reward_note`,
--   DROP COLUMN `referral_reward_by`,
--   DROP COLUMN `referral_payout_requested_at`;
-- DELETE FROM `settings` WHERE `name` = 'referral_reward';
