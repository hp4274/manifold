<?php
/**
 * Start a payment gateway session.
 *
 * POST only, from the "Pay Now" button on the portal status page. Validates
 * the applicant's session, the application ownership, and that the requested
 * stage is actually due, then redirects the browser to the ICICI payment page.
 */

declare(strict_types=1);

require_once __DIR__ . '/pg-gateway.php';

$email = require_applicant();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: status');
    exit;
}

csrf_check();

$id    = (int) ($_POST['id'] ?? 0);
$stage = (string) ($_POST['stage'] ?? '');

if (!in_array($stage, PAYMENT_STAGES, true)) {
    header('Location: status');
    exit;
}

/* load the application and confirm it belongs to this person */
$stmt = db()->prepare('SELECT * FROM applications WHERE id = ? AND email = ?');
$stmt->execute([$id, $email]);
$app = $stmt->fetch();

if (!$app) {
    header('Location: status');
    exit;
}

$totals = payment_totals($app);
$due    = $totals['stages'][$stage] ?? null;

/* the stage must be open and unpaid */
if ($due === null || $due['state'] !== 'due') {
    header('Location: status#app-' . $id);
    exit;
}

if ($app['status'] === 'rejected') {
    header('Location: status#app-' . $id);
    exit;
}

/* What is actually outstanding, not the headline stage price: a stage part-paid
   by an earlier verified receipt stays 'due', and charging the full amount again
   would take money the applicant does not owe. */
$amount = (float) ($due['balance'] ?? $due['amount']);

if ($amount <= 0) {
    header('Location: status#app-' . $id);
    exit;
}

/* call the gateway */
$response = pg_initiate_sale($app, $stage, $amount);

if ($response === null) {
    header('Location: status?pg_error=1#app-' . $id);
    exit;
}

$responseCode = (string) ($response['responseCode'] ?? '');

/* R1000 = redirect the browser to the payment page */
if ($responseCode !== 'R1000' || empty($response['redirectURI']) || empty($response['tranCtx'])) {
    error_log('PG initiateSale unexpected response: ' . json_encode($response));
    header('Location: status?pg_error=1#app-' . $id);
    exit;
}

/* send the browser to the ICICI payment page. The documented redirectURI
   carries no query of its own, but it comes from them and may one day — so the
   separator is chosen rather than assumed. */
$redirectUrl = $response['redirectURI']
    . (strpos((string) $response['redirectURI'], '?') === false ? '?' : '&')
    . 'tranCtx=' . urlencode((string) $response['tranCtx']);
header('Location: ' . $redirectUrl);
exit;
