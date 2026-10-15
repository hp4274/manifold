<?php
/**
 * The sixteen fields a dealer and a distributor both carry.
 *
 * One partial rather than two copies: the two records are the same shape, and
 * the rules on them — the identity, address and account details in
 * PARTNER_REQUIRED, PAN/GST/IFSC in capitals at fixed lengths — are enforced in
 * one place by partner_values(). Two copies of this markup is how the two forms
 * start disagreeing about a PAN.
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

/* The same fields serve three readers: the office looking at somebody, a
   distributor looking at their dealer, and a partner looking at themselves.
   Only the wording changes. */
$pSelf   = $partnerSelf ?? false;
$pWhose  = $pSelf ? 'you are' : 'they are';
$pBlank  = match ($pKind) {
    'principal_cf' => 'MP••••••',
    'zonal_cf'     => 'MZ••••••',
    'distributor'  => 'MX••••••',
    default        => 'MD••••••',
};

/* The number is national digits here and stored with the dial code in front,
   the same way the apply form works. dial_digits() decides the length, so the
   box and partner_values() cannot disagree about it. */
[$pTelMin, $pTelMax] = dial_digits(DEFAULT_DIAL_CODE);
$pTelPattern = '[0-9]{' . ($pTelMin === $pTelMax ? $pTelMin : $pTelMin . ',' . $pTelMax) . '}';
$pTelTitle   = ($pTelMin === $pTelMax ? $pTelMin . ' digits' : $pTelMin . ' to ' . $pTelMax . ' digits')
    . ', no spaces or country code';
