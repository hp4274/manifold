<?php
/**
 * Public download of the Terms & Conditions as a PDF, ?kind=stove|tuktuk|
 * principal_cf|zonal_cf|distributor|dealer. Public on purpose: a customer reads
 * these before they have any login, and a partner before they are approved.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin/terms-pdf.php';

$kind = (string) ($_GET['kind'] ?? '');

if (!in_array($kind, TERMS_PDF_KINDS, true)) {
    http_response_code(404);
    exit('Unknown terms.');
}

$pdf = build_terms_pdf($kind);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="manifold-terms-' . str_replace('_', '-', $kind) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
