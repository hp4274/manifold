-- Run once in phpMyAdmin (Hostinger) on the production database. Safe to run in order.
-- 1) per-parent caps on direct children
ALTER TABLE `principal_cf`  ADD COLUMN `max_children` int(10) unsigned DEFAULT NULL;
ALTER TABLE `zonal_cf`      ADD COLUMN `max_children` int(10) unsigned DEFAULT NULL;
ALTER TABLE `distributors`  ADD COLUMN `max_children` int(10) unsigned DEFAULT NULL;
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
