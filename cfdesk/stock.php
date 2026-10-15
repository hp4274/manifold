<?php
/**
 * A C&F's stock: what they hold, what the tier below has asked them for, and
 * what they have ordered from the tier above.
 *
 * One page for both C&F tiers, as the distributor has one: a principal buys
 * from the office and sells to its Zonal C&Fs; a zonal buys from its principal
 * and sells to its distributors. Who sells to whom is never taken from the
 * request — stock_order_create() reads it from the hierarchy, and a decision
 * is only accepted on an order this partner was the seller of. Releasing takes
 * the units off this shelf at what they cost here, so nobody passes on more
 * than they hold.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

[$tier, $me] = require_cf_partner();

$meId      = (int) $me['id'];
$pageTitle = 'Stock';
$pageLead  = 'What you hold, what your network is asking for, and what you have ordered.';
$activeNav = 'stock';

/* who this C&F buys from: a principal from the office; a zonal from its
   principal, or the office when that principal is switched off */
[$aboveType, $aboveId] = stock_seller_for($tier, $meId);
$aboveRow  = $aboveId ? stock_party($aboveType, $aboveId) : null;
$stockFrom = $aboveType === 'office' ? 'the office' : 'your ' . stock_tier_label($aboveType);
$belowWho  = $tier === 'principal_cf' ? 'Zonal C&F' : 'Distributor';

$error       = '';
$stockValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'order_stock') {
        $posted = (array) ($_POST['qty'] ?? []);

        $stockValues = [
            'wanted'    => [
                /* read as typed, as on the distributor page: a negative is
                   refused with a sentence by stock_order_create() rather than
                   quietly turned into zero */
                'stove'  => (int) ($posted['stove'] ?? 0),
                'tuktuk' => (int) ($posted['tuktuk'] ?? 0),
            ],
            'reference' => trim((string) ($_POST['reference'] ?? '')),
            'note'      => trim((string) ($_POST['note'] ?? '')),
        ];

        $proof = store_upload('payment_proof', PAYMENT_PROOF_DIR);

        if ($proof === null) {
            $error = 'Upload the proof of payment — a JPG, PNG, WebP or PDF up to 10 MB.';
        } else {
            [$orderId, $error] = stock_order_create($tier, $meId, $stockValues['wanted'], [
                'reference'  => $stockValues['reference'] ?: null,
                'note'       => $stockValues['note'] ?: null,
                'proof_path' => $proof,
            ]);

            if ($error === '') {
                cf_done('Order sent to ' . ($aboveRow['full_name'] ?? $stockFrom)
                    . '. The units are released once the payment has been confirmed.', 'stock', $tier);
            }
        }
    } elseif ($action === 'adjust_stock') {
        [$msg, $error] = stock_adjust_post($tier, $meId);

        if ($error === '') {
            cf_done($msg, 'stock', $tier);
        }
    } elseif ($action === 'approve' || $action === 'reject') {
        $orderId = (int) ($_POST['order_id'] ?? 0);

        if (!stock_order_sold_by(stock_order($orderId), $tier, $meId)) {
            $error = 'That order is not yours to decide.';
        } elseif ($action === 'approve') {
            $error = stock_order_approve($orderId);

            if ($error === '') {
                cf_done('Released. The units have moved from your shelf to theirs.', 'stock', $tier);
            }
        } else {
            $error = stock_order_reject($orderId, (string) ($_POST['reason'] ?? ''));

            if ($error === '') {
                cf_done('Order turned down. Nothing has left your shelf.', 'stock', $tier);
            }
        }
    }
}

$stock     = stock_balance($tier, $meId);
$asks      = stock_orders_to_decide($tier, $meId);
$waiting   = array_values(array_filter($asks, static fn (array $o): bool => $o['status'] === 'pending'));
$ownOrders = stock_orders_for($tier, $meId);
$history   = stock_history($tier, $meId, 500);
$stockKind = $tier;
$flash     = cf_flash();

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?><p class="alert alert--ok"><?= e($flash) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="alert alert--error" role="alert"><?= e($error) ?></p><?php endif; ?>

<?php
  $stockQueue      = count($waiting);
  $stockQueueLabel = ($tier === 'principal_cf' ? 'Zonal C&F' : 'Distributors') . ' waiting on you';
  require __DIR__ . '/../admin/partials/stock-tiles.php';
?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Orders from your <?= $tier === 'principal_cf' ? 'Zonal C&amp;F' : 'distributors' ?></h2>
      <span class="eyebrow"><?= count($waiting) ?> waiting of <?= count($asks) ?></span>
    </div>
  </div>

  <?php
    $queueRows       = $asks;
    $queueWho        = $belowWho;
    $queueEmpty      = 'nobody under you has ordered stock yet.';
    $queueProofQuery = '&as=' . $tier;
    require __DIR__ . '/../admin/partials/stock-queue-table.php';
  ?>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Order more from <?= e($stockFrom) ?></h2>
      <span class="eyebrow">Pay first, then upload the proof</span>
    </div>
  </div>

  <div class="panel__body">
    <?php require __DIR__ . '/../admin/partials/stock-order-form.php'; ?>
  </div>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Correct a <?= e(strtolower($belowWho)) ?>'s shelf</h2>
      <span class="eyebrow">Recount or fix stock held by your <?= $tier === 'principal_cf' ? 'Zonal C&amp;Fs' : 'distributors' ?></span>
    </div>
  </div>
  <div class="panel__body">
    <?php $adjustTargets = stock_adjust_targets($tier, $meId); require __DIR__ . '/../admin/partials/stock-adjust.php'; ?>
  </div>
</div>

<div class="panel">
  <div class="panel__head">
    <h2>Your orders</h2>
  </div>

  <?php require __DIR__ . '/../admin/partials/stock-orders-table.php'; ?>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Every movement</h2>
      <span class="eyebrow">Where each unit came from and went</span>
    </div>
  </div>

  <?php $ledgerRows = $history; require __DIR__ . '/../admin/partials/stock-ledger-table.php'; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
