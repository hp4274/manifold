<?php
/** Serves a pass-down transfer receipt (?transfer=) or one on this dealer's own lines (?line=). */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dealer = require_dealer();

if (isset($_GET['transfer'])) {
    commission_transfer_receipt_send((int) $_GET['transfer'], 'dealer', (int) $dealer['id']);
}

commission_receipt_send((int) ($_GET['line'] ?? 0), ['dealer'], (int) $dealer['id']);
