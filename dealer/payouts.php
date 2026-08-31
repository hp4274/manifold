<?php
/**
 * What this dealer is owed, what they have claimed, and what has been paid.
 *
 * A dealer cannot pay themselves. What they can do is raise a voucher: a claim
 * for the commission they have earned and not been paid, which goes to their
 * distributor, then to R&F, then to the office, and comes back as money. See
 * CLIENT-FLOW.md §10.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dealer    = require_dealer();
$dealerId  = (int) $dealer['id'];
$pageTitle = 'Payouts';
$pageLead  = 'What you are owed, what you have claimed, and what has been paid.';
$activeNav = 'payouts';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'raise') {
    csrf_check();

    [$voucherId, $error] = voucher_raise('dealer', $dealerId, $dealer['full_name']);

    if ($error === '') {
        $_SESSION['dealer_flash'] = 'Voucher raised. It is with your distributor now.';

        header('Location: payouts.php');
        exit;
    }
}

$totals    = dealer_totals($dealerId);
$payouts   = dealer_payouts($dealerId);
$claimable = voucher_claimable('dealer', $dealerId);
$openVoucher = voucher_open_for('dealer', $dealerId);
$myVouchers  = vouchers_for('dealer', $dealerId);

$claimTotal = 0.0;

foreach ($claimable as $claimRow) {
    $claimTotal += (float) $claimRow['amount'];
}

$flash = (string) ($_SESSION['dealer_flash'] ?? '');
unset($_SESSION['dealer_flash']);

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?>
  <p class="alert alert--ok"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error"><?= e($error) ?></p>
<?php endif; ?>

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
    <span class="tile__stats">
      <span class="tile__stat"><?= count($payouts) ?> transfer<?= count($payouts) === 1 ? '' : 's' ?></span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Still owed to you</span>
    <strong><?= e(money($totals['remaining'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">the office transfers this by hand</span>
    </span>
  </span>
</div>

<?php $voucherKind = 'dealer'; require __DIR__ . '/../admin/partials/voucher-claim.php'; ?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Transfers</h2>
      <span class="eyebrow">newest first</span>
    </div>
  </div>

  <?php if (!$payouts): ?>
    <p class="empty">
      Nothing has been transferred yet.
      <?= $totals['remaining'] > 0
          ? 'You are owed ' . e(money($totals['remaining'])) . ' — the office pays it by hand.'
          : 'Commission appears here once a client of yours has their booking payment verified.' ?>
    </p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--dealer-payouts">
        <colgroup>
          <col style="width:26%">
          <col style="width:22%">
          <col style="width:52%">
        </colgroup>
        <thead>
          <tr>
            <th>Paid on</th>
            <th>Amount</th>
            <th>Reference</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payouts as $payout): ?>
            <tr>
              <td><?= e(format_datetime($payout['paid_at'])) ?></td>
              <td class="td-amount"><strong><?= e(money((float) $payout['amount'])) ?></strong></td>
              <td><?= e($payout['note'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
