<?php
/**
 * A dealer importing their own clients from a spreadsheet.
 *
 * The only thing this file decides is whose sale it is, and it decides that from
 * the session — never from anything posted. There is no field on the form that
 * names a dealer, so there is nothing to tamper with: a dealer imports onto
 * themselves or not at all.
 *
 * Everything else is shared. admin/import-lib.php reads the file and writes the
 * applications; admin/partials/import-page.php is the screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../admin/import-lib.php';

$dealer    = require_dealer();
$pageTitle = 'Import clients';
$pageLead  = 'Add a list of customers from a spreadsheet, all booked to your code.';
$activeNav = 'clients';

/* Their distributor takes the override, the same as on any sale of theirs —
   looked up now rather than stored, so a dealer moved between distributors books
   to the one they are under today. */
$context = [
    'dealer'                 => $dealer,
    'distributor'            => distributor_for_dealer($dealer),
    'entered_by_dealer'      => (int) $dealer['id'],
    'entered_by_distributor' => null,
];

$state = import_run($context, 'import_dealer');

$backHref  = 'clients';
$backLabel = 'Back to clients';

require __DIR__ . '/partials/layout-top.php';
require __DIR__ . '/../admin/partials/import-page.php';
require __DIR__ . '/partials/layout-bottom.php';
