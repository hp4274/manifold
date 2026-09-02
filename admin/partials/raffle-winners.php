<?php
/**
 * The winners of one draw, as a table.
 * Expects $draw (a raffle_draws row) and $winners (raffle_winners_for it).
 *
 * Every name here was put on by hand, and can be taken off again — including
 * after the list has gone public, since the office decides what is right.
 */

declare(strict_types=1);

$revealed = raffle_is_revealed($draw);
?>
<div class="table-wrap">
  <table class="data-table data-table--raffle">
    <colgroup>
      <col style="width:9%">
      <col style="width:26%">
      <col style="width:24%">
      <col style="width:17%">
      <col style="width:24%">
    </colgroup>
    <thead>
      <tr>
        <th>Place</th>
        <th>Winner</th>
        <th>Contact</th>
        <th>Where</th>
        <th><?= $revealed ? 'Shown publicly as' : 'Recorded' ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($winners as $winner): ?>
        <tr>
          <td><span class="place">#<?= (int) $winner['position'] ?></span></td>
          <td>
            <div class="cell-stack">
              <strong><?= e($winner['full_name']) ?></strong>
              <span class="cell-sub"><?= e($winner['reference_code']) ?> · <?= e($winner['product']) ?></span>
            </div>
          </td>
          <td>
            <div class="cell-stack">
              <span class="cell-sub"><?= e($winner['mobile_number']) ?></span>
              <span class="cell-sub"><?= e($winner['email']) ?></span>
            </div>
          </td>
          <td>
            <div class="cell-stack">
              <strong><?= e($winner['city'] ?: '—') ?></strong>
              <span class="cell-sub"><?= e($winner['state'] ?: '') ?></span>
            </div>
          </td>
          <td>
            <div class="cell-stack">
              <?php if ($revealed): ?>
                <span class="cell-sub">
                  <?= e(raffle_mask_name((string) $winner['full_name'])) ?> ·
                  <?= e(raffle_mask_mobile((string) $winner['mobile_number'])) ?>
                  <?php if ($winner['city']): ?> · <?= e($winner['city']) ?><?php endif; ?>
                </span>
              <?php else: ?>
                <span class="cell-sub"><?= e(format_datetime($winner['created_at'])) ?></span>
              <?php endif; ?>

              <form method="post" class="inline-form"
                    onsubmit="return confirm('Take <?= e(addslashes($winner['full_name'])) ?> off the <?= e(format_date($draw['reveal_at'])) ?> draw?');">
                <?= csrf_field() ?>
                <input type="hidden" name="winner_id" value="<?= (int) $winner['id'] ?>">
                <button type="submit" name="action" value="remove" class="btn btn--ghost btn--sm">
                  <i class="bi bi-x-lg" aria-hidden="true"></i> Remove
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
