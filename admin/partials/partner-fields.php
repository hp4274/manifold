<?php
/**
 * The sixteen fields a dealer and a distributor both carry.
 *
 * One partial rather than two copies: the two records are the same shape, and
 * the rules on them — name required, PAN/GST/IFSC in capitals at fixed lengths,
 * everything else optional — are enforced in one place by partner_values(). Two
 * copies of this markup is how the two forms start disagreeing about a PAN.
 *
 * Expects $partnerKind ('dealer' or 'distributor'), $partnerEdit (the row being
 * edited, or the values just rejected), $partnerIsEdit and $partnerCode.
 * $partnerExtra optionally names another partial to drop into the first section.
 */

declare(strict_types=1);

$pf      = $partnerEdit ?? null;
$pIsEdit = $partnerIsEdit ?? false;
$pKind   = $partnerKind ?? 'dealer';
$pCode   = $partnerCode ?? '';
$pBlank  = $pKind === 'distributor' ? 'MX••••••' : 'MD••••••';
?>
        <?php /* A partner is usually added mid-phone-call. The name is the only
                 thing the office has for certain, so it is the only thing this
                 form insists on — the rest is whatever the paperwork says. */ ?>
        <section class="form-section">
          <div class="form-section__head">
            <h3 class="form-section__title">Who they are</h3>
            <span class="form-section__note">Name required · the rest can follow later</span>
          </div>

          <div class="field field--primary">
            <label for="<?= e($pKind) ?>_full_name">Full name<span class="field__req" aria-hidden="true">*</span></label>
            <input id="<?= e($pKind) ?>_full_name" name="full_name" type="text" maxlength="160" required
                   autocomplete="off" value="<?= e($pf['full_name'] ?? '') ?>">
          </div>

          <div class="form-grid">
            <div class="field">
              <label for="<?= e($pKind) ?>_company">Company</label>
              <input id="<?= e($pKind) ?>_company" name="company" type="text" maxlength="160"
                     value="<?= e($pf['company'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_email">Email</label>
              <input id="<?= e($pKind) ?>_email" name="email" type="email" maxlength="190"
                     value="<?= e($pf['email'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_mobile">Mobile</label>
              <input id="<?= e($pKind) ?>_mobile" name="mobile_number" type="text" maxlength="30"
                     value="<?= e($pf['mobile_number'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_alt_mobile">Alternative mobile</label>
              <input id="<?= e($pKind) ?>_alt_mobile" name="alt_mobile_number" type="text" maxlength="30"
                     value="<?= e($pf['alt_mobile_number'] ?? '') ?>">
            </div>
          </div>

          <div class="code-preview">
            <span class="code-preview__chip<?= $pIsEdit ? '' : ' code-preview__chip--pending' ?>">
              <?= $pIsEdit ? e($pf['dealer_code']) : $pBlank ?>
            </span>
            <span class="code-preview__text">
              <?= $pIsEdit
                  ? 'Their code. Issued when they were added and never changed since — every sale they have made quotes it.'
                  : 'A code is issued when you save, and never changes afterwards. It goes in the link they share.' ?>
            </span>
          </div>

          <?php /* a dealer also answers to a distributor; a distributor does not */ ?>
          <?php if (!empty($partnerExtra)) { require __DIR__ . '/' . $partnerExtra; } ?>
        </section>

        <section class="form-section">
          <div class="form-section__head">
            <h3 class="form-section__title">Where they are</h3>
          </div>

          <div class="form-grid">
            <div class="field field--wide">
              <label for="<?= e($pKind) ?>_address">Address</label>
              <input id="<?= e($pKind) ?>_address" name="address" type="text" maxlength="255"
                     value="<?= e($pf['address'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_city">City</label>
              <input id="<?= e($pKind) ?>_city" name="city" type="text" maxlength="120"
                     value="<?= e($pf['city'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_state">State</label>
              <input id="<?= e($pKind) ?>_state" name="state" type="text" maxlength="120"
                     value="<?= e($pf['state'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_pin">Pin code</label>
              <input id="<?= e($pKind) ?>_pin" name="pin_code" type="text" maxlength="20"
                     value="<?= e($pf['pin_code'] ?? '') ?>">
            </div>
          </div>
        </section>

        <section class="form-section">
          <div class="form-section__head">
            <h3 class="form-section__title">Where the commission goes</h3>
            <span class="form-section__note">Needed before the first payout</span>
          </div>

          <div class="form-grid">
            <div class="field">
              <label for="<?= e($pKind) ?>_pan">PAN</label>
              <input class="field-shout" id="<?= e($pKind) ?>_pan" name="pan_number" type="text" maxlength="10"
                     pattern="[A-Za-z0-9]{10}" title="10 letters and digits"
                     placeholder="ABCDE1234F" value="<?= e($pf['pan_number'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_gst">GST</label>
              <input class="field-shout" id="<?= e($pKind) ?>_gst" name="gst_number" type="text" maxlength="15"
                     pattern="[A-Za-z0-9]{15}" title="15 letters and digits"
                     placeholder="24ABCDE1234F1Z5" value="<?= e($pf['gst_number'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_bank">Bank</label>
              <input id="<?= e($pKind) ?>_bank" name="bank_name" type="text" maxlength="120"
                     value="<?= e($pf['bank_name'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_account">Account number</label>
              <input id="<?= e($pKind) ?>_account" name="bank_account" type="text" maxlength="60"
                     value="<?= e($pf['bank_account'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_ifsc">IFSC</label>
              <input class="field-shout" id="<?= e($pKind) ?>_ifsc" name="bank_ifsc" type="text" maxlength="11"
                     pattern="[A-Za-z0-9]{11}" title="11 letters and digits"
                     placeholder="HDFC0001234" value="<?= e($pf['bank_ifsc'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_upi">UPI ID</label>
              <input id="<?= e($pKind) ?>_upi" name="upi_id" type="text" maxlength="120"
                     value="<?= e($pf['upi_id'] ?? '') ?>">
            </div>
          </div>
        </section>

        <section class="form-section">
          <div class="form-section__head">
            <h3 class="form-section__title">Note</h3>
            <span class="form-section__note">Only the office sees this</span>
          </div>

          <div class="field">
            <label class="visually-hidden" for="<?= e($pKind) ?>_note">Note</label>
            <textarea id="<?= e($pKind) ?>_note" name="note" rows="3"
                      placeholder="Which area they cover, who introduced them, anything worth knowing on the next call."><?= e($pf['note'] ?? '') ?></textarea>
          </div>
        </section>
