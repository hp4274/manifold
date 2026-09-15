<?php
/**
 * Payment gateway return handler.
 *
 * The ICICI gateway sends the browser back here after a payment attempt. The
 * response arrives as POST parameters (or GET on some configurations). This
 * handler:
 *
 *   1. Looks up the pg_transactions row by merchantTxnNo
 *   2. Cross-checks with the status API for authoritative confirmation
 *   3. On success: auto-creates a verified payment + advances the application
 *   4. Redirects back to the portal status page with a result flag
 *
 * ---------------------------------------------------------------------------
 * Why this file must not touch the session
 * ---------------------------------------------------------------------------
 * The gateway's return is a cross-site POST from the bank's own domain, and our
 * session cookie is SameSite=Lax — so the browser does not send it on this
 * request. Called normally, session_start() would find no cookie, mint a
 * brand-new empty session, and Set-Cookie it over the applicant's real session
 * ID. They would then land on the status page signed out and told their session
 * had expired, having just paid. That was the bug.
 *
 * Turning session cookies off for this request means PHP neither reads nor
 * writes the cookie: the session in the browser is left exactly as it was, and
 * the follow-up redirect to `status` is a top-level GET, which Lax does send the
 * cookie on. Nothing here needs the session anyway — the application is read
 * from the pg_transactions row, which is the only trustable source on a request
 * that arrives without one.
 */

declare(strict_types=1);

ini_set('session.use_cookies', '0');

require_once __DIR__ . '/pg-gateway.php';
require_once __DIR__ . '/../admin/emails.php';

/** Back to the portal, always as an absolute same-origin URL. */
function pg_return_to(string $query, int $appId = 0): void
{
    $url = base_url() . '/portal/status' . ($query === '' ? '' : '?' . $query)
         . ($appId > 0 ? '#app-' . $appId : '');

    header('Location: ' . $url);
    exit;
}

/* the gateway may POST or GET — read from both */
$data = array_merge($_GET, $_POST);

$merchantTxnNo = trim((string) ($data['merchantTxnNo'] ?? ''));
$responseCode  = trim((string) ($data['responseCode'] ?? ''));
$paymentId     = trim((string) ($data['paymentID'] ?? ''));
$paymentMode   = trim((string) ($data['paymentMode'] ?? ''));
$paymentSub    = trim((string) ($data['paymentSubInstType'] ?? ''));
$txnId         = trim((string) ($data['txnID'] ?? ''));

if ($merchantTxnNo === '') {
    pg_return_to('pg_error=1');
}

/* find the initiated transaction */
$stmt = db()->prepare('SELECT * FROM pg_transactions WHERE merchant_txn_no = ?');
$stmt->execute([$merchantTxnNo]);
$pgTxn = $stmt->fetch();

if (!$pgTxn) {
    error_log('PG return: unknown merchantTxnNo ' . $merchantTxnNo);
    pg_return_to('pg_error=1');
}

$appId = (int) $pgTxn['application_id'];
$stage = (string) $pgTxn['stage'];

/* Already credited — nothing to do but say so. Everything else, including a row
   already marked failed, is re-evaluated below: the gateway is asked again, and
   a transaction that settled after we last looked gets its receipt. Replaying a
   failed return cannot forge a success, because it is the status API and not
   the posted responseCode that decides. */
if ($pgTxn['status'] === 'success') {
    pg_return_to('pg_paid=1&stage=' . urlencode($stage), $appId);
}

/* save the raw response for audit */
$rawJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$expected = (float) $pgTxn['amount'];

/* ---------------------------------------------------------------------------
 * Decide the outcome.
 *
 * Nothing in $data is trusted on its own: it reaches us through the customer's
 * browser, and anybody can post whatever they like to this URL. The status API
 * is a server-to-server call and is the authority. Where it cannot be reached,
 * the return's own secureHash is the fallback — it is computed with PG_KEY, so
 * a browser cannot forge it. Where neither is available the transaction is left
 * as it was, 'initiated', and the applicant is told we are still confirming,
 * rather than handed a success or a failure we have not established.
 * ------------------------------------------------------------------------- */

$outcome = 'pending';          /* 'success' | 'failed' | 'pending' */

$statusResp = pg_check_status($merchantTxnNo);

