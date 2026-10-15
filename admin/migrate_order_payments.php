<?php
/**
 * Part payments on stock orders.
 *
 * A buyer may pay less than an order's total and settle the rest later, so the
 * money moves out of stock_orders (one amount, one proof) into
 * stock_order_payments (one row per installment , each with its own proof).
 * Existing orders get one payment for their full total. Safe to run twice.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This is a command-line migration: php admin/migrate_order_payments.php\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib.php';

$db = db();

$db->exec(
    "CREATE TABLE IF NOT EXISTS `stock_order_payments` (
       `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
       `order_id`   int(10) unsigned NOT NULL,
       `amount`     decimal(14,2) NOT NULL,
       `reference`  varchar(120) DEFAULT NULL,
       `proof_path` varchar(255) DEFAULT NULL,
       `paid_at`    datetime NOT NULL DEFAULT current_timestamp(),
       PRIMARY KEY (`id`),
       KEY `idx_order_payment_order` (`order_id`),
       CONSTRAINT `fk_order_payment_order` FOREIGN KEY (`order_id`) REFERENCES `stock_orders` (`id`) ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$n = $db->exec(
    'INSERT INTO stock_order_payments (order_id, amount, reference, proof_path, paid_at)
     SELECT o.id, o.total_amount, o.reference, o.proof_path, o.requested_at
       FROM stock_orders o
      WHERE NOT EXISTS (SELECT 1 FROM stock_order_payments p WHERE p.order_id = o.id)'
);

echo "stock_order_payments ready; backfilled $n existing order(s).\n";
