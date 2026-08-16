<?php
/**
 * What the search found.
 *
 * Expects $results (from raffle_search), $search (what was typed) and $nextDraw
 * (the draw a name would be added to).
 *
 * Rendered twice from the same file so the two paths cannot drift: once by
 * raffle.php on a plain page load, and once by raffle-search.php as the office
 * types, which replaces this block in place.
 */

declare(strict_types=1);
?>
<?php if ($search === ''): ?>
  <p class="finder__none finder__none--idle">Start typing to find somebody.</p>
<?php elseif (!$results): ?>
  <p class="finder__none">No applicant with a verified booking payment matches
    <strong><?= e($search) ?></strong>.</p>
<?php else: ?>
  <ul class="finder__results">
    <?php foreach ($results as $person): ?>
      <li>
        <span class="finder__who">
          <strong><?= e($person['full_name']) ?></strong>
          <span class="cell-sub"><?= e($person['mobile_number']) ?> · <?= e($person['email']) ?></span>
          <span class="cell-sub">
            <?= e($person['reference_code']) ?> · <?= e($person['product']) ?>
            <?php if ($person['city']): ?> · <?= e($person['city']) ?><?php endif; ?>
          </span>
          <?php if ($person['won_draws']): ?>
            <span class="cell-sub cell-sub--note">
              already on draw <?= e(implode(', ', array_unique($person['won_draws']))) ?>
            </span>
          <?php endif; ?>
        </span>

        <?php if ($person['in_this_draw']): ?>
          <span class="pill pill--complete">On this list</span>
        <?php else: ?>
          <form method="post" action="raffle.php" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="draw_id" value="<?= (int) $nextDraw['id'] ?>">
            <input type="hidden" name="application_id" value="<?= (int) $person['id'] ?>">
            <input type="hidden" name="q" value="<?= e($search) ?>">
            <button type="submit" name="action" value="add" class="btn btn--primary btn--sm">
              <i class="bi bi-plus-lg" aria-hidden="true"></i>
              Add to draw <?= (int) $nextDraw['draw_no'] ?>
            </button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
