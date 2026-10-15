<?php
/**
 * The pass-down ledger for one holder: money received from the tier above,
 * what is due to each partner directly below, and what has been passed on.
 *
 * One partial for the office, both C&F desks, distributors and dealers, so the
 * four screens cannot drift apart on what a hop looks like. The figures are
 * read here, from commission_passdown_due() and commission_transfers_for(); the
 * form only names the recipient, and commission_transfer() works the amount
 * out again under lock — the hidden "expect" is a check, never the amount.
 *
 * Expects:
 *   $pdType    'office' | 'principal_cf' | 'zonal_cf' | 'distributor' | 'dealer'
 *   $pdId      the holder's id (null for the office)
 *   $pdAction  where the form posts (the page itself, with ?as= on the C&F desk)
 *   $pdReceipt fn (array $transfer): string — the receipt link for one transfer
 */

$pdCanSend  = $pdType !== 'dealer';
$pdDue      = $pdCanSend ? commission_passdown_due($pdType, $pdId) : [];
$pdReceived = $pdType === 'office' ? [] : commission_transfers_for('to', $pdType, $pdId);
$pdSent     = $pdCanSend ? commission_transfers_for('from', $pdType, $pdId) : [];
?>
<?php if ($pdCanSend): ?>
<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>To pass on</h2>
      <span class="eyebrow">released commission you are holding, per partner directly below you</span>
    </div>
  </div>

  <?php if (!$pdDue): ?>
    <p class="empty">Nothing to pass on. Money for the partners below you appears here once it has reached you.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--stack" data-paged="10" data-export="commission-to-pass-on">
        <colgroup><col style="width:17%"><col style="width:13%"><col style="width:20%"><col style="width:50%"></colgroup>
        <thead>
          <tr><th>Send to</th><th>Amount</th><th>Covers</th><th data-skip>Transfer</th></tr>
        </thead>
        <tbody>
          <?php foreach ($pdDue as $pdKey => $pdRow): ?>
            <?php $pdFor = 'pd-' . preg_replace('/[^a-z0-9]/', '-', (string) $pdKey); ?>
            <tr>
              <td data-lead>
                <strong><?= e(commission_party_name($pdRow['to_type'], $pdRow['to_id'])) ?></strong>
                <span class="cell-sub"><?= e(COMMISSION_TIER_LABELS[$pdRow['to_type']] ?? $pdRow['to_type']) ?></span>
              </td>
              <td class="td-amount" data-label="Amount"><strong><?= e(money($pdRow['amount'])) ?></strong></td>
              <td data-label="Covers">
                <?= count($pdRow['lines']) ?> line<?= count($pdRow['lines']) === 1 ? '' : 's' ?>
                <span class="cell-sub"><?= e(implode(', ', array_slice(array_unique(array_column($pdRow['lines'], 'reference_code')), 0, 6))) ?><?= count(array_unique(array_column($pdRow['lines'], 'reference_code'))) > 6 ? '…' : '' ?></span>
              </td>
              <td data-label="Transfer">
                <form class="pd-form" method="post" action="<?= e($pdAction) ?>" enctype="multipart/form-data">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="passdown">
                  <input type="hidden" name="to_type" value="<?= e($pdRow['to_type']) ?>">
                  <input type="hidden" name="to_id" value="<?= (int) $pdRow['to_id'] ?>">
                  <input type="hidden" name="expect" value="<?= e(number_format($pdRow['amount'], 2, '.', '')) ?>">
                  <div class="field"><label for="<?= e($pdFor) ?>-utr">UTR / reference</label>
                    <input id="<?= e($pdFor) ?>-utr" type="text" name="utr" maxlength="120" required></div>
                  <div class="field"><label for="<?= e($pdFor) ?>-file">Transfer receipt</label>
                    <input id="<?= e($pdFor) ?>-file" type="file" name="proof_file" required
                           accept="image/jpeg,image/png,image/webp,application/pdf"></div>
                  <button type="submit" class="btn btn--primary btn--sm"
                          data-confirm="Record <?= e(money($pdRow['amount'])) ?> sent to <?= e(commission_party_name($pdRow['to_type'], $pdRow['to_id'])) ?>?">
                    <i class="bi bi-send" aria-hidden="true"></i> Record transfer</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php foreach ($pdType === 'office' ? ['sent'] : ($pdCanSend ? ['received', 'sent'] : ['received']) as $pdSide): ?>
  <?php $pdRows = $pdSide === 'received' ? $pdReceived : $pdSent; ?>
  <div class="panel">
    <div class="panel__head">
      <div class="panel__head-text">
        <h2><?= $pdSide === 'received' ? 'Received' : 'Passed on' ?></h2>
        <span class="eyebrow"><?= $pdSide === 'received' ? 'commission sent to you from above, newest first' : 'transfers you have recorded, newest first' ?></span>
      </div>
    </div>

    <?php if (!$pdRows): ?>
      <p class="empty"><?= $pdSide === 'received' ? 'Nothing has been sent to you yet.' : 'No transfers recorded yet.' ?></p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table data-table--stack" data-paged="10" data-export="commission-<?= e($pdSide) ?>">
          <thead>
            <tr><th>Date</th><th><?= $pdSide === 'received' ? 'From' : 'To' ?></th><th>Amount</th><th>UTR</th><th data-skip>Receipt</th></tr>
          </thead>
          <tbody>
            <?php foreach ($pdRows as $pdT): ?>
              <tr>
                <td data-label="Date"><?= e(format_datetime($pdT['created_at'])) ?></td>
                <td data-lead>
                  <strong><?= e($pdSide === 'received' ? $pdT['from_name'] : $pdT['to_name']) ?></strong>
                  <span class="cell-sub"><?= e(COMMISSION_TIER_LABELS[$pdSide === 'received' ? $pdT['from_type'] : $pdT['to_type']] ?? '') ?></span>
                </td>
                <td class="td-amount" data-label="Amount"><strong><?= e(money((float) $pdT['amount'])) ?></strong></td>
                <td data-label="UTR"><?= e((string) $pdT['utr']) ?></td>
                <td data-label="Receipt">
                  <a href="<?= e($pdReceipt($pdT)) ?>" target="_blank" rel="noopener">
                    <i class="bi bi-download" aria-hidden="true"></i> Receipt</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
