<?php
/**
 * A distributor's stock: what they hold, what they have ordered from their
 * Zonal C&F (or the office, when no Zonal C&F stands above them), and what
 * their dealers have asked them for.
 *
 * Both directions live on one page because they are one question — how many
 * units are on the shelf and where they are going. Releasing stock to a dealer
 * takes it off this shelf at what it cost here, so a distributor can never pass
 * on more than they hold.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dist      = require_distributor();
$distId    = (int) $dist['id'];
$pageTitle = 'Stock';
$pageLead  = 'What you hold, what you have ordered, and what your dealers are asking for.';
$activeNav = 'stock';

$error       = '';
$stockValues = [];

/* who this distributor buys from: their Zonal C&F, or the office when there
   is none — the same rule stock_order_create() applies, read here for the words */
[$aboveType, $aboveId] = stock_seller_for('distributor', $distId);
$aboveRow  = $aboveId ? stock_party($aboveType, $aboveId) : null;
$stockFrom = $aboveType === 'office' ? 'the office' : 'your ' . stock_tier_label($aboveType);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'order_stock') {
        /* a quantity per product: one order can be for both */
        $posted = (array) ($_POST['qty'] ?? []);

        $stockValues = [
            'wanted'    => [
                /* read as typed: turning a negative into zero here would drop
                   the line silently and hand back an order nobody asked for.
                   stock_order_create() refuses it with a sentence instead. */
                'stove'  => (int) ($posted['stove'] ?? 0),
                'tuktuk' => (int) ($posted['tuktuk'] ?? 0),
            ],
            'paid'      => trim((string) ($_POST['paid_amount'] ?? '')),
            'reference' => trim((string) ($_POST['reference'] ?? '')),
            'note'      => trim((string) ($_POST['note'] ?? '')),
        ];

        /* the proof is the whole point of the order — without it the seller
           has nothing to check, so it is taken before anything is written */
        $proof = store_upload('payment_proof', PAYMENT_PROOF_DIR);

        if ($proof === null) {
            $error = 'Upload the proof of payment — a JPG, PNG, WebP or PDF up to 10 MB.';
        } else {
            [$orderId, $error] = stock_order_create(
                'distributor',
                $distId,
                $stockValues['wanted'],
                [
                    'paid'       => $stockValues['paid'],
                    'reference'  => $stockValues['reference'] ?: null,
                    'note'       => $stockValues['note'] ?: null,
                    'proof_path' => $proof,
                ]
            );

            if ($error === '') {
                $_SESSION['distributor_flash'] = 'Order sent to '
                    . ($aboveRow['full_name'] ?? $stockFrom)
                    . '. The units are released once the payment has been confirmed.';

                header('Location: stock');
                exit;
            }
        }
    } elseif ($action === 'pay_balance') {
        [$msg, $error] = stock_pay_post('distributor', $distId);

        if ($error === '') {
            $_SESSION['distributor_flash'] = $msg;

            header('Location: stock');
            exit;
        }
    } elseif ($action === 'adjust_stock') {
        [$msg, $error] = stock_adjust_post('distributor', $distId);

        if ($error === '') {
            $_SESSION['distributor_flash'] = $msg;

            header('Location: stock');
            exit;
        }
    } elseif ($action === 'approve' || $action === 'reject') {
        /* a dealer's order: only ever one of this distributor's own */
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $order   = stock_order($orderId);

        if (!stock_order_sold_by($order, 'distributor', $distId)) {
            $error = 'That order is not yours to decide.';
        } elseif ($action === 'approve') {
            $error = stock_order_approve($orderId);

            if ($error === '') {
                $_SESSION['distributor_flash'] = 'Released. The units have moved from your shelf to theirs.';

                header('Location: stock');
                exit;
            }
        } else {
            $error = stock_order_reject($orderId, (string) ($_POST['reason'] ?? ''));

            if ($error === '') {
                $_SESSION['distributor_flash'] = 'Order turned down. Nothing has left your shelf.';

                header('Location: stock');
                exit;
            }
        }
    }
}

$stock       = stock_balance('distributor', $distId);
$ownOrders   = stock_orders_for('distributor', $distId);
$dealerAsks  = stock_orders_to_decide('distributor', $distId);
$waiting     = array_values(array_filter($dealerAsks, static fn (array $o): bool => $o['status'] === 'pending'));
$history     = stock_history('distributor', $distId, 500);
$stockKind   = 'distributor';

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

<?php
  $stockQueue      = count($waiting);
  $stockQueueLabel = 'Dealers waiting on you';
  require __DIR__ . '/../admin/partials/stock-tiles.php';
?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Your dealers' orders</h2>
      <span class="eyebrow"><?= count($waiting) ?> waiting of <?= count($dealerAsks) ?></span>
    </div>
  </div>

  <?php
    $queueRows  = $dealerAsks;
    $queueWho   = 'Dealer';
    $queueEmpty = 'none of your dealers has ordered stock yet.';
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

<?php require __DIR__ . '/../admin/partials/stock-pending.php'; ?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Correct a dealer's shelf</h2>
      <span class="eyebrow">Recount or fix stock held by your dealers</span>
    </div>
  </div>
  <div class="panel__body">
    <?php $adjustTargets = stock_adjust_targets('distributor', $distId); require __DIR__ . '/../admin/partials/stock-adjust.php'; ?>
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
