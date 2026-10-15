<?php
/**
 * The orders a selling partner has been asked to release, with the proof
 * beside each and the decision in the last column.
 *
 * Shared by the distributor (orders from its dealers) and both C&F desks
 * (orders from the tier below), because the decision is the same one at every
 * step of the chain: has the money arrived, and do I hold the units. The page
 * itself handles the approve / reject posts, and checks the order is its own.
 *
 * Expects $queueRows (from stock_orders_to_decide()), $queueWho (the column
 * heading: who is buying), $queueEmpty (the empty-table sentence) and
 * optionally $queueProofQuery, appended to the proof link — the C&F desk needs
 * its ?as= carried through.
 */

declare(strict_types=1);
?>
<div class="table-wrap">
  <table class="data-table data-table--stack" data-paged="10" data-export="stock-order-requests">
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
        <th><?= e($queueWho) ?></th>
        <th>Ordered</th>
        <th>Units</th>
        <th>They paid</th>
        <th>Proof</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$queueRows): ?>
        <tr class="row-empty">
          <td colspan="6">No entry found — <?= e($queueEmpty) ?></td>
        </tr>
      <?php endif; ?>

      <?php foreach ($queueRows as $ask): ?>
        <tr>
          <td data-lead>
            <div class="cell-stack">
              <strong><?= e($ask['buyer_name'] ?? '—') ?></strong>
              <span class="cell-sub"><?= e($ask['buyer_code'] ?? '') ?></span>
            </div>
          </td>
          <td data-label="Ordered">
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
          <td class="td-amount stock-figure" data-label="Units">
            <strong><?= stock_order_units((int) $ask['id']) ?></strong>
          </td>
          <td class="td-amount" data-label="They paid">
            <div class="cell-stack">
              <strong><?= e(money((float) $ask['paid_amount'])) ?></strong>
              <?php if (stock_order_balance($ask) > 0): ?>
                <span class="cell-sub">of <?= e(money((float) $ask['total_amount'])) ?> · <?= e(money(stock_order_balance($ask))) ?> pending</span>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Proof">
            <?php /* every payment is theirs to show and yours to check — each
                     opens over the page rather than in a tab of its own */ ?>
            <div class="cell-stack">
              <?= stock_payments_html($ask, static fn (array $p): string =>
                  'proof.php?order=' . (int) $ask['id'] . '&pay=' . (int) $p['id'] . ($queueProofQuery ?? '')) ?>
            </div>
          </td>
          <td data-block="Decide" data-export="<?= e(stock_status_label((string) $ask['status'])
              . (!empty($ask['reject_reason']) ? ' — ' . $ask['reject_reason'] : '')) ?>">
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
