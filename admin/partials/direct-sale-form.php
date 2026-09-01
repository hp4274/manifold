<?php
/**
 * The form a partner fills in for a customer they sold to themselves.
 *
 * Shared by the dealer and the distributor portals, because it is the same
 * sale from either side and two copies would drift. Expects $saleKind
 * ('dealer' or 'distributor') and optionally $saleValues to redisplay.
 *
 * Short on purpose: the office fills in the rest. What it cannot fill in later
 * is who the customer is and how to reach them, so those are the only things
 * insisted on.
 */

declare(strict_types=1);

$sv = $saleValues ?? [];
?>
<form method="post" class="direct-sale" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add_client">

  <section class="form-section">
    <div class="form-section__head">
      <h3 class="form-section__title">Who bought it</h3>
      <span class="form-section__note">Name, email and mobile required</span>
    </div>

    <div class="form-grid">
      <div class="field field--wide field--primary">
        <label for="sale_full_name">Full name<span class="field__req" aria-hidden="true">*</span></label>
        <input id="sale_full_name" name="full_name" type="text" maxlength="160" required
               value="<?= e($sv['full_name'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="sale_email">Email<span class="field__req" aria-hidden="true">*</span></label>
        <input id="sale_email" name="email" type="email" maxlength="190" required
               value="<?= e($sv['email'] ?? '') ?>">
        <span class="field-hint">They sign in to their portal with this.</span>
      </div>

      <div class="field">
        <label for="sale_mobile">Mobile<span class="field__req" aria-hidden="true">*</span></label>
        <input id="sale_mobile" name="mobile_number" type="tel" inputmode="numeric"
               pattern="[0-9]{10}" maxlength="10" title="Ten digits, no spaces or country code" required
               value="<?= e($sv['mobile_number'] ?? '') ?>">
      </div>
    </div>
  </section>

  <section class="form-section">
    <div class="form-section__head">
      <h3 class="form-section__title">What they bought</h3>
    </div>

    <div class="form-grid">
      <div class="field">
        <label for="sale_product">Product</label>
        <select id="sale_product" name="product">
          <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $saleKey => $saleLabel): ?>
            <?php $salePlan = payment_plan($saleKey); ?>
            <option value="<?= e($saleKey) ?>" <?= ($sv['product'] ?? '') === $saleKey ? 'selected' : '' ?>>
              <?= e($saleLabel) ?> — <?= e(money((float) $salePlan['booking'] + (float) $salePlan['delivery'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="sale_units">Units</label>
        <input id="sale_units" name="units_required" type="number" min="1" max="99" step="1"
               inputmode="numeric"
               value="<?= (int) ($sv['units_required'] ?? 1) ?>">
      </div>
    </div>
  </section>

  <section class="form-section">
    <div class="form-section__head">
      <h3 class="form-section__title">Where they are</h3>
      <span class="form-section__note">Optional — the office can add it later</span>
    </div>

    <div class="form-grid">
      <div class="field field--wide">
        <label for="sale_address">Address</label>
        <input id="sale_address" name="address" type="text" maxlength="255"
               value="<?= e($sv['address'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="sale_city">City</label>
        <input id="sale_city" name="city" type="text" maxlength="120" value="<?= e($sv['city'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="sale_state">State</label>
        <input id="sale_state" name="state" type="text" maxlength="120" value="<?= e($sv['state'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="sale_pin">Pin code</label>
        <input id="sale_pin" name="pin_code" type="text" inputmode="numeric" pattern="[0-9]{6}"
               maxlength="6" title="Six digits" value="<?= e($sv['pin_code'] ?? '') ?>">
      </div>

      <div class="field field--wide">
        <label for="sale_note">Note</label>
        <textarea id="sale_note" name="note" rows="2"
                  placeholder="Anything the office should know about this sale."><?= e($sv['note'] ?? '') ?></textarea>
      </div>
    </div>
  </section>

  <?php /* said plainly, because it is the whole difference between this form
           and the public one: the customer already paid, and nothing here asks
           the company for money */ ?>
  <section class="form-section">
    <div class="form-section__head">
      <h3 class="form-section__title">Identification details</h3>
      <span class="form-section__note">Optional now — the office will ask if it is missing</span>
    </div>

    <div class="form-grid">
      <div class="field field--wide">
        <label for="sale_id_number">National ID / Passport number</label>
        <input id="sale_id_number" name="id_number" type="text" maxlength="80"
               value="<?= e($sv['id_number'] ?? '') ?>">
      </div>

      <?php /* not redisplayed after an error — a browser cannot refill a file
               input, so saying nothing is honest and asking again is clear */ ?>
      <div class="field">
        <label for="sale_id_document">Copy of the ID</label>
        <input id="sale_id_document" name="id_document_file" type="file"
               accept="image/jpeg,image/png,image/webp,application/pdf">
        <span class="field-hint">Photo or PDF, up to 10&nbsp;MB.</span>
      </div>

      <div class="field">
        <label for="sale_residence_proof">Residence proof</label>
        <input id="sale_residence_proof" name="residence_proof_file" type="file"
               accept="image/jpeg,image/png,image/webp,application/pdf">
        <span class="field-hint">Photo or PDF, up to 10&nbsp;MB.</span>
      </div>
    </div>
  </section>

  <section class="form-section">
    <div class="form-section__head">
      <h3 class="form-section__title">Declaration</h3>
      <span class="form-section__note">Required</span>
    </div>

    <p class="form-section__copy">
      The customer certifies that everything given here is true and correct to the best of their
      knowledge, and understands that this application does not guarantee approval, product
      allocation or installation until confirmed by the Company.
    </p>

    <label class="field-consent" for="sale_declaration">
      <input id="sale_declaration" name="declaration_accepted" type="checkbox" required
             <?= !empty($sv['declaration_accepted']) ? 'checked' : '' ?>>
      <span>I have read the declaration to the customer and they agree to it.
        <span class="field__req" aria-hidden="true">*</span></span>
    </label>

    <label class="field-consent" for="sale_testimonial">
      <input id="sale_testimonial" name="testimonial_consent" type="checkbox"
             <?= !empty($sv['testimonial_consent']) ? 'checked' : '' ?>>
      <span>They consent to being contacted for a customer testimonial.</span>
    </label>
  </section>

  <section class="form-section">
    <div class="form-section__head">
      <h3 class="form-section__title">Terms &amp; conditions</h3>
      <span class="form-section__note">Required</span>
    </div>

    <?php /* the same terms the website shows, kept short enough to be read on
             a phone in front of the customer */ ?>
    <div class="terms-scroll" tabindex="0" role="region" aria-label="Terms and conditions">
      <ul>
        <li>This application does not itself constitute approval, sale, financing or installation of the appliance.</li>
        <li>The Company may accept, reject or defer any application without assigning a reason.</li>
        <li>All information given by the applicant must be accurate and complete.</li>
        <li>The applicant authorises the Company to carry out verification, technical assessment and site inspection where required.</li>
        <li>False or misleading information may result in immediate rejection.</li>
        <li>Installation timelines are estimates and vary with location, product availability and regulatory approvals.</li>
        <li>If the financing application is rejected, any amount already paid towards the order is refunded in full.</li>
        <li>Specifications, performance figures and operating requirements may change without prior notice.</li>
        <li>The Company is not liable for delays caused by force majeure, government restrictions, utility interruptions or circumstances beyond its control.</li>
        <li>The applicant consents to their personal information being processed for application review, installation, support and warranty administration.</li>
        <li>Financing approvals, where applicable, remain subject to the financing institution's own review.</li>
        <li>Warranty terms are governed by the Warranty Certificate supplied with the appliance on installation.</li>
      </ul>
    </div>

    <label class="field-consent" for="sale_terms">
      <input id="sale_terms" name="terms_accepted" type="checkbox" required
             <?= !empty($sv['terms_accepted']) ? 'checked' : '' ?>>
      <span>The customer has been given these terms and accepts them.
        <span class="field__req" aria-hidden="true">*</span></span>
    </label>
  </section>

  <?php /* the classes here belong to admin.css, which is the only stylesheet
           the two partner portals load — portal-* classes live in the website's
           and rendered as bare text on this page */ ?>
  <p class="direct-sale__notice">
    <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
    <span>
      <strong>This records a sale you have already been paid for.</strong>
      The customer owes nothing further, so their portal shows the order complete and your commission is
      earned straight away. The office sees it marked as a direct sale. Use your share link instead if the
      customer is going to pay Manifold themselves.
    </span>
  </p>

  <div class="direct-sale__foot">
    <button type="submit" class="btn btn--primary">Record the sale</button>
  </div>
</form>
