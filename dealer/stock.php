<?php
/**
 * A dealer's stock: what they hold, and what they have ordered from their
 * distributor.
 *
 * A dealer buys from whoever they answer to, never from the office — so a
 * dealer under nobody has nowhere to order from, and is told so rather than
 * shown a form that cannot work.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dealer     = require_dealer();
$dealerId   = (int) $dealer['id'];
$pageTitle  = 'Stock';
$pageLead   = 'What you hold, and what you have ordered.';
$activeNav  = 'stock';

/* every dealer answers to a distributor, so there is always somebody to
   order from */
$sellerId = (int) $dealer['distributor_id'];
$seller   = distributor_by_id($sellerId);

$error       = '';
$stockValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'order_stock') {
    csrf_check();

    /* a quantity per product: one order can be for both */
    $posted = (array) ($_POST['qty'] ?? []);

    $stockValues = [
        'wanted'    => [
            /* read as typed: turning a negative into zero here would drop the
               line silently and hand back an order nobody asked for.
               stock_order_create() refuses it with a sentence instead. */
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
        [$orderId, $error] = stock_order_create(
            'dealer',
            $dealerId,
            $stockValues['wanted'],
            $sellerId,
            [
                'reference'  => $stockValues['reference'] ?: null,
                'note'       => $stockValues['note'] ?: null,
                'proof_path' => $proof,
            ]
        );

        if ($error === '') {
            $_SESSION['dealer_flash'] = 'Order sent to ' . ($seller['full_name'] ?? 'your distributor')
                . '. The units reach you once they have confirmed the payment.';

            header('Location: stock');
            exit;
        }
    }
}

$stock     = stock_balance('dealer', $dealerId);
$ownOrders = stock_orders_for('dealer', $dealerId);
$history   = stock_history('dealer', $dealerId, 30);
$stockKind = 'dealer';

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
    <span class="eyebrow">Stoves in hand</span>
    <strong class="stock-figure"><?= (int) $stock['stove']['units'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money((float) $stock['stove']['value'])) ?> at cost</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">TukTuk kits in hand</span>
    <strong class="stock-figure"><?= (int) $stock['tuktuk']['units'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money((float) $stock['tuktuk']['value'])) ?> at cost</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Value on the shelf</span>
    <strong class="stock-figure"><?= e(money((float) $stock['value'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">what these units cost you</span>
    </span>
  </span>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Order more</h2>
      <span class="eyebrow">From <?= e($seller['full_name']) ?></span>
    </div>
  </div>

  <div class="panel__body">
    <?php require __DIR__ . '/../admin/partials/stock-order-form.php'; ?>
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
