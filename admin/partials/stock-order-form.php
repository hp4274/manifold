<?php
/**
 * The form a partner uses to order units from the tier above them.
 *
 * Shared by the dealer and the distributor portals, because it is the same
 * order from either side — only the price and who receives it differ, and both
 * come from $stockKind ('dealer' or 'distributor').
 *
 * One order can be for both products. The partner pays once and uploads one
 * proof, so this is one document with a quantity against each product rather
 * than a picker that makes them come back for the second one.
 *
 * They pay first; the units are not theirs until the tier above has looked at
 * that proof and released them. Expects $stockKind and optionally $stockValues
 * to redisplay.
 */

declare(strict_types=1);

$so       = $stockValues ?? [];
$soWanted = $so['wanted'] ?? [];
$soTotal  = 0.0;

foreach (['stove', 'tuktuk'] as $soProduct) {
    $soTotal += stock_price($stockKind, $soProduct) * max(0, (int) ($soWanted[$soProduct] ?? 0));
}
?>
<form method="post" class="stock-order" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="order_stock">

  <div class="order-lines">
    <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk kit'] as $orderKey => $orderLabel): ?>
      <?php $orderPrice = stock_price($stockKind, $orderKey); ?>
      <div class="order-line">
        <div class="order-line__what">
          <label for="qty_<?= e($orderKey) ?>"><?= e($orderLabel) ?></label>
          <span class="order-line__price"><?= e(money($orderPrice)) ?> each</span>
        </div>

        <div class="order-line__qty">
          <input id="qty_<?= e($orderKey) ?>" name="qty[<?= e($orderKey) ?>]" type="number"
                 min="0" step="1" inputmode="numeric"
                 data-stock-qty data-price="<?= e(number_format($orderPrice, 2, '.', '')) ?>"
                 value="<?= (int) ($soWanted[$orderKey] ?? 0) ?>">
          <span class="order-line__unit">units</span>
        </div>

        <?php /* worked out as it is typed, and again on the server — the figure
                 that counts is never one that came from the browser */ ?>
        <p class="order-line__sum" data-stock-line>
          <?= e(money($orderPrice * max(0, (int) ($soWanted[$orderKey] ?? 0)))) ?>
        </p>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="stock-order__total">
    <span class="eyebrow">To pay</span>
    <strong data-stock-total><?= e(money($soTotal)) ?></strong>
    <span class="field-hint">
      Order either product or both — it is one payment and one proof either way. The units reach your
      account once <?= $stockKind === 'dealer' ? 'your distributor' : 'the office' ?> has confirmed it.
    </span>
  </div>

  <div class="form-grid">
    <div class="field">
      <label for="order_proof">
        Proof of payment<span class="field__req" aria-hidden="true">*</span>
      </label>
      <input id="order_proof" name="payment_proof" type="file" required
             accept="image/jpeg,image/png,image/webp,application/pdf">
      <span class="field-hint">Screenshot or PDF of the transfer, up to 10&nbsp;MB.</span>
    </div>

    <div class="field">
      <label for="order_reference">Reference</label>
      <input id="order_reference" name="reference" type="text" maxlength="120"
             placeholder="UPI / UTR reference" value="<?= e($so['reference'] ?? '') ?>">
    </div>

    <div class="field field--wide">
      <label for="order_note">Note</label>
      <input id="order_note" name="note" type="text" maxlength="255"
             placeholder="Anything worth knowing about this order"
             value="<?= e($so['note'] ?? '') ?>">
    </div>
  </div>

  <div class="direct-sale__foot">
    <button type="submit" class="btn btn--primary">
      <i class="bi bi-send" aria-hidden="true"></i> Send the order
    </button>
  </div>
</form>
