<?php
/**
 * C&F: the paying agent's desk.
 *
 * Commission claims arrive here as bundles — one distributor, their own claim
 * and every dealer voucher they approved. C&F has three jobs and they are the
 * three sections of this page:
 *
 *   1. Check a bundle and send it to the office, or send it back.
 *   2. Wait while the office decides and funds it.
 *   3. Pay the partners once the money is in, and record the reference.
 *
 * C&F sees no clients, no stock and no rates. Only who is owed what.
 */

declare(strict_types=1);

require_once __DIR__ . '/../admin/lib.php';

$user      = require_rf();
$pageTitle = 'Commission';
$pageLead  = 'What has been claimed, what the office has funded, and what is left to pay.';
$activeNav = 'dashboard';

$error = '';

$flash = (string) ($_SESSION['rf_flash'] ?? '');
unset($_SESSION['rf_flash']);

/** Finish an action: remember what happened, then reload as a plain GET. */
function rf_done(string $message): void
{
    $_SESSION['rf_flash'] = $message;

    header('Location: ./');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action   = (string) ($_POST['action'] ?? '');
    $bundleId = (int) ($_POST['bundle_id'] ?? 0);
    $bundle   = voucher($bundleId);
    $actor    = 'C&F';

    if (!$bundle) {
        $error = 'That bundle no longer exists.';
    } elseif ($action === 'forward') {
        /* the office is the only party that can say the money is owed, so this
           does not pay anything — it puts the claim in front of them */
        $error = voucher_move_bundle($bundleId, 'with_admin', $actor, ['with_rf'], 'Checked and forwarded');

        if ($error === '') {
            rf_done('Sent to the office. They decide whether it is owed.');
        }
    } elseif ($action === 'send_back') {
        $error = voucher_reject($bundleId, $actor, (string) ($_POST['reason'] ?? ''));

        if ($error === '') {
            rf_done('Sent back. The dealers\' vouchers have gone to their distributor again.');
        }
    } elseif ($action === 'pay') {
        $error = voucher_pay($bundleId, $actor, (string) ($_POST['reference'] ?? ''));

        if ($error === '') {
            rf_done('Paid. Every partner in that bundle has a payout recorded against them.');
        }
    } else {
        $error = 'Unknown action.';
    }
}

$toCheck  = voucher_bundles(['with_rf']);
$withAdmin = voucher_bundles(['with_admin']);
$toPay    = voucher_bundles(['funded']);

/** What a list of bundles is worth altogether. */
$sumBundles = static function (array $bundles): float {
    $total = 0.0;

    foreach ($bundles as $bundle) {
        $total += voucher_bundle_total((int) $bundle['id']);
    }

    return $total;
};

$paidTotal = (float) db()->query(
    "SELECT COALESCE(SUM(amount), 0) FROM commission_vouchers WHERE status = 'paid'"
)->fetchColumn();

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
    <span class="eyebrow">To check</span>
    <strong class="stock-figure"><?= count($toCheck) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money($sumBundles($toCheck))) ?> claimed</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">With the office</span>
    <strong class="stock-figure"><?= count($withAdmin) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money($sumBundles($withAdmin))) ?> waiting on a decision</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">To pay</span>
    <strong class="stock-figure"><?= count($toPay) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money($sumBundles($toPay))) ?> funded and owed out</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Paid so far</span>
    <strong class="stock-figure"><?= e(money($paidTotal)) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">across every voucher settled</span>
    </span>
  </span>
</div>

<?php /* 1. the queue: check the claim, then forward it or send it back */ ?>
<div class="panel" id="queue">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>To check</h2>
      <span class="eyebrow">Claims from distributors, not yet with the office</span>
    </div>
  </div>

  <?php $rfBundles = $toCheck; $rfMode = 'check'; require __DIR__ . '/partials/bundle-list.php'; ?>
</div>

<?php /* 2. nothing to do but see it: the office has it */ ?>
<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>With the office</h2>
      <span class="eyebrow">Waiting on their decision and the funds</span>
    </div>
  </div>

  <?php $rfBundles = $withAdmin; $rfMode = 'waiting'; require __DIR__ . '/partials/bundle-list.php'; ?>
</div>

<?php /* 3. the money is in: pay it out and say what the reference was */ ?>
<div class="panel" id="paying">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>To pay</h2>
      <span class="eyebrow">Funded by the office — pay the partners and record it</span>
    </div>
  </div>

  <?php $rfBundles = $toPay; $rfMode = 'pay'; require __DIR__ . '/partials/bundle-list.php'; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
