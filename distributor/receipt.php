<?php
/** Serves a pass-down transfer receipt (?transfer=) or one on this distributor's own lines (?line=). */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dist = require_distributor();

if (isset($_GET['transfer'])) {
    commission_transfer_receipt_send((int) $_GET['transfer'], 'distributor', (int) $dist['id']);
}

commission_receipt_send((int) ($_GET['line'] ?? 0), ['distributor'], (int) $dist['id']);
