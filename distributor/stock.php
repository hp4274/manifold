<?php
/**
 * A distributor's stock: what they hold, what they have ordered from the
 * office, and what their dealers have asked them for.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'order_stock') {
        /* a quantity per product: one order can be for both */
        $posted = (array) ($_POST['qty'] ?? []);

        $stockValues = [
            'wanted'    => [
                'stove'  => max(0, (int) ($posted['stove'] ?? 0)),
                'tuktuk' => max(0, (int) ($posted['tuktuk'] ?? 0)),
            ],
            'reference' => trim((string) ($_POST['reference'] ?? '')),
            'note'      => trim((string) ($_POST['note'] ?? '')),
        ];

        /* the proof is the whole point of the order — without it the office has
           nothing to check, so it is taken before anything is written */
        $proof = store_upload('payment_proof', PAYMENT_PROOF_DIR);

        if ($proof === null) {
            $error = 'Upload the proof of payment — a JPG, PNG, WebP or PDF up to 10 MB.';
        } else {
            [$orderId, $error] = stock_order_create(
                'distributor',
                $distId,
                $stockValues['wanted'],
                null,
                [
                    'reference'  => $stockValues['reference'] ?: null,
                    'note'       => $stockValues['note'] ?: null,
                    'proof_path' => $proof,
                ]
            );

            if ($error === '') {
                $_SESSION['distributor_flash'] = 'Order sent. The office releases the units once they '
                    . 'have confirmed the payment.';

                header('Location: stock.php');
                exit;
            }
        }
    } elseif ($action === 'approve' || $action === 'reject') {
        /* a dealer's order: only ever one of this distributor's own */
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $order   = stock_order($orderId);

        if (!$order || (int) $order['seller_distributor_id'] !== $distId) {
            $error = 'That order is not yours to decide.';
        } elseif ($action === 'approve') {
            $error = stock_order_approve($orderId);

            if ($error === '') {
                $_SESSION['distributor_flash'] = 'Released. The units have moved from your shelf to theirs.';

                header('Location: stock.php');
                exit;
            }
        } else {
            $error = stock_order_reject($orderId, (string) ($_POST['reason'] ?? ''));

            if ($error === '') {
                $_SESSION['distributor_flash'] = 'Order turned down. Nothing has left your shelf.';

                header('Location: stock.php');
                exit;
            }
        }
    }
}

$stock       = stock_balance('distributor', $distId);
$ownOrders   = stock_orders_for('distributor', $distId);
$dealerAsks  = stock_orders_to_decide($distId);
$waiting     = array_values(array_filter($dealerAsks, static fn (array $o): bool => $o['status'] === 'pending'));
$history     = stock_history('distributor', $distId, 30);
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
  <span class="tile">
    <span class="eyebrow">Dealers waiting on you</span>
    <strong><?= count($waiting) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">orders paid for and not yet released</span>
    </span>
  </span>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Your dealers' orders</h2>
      <span class="eyebrow"><?= count($waiting) ?> waiting of <?= count($dealerAsks) ?></span>
    </div>
  </div>

  <div class="table-wrap">
    <table class="data-table">
      <colgroup>
        <col style="width:18%">
        <col style="width:18%">
        <col style="width:8%">
        <col style="width:14%">
        <col style="width:14%">
        <col style="width:28%">
      </colgroup>
      <thead>
        <tr>
          <th>Dealer</th>
          <th>Ordered</th>
          <th>Units</th>
          <th>They paid</th>
          <th>Proof</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$dealerAsks): ?>
          <tr class="row-empty">
            <td colspan="6">No entry found — none of your dealers has ordered stock yet.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($dealerAsks as $ask): ?>
          <tr>
            <td>
              <div class="cell-stack">
                <strong><?= e($ask['buyer_name']) ?></strong>
                <span class="cell-sub"><?= e($ask['buyer_code']) ?></span>
              </div>
            </td>
            <td>
              <div class="cell-stack">
                <?php foreach (stock_order_items((int) $ask['id']) as $askItem): ?>
                  <span>
                    <b><?= (int) $askItem['quantity'] ?></b> ×
                    <?= e(product_label((string) $askItem['product'])) ?>
                  </span>
                <?php endforeach; ?>
                <span class="cell-sub"><?= e(format_datetime($ask['requested_at'])) ?></span>
              </div>
            </td>
            <td class="td-amount stock-figure">
              <strong><?= stock_order_units((int) $ask['id']) ?></strong>
            </td>
            <td class="td-amount"><strong><?= e(money((float) $ask['total_amount'])) ?></strong></td>
            <td>
              <?php /* the proof is theirs to show and yours to check — it opens
                       over the page rather than in a tab of its own */ ?>
              <?php if (!empty($ask['proof_path'])): ?>
                <div class="cell-stack">
                  <a class="link-arrow" data-viewer="Proof of payment"
                     href="proof.php?order=<?= (int) $ask['id'] ?>">
                    <i class="bi bi-paperclip" aria-hidden="true"></i> Proof
                  </a>
                  <?php if (!empty($ask['reference'])): ?>
                    <span class="cell-sub">ref <?= e($ask['reference']) ?></span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <span class="cell-sub">none uploaded</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($ask['status'] === 'pending'): ?>
                <div class="decide">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="order_id" value="<?= (int) $ask['id'] ?>">
                    <button type="submit" class="btn btn--primary btn--sm">
                      <i class="bi bi-check-lg" aria-hidden="true"></i> Payment received
                    </button>
                  </form>

                  <form method="post" data-confirm="Turn this order down? Nothing leaves your shelf.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="order_id" value="<?= (int) $ask['id'] ?>">
                    <div class="decide__reason">
                      <label class="visually-hidden" for="reason-<?= (int) $ask['id'] ?>">
                        Why this order is being turned down
                      </label>
                      <input id="reason-<?= (int) $ask['id'] ?>" name="reason" type="text" maxlength="255"
                             placeholder="Why? They see this">
                      <button type="submit" class="btn btn--ghost btn--sm">Turn down</button>
                    </div>
                  </form>
                </div>
              <?php else: ?>
                <div class="decide__settled">
                  <span class="pill pill--<?= $ask['status'] === 'approved' ? 'accepted' : 'rejected' ?>">
                    <?= e(stock_status_label((string) $ask['status'])) ?>
                  </span>
                  <?php if (!empty($ask['reject_reason'])): ?>
                    <span class="cell-sub"><?= e($ask['reject_reason']) ?></span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Order more from the office</h2>
      <span class="eyebrow">Pay first, then upload the proof</span>
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
