<?php
/**
 * Public raffle feed.
 *
 * The pages are static HTML, so the raffle popup asks here for the countdown
 * to the next draw and the winners of the ones already public. A draw that has
 * been filled but not yet revealed is invisible from outside — that list
 * belongs to the office for its 48 hours.
 *
 * GET raffle.php             → the next draw and the last few winner lists
 * GET raffle.php?history=1   → fewer past draws
 */

declare(strict_types=1);

require_once __DIR__ . '/admin/raffle-lib.php';

header('Content-Type: application/json');
/* short, so a reveal is not held back by a cache */
header('Cache-Control: public, max-age=30');

$history = (int) ($_GET['history'] ?? 4);
$history = max(1, min($history, 8));

try {
    echo json_encode(raffle_public_payload($history));
} catch (Throwable $e) {
    /* the raffle tables have not been imported yet, or the database is down.
       The popup treats this the same as a raffle that is not running. */
    http_response_code(200);
    echo json_encode(['enabled' => false, 'running' => false, 'draws' => []]);
}
