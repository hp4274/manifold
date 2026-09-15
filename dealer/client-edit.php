<?php
/**
 * A dealer editing one of their own clients.
 *
 * client_for_actor() puts the ownership into the query, so a dealer asking for
 * an id that is not theirs gets a 404 rather than somebody else's customer.
 * Editing closes once the order is finished or called off — by then there is
 * nothing left to correct, and the record is history for the office to keep.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../admin/client-lib.php';

$dealer = require_dealer();

$actor = [
    'type'  => 'dealer',
    'id'    => (int) $dealer['id'],
    'label' => (string) $dealer['full_name'] . ' (' . (string) $dealer['dealer_code'] . ')',
];

$state     = client_edit_run($actor);
$pageTitle = 'Edit client';
$activeNav = 'clients';

if ($state['app'] === null) {
    $pageLead = '';
    require __DIR__ . '/partials/layout-top.php';
    echo '<p class="alert alert--error">That client is not one of yours, or no longer exists.</p>';
    require __DIR__ . '/partials/layout-bottom.php';
    exit;
}

$app     = $state['app'];
$error   = $state['error'];
$saved   = $state['saved'];
$closed  = $state['closed'];
$shared  = $state['shared'];
$posted  = $state['posted'];
$history = $state['history'];

$pageTitle = 'Edit ' . $app['full_name'];
$pageLead  = 'Correct this client\'s own details. Their payments and documents are the office\'s.';

$backHref  = 'clients';
$backLabel = 'Back to clients';

require __DIR__ . '/partials/layout-top.php';
require __DIR__ . '/../admin/partials/client-edit-form.php';
require __DIR__ . '/partials/layout-bottom.php';
