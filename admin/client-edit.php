<?php
/**
 * The office editing a client's details.
 *
 * Reached from the Edit button on an application's drawer. The office may edit
 * any client, at any point in the order — including one that is finished, which
 * is the difference from the two partner screens.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/client-lib.php';

$user = require_login();

$actor = [
    'type'  => 'office',
    'id'    => (int) $user['id'],
    'label' => trim((string) ($user['name'] ?? '')) ?: (string) ($user['username'] ?? 'the office'),
];

$state = client_edit_run($actor);

if ($state['app'] === null) {
    $pageTitle  = 'Client not found';
    $activeType = '';
    require __DIR__ . '/partials/layout-top.php';
    echo '<p class="alert alert--error">That application no longer exists.</p>';
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

$pageTitle  = 'Edit ' . $app['full_name'];
$pageLead   = 'Correct this client\'s own details. Their order is not touched here.';
$activeType = (string) $app['product'];

$backHref  = 'list?type=' . rawurlencode((string) $app['product']);
$backLabel = 'Back to applications';

require __DIR__ . '/partials/layout-top.php';
require __DIR__ . '/partials/client-edit-form.php';
require __DIR__ . '/partials/layout-bottom.php';
