<?php
/**
 * The stat tiles at the top of every partner's stock page: each product in
 * hand, the value on the shelf, and — for a tier that sells on — how many
 * orders from below are waiting on them.
 *
 * One partial for the dealer, distributor and both C&F desks, because the four
 * copies had already drifted: each tile laid its sub-text and its status pill
 * side by side, so the sentence wrapped, the pill crowded it, and the one tile
 * without a pill sat at a different height. Here every tile has the same three
 * rows — label (with the pill at its right), figure, one line of sub-text —
 * and .tiles--stock lines those rows up across the whole row of cards.
 *
 * Expects $stock (from stock_balance()); optionally $stockQueue, a count of
 * orders waiting on this partner, with $stockQueueLabel naming who sent them.
 */

declare(strict_types=1);

$stTiles = [
    ['Stoves in hand', (string) (int) $stock['stove']['units'], (int) $stock['stove']['units'],
     money((float) $stock['stove']['value']) . ' at cost'],
    ['TukTuk kits in hand', (string) (int) $stock['tuktuk']['units'], (int) $stock['tuktuk']['units'],
     money((float) $stock['tuktuk']['value']) . ' at cost'],
    ['Value on the shelf', money_short((float) $stock['value']), (int) $stock['units'],
     'what these ' . (int) $stock['units'] . ' units cost you'],
];
?>
<div class="tiles tiles--stock">
  <?php foreach ($stTiles as [$stLabel, $stFigure, $stUnits, $stNote]): ?>
    <span class="tile <?= e(stock_tile_class($stUnits)) ?>">
      <span class="tile__head">
        <span class="eyebrow"><?= e($stLabel) ?></span>
        <?= stock_badge($stUnits, true) ?>
      </span>
      <strong class="stock-figure"><?= e($stFigure) ?></strong>
      <span class="tile__note" title="<?= e($stNote) ?>"><?= e($stNote) ?></span>
    </span>
  <?php endforeach; ?>

  <?php if (isset($stockQueue)): ?>
    <span class="tile">
      <span class="tile__head">
        <span class="eyebrow"><?= e($stockQueueLabel ?? 'Waiting on you') ?></span>
        <?php if ($stockQueue > 0): ?>
          <span class="pill pill--pending">To release</span>
        <?php else: ?>
          <span class="pill pill--good">Clear</span>
        <?php endif; ?>
      </span>
      <strong class="stock-figure"><?= (int) $stockQueue ?></strong>
      <span class="tile__note">orders paid for and not yet released</span>
    </span>
  <?php endif; ?>
</div>
