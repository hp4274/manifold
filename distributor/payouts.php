<?php
/**
 * This distributor's commission: what has come down to them, and their
 * dealers' share to pass on.
 *
 * Released money reaches a distributor from their zonal C&F (or from the office
 * when the sale had none). Their own lines are paid by that arrival; the rest is
 * their dealers', sent on from here with a UTR and receipt per dealer. The
 * amount is never typed — commission_transfer() works it out from the lines.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dist      = require_distributor();
$distId    = (int) $dist['id'];
$pageTitle = 'Payouts';
$pageLead  = 'What has reached you, and what you owe each of your dealers.';
$activeNav = 'payouts';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ((string) ($_POST['action'] ?? '') === 'passdown') {
        [$done, $error] = commission_transfer_post('distributor', $distId, (string) $dist['full_name']);

        if ($error === '') {
            $_SESSION['distributor_flash'] = $done;
            header('Location: payouts');
            exit;
        }
    }
}

$totals  = distributor_totals($distId);
$payouts = distributor_payouts($distId);   /* voucher-era transfers, before the pass-down */

$payoutLines = commission_payout_lines(['distributor'], $distId);
$receiptUrl  = 'receipt';

$flash = (string) ($_SESSION['distributor_flash'] ?? '');
unset($_SESSION['distributor_flash']);

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?>
  <p class="alert alert--ok"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error" role="alert"><?= e($error) ?></p>
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
  </span>
  <span class="tile">
    <span class="eyebrow">Still to reach you</span>
    <strong><?= e(money($totals['remaining'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">released commission comes down the chain to you</span>
    </span>
  </span>
</div>

<?php
$pdType    = 'distributor';
$pdId      = $distId;
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
