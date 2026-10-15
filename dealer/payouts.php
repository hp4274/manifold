<?php
/**
 * What this dealer has earned and what has reached them.
 *
 * A dealer is the end of the pass-down: released commission comes from the
 * office down the chain, and their distributor sends them their share with a
 * UTR and receipt (commission_transfer()). Nothing to claim, nothing to pass on.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dealer    = require_dealer();
$dealerId  = (int) $dealer['id'];
$pageTitle = 'Payouts';
$pageLead  = 'What you have earned, and every transfer your distributor has sent you.';
$activeNav = 'payouts';

$totals  = dealer_totals($dealerId);
$payouts = dealer_payouts($dealerId);   /* voucher-era transfers, before the pass-down */

$payoutLines = commission_payout_lines(['dealer'], $dealerId);
$receiptUrl  = 'receipt';

require __DIR__ . '/partials/layout-top.php';
?>

<div class="tiles">
  <span class="tile">
    <span class="eyebrow">Commission earned</span>
    <strong><?= e(money($totals['earned'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">across <?= (int) $totals['confirmed'] ?> confirmed
        sale<?= (int) $totals['confirmed'] === 1 ? '' : 's' ?></span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Paid to you</span>
    <strong><?= e(money($totals['paid'])) ?></strong>
  </span>
  <span class="tile">
    <span class="eyebrow">Still to reach you</span>
    <strong><?= e(money($totals['remaining'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">released commission comes down through your distributor</span>
    </span>
  </span>
</div>

<?php
$pdType    = 'dealer';
$pdId      = $dealerId;
$pdAction  = 'payouts';
$pdReceipt = static fn (array $t): string => 'receipt?transfer=' . (int) $t['id'];
require __DIR__ . '/../admin/partials/commission-passdown.php';
?>

<?php require __DIR__ . '/../admin/partials/commission-lines.php'; ?>

<?php if ($payouts): ?>
<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Earlier transfers</h2>
      <span class="eyebrow">paid against commission vouchers, before the pass-down</span>
    </div>
  </div>

  <div class="table-wrap">
    <table class="data-table data-table--dealer-payouts data-table--stack">
      <thead>
        <tr><th>Paid on</th><th>Amount</th><th>Reference</th></tr>
      </thead>
      <tbody>
        <?php foreach ($payouts as $payout): ?>
          <tr>
            <td data-label="Paid on"><?= e(format_datetime($payout['paid_at'])) ?></td>
            <td class="td-amount" data-label="Amount"><strong><?= e(money((float) $payout['amount'])) ?></strong></td>
            <td data-label="Reference"><?= e($payout['note'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
