<?php
/**
 * What the office has transferred to this distributor, and what is standing.
 *
 * Reading only — the office records a transfer from the admin, and this is the
 * statement of it.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dist      = require_distributor();
$distId    = (int) $dist['id'];
$pageTitle = 'Payouts';
$pageLead  = 'What has been transferred to you, and what has not.';
$activeNav = 'payouts';

$totals  = distributor_totals($distId);
$payouts = distributor_payouts($distId);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'approve_dealer_voucher' || $action === 'reject_dealer_voucher') {
        /* their dealers' claims: the first check, because they are the one who
           knows whether those sales are real */
        $voucherId = (int) ($_POST['voucher_id'] ?? 0);

        $error = $action === 'approve_dealer_voucher'
            ? voucher_approve_dealer($voucherId, $distId, $dist['full_name'])
            : voucher_reject($voucherId, $dist['full_name'], (string) ($_POST['reason'] ?? ''));

        if ($error === '') {
            $_SESSION['distributor_flash'] = $action === 'approve_dealer_voucher'
                ? 'Approved. It goes to C&F in your next bundle.'
                : 'Turned down. Those sales can be claimed again.';

            header('Location: payouts');
            exit;
        }
    } elseif ($action === 'bundle') {
        [$bundleId, $error] = voucher_bundle($distId, $dist['full_name']);

        if ($error === '') {
            $_SESSION['distributor_flash'] = 'Bundle sent to C&F — your own claim and every dealer '
                . 'voucher you had approved.';

            header('Location: payouts');
            exit;
        }
    }
}

$claimable   = voucher_claimable('distributor', $distId);
$openVoucher = voucher_open_for('distributor', $distId);
$myVouchers  = vouchers_for('distributor', $distId);
$dealerAsks  = voucher_dealer_claims($distId, ['with_distributor']);
$approvedAsks = voucher_dealer_claims($distId, ['bundled']);
$approvedAsks = array_values(array_filter(
    $approvedAsks,
    static fn (array $v): bool => $v['parent_id'] === null
));

$claimTotal = 0.0;

foreach ($claimable as $claimRow) {
    $claimTotal += (float) $claimRow['amount'];
}

$flash = (string) ($_SESSION['distributor_flash'] ?? '');
unset($_SESSION['distributor_flash']);

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
      <span class="tile__stat">across <?= (int) $totals['confirmed'] ?> completed
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

<?php $voucherKind = 'distributor'; require __DIR__ . '/../admin/partials/voucher-claim.php'; ?>

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
          : 'Commission appears here once a sale under you is complete.' ?>
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
