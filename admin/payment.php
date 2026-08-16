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
    header('Location: index.php');
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
        $flash  = send_receipt_email($app, $payment, $totals) ? 'receipt' : 'mailfail';
        break;

    /* ---------- that transfer does not check out ---------- */
    case 'reject':
        $payment = load_payment($paymentId, $id);

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

        $flash = send_payment_rejected_email($app, $reason, $payment, payment_totals($app)) ? 'rejected' : 'mailfail';
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

        if (send_payment_reminder_email($app, $totals)) {
            db()->prepare('UPDATE applications SET reminded_at = NOW(), reminder_count = reminder_count + 1 WHERE id = ?')
                ->execute([$id]);
            $flash = 'reminded';
        } else {
            $flash = 'mailfail';
        }
        break;

    default:
        http_response_code(422);
        exit('Unknown action.');
}

$return = (string) ($_POST['return'] ?? '');

if (!preg_match('/^(index|list)\.php(\?[a-z0-9=&_%-]*)?$/i', $return)) {
    $return = 'list.php?type=' . urlencode($type);
}

$return .= (strpos($return, '?') === false ? '?' : '&') . 'pay=' . $flash . '&saved=' . $id . '#row-' . $id;

header('Location: ' . $return);
exit;
