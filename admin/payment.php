<?php
/**
 * Payment decisions. POST only, from the Details drawer.
 *
 * Every application is two transfers — booking and delivery — so decisions
 * are made per transfer and the application's own status is recalculated
 * afterwards.
 *
 *   action=accept   verify one transfer → its own receipt is emailed
 *   action=reject   that transfer is no good → applicant told why
 *   action=remind   nudge whoever still owes the payment that is due
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/emails.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./');
    exit;
}

csrf_check();

$action    = (string) ($_POST['action'] ?? '');
$id        = (int) ($_POST['id'] ?? 0);
$paymentId = (int) ($_POST['payment_id'] ?? 0);
$type      = (string) ($_POST['type'] ?? '');
$reason    = trim((string) ($_POST['reason'] ?? ''));

if (type_config($type)['table'] !== 'applications') {
    http_response_code(422);
    exit('Payments only apply to product applications.');
}

$stmt = db()->prepare('SELECT * FROM applications WHERE id = ?');
$stmt->execute([$id]);
$app = $stmt->fetch();

if (!$app) {
    http_response_code(404);
    exit('Application not found.');
}

/** One transfer belonging to this application. */
function load_payment(int $paymentId, int $applicationId): array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE id = ? AND application_id = ?');
    $stmt->execute([$paymentId, $applicationId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        http_response_code(404);
        exit('Payment not found.');
    }

    if ($payment['status'] !== 'pending') {
        http_response_code(409);
        exit('That payment has already been decided.');
    }

    return $payment;
}

$flash = '';

switch ($action) {
    /* ---------- verify one transfer ---------- */
    case 'accept':
        $payment = load_payment($paymentId, $id);
        $receipt = next_receipt_no($app);

        db()->prepare(
            'UPDATE payments
                SET status = ?, receipt_no = ?, decided_at = NOW(), decided_by = ?, reject_reason = NULL
              WHERE id = ?'
        )->execute(['verified', $receipt, (int) $user['id'], $paymentId]);

        sync_application_status($id);

        $payment['status']     = 'verified';
        $payment['receipt_no'] = $receipt;
        $payment['decided_at'] = date('Y-m-d H:i:s');

        $totals = payment_totals($app);

        /* the receipt carries a generated PDF and takes seconds to send; the
           office should not stand there while it goes */
        after_response(static function () use ($app, $payment, $totals): void {
            send_receipt_email($app, $payment, $totals);
        });

        $flash = 'receipt';
        break;

    /* ---------- that transfer does not check out ---------- */
    case 'reject':
        $payment = load_payment($paymentId, $id);

        /* The applicant is emailed this, and it is the whole of what they are
           told. Refusing a payment without saying why leaves somebody looking at
           a rejected receipt for money they have already sent. */
        if ($reason === '') {
            http_response_code(422);
            exit('Say why the payment is being turned down - the applicant is emailed the reason.');
        }

        db()->prepare(
            'UPDATE payments
                SET status = ?, reject_reason = ?, decided_at = NOW(), decided_by = ?
              WHERE id = ?'
        )->execute(['rejected', $reason !== '' ? mb_substr($reason, 0, 255) : null, (int) $user['id'], $paymentId]);

        sync_application_status($id);

        /* the proof is no longer evidence of anything — take it off disk */
        if (!empty($payment['proof_path'])) {
            $path = PAYMENT_PROOF_DIR . '/' . basename((string) $payment['proof_path']);

            if (is_file($path)) {
                unlink($path);
            }
        }

        $rejectTotals = payment_totals($app);

        after_response(static function () use ($app, $reason, $payment, $rejectTotals): void {
            send_payment_rejected_email($app, $reason, $payment, $rejectTotals);
        });

        $flash = 'rejected';
        break;

    /* ---------- the finance team's check ----------
       Between the booking payment and the delivery payment sits the paperwork.
       Verifying it is what opens the delivery payment to the applicant — and on
       a sale a partner recorded as paid in full, it finishes the sale. */
    case 'docs':
        $done = docs_verify($id, (int) $user['id']);

        if (isset($done['error'])) {
            http_response_code(409);
            exit($done['error']);
        }

        $fresh = db()->prepare('SELECT * FROM applications WHERE id = ?');
        $fresh->execute([$id]);
        $after = $fresh->fetch() ?: $app;

        after_response(static function () use ($after): void {
            send_docs_verified_email($after);
        });

        $flash = 'docs';
        break;

    /* ---------- nudge whoever still owes ---------- */
    case 'remind':
        $totals = payment_totals($app);

        if ($totals['settled']) {
            http_response_code(409);
            exit('Both payments on this application are verified.');
        }

        if ($totals['stages'][$totals['current']]['state'] === 'checking') {
            http_response_code(409);
            exit('There is a receipt waiting to be checked — verify or reject it first.');
        }

        /* counted now, sent after: a reminder nobody is watching go out */
        db()->prepare('UPDATE applications SET reminded_at = NOW(), reminder_count = reminder_count + 1 WHERE id = ?')
            ->execute([$id]);

        after_response(static function () use ($app, $totals): void {
            send_payment_reminder_email($app, $totals);
        });

        $flash = 'reminded';
        break;

    default:
        http_response_code(422);
        exit('Unknown action.');
}

$return = (string) ($_POST['return'] ?? '');

if (!preg_match('#^(\./|list)(\?[a-z0-9=&_%-]*)?$#i', $return)) {
    $return = 'list?type=' . urlencode($type);
}

admin_flash(['pay' => $flash, 'saved' => $id]);

header('Location: ' . $return);
exit;
