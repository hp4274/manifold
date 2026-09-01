<?php
/**
 * Stock orders: what distributors have asked the office for, and what has been
 * released to them.
 *
 * A distributor pays first and uploads the proof; approving here is what puts
 * the units on their shelf. Nothing moves until somebody has looked at the
 * proof, which is why the decision is a pair of buttons next to it rather than
 * anything automatic.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Stock';
$pageLead   = 'What distributors have ordered, and what each of them is holding.';
$activeType = 'stock';

$error = '';

$flash = (string) ($_SESSION['stock_flash'] ?? '');
unset($_SESSION['stock_flash']);

/** Finish an action: remember what happened, then reload as a plain GET. */
function stock_done(string $message): void
{
    $_SESSION['stock_flash'] = $message;

    header('Location: stock');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action  = (string) ($_POST['action'] ?? '');
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $order   = stock_order($orderId);

    if (!$order && in_array($action, ['approve', 'reject'], true)) {
        $error = 'That order no longer exists.';
    } elseif ($action === 'approve') {
        $error = stock_order_approve($orderId, (int) $user['id']);

        if ($error === '') {
            $buyer = distributor_by_id((int) $order['buyer_id']);

            stock_done(($buyer['full_name'] ?? 'That distributor') . ' now holds '
                . stock_order_summary($orderId) . ', worth '
                . money((float) $order['total_amount']) . '.');
        }
    } elseif ($action === 'reject') {
        $error = stock_order_reject($orderId, (string) ($_POST['reason'] ?? ''), (int) $user['id']);

        if ($error === '') {
            stock_done('Order turned down. No stock has moved.');
        }
    } else {
        $error = 'Unknown action.';
    }
}

$orders  = stock_orders_to_decide(null);
$waiting = array_values(array_filter($orders, static fn (array $o): bool => $o['status'] === 'pending'));

/* who is holding what, so the office can see where the stock actually sits */
$holders = db()->query('SELECT id, full_name, distributor_code FROM distributors ORDER BY full_name')->fetchAll();

foreach ($holders as $i => $holder) {
    $holders[$i]['stock'] = stock_balance('distributor', (int) $holder['id']);
}

$outUnits = 0;
$outValue = 0.0;

foreach ($holders as $holder) {
    $outUnits += (int) $holder['stock']['units'];
    $outValue += (float) $holder['stock']['value'];
}

$takenValue = (float) db()->query(
    "SELECT COALESCE(SUM(total_amount), 0) FROM stock_orders
      WHERE status = 'approved' AND seller_distributor_id IS NULL"
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
    <span class="eyebrow">Waiting on you</span>
    <strong><?= count($waiting) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">orders paid for and not yet released</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Units out with distributors</span>
    <strong><?= (int) $outUnits ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">still on their shelves, unsold</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Value of that stock</span>
    <strong><?= e(money_short($outValue)) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">at what they paid for it</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Taken in for stock</span>
    <strong><?= e(money_short($takenValue)) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">across every order released</span>
    </span>
  </span>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Orders</h2>
      <span class="eyebrow"><?= count($waiting) ?> waiting of <?= count($orders) ?></span>
    </div>
  </div>

  <div class="table-wrap">
    <table class="data-table data-table--dealers">
      <?php /* the decision column carries a button and a reason box, so it
               takes the largest share; a count needs almost nothing */ ?>
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
          <th>Distributor</th>
          <th>Ordered</th>
          <th>Units</th>
          <th>Paid</th>
          <th>Proof</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$orders): ?>
          <tr class="row-empty">
            <td colspan="6">No entry found — no distributor has ordered stock yet.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($orders as $order): ?>
          <tr>
            <td>
              <div class="cell-stack">
                <strong><?= e($order['buyer_name']) ?></strong>
                <span class="cell-sub"><?= e($order['buyer_code']) ?></span>
              </div>
            </td>
            <td>
              <div class="cell-stack">
                <?php foreach (stock_order_items((int) $order['id']) as $orderItem): ?>
                  <span>
                    <b><?= (int) $orderItem['quantity'] ?></b> ×
                    <?= e(product_label((string) $orderItem['product'])) ?>
                  </span>
                <?php endforeach; ?>
                <span class="cell-sub"><?= e(format_datetime($order['requested_at'])) ?></span>
              </div>
            </td>
            <td class="td-amount stock-figure">
              <strong><?= stock_order_units((int) $order['id']) ?></strong>
            </td>
            <td class="td-amount">
              <strong><?= e(money((float) $order['total_amount'])) ?></strong>
            </td>
            <td>
              <?php if (!empty($order['proof_path'])): ?>
                <div class="cell-stack">
                  <a class="link-arrow" data-viewer="Proof of payment"
                     href="file.php?path=<?= e(rawurlencode((string) $order['proof_path'])) ?>&amp;dir=payments">
                    <i class="bi bi-paperclip" aria-hidden="true"></i> Proof
                  </a>
                  <?php if (!empty($order['reference'])): ?>
                    <span class="cell-sub">ref <?= e($order['reference']) ?></span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <span class="cell-sub">none uploaded</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($order['status'] === 'pending'): ?>
                <?php /* the answer first, the reason under it — a decision has
                         to fit the column it is made in */ ?>
                <div class="decide">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <button type="submit" class="btn btn--primary btn--sm">
                      <i class="bi bi-check-lg" aria-hidden="true"></i> Payment received
                    </button>
                  </form>

                  <form method="post"
                        data-confirm="Turn this order down? No stock moves and nothing is deducted.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <div class="decide__reason">
                      <label class="visually-hidden" for="reason-<?= (int) $order['id'] ?>">
                        Why this order is being turned down
                      </label>
                      <input id="reason-<?= (int) $order['id'] ?>" name="reason" type="text" maxlength="255"
                             placeholder="Why? They see this">
                      <button type="submit" class="btn btn--ghost btn--sm">Turn down</button>
                    </div>
                  </form>
                </div>
              <?php else: ?>
                <div class="decide__settled">
                  <span class="pill pill--<?= $order['status'] === 'approved' ? 'accepted' : 'rejected' ?>">
                    <?= e(stock_status_label((string) $order['status'])) ?>
                  </span>
                  <span class="cell-sub"><?= e(format_datetime($order['decided_at'])) ?></span>
                  <?php if (!empty($order['reject_reason'])): ?>
                    <span class="cell-sub"><?= e($order['reject_reason']) ?></span>
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
      <h2>Who is holding what</h2>
      <span class="eyebrow">Units in hand, and what they cost</span>
    </div>
  </div>

  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Distributor</th>
          <th>Stoves</th>
          <th>TukTuk kits</th>
          <th>Value at cost</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$holders): ?>
          <tr class="row-empty"><td colspan="4">No entry found — no distributors yet.</td></tr>
        <?php endif; ?>

        <?php foreach ($holders as $holder): ?>
          <tr>
            <td>
              <div class="cell-stack">
                <strong><?= e($holder['full_name']) ?></strong>
                <span class="cell-sub"><?= e($holder['distributor_code']) ?></span>
              </div>
            </td>
            <td class="td-amount stock-figure"><strong><?= (int) $holder['stock']['stove']['units'] ?></strong></td>
            <td class="td-amount stock-figure"><strong><?= (int) $holder['stock']['tuktuk']['units'] ?></strong></td>
            <td class="td-amount"><strong><?= e(money((float) $holder['stock']['value'])) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
