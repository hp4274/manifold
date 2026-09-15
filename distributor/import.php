<?php
/**
 * A distributor importing their own clients from a spreadsheet.
 *
 * Their own, and not their dealers'. A sale imported here is a distributor's
 * direct sale: it books the direct figure to them and no dealer is involved,
 * which is what commission_split() does when it is given a distributor and no
 * dealer. A dealer's own clients are imported by that dealer, from their portal.
 *
 * Whose sale it is comes from the session, never from the form — there is no
 * field naming a distributor, so there is nothing to tamper with.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../admin/import-lib.php';

$dist      = require_distributor();
$pageTitle = 'Import clients';
$pageLead  = 'Add a list of customers from a spreadsheet, all booked to your code.';
$activeNav = 'clients';

$context = [
    'dealer'                 => null,
    'distributor'            => $dist,
    'entered_by_dealer'      => null,
    'entered_by_distributor' => (int) $dist['id'],
];

$state = import_run($context, 'import_distributor');

$backHref  = 'clients';
$backLabel = 'Back to clients';

require __DIR__ . '/partials/layout-top.php';
require __DIR__ . '/../admin/partials/import-page.php';
require __DIR__ . '/partials/layout-bottom.php';
