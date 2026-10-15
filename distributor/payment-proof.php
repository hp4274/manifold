<?php
/**
 * Serves the proof a customer uploaded for a payment made to this distributor.
 *
 * The file is looked up from the payments row and the sale has to be one of
 * this distributor's direct sales (partner_sold). Nothing here takes a path
 * from the request.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dist = require_distributor();

serve_partner_payment_proof('distributor', (int) $dist['id'], (int) ($_GET['payment'] ?? 0));
