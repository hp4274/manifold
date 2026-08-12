<?php
/**
 * The raffle search, answered as the office types.
 *
 * Returns the same markup raffle.php renders for its results — both come from
 * partials/raffle-results.php — so the live list and the one on a plain page
 * load cannot drift apart.
 *
 * GET raffle-search.php?q=patel&draw=3
 *
 * Signed-in staff only. This hands out applicants' names, numbers and email
 * addresses, so a request without a session gets a 401 and nothing else.
 */

declare(strict_types=1);

require_once __DIR__ . '/raffle-lib.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (!current_user()) {
    http_response_code(401);
    exit('<p class="finder__none">Your session has expired. Reload the page and sign in again.</p>');
}

$search = trim((string) ($_GET['q'] ?? ''));
$drawId = (int) ($_GET['draw'] ?? 0);

$nextDraw = raffle_draw_by_id($drawId);

if (!$nextDraw) {
    http_response_code(404);
    exit('<p class="finder__none">That draw no longer exists. Reload the page.</p>');
}

$results = $search === '' ? [] : raffle_search($search, $drawId);

require __DIR__ . '/partials/raffle-results.php';
