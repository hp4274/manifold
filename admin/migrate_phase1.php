<?php
/**
 * Phase 1 Database Migration Script
 * Enforces schema definitions for Manifold Clean Energy Phase 1.
 */

declare(strict_types=1);

/* DDL, so it runs from a console where somebody is watching it. Served over
   the web it would let anybody at all rewrite the schema, and run twice at
   once it would sit on an ALTER lock. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This is a command-line migration: php admin/migrate_phase1.php
");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib.php';

echo "Starting Phase 1 Migration...\n";

$db = db();

// 1. Ensure admin_users role includes principal_cf and zonal_cf
$db->exec("ALTER TABLE `admin_users` MODIFY COLUMN `role` ENUM('admin','cf','principal_cf','zonal_cf') NOT NULL DEFAULT 'admin'");
echo "[1/8] admin_users role updated.\n";

// 2. Ensure principal_cf table exists
$db->exec("CREATE TABLE IF NOT EXISTS `principal_cf` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `principal_code` varchar(20) DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `full_name` varchar(160) NOT NULL,
  `company` varchar(160) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `mobile_number` varchar(30) DEFAULT NULL,
  `alt_mobile_number` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `state` varchar(120) DEFAULT NULL,
  `pin_code` varchar(20) DEFAULT NULL,
  `pan_number` varchar(20) DEFAULT NULL,
  `gst_number` varchar(30) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `bank_account` varchar(60) DEFAULT NULL,
  `bank_ifsc` varchar(20) DEFAULT NULL,
  `upi_id` varchar(120) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `terms_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `terms_accepted_at` datetime DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decided_by_label` varchar(160) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_principal_code` (`principal_code`),
  KEY `ix_principal_active` (`is_active`),
  KEY `ix_principal_approval` (`approval_status`),
  KEY `fk_principal_admin` (`created_by`),
  CONSTRAINT `fk_principal_admin` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "[2/8] principal_cf table verified.\n";

// 3. Ensure zonal_cf table exists
$db->exec("CREATE TABLE IF NOT EXISTS `zonal_cf` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `zonal_code` varchar(20) DEFAULT NULL,
  `principal_cf_id` int(10) unsigned NOT NULL,
  `approval_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `full_name` varchar(160) NOT NULL,
  `company` varchar(160) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `mobile_number` varchar(30) DEFAULT NULL,
  `alt_mobile_number` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `state` varchar(120) DEFAULT NULL,
  `pin_code` varchar(20) DEFAULT NULL,
  `pan_number` varchar(20) DEFAULT NULL,
  `gst_number` varchar(30) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `bank_account` varchar(60) DEFAULT NULL,
  `bank_ifsc` varchar(20) DEFAULT NULL,
  `upi_id` varchar(120) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `terms_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `terms_accepted_at` datetime DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decided_by_label` varchar(160) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_zonal_code` (`zonal_code`),
  KEY `ix_zonal_active` (`is_active`),
  KEY `ix_zonal_principal` (`principal_cf_id`),
  KEY `ix_zonal_approval` (`approval_status`),
  KEY `fk_zonal_admin` (`created_by`),
  CONSTRAINT `fk_zonal_admin` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_zonal_principal` FOREIGN KEY (`principal_cf_id`) REFERENCES `principal_cf` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "[3/8] zonal_cf table verified.\n";

// 4. Ensure foreign keys for distributors -> zonal_cf
$checkFk = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'manifold' AND TABLE_NAME = 'distributors' AND REFERENCED_TABLE_NAME = 'zonal_cf'")->fetchColumn();
if (!$checkFk) {
    $db->exec("ALTER TABLE `distributors` ADD CONSTRAINT `fk_distributor_zonal` FOREIGN KEY (`zonal_cf_id`) REFERENCES `zonal_cf` (`id`) ON DELETE SET NULL");
    echo "[4/8] distributors fk_distributor_zonal added.\n";
} else {
    echo "[4/8] distributors fk_distributor_zonal exists.\n";
}

// 5. Ensure applications foreign keys
$checkFkZ = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'manifold' AND TABLE_NAME = 'applications' AND REFERENCED_TABLE_NAME = 'zonal_cf'")->fetchColumn();
if (!$checkFkZ) {
    $db->exec("ALTER TABLE `applications` ADD CONSTRAINT `fk_app_zonal` FOREIGN KEY (`zonal_cf_id`) REFERENCES `zonal_cf` (`id`) ON DELETE SET NULL");
    echo "[5/8] applications fk_app_zonal added.\n";
} else {
    echo "[5/8] applications fk_app_zonal exists.\n";
}

$checkFkP = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'manifold' AND TABLE_NAME = 'applications' AND REFERENCED_TABLE_NAME = 'principal_cf'")->fetchColumn();
if (!$checkFkP) {
    $db->exec("ALTER TABLE `applications` ADD CONSTRAINT `fk_app_principal` FOREIGN KEY (`principal_cf_id`) REFERENCES `principal_cf` (`id`) ON DELETE SET NULL");
    echo "[5/8] applications fk_app_principal added.\n";
} else {
    echo "[5/8] applications fk_app_principal exists.\n";
}

// 6. Update commission_vouchers & commission_voucher_lines party_type
$db->exec("ALTER TABLE `commission_vouchers` MODIFY COLUMN `party_type` ENUM('dealer','distributor','zonal_cf','principal_cf') NOT NULL");
$db->exec("ALTER TABLE `commission_voucher_lines` MODIFY COLUMN `party_type` ENUM('dealer','distributor','zonal_cf','principal_cf') NOT NULL DEFAULT 'dealer'");
echo "[6/8] commission_vouchers party_type extended.\n";

// 7. Ensure zonal_cf_payouts and principal_cf_payouts tables
$db->exec("CREATE TABLE IF NOT EXISTS `zonal_cf_payouts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `zonal_cf_id` int(10) unsigned NOT NULL,
  `voucher_id` int(10) unsigned DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `paid_by` int(10) unsigned DEFAULT NULL,
  `paid_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_zpayout_zonal` (`zonal_cf_id`),
  KEY `idx_zpayout_voucher` (`voucher_id`),
  KEY `fk_zpayout_admin` (`paid_by`),
  CONSTRAINT `fk_zpayout_zonal` FOREIGN KEY (`zonal_cf_id`) REFERENCES `zonal_cf` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_zpayout_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `commission_vouchers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_zpayout_admin` FOREIGN KEY (`paid_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS `principal_cf_payouts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `principal_cf_id` int(10) unsigned NOT NULL,
  `voucher_id` int(10) unsigned DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `paid_by` int(10) unsigned DEFAULT NULL,
  `paid_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_ppayout_principal` (`principal_cf_id`),
  KEY `idx_ppayout_voucher` (`voucher_id`),
  KEY `fk_ppayout_admin` (`paid_by`),
  CONSTRAINT `fk_ppayout_principal` FOREIGN KEY (`principal_cf_id`) REFERENCES `principal_cf` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ppayout_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `commission_vouchers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ppayout_admin` FOREIGN KEY (`paid_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "[7/8] Payout tables for zonal_cf and principal_cf verified.\n";

// 8. Ensure settings defaults
$settingsToEnsure = [
    'commission_rate_principal_cf' => '20.00',
    'commission_rate_zonal_cf'     => '15.00',
    'commission_rate_distributor'  => '10.00',
    'commission_rate_dealer'       => '6.00',
    'commission_release_hours'     => '48',
    'payment_deadline_hours'       => '48',
    'stock_warn_units'             => '200',
    'stock_critical_units'         => '100',
];

foreach ($settingsToEnsure as $key => $val) {
    $stmt = $db->prepare("INSERT IGNORE INTO `settings` (`name`, `value`) VALUES (?, ?)");
    $stmt->execute([$key, $val]);
}
echo "[8/8] Settings defaults ensured.\n";

echo "Phase 1 Migration Completed Successfully!\n";
