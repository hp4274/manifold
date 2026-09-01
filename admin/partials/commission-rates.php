<?php
/**
 * The commission amounts, as a form.
 *
 * The office sets these under Settings and R&F sets the same ones from their own
 * desk, so the fields and the wording live here rather than in two places that
 * could drift apart.
 *
 * A flat amount per sale, per product — not a percentage. Set
 * $rateHasPartnerPages to false where the reader cannot open the Dealers and
 * Distributors pages; R&F cannot.
 */

declare(strict_types=1);

$rateHasPartnerPages = $rateHasPartnerPages ?? true;

/* what each product sells for, so an amount can be read against it */
$rateProducts = [
    'stove'  => 'Stove',
    'tuktuk' => 'TukTuk kit',
];

$rateKinds = [
    'dealer'   => ['label' => 'Dealer commission',   'note' => 'to the dealer who made the sale'],
    'override' => ['label' => 'Distributor override', 'note' => 'to that dealer’s distributor'],
    'direct'   => ['label' => 'Distributor commission', 'note' => 'when a distributor sells it themselves'],
];
?>
  <form method="post" class="panel__body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="commission">

    <?php /* Two ways a sale can arrive, and who is paid out of each. Stating it
             in full is the only way the fields below read as one scheme rather
             than six unrelated numbers. */ ?>
    <div class="rate-rules">
      <p class="rate-rule">
        <strong>A dealer sells.</strong> The dealer takes their commission and the distributor who
        signed them up takes the override. Every dealer answers to a distributor, so the override is
        owed to nobody.
      </p>
      <p class="rate-rule">
        <strong>A distributor sells.</strong> They take the distributor commission and no dealer is
        involved.
      </p>
    </div>

    <?php foreach ($rateKinds as $rateKind => $rateMeta): ?>
      <section class="form-section">
        <div class="form-section__head">
          <h3 class="form-section__title"><?= e($rateMeta['label']) ?></h3>
          <span class="form-section__note"><?= e($rateMeta['note']) ?></span>
        </div>

        <div class="form-grid">
          <?php foreach ($rateProducts as $rateProduct => $rateProductLabel): ?>
            <?php
              $rateField = 'commission_' . $rateKind . '_' . $rateProduct;
              $ratePlan  = payment_plan($rateProduct);
              $rateSale  = (float) $ratePlan['booking'] + (float) $ratePlan['delivery'];
              $rateNow   = commission_value($rateKind, $rateProduct);
            ?>
            <div class="field">
              <label for="<?= e($rateField) ?>"><?= e($rateProductLabel) ?></label>
              <input id="<?= e($rateField) ?>" name="<?= e($rateField) ?>" type="number"
                     step="0.01" min="0" value="<?= e(number_format($rateNow, 2, '.', '')) ?>" required>
              <span class="field-hint">
                out of the <?= e(money($rateSale)) ?> the sale is worth
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

    <span class="field-hint">
      A flat amount per sale, earned in full when the <strong>delivery payment</strong> is verified —
      the booking payment on its own earns nobody anything. Nothing is transferred automatically: it is
      paid out against a commission voucher<?php
        if ($rateHasPartnerPages): ?>, and recorded under
      <a href="dealers">Dealers</a> or <a href="distributors">Distributors</a><?php endif; ?>.
      Every sale keeps the figures that applied on the day it came in, so changing these never
      rewrites commission already owed.
    </span>

    <button type="submit" class="btn btn--primary">Save</button>
  </form>
