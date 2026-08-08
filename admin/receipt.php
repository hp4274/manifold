<?php
/**
 * Receipt PDF for one verified payment — admin side.
 * receipt.php?payment=12
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/receipt-pdf.php';

require_login();

$paymentId = (int) ($_GET['payment'] ?? 0);

$stmt = db()->prepare(
    'SELECT p.*, a.reference_code, a.full_name, a.email, a.mobile_number, a.product, a.payment_amount, a.status AS app_status
       FROM payments p
       JOIN applications a ON a.id = p.application_id
      WHERE p.id = ?'
);
$stmt->execute([$paymentId]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Receipt not found.');
}

if ($row['status'] !== 'verified') {
    http_response_code(409);
    exit('A receipt only exists once the payment has been verified.');
}

$app = [
    'id'             => (int) $row['application_id'],
    'reference_code' => $row['reference_code'],
    'full_name'      => $row['full_name'],
    'email'          => $row['email'],
    'mobile_number'  => $row['mobile_number'],
    'product'        => $row['product'],
    'payment_amount' => $row['payment_amount'],
    'status'         => $row['app_status'],
];

$pdf = build_receipt_pdf($app, $row, payment_totals($app));

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . receipt_filename($row) . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
