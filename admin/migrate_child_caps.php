<?php
/**
 * How many direct children each partner may hold.
 *
 * Adds max_children to principal_cf (Zonal C&Fs), zonal_cf (distributors) and
 * distributors (dealers) — NULL follows the default under Settings — and seeds
 * the two new defaults. The dealer default is the existing dealer_limit.
 * Safe to run twice: each column is added only when it is missing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This is a command-line migration: php admin/migrate_child_caps.php\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib.php';

$db = db();

foreach (['principal_cf', 'zonal_cf', 'distributors'] as $table) {
    $has = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'max_children'"
    );
    $has->execute([$table]);

    if ((int) $has->fetchColumn() > 0) {
        echo "$table.max_children exists.\n";
        continue;
    }

    $db->exec("ALTER TABLE `$table` ADD COLUMN `max_children` int(10) unsigned DEFAULT NULL");
    echo "$table.max_children added.\n";
}

$seed = $db->prepare('INSERT IGNORE INTO `settings` (`name`, `value`) VALUES (?, ?)');

foreach (['default_max_zonals' => '0', 'default_max_distributors' => '0', 'dealer_limit' => '10'] as $name => $value) {
    $seed->execute([$name, $value]);
}

echo "Defaults ensured (0 = no limit).\nDone.\n";
