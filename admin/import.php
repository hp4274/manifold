<?php
/**
 * The office importing clients from a spreadsheet.
 *
 * The one difference from the two partner screens: the office can say whose sale
 * it is. A code in the box books the import to that dealer or that distributor
 * exactly as a code typed on the website would; an empty box books it to nobody,
 * which is a sale the company made itself.
 *
 * The code is asked for once, with the file, and is carried through the
 * confirmation step in the session — so the sale the second screen promises is
 * the sale the button makes.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/import-lib.php';

$user      = require_login();
$pageTitle = 'Import clients';
$pageLead  = 'Add a list of customers from a spreadsheet.';
$activeType = 'import';

const IMPORT_OFFICE_KEY = 'import_office';

/* Read from the form while the file is being read, and from the session
   afterwards: the confirmation posts no code, and the sale must not quietly
   become the company's own between one screen and the next. */
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';

$partnerCode = $action === 'upload'
    ? normalise_referral_code((string) ($_POST['partner_code'] ?? ''))
    : (string) ($_SESSION[IMPORT_OFFICE_KEY]['partner_code'] ?? '');

/* A code is one of the two, never both: MD is a dealer's, MX a distributor's.
   A dealer's sale also pays their distributor an override, so that one is looked
   up rather than asked for. */
$dealer      = $partnerCode === '' ? null : dealer_for_code($partnerCode);
$distributor = $dealer
    ? distributor_for_dealer($dealer)
    : ($partnerCode === '' ? null : distributor_for_code($partnerCode));

$context = [
    'dealer'                 => $dealer,
    'distributor'            => $dealer ? $distributor : ($distributor ?: null),
    'entered_by_dealer'      => null,
    'entered_by_distributor' => null,
];

/* A code that matched nothing is refused rather than quietly ignored: an import
   of two hundred clients booked to nobody, because a code was mistyped, is not
   something anybody would notice until the commission did not appear. */
if ($partnerCode !== '' && !$dealer && !$distributor) {
    $state = [
        'stage'   => 'upload',
        'error'   => 'No active partner holds the code ' . $partnerCode . '. Check it against the '
                   . 'dealer or distributor list, or leave the box empty to book the sale to the company.',
        'preview' => null,
        'result'  => null,
    ];

    unset($_SESSION[IMPORT_OFFICE_KEY]);
} else {
    $state = import_run($context, IMPORT_OFFICE_KEY);

    /* carried to the confirmation step */
    if ($state['stage'] === 'preview') {
        $_SESSION[IMPORT_OFFICE_KEY]['partner_code'] = $partnerCode;
    }
}

$backHref    = 'list?type=stove&status=submitted';
$backLabel   = 'Back to applications';
$extraFields = __DIR__ . '/partials/import-partner-field.php';

require __DIR__ . '/partials/layout-top.php';
require __DIR__ . '/partials/import-page.php';
require __DIR__ . '/partials/layout-bottom.php';
