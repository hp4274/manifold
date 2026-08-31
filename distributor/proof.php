<?php
/**
 * Serves the payment proof on one of this distributor's own dealer orders.
 *
 * The admin has file.php for the same job, but that asks for an admin session.
 * A distributor has to see the proof to decide on it, and may see exactly that:
 * the file is looked up from the order, and the order has to be one they were
 * asked to release. Nothing here takes a path from the request.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dist  = require_distributor();
$order = stock_order((int) ($_GET['order'] ?? 0));

if (!$order
    || (int) $order['seller_distributor_id'] !== (int) $dist['id']
    || empty($order['proof_path'])) {
    http_response_code(404);
    exit('File not found.');
}

$name = basename((string) $order['proof_path']);
$full = PAYMENT_PROOF_DIR . '/' . $name;

if (!is_file($full) || strpos(realpath($full) ?: '', realpath(PAYMENT_PROOF_DIR) ?: '') !== 0) {
    http_response_code(404);
    exit('File not found.');
}

$mime = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
][strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
readfile($full);
