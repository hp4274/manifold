<?php
/**
 * Retired: commission vouchers were replaced by the pass-down (admin/commissions).
 * Kept as a redirect so old bookmarks and voucher emails still land somewhere.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

require_login();

header('Location: commissions');
exit;