if ($statusResp !== null) {
    $statusCode   = trim((string) ($statusResp['responseCode'] ?? ''));
    $statusAmount = (float) ($statusResp['amount'] ?? $expected);

    if (pg_is_success($statusCode)) {
        /* A success for the wrong money is not a success for this stage. The
           gateway should never do it; if it ever does we want a line in the log
           and a human looking, not a receipt going out. */
        if (abs($statusAmount - $expected) >= 0.01) {
            error_log(sprintf(
                'PG return: amount mismatch on %s — expected %.2f, gateway says %.2f',
                $merchantTxnNo, $expected, $statusAmount
            ));
        } else {
            $outcome = 'success';
            /* prefer the status API values over the redirect params */
            $responseCode = $statusCode;
            $paymentId    = trim((string) ($statusResp['paymentID'] ?? $paymentId));
            $txnId        = trim((string) ($statusResp['txnID'] ?? $txnId));
        }
    } elseif ($statusCode !== '') {
        $outcome      = 'failed';
        $responseCode = $statusCode;
    }
} elseif (pg_verify_return($data)) {
    /* status API unreachable, but the return carries a hash only PG_KEY could
       have produced, so its verdict stands */
    $outcome = pg_is_success($responseCode) ? 'success' : 'failed';

    if ($outcome === 'success' && abs((float) ($data['amount'] ?? $expected) - $expected) >= 0.01) {
        error_log('PG return: amount mismatch on signed return ' . $merchantTxnNo);
        $outcome = 'pending';
    }
} else {
    error_log('PG return: unverifiable outcome for ' . $merchantTxnNo . ' — left pending');
}

/* record what came back, whatever the verdict */
db()->prepare(
    'UPDATE pg_transactions
        SET response_code = ?, payment_id = ?, payment_mode = ?,
            payment_sub_type = ?, txn_id = ?, status = ?,
            raw_response = ?, completed_at = ?
      WHERE id = ?'
)->execute([
    $responseCode, $paymentId, $paymentMode,
    $paymentSub, $txnId,
    $outcome === 'pending' ? 'initiated' : $outcome,
    $rawJson,
    $outcome === 'pending' ? null : date('Y-m-d H:i:s'),
    (int) $pgTxn['id'],
]);

if ($outcome === 'failed') {
    pg_return_to('pg_failed=1', $appId);
}

if ($outcome === 'pending') {
    pg_return_to('pg_pending=1', $appId);
}

/* ---------- auto-verify: create a verified payment record ---------- */

$stmt = db()->prepare('SELECT * FROM applications WHERE id = ?');
$stmt->execute([$appId]);
$app = $stmt->fetch();

if (!$app) {
    /* should never happen — the FK guarantees the row */
    pg_return_to('pg_error=1');
}

$amount = $expected;

/* Guard against crediting the same stage twice. The obvious case is this return
   arriving again; the real one is an applicant paying by card while the office
   is still verifying the bank transfer they uploaded for the same stage. The
   gateway payment stays recorded on the transaction row either way, so nothing
   is lost — but a second verified payment would double the books. */
$exists = db()->prepare(
    "SELECT COUNT(*) FROM payments
      WHERE application_id = ? AND stage = ? AND status = 'verified'"
);
$exists->execute([$appId, $stage]);

if ((int) $exists->fetchColumn() > 0) {
    error_log(sprintf(
        'PG return: %s succeeded but the %s stage of application %d is already paid — no second receipt',
        $merchantTxnNo, $stage, $appId
    ));

    pg_return_to('pg_paid=1&stage=' . urlencode($stage), $appId);
}

$receipt = next_receipt_no($app);

db()->prepare(
    'INSERT INTO payments (application_id, stage, amount, reference, proof_path, status, receipt_no, decided_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
)->execute([
    $appId,
    $stage,
    $amount,
    'PG:' . $merchantTxnNo,
    null,                       /* no proof file — the gateway is the proof */
    'verified',
    $receipt,
]);

/* advance the application status machine */
sync_application_status($appId);

/* reload the application after the status sync for the email */
$stmt = db()->prepare('SELECT * FROM applications WHERE id = ?');
$stmt->execute([$appId]);
$appAfter = $stmt->fetch() ?: $app;

$payment = [
    'stage'      => $stage,
    'amount'     => $amount,
    'receipt_no' => $receipt,
    'status'     => 'verified',
    'decided_at' => date('Y-m-d H:i:s'),
];

$totals = payment_totals($appAfter);

/* send the receipt email in the background */
after_response(static function () use ($appAfter, $payment, $totals): void {
    send_receipt_email($appAfter, $payment, $totals);
});

pg_return_to('pg_paid=1&stage=' . urlencode($stage), $appId);
