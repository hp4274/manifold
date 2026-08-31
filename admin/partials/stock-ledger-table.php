<?php
/**
 * Every movement behind one partner's stock balance, newest first.
 *
 * The balance is the sum of these rows, so this is not a summary of it — it is
 * where it comes from. Expects $ledgerRows.
 */

declare(strict_types=1);
?>
<div class="table-wrap">
  <table class="data-table" data-paged="10">
    <colgroup>
      <col style="width:22%">
      <col style="width:30%">
      <col style="width:24%">
      <col style="width:10%">
      <col style="width:14%">
    </colgroup>
    <thead>
      <tr>
        <th>When</th>
        <th>What happened</th>
        <th>Product</th>
        <th>Units</th>
        <th>Value</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$ledgerRows): ?>
        <tr class="row-empty">
          <td colspan="5">No entry found — nothing has moved yet.</td>
        </tr>
      <?php endif; ?>

      <?php foreach ($ledgerRows as $ledgerRow): ?>
        <?php $ledgerIn = (int) $ledgerRow['units'] >= 0; ?>
        <tr>
          <td><?= e(format_datetime($ledgerRow['created_at'])) ?></td>
          <td>
            <div class="cell-stack">
              <span><?= e(stock_reason_label((string) $ledgerRow['reason'])) ?></span>
              <?php if (!empty($ledgerRow['note'])): ?>
                <span class="cell-sub"><?= e($ledgerRow['note']) ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td><?= e(product_label((string) $ledgerRow['product'])) ?></td>
          <?php /* the sign carries the meaning, not the colour: a movement has
                   to read as in or out in greyscale too */ ?>
          <td class="td-amount">
            <strong class="stock-move stock-move--<?= $ledgerIn ? 'in' : 'out' ?>">
              <?= $ledgerIn ? '+' : '−' ?><?= abs((int) $ledgerRow['units']) ?>
            </strong>
          </td>
          <td class="td-amount">
            <span class="stock-move stock-move--<?= $ledgerIn ? 'in' : 'out' ?>">
              <?= $ledgerIn ? '+' : '−' ?><?= e(money(abs((float) $ledgerRow['value']))) ?>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
