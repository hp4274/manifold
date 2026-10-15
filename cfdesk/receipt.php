<?php
/** Serves a pass-down transfer receipt (?transfer=) or one on this C&F's own lines (?line=). */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

[$tier, $me] = require_cf_partner();

if (isset($_GET['transfer'])) {
    commission_transfer_receipt_send((int) $_GET['transfer'], $tier, (int) $me['id']);
}

commission_receipt_send((int) ($_GET['line'] ?? 0), [$tier], (int) $me['id']);
