<?php
/**
 * Serves the proof a customer uploaded for a payment made to this dealer.
 *
 * The file is looked up from the payments row and the sale has to be this
 * dealer's (partner_sold). Nothing here takes a path from the request.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dealer = require_dealer();

serve_partner_payment_proof('dealer', (int) $dealer['id'], (int) ($_GET['payment'] ?? 0));
