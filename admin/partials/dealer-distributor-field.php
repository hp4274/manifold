<?php
/**
 * Which distributor a dealer answers to.
 *
 * Every dealer answers to one — there is no such thing as a dealer without a
 * distributor, so this is required rather than offering nobody as an answer.
 *
 * It is picked once, when the dealer is added, and shown but not editable
 * afterwards: the override on every sale they have made is booked to that
 * distributor, and moving the dealer would leave those figures behind a name
 * that no longer holds them. Move the dealer by switching this one off and
 * adding them under the distributor they now answer to.
 *
 * Expects $pf (the dealer being edited, or null), $pIsEdit and
 * $distributorChoices.
 */

declare(strict_types=1);

$fieldCurrent = (int) ($pf['distributor_id'] ?? 0);
$fieldHolder  = $fieldCurrent > 0 ? distributor_by_id($fieldCurrent) : null;
?>
<?php if (!empty($pIsEdit)): ?>
  <div class="field field--wide">
    <label>Distributor</label>
    <div class="code-preview">
      <span class="code-preview__chip<?= $fieldHolder ? '' : ' code-preview__chip--pending' ?>">
        <?= $fieldHolder ? e($fieldHolder['distributor_code']) : 'None' ?>
      </span>
      <span class="code-preview__text">
        <?= $fieldHolder ? e($fieldHolder['full_name']) . '.' : 'No distributor on record.' ?>
        Set when this dealer was added and not changed afterwards — every override already booked
        belongs to them. To move the dealer, switch this one off and add them under the distributor
        they now answer to.
      </span>
    </div>
  </div>
<?php else: ?>
  <div class="field field--wide">
    <label for="dealer_distributor_id">
      Distributor<span class="field__req" aria-hidden="true">*</span>
    </label>
    <select id="dealer_distributor_id" name="distributor_id" required>
      <option value="" selected disabled>Pick a distributor</option>
      <?php foreach ($distributorChoices as $choice): ?>
        <option value="<?= (int) $choice['id'] ?>"
                <?= $fieldCurrent === (int) $choice['id'] ? 'selected' : '' ?>>
          <?= e($choice['full_name']) ?> · <?= e($choice['distributor_code']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <span class="field-hint">
      Whoever this dealer answers to takes the override —
      <?= e(money_short(commission_value('override', 'stove'))) ?> a stove,
      <?= e(money_short(commission_value('override', 'tuktuk'))) ?> a kit — on every sale they make.
      This is picked once and cannot be changed later.
    </span>
  </div>
<?php endif; ?>