?>
        <?php /* A partner is paid by bank transfer, so the identity, the address
                 and the account are all wanted before the first sale rather than
                 after it. The optional few are the ones that may genuinely not
                 exist: a second mobile, a GST number, the bank's name, a note.
                 PARTNER_REQUIRED in lib.php is the same list, enforced. */ ?>
        <section class="form-section">
          <div class="form-section__head">
            <h3 class="form-section__title">Who <?= e($pWhose) ?></h3>
            <span class="form-section__note">Everything marked * is needed</span>
          </div>

          <div class="field field--primary">
            <label for="<?= e($pKind) ?>_full_name">Full name<span class="field__req" aria-hidden="true">*</span></label>
            <input id="<?= e($pKind) ?>_full_name" name="full_name" type="text" maxlength="160" required
                   autocomplete="off" value="<?= e($pf['full_name'] ?? '') ?>">
          </div>

          <div class="form-grid">
            <div class="field">
              <label for="<?= e($pKind) ?>_company">Company<span class="field__req" aria-hidden="true">*</span></label>
              <input id="<?= e($pKind) ?>_company" name="company" type="text" maxlength="160" required
                     value="<?= e($pf['company'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_email">Email<span class="field__req" aria-hidden="true">*</span></label>
              <input id="<?= e($pKind) ?>_email" name="email" type="email" maxlength="190" required
                     value="<?= e($pf['email'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_mobile">Mobile<span class="field__req" aria-hidden="true">*</span></label>
              <input id="<?= e($pKind) ?>_mobile" name="mobile_number" type="tel" inputmode="numeric"
                     autocomplete="off" maxlength="<?= $pTelMax ?>" pattern="<?= e($pTelPattern) ?>"
                     title="<?= e($pTelTitle) ?>" required
                     value="<?= e(partner_mobile_digits($pf['mobile_number'] ?? '')) ?>">
              <span class="field-hint">+<?= e(DEFAULT_DIAL_CODE) ?> is put in front when it is saved.</span>
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_alt_mobile">Alternative mobile</label>
              <input id="<?= e($pKind) ?>_alt_mobile" name="alt_mobile_number" type="tel" inputmode="numeric"
                     autocomplete="off" maxlength="<?= $pTelMax ?>" pattern="<?= e($pTelPattern) ?>"
                     title="<?= e($pTelTitle) ?>"
                     value="<?= e(partner_mobile_digits($pf['alt_mobile_number'] ?? '')) ?>">
            </div>
          </div>

          <?php
            /* whichever kind this is, and blank while a dealer is still waiting
               on the office — a code is issued with the approval, not before */
            $pHeld = (string) ($pf['principal_code'] ?? $pf['zonal_code'] ?? $pf['dealer_code'] ?? $pf['distributor_code'] ?? $pCode ?? '');
          ?>
          <div class="code-preview">
            <span class="code-preview__chip<?= $pHeld === '' ? ' code-preview__chip--pending' : '' ?>">
              <?= $pHeld === '' ? $pBlank : e($pHeld) ?>
            </span>
            <span class="code-preview__text">
              <?php if ($pHeld !== ''): ?>
                <?= $pSelf ? 'Your code' : 'Their code' ?>. Issued once and never changed since —
                every sale made under it quotes it.
              <?php elseif ($pIsEdit): ?>
                No code yet. The office issues one when it approves them, and it never changes
                afterwards.
              <?php else: ?>
                A code is issued when you save, and never changes afterwards. It goes in the link
                they share.
              <?php endif; ?>
            </span>
          </div>

          <?php if ($pKind === 'zonal_cf'): ?>
            <?php
              $pCurrentPrincipalId = (int) ($pf['principal_cf_id'] ?? 0);
              $pCurrentPrincipal   = $pCurrentPrincipalId > 0 ? principal_cf_by_id($pCurrentPrincipalId) : null;
              $pPrincipalChoices   = principal_cf_all(true);
            ?>
            <div class="field field--wide">
              <label for="zonal_principal_cf_id">
                Principal C&amp;F<span class="field__req" aria-hidden="true">*</span>
              </label>
              <?php if (!empty($pIsEdit) && $pCurrentPrincipal): ?>
                <div class="code-preview">
                  <span class="code-preview__chip"><?= e($pCurrentPrincipal['principal_code'] ?: 'MP••••••') ?></span>
                  <span class="code-preview__text">
                    <?= e($pCurrentPrincipal['full_name']) ?>. Assigned to this Principal C&amp;F.
                  </span>
                </div>
                <input type="hidden" name="principal_cf_id" value="<?= $pCurrentPrincipalId ?>">
              <?php else: ?>
                <select id="zonal_principal_cf_id" name="principal_cf_id" required>
                  <option value="" disabled <?= $pCurrentPrincipalId === 0 ? 'selected' : '' ?>>Pick a Principal C&amp;F</option>
                  <?php foreach ($pPrincipalChoices as $choice): ?>
                    <option value="<?= (int) $choice['id'] ?>" <?= $pCurrentPrincipalId === (int) $choice['id'] ? 'selected' : '' ?>>
                      <?= e($choice['full_name']) ?> · <?= e($choice['principal_code'] ?: 'Pending') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="field-hint">Every Zonal C&amp;F reports to a Principal C&amp;F in the 5-tier chain.</span>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($pKind === 'distributor'): ?>
            <?php
              $pCurrentZonalId = (int) ($pf['zonal_cf_id'] ?? 0);
              $pCurrentZonal   = $pCurrentZonalId > 0 ? zonal_cf_by_id($pCurrentZonalId) : null;
              $pZonalChoices   = zonal_cf_all(true);
            ?>
            <div class="field field--wide">
              <label for="distributor_zonal_cf_id">Zonal C&amp;F</label>
              <select id="distributor_zonal_cf_id" name="zonal_cf_id">
                <option value="" <?= $pCurrentZonalId === 0 ? 'selected' : '' ?>>None (Direct Office Assignment)</option>
                <?php foreach ($pZonalChoices as $choice): ?>
                  <option value="<?= (int) $choice['id'] ?>" <?= $pCurrentZonalId === (int) $choice['id'] ? 'selected' : '' ?>>
                    <?= e($choice['full_name']) ?> · <?= e($choice['zonal_code'] ?: 'Pending') ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <span class="field-hint">The Zonal C&amp;F that manages this distributor.</span>
            </div>
          <?php endif; ?>

          <?php /* How many direct children this partner may hold. Only shown where
                   the page has decided its reader has authority over this row
                   ($partnerCapEdit) — the page that saves it checks again. */ ?>
          <?php if (!empty($partnerCapEdit) && isset(CHILD_CAP_RULES[$pKind])): ?>
            <?php
              $pCapNoun    = CHILD_CAP_RULES[$pKind][4];
              $pCapDefault = child_cap_default($pKind);
              $pCapId      = (int) ($pf['id'] ?? 0);
            ?>
            <div class="field">
              <label for="<?= e($pKind) ?>_max_children">Max <?= e($pCapNoun) ?> under this person</label>
              <input id="<?= e($pKind) ?>_max_children" name="max_children" type="number" step="1" min="0"
                     max="100000" inputmode="numeric"
                     value="<?= isset($pf['max_children']) && $pf['max_children'] !== null ? (int) $pf['max_children'] : '' ?>"
                     placeholder="<?= $pCapDefault === 0 ? 'No limit' : (int) $pCapDefault ?>">
              <span class="field-hint">
                Blank follows the default (<?= $pCapDefault === 0 ? 'no limit' : (int) $pCapDefault ?>); 0 means no limit.
                <?php if ($pIsEdit && $pCapId > 0): ?>Using <?= e(child_cap_usage($pKind, $pCapId)) ?>.<?php endif; ?>
              </span>
            </div>
          <?php endif; ?>

          <?php /* a dealer also answers to a distributor; a distributor does not */ ?>
          <?php if (!empty($partnerExtra)) { require __DIR__ . '/' . $partnerExtra; } ?>
        </section>

        <section class="form-section">
          <div class="form-section__head">
            <h3 class="form-section__title">Where <?= e($pWhose) ?></h3>
          </div>

          <div class="form-grid">
            <div class="field field--wide">
              <label for="<?= e($pKind) ?>_address">Address<span class="field__req" aria-hidden="true">*</span></label>
              <input id="<?= e($pKind) ?>_address" name="address" type="text" maxlength="255" required
                     value="<?= e($pf['address'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_pin">Pin code<span class="field__req" aria-hidden="true">*</span></label>
              <input id="<?= e($pKind) ?>_pin" name="pin_code" type="text" maxlength="20" required
                     value="<?= e($pf['pin_code'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_city">City<span class="field__req" aria-hidden="true">*</span></label>
              <input id="<?= e($pKind) ?>_city" name="city" type="text" maxlength="120" required
                     value="<?= e($pf['city'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_state">State<span class="field__req" aria-hidden="true">*</span></label>
              <input id="<?= e($pKind) ?>_state" name="state" type="text" maxlength="120" required
                     value="<?= e($pf['state'] ?? '') ?>">
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
              <label for="<?= e($pKind) ?>_pan">PAN<span class="field__req" aria-hidden="true">*</span></label>
              <input class="field-shout" id="<?= e($pKind) ?>_pan" name="pan_number" type="text" maxlength="10" required
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
              <label for="<?= e($pKind) ?>_account">Account number<span class="field__req" aria-hidden="true">*</span></label>
              <input id="<?= e($pKind) ?>_account" name="bank_account" type="text" maxlength="60" required
                     value="<?= e($pf['bank_account'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_ifsc">IFSC<span class="field__req" aria-hidden="true">*</span></label>
              <input class="field-shout" id="<?= e($pKind) ?>_ifsc" name="bank_ifsc" type="text" maxlength="11" required
                     pattern="[A-Za-z0-9]{11}" title="11 letters and digits"
                     placeholder="HDFC0001234" value="<?= e($pf['bank_ifsc'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="<?= e($pKind) ?>_upi">UPI ID<span class="field__req" aria-hidden="true">*</span></label>
              <input id="<?= e($pKind) ?>_upi" name="upi_id" type="text" maxlength="120" required
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

        <section class="form-section">
          <div class="form-section__head">
            <h3 class="form-section__title">Terms &amp; Conditions Agreement<?php if (!$pIsEdit): ?><span class="field__req" aria-hidden="true">*</span><?php endif; ?></h3>
            <span class="form-section__note">Mandatory compliance before partner operations</span>
            <?php /* every page that includes this sits one folder below the site root */ ?>
            <a class="btn btn--ghost btn--sm" href="../terms-pdf?kind=<?= e($pKind) ?>" target="_blank" rel="noopener"><i class="bi bi-download" aria-hidden="true"></i> Download PDF</a>
          </div>

          <?php $pTerms = partner_terms($pKind); ?>

          <?php if (!empty($pf['terms_accepted'])): ?>
            <div class="field field--wide">
              <span class="pill pill--good"><i class="bi bi-check-circle" aria-hidden="true"></i> <?= $pTerms['label'] ?> Terms &amp; Conditions Accepted</span>
              <span class="field-hint">
                Accepted on <?= e(format_datetime($pf['terms_accepted_at'] ?? '')) ?>,
                binding <?= e($pTerms['summary']) ?>.
              </span>
            </div>
          <?php else: ?>
            <div class="terms-scroll" tabindex="0" role="region" aria-label="<?= e(html_entity_decode($pTerms['label'])) ?> terms and conditions">
              <p><strong>Manifold Clean Energy &mdash; <?= $pTerms['label'] ?> Terms &amp; Conditions</strong></p>
              <p><?= $pTerms['intro'] ?></p>
              <ul>
                <?php foreach ($pTerms['items'] as [$pHead, $pBody]): ?>
                  <li><strong><?= $pHead ?>:</strong> <?= $pBody ?></li>
                <?php endforeach; ?>
              </ul>
            </div>

            <div class="field-consent field-consent--control">
              <label for="<?= e($pKind) ?>_terms_accepted">
                <input id="<?= e($pKind) ?>_terms_accepted" name="terms_accepted" type="checkbox" value="1" <?= !$pIsEdit ? 'required' : '' ?>>
                <span><strong>I accept the <?= $pTerms['label'] ?> Terms &amp; Conditions</strong> on behalf of <?= $pSelf ? 'myself' : 'this partner' ?>, agreeing to <?= e($pTerms['summary']) ?>.<?php if (!$pIsEdit): ?><span class="field__req" aria-hidden="true">*</span><?php endif; ?></span>
              </label>
            </div>
          <?php endif; ?>
        </section>

