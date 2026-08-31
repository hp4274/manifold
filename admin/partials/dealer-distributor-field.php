<?php
/**
 * Which distributor a dealer answers to.
 *
 * Every dealer answers to one — there is no such thing as a dealer without a
 * distributor, so this is required rather than offering nobody as an answer.
 * Changing it only affects sales from now on: the override on a sale already
 * made was frozen onto that application the day it came in.
 *
 * Expects $pf (the dealer being edited, or null) and $distributorChoices.
 */

declare(strict_types=1);

$fieldCurrent = (int) ($pf['distributor_id'] ?? 0);
?>
<div class="field field--wide">
  <label for="dealer_distributor_id">
    Distributor<span class="field__req" aria-hidden="true">*</span>
  </label>
  <select id="dealer_distributor_id" name="distributor_id" required>
    <option value="" <?= $fieldCurrent === 0 ? 'selected' : '' ?> disabled>Pick a distributor</option>
    <?php foreach ($distributorChoices as $choice): ?>
      <option value="<?= (int) $choice['id'] ?>"
              <?= $fieldCurrent === (int) $choice['id'] ? 'selected' : '' ?>>
        <?= e($choice['full_name']) ?> · <?= e($choice['distributor_code']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <span class="field-hint">
    Whoever this dealer answers to takes the
    <?= e(rtrim(rtrim(number_format(distributor_override_rate() * 100, 2, '.', ''), '0'), '.')) ?>% override on
    every sale they make from now on. Sales already made keep the split they were written with.
  </span>
</div>
