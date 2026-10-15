<?php
/**
 * Correct the stock of the tier below (the office: any tier). Expects
 * $adjustTargets from stock_adjust_targets(); posts action=adjust_stock, handled
 * by stock_adjust_post(), which re-checks the target against the hierarchy.
 */

declare(strict_types=1);
?>
<?php if (!$adjustTargets): ?>
  <p class="field-hint">Nobody sits under you yet, so there is no shelf to correct.</p>
<?php else: ?>
  <form method="post" class="pd-form" data-confirm="Change this shelf? It is recorded in the movement list.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="adjust_stock">

    <div class="field">
      <label for="adj_target">Whose shelf</label>
      <select id="adj_target" name="target" required>
        <?php foreach ($adjustTargets as $t): ?>
          <option value="<?= e($t['type'] . ':' . $t['id']) ?>">
            <?= e($t['full_name']) ?> · <?= e(ucfirst(stock_tier_label((string) $t['type']))) ?><?= $t['code'] ? ' · ' . e((string) $t['code']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="adj_product">Product</label>
      <select id="adj_product" name="product" required>
        <option value="stove">Stove</option>
        <option value="tuktuk">TukTuk kit</option>
      </select>
    </div>

    <div class="field">
      <label for="adj_units">Change by (units)</label>
      <input id="adj_units" name="units" type="number" step="1" required placeholder="+5 or -2">
    </div>

    <div class="field">
      <label for="adj_note">Reason</label>
      <input id="adj_note" name="note" type="text" maxlength="200" placeholder="Recount, breakage…">
    </div>

    <button type="submit" class="btn btn--primary">Correct stock</button>
  </form>
<?php endif; ?>
