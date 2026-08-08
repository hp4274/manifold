<?php
/**
 * Serves an uploaded application document to a signed-in admin.
 * Direct access to /admin/uploads is blocked by .htaccess, so everything
 * goes through here — which means only authenticated users can read files.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

require_login();

$name = basename((string) ($_GET['path'] ?? ''));
$base = ($_GET['dir'] ?? '') === 'payments' ? PAYMENT_PROOF_DIR : UPLOAD_DIR;
$full = $base . '/' . $name;

if ($name === '' || !is_file($full) || strpos(realpath($full) ?: '', realpath($base) ?: '') !== 0) {
    http_response_code(404);
    exit('File not found.');
}

$mime = [
    'jpg'  => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
][strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
readfile($full);
