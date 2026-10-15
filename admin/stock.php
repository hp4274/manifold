<?php
/**
 * Stock orders: what the office has been asked for, and what has been released.
 *
 * The office sells to the top of the chain — Principal C&Fs — and to anyone
 * further down whose own tier above is missing (a distributor with no Zonal
 * C&F, say). The buyer pays first and uploads the proof; approving here is what puts
 * the units on their shelf. Nothing moves until somebody has looked at the
 * proof, which is why the decision is a pair of buttons next to it rather than
 * anything automatic.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Stock';
$pageLead   = 'What the chain has ordered from the office, and who is holding what.';
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
    } elseif (in_array($action, ['approve', 'reject'], true) && $order['seller_type'] !== 'office') {
        /* a partner's shelf is theirs to release from, not the office's */
        $error = 'That order is for ' . stock_tier_label((string) $order['seller_type']) . ' to decide.';
    } elseif ($action === 'approve') {
        $error = stock_order_approve($orderId, (int) $user['id']);

        if ($error === '') {
            $buyer = stock_order_buyer($order);

            stock_done(($buyer['full_name'] ?? 'That partner') . ' now holds '
                . stock_order_summary($orderId) . ', worth '
                . money((float) $order['total_amount']) . '.');
        }
    } elseif ($action === 'reject') {
        $error = stock_order_reject($orderId, (string) ($_POST['reason'] ?? ''), (int) $user['id']);

        if ($error === '') {
            stock_done('Order turned down. No stock has moved.');
        }
    } elseif ($action === 'adjust_stock') {
        [$msg, $error] = stock_adjust_post('office', 0);

        if ($error === '') {
            stock_done($msg);
        }
    } else {
        $error = 'Unknown action.';
    }
}

$orders  = stock_orders_to_decide('office', null);
$waiting = array_values(array_filter($orders, static fn (array $o): bool => $o['status'] === 'pending'));

/* who is holding what, so the office can see where the stock actually sits —
   every tier that keeps a shelf above the dealers, top of the chain first */
$holders = db()->query(
    "SELECT 'principal_cf' AS tier, id, full_name, principal_code AS code FROM principal_cf
     UNION ALL
     SELECT 'zonal_cf', id, full_name, zonal_code FROM zonal_cf
     UNION ALL
     SELECT 'distributor', id, full_name, distributor_code FROM distributors
     ORDER BY FIELD(tier, 'principal_cf', 'zonal_cf', 'distributor'), full_name"
)->fetchAll();

foreach ($holders as $i => $holder) {
    $holders[$i]['stock'] = stock_balance((string) $holder['tier'], (int) $holder['id']);
}

$outUnits = 0;
$outValue = 0.0;

foreach ($holders as $holder) {
    $outUnits += (int) $holder['stock']['units'];
    $outValue += (float) $holder['stock']['value'];
}

$takenValue = (float) db()->query(
    "SELECT COALESCE(SUM(total_amount), 0) FROM stock_orders
      WHERE status = 'approved' AND seller_type = 'office'"
)->fetchColumn();

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?>
  <p class="alert alert--ok"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error"><?= e($error) ?></p>
<?php endif; ?>

<?php /* the same three rows as every other stock page — label (pill at its
         right), figure, one line of sub-text — see admin/partials/stock-tiles.php */ ?>
<div class="tiles tiles--stock">
  <span class="tile">
    <span class="tile__head">
      <span class="eyebrow">Waiting on you</span>
      <?php if ($waiting): ?>
        <span class="pill pill--pending">To release</span>
      <?php else: ?>
        <span class="pill pill--good">Clear</span>
      <?php endif; ?>
    </span>
    <strong class="stock-figure"><?= count($waiting) ?></strong>
    <span class="tile__note">orders paid for and not yet released</span>
  </span>
  <span class="tile <?= e(stock_tile_class((int) $outUnits)) ?>">
    <span class="tile__head">
      <span class="eyebrow">Units out in the chain</span>
      <?= stock_badge((int) $outUnits, true) ?>
    </span>
    <strong class="stock-figure"><?= (int) $outUnits ?></strong>
    <span class="tile__note">on partners' shelves, unsold</span>
  </span>
  <span class="tile">
    <span class="tile__head"><span class="eyebrow">Value of that stock</span></span>
    <strong class="stock-figure"><?= e(money_short($outValue)) ?></strong>
    <span class="tile__note">at what each holder paid for it</span>
  </span>
  <span class="tile">
    <span class="tile__head"><span class="eyebrow">Taken in for stock</span></span>
    <strong class="stock-figure"><?= e(money_short($takenValue)) ?></strong>
    <span class="tile__note">across every order the office released</span>
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
    <table class="data-table data-table--dealers" data-paged="10" data-export="stock-order-requests">
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
          <th>Buyer</th>
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
            <td colspan="6">No entry found — nobody has ordered stock from the office yet.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($orders as $order): ?>
          <tr>
            <td>
              <div class="cell-stack">
                <strong><?= e($order['buyer_name'] ?? '—') ?></strong>
                <span class="cell-sub">
                  <?= e(ucfirst(stock_tier_label((string) $order['buyer_type']))) ?> · <?= e($order['buyer_code'] ?? '') ?>
                </span>
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
            <td data-export="<?= e(stock_status_label((string) $order['status'])
                . (!empty($order['reject_reason']) ? ' — ' . $order['reject_reason'] : '')) ?>">
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
    <table class="data-table data-table--stack" data-paged="10" data-export="stock-holders">
      <thead>
        <tr>
          <th>Partner</th>
          <th>Stoves</th>
          <th>TukTuk kits</th>
          <th>Value at cost</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$holders): ?>
          <tr class="row-empty"><td colspan="4">No entry found — no partners yet.</td></tr>
        <?php endif; ?>

        <?php foreach ($holders as $holder): ?>
          <tr>
            <td data-label="Partner" data-export="<?= e($holder['full_name'] . ' — ' . ucfirst(stock_tier_label((string) $holder['tier'])) . ' ' . $holder['code']) ?>">
              <div class="cell-stack">
                <strong><?= e($holder['full_name']) ?></strong>
                <span class="cell-sub">
                  <?= e(ucfirst(stock_tier_label((string) $holder['tier']))) ?> · <?= e((string) $holder['code']) ?>
                </span>
              </div>
            </td>
            <?php foreach (['stove' => 'Stoves', 'tuktuk' => 'TukTuk kits'] as $pKey => $pLabel): ?>
              <?php $pUnits = (int) $holder['stock'][$pKey]['units']; ?>
              <td class="td-amount" data-label="<?= e($pLabel) ?>" data-export="<?= $pUnits ?>">
                <div class="stock-cell">
                  <strong class="stock-figure"><?= $pUnits ?></strong>
                  <?= stock_badge($pUnits, true) ?>
                </div>
              </td>
            <?php endforeach; ?>
            <td class="td-amount" data-label="Value at cost" data-export="<?= e(number_format((float) $holder['stock']['value'], 2, '.', '')) ?>">
              <strong><?= e(money((float) $holder['stock']['value'])) ?></strong>
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
      <h2>Correct a shelf</h2>
      <span class="eyebrow">Recount or fix any partner's stock</span>
    </div>
  </div>
  <div class="panel__body">
    <?php $adjustTargets = stock_adjust_targets('office'); require __DIR__ . '/partials/stock-adjust.php'; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
