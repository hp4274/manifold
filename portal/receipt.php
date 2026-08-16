<?php
/**
 * Receipt PDF for one verified payment — applicant side.
 * Only ever serves a receipt belonging to the signed-in email address.
 * receipt.php?payment=12
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../admin/receipt-pdf.php';

$email     = require_applicant();
$paymentId = (int) ($_GET['payment'] ?? 0);

$stmt = db()->prepare(
    'SELECT p.*, a.reference_code, a.full_name, a.email, a.mobile_number, a.product,
            a.payment_amount, a.booking_amount, a.delivery_amount, a.status AS app_status,
            a.referral_code, a.referred_by_code, a.referral_reward
       FROM payments p
       JOIN applications a ON a.id = p.application_id
      WHERE p.id = ? AND a.email = ?'
);
$stmt->execute([$paymentId, $email]);
$row = $stmt->fetch();

if (!$row || $row['status'] !== 'verified') {
    http_response_code(404);
    exit('Receipt not found.');
}

$app = [
    'id'             => (int) $row['application_id'],
    'reference_code' => $row['reference_code'],
    'full_name'      => $row['full_name'],
    'email'          => $row['email'],
    'mobile_number'  => $row['mobile_number'],
    'product'        => $row['product'],
    'payment_amount'  => $row['payment_amount'],
    'booking_amount'  => $row['booking_amount'],
    'delivery_amount' => $row['delivery_amount'],
    'referral_code'  => $row['referral_code'],
    'referred_by_code' => $row['referred_by_code'],
    'referral_reward' => $row['referral_reward'],
    'status'         => $row['app_status'],
];

$pdf = build_receipt_pdf($app, $row, payment_totals($app));

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . receipt_filename($row) . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
