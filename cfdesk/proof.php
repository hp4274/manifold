<?php
/**
 * Serves the payment proof on a stock order this C&F was asked to release.
 *
 * The same rule as distributor/proof.php: the file is looked up from the order,
 * and the order has to be one sold by this tier and this partner — a principal
 * sees what its zonals paid it, a zonal what its distributors paid it, and a
 * guessed order id opens nothing.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

[$tier, $me] = require_cf_partner();

$order = stock_order((int) ($_GET['order'] ?? 0));

stock_proof_send(stock_order_sold_by($order, $tier, (int) $me['id']) ? $order : null);
