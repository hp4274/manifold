<?php
/**
 * C&F office desk — retired.
 *
 * This desk presented and paid commission voucher bundles. Vouchers are gone:
 * released commission now passes down the chain (office -> Principal C&F ->
 * Zonal C&F -> Distributor -> Dealer), each partner recording their own hop
 * with a UTR and receipt from their own portal. The office's end is
 * admin/commissions. The desk stays as a landing page so a 'cf' account, and
 * the links in old voucher emails, arrive somewhere that says so.
 */

declare(strict_types=1);

require_once __DIR__ . '/../admin/lib.php';

$user      = require_rf();
$pageTitle = 'Commission';
$pageLead  = 'Commission claims are no longer handled here.';
$activeNav = 'dashboard';

require __DIR__ . '/partials/layout-top.php';
?>

<div class="panel">
  <p class="empty">
    Commission vouchers have been retired. Released commission now passes down the chain:
    the office sends each Principal C&amp;F their network's total, and every partner passes
    the rest on to the partners below them, with a UTR and receipt at every step. Ask the
    office if you need access to Commission payouts.
  </p>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
