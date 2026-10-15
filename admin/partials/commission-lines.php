<?php
/**
 * A partner's commission, line by line: where each one is in the 48-hour hold,
 * the transfer and the receipt.
 *
 * Expects $payoutLines (commission_payout_lines()) and $receiptUrl (the portal's
 * receipt endpoint, which takes ?line=ID). $showParty adds a partner column.
 */

$showParty = $showParty ?? false;
$tierNames = ['principal_cf' => 'Principal C&F', 'zonal_cf' => 'Zonal C&F', 'distributor' => 'Distributor', 'dealer' => 'Dealer'];
?>
<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Commission status</h2>
      <span class="eyebrow">held 48 hours, then released and passed down the chain to you</span>
    </div>
  </div>

  <?php if (!$payoutLines): ?>
    <p class="empty">No commission yet. A line appears here as soon as a client's payment is verified.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--stack" data-paged="10" data-export="commission-lines">
        <thead>
          <tr>
            <?php if ($showParty): ?><th>Tier</th><?php endif; ?>
            <th>Sale</th><th>Stage</th><th>Commission</th><th>Status</th><th data-skip>Receipt</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payoutLines as $l): ?>
            <tr>
              <?php if ($showParty): ?>
                <td data-label="Tier"><?= e($tierNames[$l['party_type']] ?? (string) $l['party_type']) ?></td>
              <?php endif; ?>
              <td data-label="Sale"><?= e((string) $l['reference_code']) ?></td>
              <td data-label="Stage"><?= e(tranche_label((string) $l['stage'])) ?></td>
              <td class="td-amount" data-label="Commission">
                <strong><?= e(money((float) $l['amount'])) ?></strong>
                <span class="cell-sub"><?= e(rate_short((float) $l['rate'])) ?> of <?= e(money((float) $l['base_amount'])) ?></span>
              </td>
              <td data-label="Status">
                <span class="pill"><?= e(payout_status_label((string) $l['payout_status'])) ?></span>
                <?php if ($l['payout_status'] === 'pending' && !empty($l['release_at'])): ?>
                  <span class="cell-sub">releases <?= e(format_datetime($l['release_at'])) ?></span>
                <?php elseif ($l['payout_status'] === 'disbursed' && !empty($l['disbursed_at'])): ?>
                  <span class="cell-sub"><?= e(format_datetime($l['disbursed_at'])) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Receipt">
                <?php if (!empty($l['proof_path'])): ?>
                  <a href="<?= e($receiptUrl) ?>?line=<?= (int) $l['id'] ?>" target="_blank" rel="noopener">
                    <i class="bi bi-download" aria-hidden="true"></i> Receipt</a>
                  <span class="cell-sub">UTR <?= e((string) $l['payout_utr']) ?></span>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
