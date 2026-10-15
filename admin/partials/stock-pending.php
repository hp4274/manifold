<?php
/**
 * The buyer's "Pending amount": orders paid only in part, one card each with
 * what is owed and a form to add the next payment (amount, reference, proof)
 * until the balance is nil. The page handles action=pay_balance through
 * stock_pay_post(), which checks the order is the buyer's own and caps the
 * amount at what is still owed.
 *
 * Expects $ownOrders (from stock_orders_for()).
 */

declare(strict_types=1);

$pendingOrders = array_values(array_filter($ownOrders, static fn (array $o): bool => stock_order_balance($o) > 0));
?>
<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Pending amount</h2>
      <span class="eyebrow"><?= count($pendingOrders) ?> order<?= count($pendingOrders) === 1 ? '' : 's' ?> not fully paid</span>
    </div>
  </div>

  <div class="panel__body pending-list">
    <?php if (!$pendingOrders): ?>
      <p class="field-hint">No entry found — every order is paid in full.</p>
    <?php endif; ?>

    <?php foreach ($pendingOrders as $po): ?>
      <?php
        $poBalance = stock_order_balance($po);
        $poPercent = (int) round(100 * (float) $po['paid_amount'] / max(1.0, (float) $po['total_amount']));
      ?>
      <article class="pending-card">
        <header class="pending-card__head">
          <div class="cell-stack">
            <strong><?= e(stock_order_summary((int) $po['id'])) ?></strong>
            <span class="cell-sub">Ordered <?= e(format_datetime($po['requested_at'])) ?></span>
          </div>
          <dl class="pending-card__sums">
            <div><dt>Total</dt><dd><?= e(money((float) $po['total_amount'])) ?></dd></div>
            <div><dt>Paid</dt><dd><?= e(money((float) $po['paid_amount'])) ?></dd></div>
            <div class="pending-card__owed"><dt>Pending</dt><dd><?= e(money($poBalance)) ?></dd></div>
          </dl>
        </header>

        <div class="pending-card__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"
             aria-valuenow="<?= $poPercent ?>" aria-label="Share of the order paid">
          <span style="width:<?= $poPercent ?>%"></span>
        </div>

        <form method="post" enctype="multipart/form-data" class="pending-card__form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="pay_balance">
          <input type="hidden" name="order_id" value="<?= (int) $po['id'] ?>">

          <div class="field">
            <label for="pay_amount_<?= (int) $po['id'] ?>">Amount paid</label>
            <input id="pay_amount_<?= (int) $po['id'] ?>" name="amount" type="number" min="1"
                   max="<?= e(number_format($poBalance, 2, '.', '')) ?>" step="0.01" inputmode="decimal" required
                   value="<?= e(number_format($poBalance, 2, '.', '')) ?>">
          </div>
          <div class="field">
            <label for="pay_ref_<?= (int) $po['id'] ?>">Reference</label>
            <input id="pay_ref_<?= (int) $po['id'] ?>" name="reference" type="text" maxlength="120"
                   placeholder="UPI / UTR reference">
          </div>
          <div class="field">
            <label for="pay_proof_<?= (int) $po['id'] ?>">Proof of payment</label>
            <input id="pay_proof_<?= (int) $po['id'] ?>" name="payment_proof" type="file" required
                   accept="image/jpeg,image/png,image/webp,application/pdf">
          </div>
          <button type="submit" class="btn btn--primary">
            <i class="bi bi-cash-coin" aria-hidden="true"></i> Add payment
          </button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
</div>
