<?php
/**
 * A partner's own stock orders and where each one has got to.
 *
 * Read-only: the decision belongs to the tier above, and this is where the
 * answer comes back — including the reason, when the answer was no.
 *
 * Expects $ownOrders.
 */

declare(strict_types=1);
?>
<div class="table-wrap">
  <table class="data-table">
    <colgroup>
      <col style="width:20%">
      <col style="width:28%">
      <col style="width:10%">
      <col style="width:18%">
      <col style="width:24%">
    </colgroup>
    <thead>
      <tr>
        <th>Ordered</th>
        <th>Product</th>
        <th>Units</th>
        <th>Paid</th>
        <th>Where it stands</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$ownOrders): ?>
        <tr class="row-empty">
          <td colspan="5">No entry found — you have not ordered any stock yet.</td>
        </tr>
      <?php endif; ?>

      <?php foreach ($ownOrders as $ownOrder): ?>
        <tr>
          <td><?= e(format_datetime($ownOrder['requested_at'])) ?></td>
          <td>
            <div class="cell-stack">
              <?php foreach (stock_order_items((int) $ownOrder['id']) as $ownItem): ?>
                <span>
                  <b><?= (int) $ownItem['quantity'] ?></b> ×
                  <?= e(product_label((string) $ownItem['product'])) ?>
                </span>
              <?php endforeach; ?>
            </div>
          </td>
          <td class="td-amount stock-figure">
            <strong><?= stock_order_units((int) $ownOrder['id']) ?></strong>
          </td>
          <td class="td-amount">
            <strong><?= e(money((float) $ownOrder['total_amount'])) ?></strong>
          </td>
          <td>
            <div class="cell-stack">
              <span class="pill pill--<?= $ownOrder['status'] === 'approved'
                  ? 'accepted' : ($ownOrder['status'] === 'pending' ? 'booking_review' : 'rejected') ?>">
                <?= e(stock_status_label((string) $ownOrder['status'])) ?>
              </span>
              <?php if (!empty($ownOrder['reject_reason'])): ?>
                <span class="cell-sub"><?= e($ownOrder['reject_reason']) ?></span>
              <?php elseif (!empty($ownOrder['decided_at'])): ?>
                <span class="cell-sub"><?= e(format_datetime($ownOrder['decided_at'])) ?></span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
