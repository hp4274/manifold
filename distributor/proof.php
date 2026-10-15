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

stock_proof_send(stock_order_sold_by($order, 'distributor', (int) $dist['id']) ? $order : null);
