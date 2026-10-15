<?php
/** C&F desk dashboard: network size and this tier's own commission. */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

[$tier, $me] = require_cf_partner();

$pageTitle = 'Dashboard';
$pageLead  = 'Your network and the commission you have earned.';
$activeNav = 'dashboard';

$zonals       = cf_zonals($tier, $me);
$distributors = cf_distributors($tier, $me);
$payoutLines  = commission_payout_lines([$tier], (int) $me['id']);
$receiptUrl   = 'receipt';

$earned = $paid = 0.0;

foreach ($payoutLines as $l) {
    $earned += (float) $l['amount'];
    $paid   += $l['payout_status'] === 'disbursed' ? (float) $l['amount'] : 0.0;
}

require __DIR__ . '/partials/layout-top.php';
?>

<div class="tiles">
  <span class="tile"><span class="eyebrow">Commission earned</span><strong><?= e(money($earned)) ?></strong>
    <span class="tile__stats"><span class="tile__stat">your rate <?= e(rate_short(cf_own_rate($tier, $me))) ?></span></span></span>
  <span class="tile"><span class="eyebrow">Paid to you</span><strong><?= e(money($paid)) ?></strong></span>
  <span class="tile"><span class="eyebrow">Still owed to you</span><strong><?= e(money($earned - $paid)) ?></strong></span>
  <?php if ($tier === 'principal_cf'): ?>
    <span class="tile"><span class="eyebrow">Zonal C&amp;F</span><strong><?= count($zonals) ?></strong></span>
  <?php endif; ?>
  <span class="tile"><span class="eyebrow">Distributors</span><strong><?= count($distributors) ?></strong></span>
</div>

<?php require __DIR__ . '/../admin/partials/commission-lines.php'; ?>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
