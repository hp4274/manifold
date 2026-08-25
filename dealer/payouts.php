<?php
/**
 * What the office has transferred to this dealer, and what is still standing.
 *
 * Reading only. A dealer cannot record, edit or remove a transfer — the office
 * does that from the admin, and this is the statement of it.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dealer    = require_dealer();
$dealerId  = (int) $dealer['id'];
$pageTitle = 'Payouts';
$pageLead  = 'What has been transferred to you, and what has not.';
$activeNav = 'payouts';

$totals  = dealer_totals($dealerId);
$payouts = dealer_payouts($dealerId);

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
