<?php
/**
 * The commission percentages, as a form.
 *
 * The office sets these under Settings and C&F sets the same ones from their own
 * desk, so the fields and the wording live here rather than in two places that
 * could drift apart.
 *
 * A percentage of the sale, per tier of the chain, earned tranche by tranche.
 * Set $rateHasPartnerPages to false where the reader cannot open the Dealers and
 * Distributors pages; C&F cannot.
 */

declare(strict_types=1);

$rateHasPartnerPages = $rateHasPartnerPages ?? true;

$rateTiers = [
    'principal_cf' => ['label' => 'Principal C&F', 'note' => 'the principal carrying and forwarding agent'],
    'zonal_cf'     => ['label' => 'Zonal C&F',     'note' => 'the zonal agent under that principal'],
    'distributor'  => ['label' => 'Distributor',   'note' => 'the distributor the sale belongs to'],
    'dealer'       => ['label' => 'Dealer',        'note' => 'the dealer who made the sale'],
];

$rateGst   = commission_gst_percent();
$rateHours = commission_release_hours();
?>
  <form method="post" class="panel__body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="commission">

    <div class="rate-rules">
      <p class="rate-rule">
        <strong>Every tier on the sale is paid.</strong> A dealer sale pays the dealer, their
        distributor, that distributor’s zonal C&amp;F and the principal above them — each its own
        percentage, and none out of another’s share.
      </p>
      <p class="rate-rule">
        <strong>Earned payment by payment.</strong> Each percentage is taken on what the customer
        actually paid, so the booking payment earns its share at once and the delivery payment earns
        the rest when it is verified.
      </p>
    </div>

    <section class="form-section">
      <div class="form-section__head">
        <h3 class="form-section__title">Rates</h3>
        <span class="form-section__note">percent of the sale, excluding GST</span>
      </div>

      <div class="form-grid">
        <?php foreach ($rateTiers as $rateTier => $rateMeta): ?>
          <?php
            $rateField = 'commission_rate_' . $rateTier;
            $rateNow   = commission_rate($rateTier);
            $ratePlan  = payment_plan('stove');
            $rateSale  = (float) $ratePlan['booking'] + (float) $ratePlan['delivery'];
            $rateBase  = $rateGst > 0 ? $rateSale / (1 + $rateGst / 100) : $rateSale;
          ?>
          <div class="field">
            <label for="<?= e($rateField) ?>"><?= e($rateMeta['label']) ?></label>
            <span class="pct-input">
              <input id="<?= e($rateField) ?>" name="<?= e($rateField) ?>" type="number"
                     step="0.01" min="0" max="100"
                     value="<?= e(number_format($rateNow, 2, '.', '')) ?>" required>
              <span aria-hidden="true">%</span>
            </span>
            <span class="field-hint">
              <?= e($rateMeta['note']) ?> — <?= e(money(round($rateBase * $rateNow / 100, 2))) ?> on a stove
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="form-section">
      <div class="form-section__head">
        <h3 class="form-section__title">GST and the hold</h3>
        <span class="form-section__note">what is taken out first, and how long before the money is released</span>
      </div>

      <div class="form-grid">
        <div class="field">
          <label for="commission_gst_percent">GST in the price</label>
          <span class="pct-input">
            <input id="commission_gst_percent" name="commission_gst_percent" type="number"
                   step="0.01" min="0" max="100"
                   value="<?= e(number_format($rateGst, 2, '.', '')) ?>" required>
            <span aria-hidden="true">%</span>
          </span>
          <span class="field-hint">
            Taken out of the payment before the rates are applied. Zero where the published prices
            carry no GST.
          </span>
        </div>
        <div class="field">
          <label for="commission_release_hours">Hold before release</label>
          <input id="commission_release_hours" name="commission_release_hours" type="number"
                 step="1" min="0" max="8760" value="<?= (int) $rateHours ?>" required>
          <span class="field-hint">
            Hours. A commission line is held this long after the customer’s payment is verified,
            then releases itself for the office to transfer.
          </span>
        </div>
      </div>
    </section>

    <span class="field-hint">
      The rates together come to <strong><?= e(number_format(
          commission_rate('principal_cf') + commission_rate('zonal_cf')
          + commission_rate('distributor') + commission_rate('dealer'), 2
      )) ?>%</strong> of a sale that has the whole chain on it. Nothing is transferred automatically:
      a released line is paid by hand against its receipt<?php
        if ($rateHasPartnerPages): ?>, recorded under <a href="commissions">Payouts</a><?php endif; ?>.
      Every sale keeps the percentages that applied on the day it came in, so changing these never
      rewrites commission already owed.
    </span>

    <button type="submit" class="btn btn--primary">Save</button>
  </form>
