<?php
/**
 * The hidden markup one Details button opens.
 * Expects $srcType and $srcRow.
 */

declare(strict_types=1);

$srcId     = (int) $srcRow['id'];
$srcGroups = field_groups($srcType);
?>
<div class="drawer-source" id="detail-<?= e($srcType) ?>-<?= $srcId ?>" hidden>
  <div class="detail-grid">
    <?php foreach ($srcGroups as $groupLabel => $fields): ?>
      <div class="detail-block">
        <p class="detail-block__title"><?= e($groupLabel) ?></p>
        <dl>
          <?php foreach ($fields as $key => $label): ?>
            <?php if (!array_key_exists($key, $srcRow)) { continue; } ?>
            <dt><?= e($label) ?></dt>
            <dd><?= render_value($key, $srcRow[$key]) ?></dd>
          <?php endforeach; ?>
        </dl>
      </div>
    <?php endforeach; ?>
  </div>
</div>
