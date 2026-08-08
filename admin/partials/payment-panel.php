<?php
/**
 * Payment block at the top of an application's Details drawer.
 * One row per transfer, decided one at a time — an applicant paying the fee in
 * instalments gets a receipt for each. Expects $srcType, $srcRow, $srcReturn.
 */

declare(strict_types=1);

$payId       = (int) $srcRow['id'];
$payStatus   = (string) $srcRow['status'];
$payList     = payments_for($payId);
$payTotals   = payment_totals($srcRow, $payList);
$payWaiting  = array_filter($payList, static fn (array $p): bool => $p['status'] === 'pending');
?>
<div class="pay-panel pay-panel--<?= e($payStatus) ?>">
  <div class="pay-panel__head">
    <div>
      <p class="detail-block__title">Payment</p>
      <p class="pay-panel__amount">
        <?= e(money((float) $payTotals['paid'])) ?>
        <span class="pay-panel__of">of <?= e(money((float) $payTotals['due'])) ?></span>
        <span class="pill pill--<?= e($payStatus) ?>"><?= e(status_short($payStatus)) ?></span>
      </p>
    </div>

    <?php if ($payTotals['balance'] > 0): ?>
      <p class="pay-panel__balance"><?= e(money((float) $payTotals['balance'])) ?> outstanding</p>
    <?php endif; ?>
  </div>

  <div class="pay-bar" role="img"
       aria-label="<?= (int) $payTotals['percent'] ?>% of the fee has been verified">
    <span style="width:<?= (int) $payTotals['percent'] ?>%"></span>
  </div>

  <?php if (!$payList): ?>
    <p class="pay-panel__note">No transfers yet — the applicant has not uploaded a receipt.</p>
  <?php else: ?>
    <ul class="pay-list">
      <?php foreach ($payList as $i => $payment): ?>
        <li class="pay-item pay-item--<?= e($payment['status']) ?>">
          <div class="pay-item__main">
            <strong><?= e(money((float) $payment['amount'])) ?></strong>
            <span class="pay-item__meta">
              <?= e(format_datetime($payment['uploaded_at'])) ?>
              <?php if (!empty($payment['reference'])): ?> · ref <?= e($payment['reference']) ?><?php endif; ?>
              <?php if (!empty($payment['receipt_no'])): ?> · receipt <?= e($payment['receipt_no']) ?><?php endif; ?>
              <?php if (!empty($payment['reject_reason'])): ?> · <?= e($payment['reject_reason']) ?><?php endif; ?>
            </span>
          </div>

          <div class="pay-item__side">
            <?php if ($payment['status'] === 'verified'): ?>
              <a class="link-arrow" target="_blank" rel="noopener"
                 href="receipt.php?payment=<?= (int) $payment['id'] ?>">
                <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Receipt PDF
              </a>
            <?php endif; ?>

            <?php if (!empty($payment['proof_path'])): ?>
              <a class="pay-item__proof" target="_blank" rel="noopener"
                 href="file.php?path=<?= e(rawurlencode((string) $payment['proof_path'])) ?>&amp;dir=payments">
                <i class="bi bi-paperclip" aria-hidden="true"></i> Proof of payment
              </a>
            <?php else: ?>
              <span class="pay-item__proof pay-item__proof--gone">
                <i class="bi bi-paperclip" aria-hidden="true"></i> proof removed
              </span>
            <?php endif; ?>

            <span class="pay-item__state"><?= e($payment['status']) ?></span>
          </div>

          <?php if ($payment['status'] === 'pending'): ?>
            <div class="pay-item__actions">
              <form method="post" action="payment.php"
                    data-confirm="Verify this <?= e(money((float) $payment['amount'])) ?> payment? The applicant is emailed a receipt.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="accept">
                <input type="hidden" name="type" value="<?= e($srcType) ?>">
                <input type="hidden" name="id" value="<?= $payId ?>">
                <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                <input type="hidden" name="return" value="<?= e($srcReturn) ?>">
                <button type="submit" class="btn btn--primary">
                  <i class="bi bi-check-lg"></i> Accept &amp; send receipt
                </button>
              </form>

              <form method="post" action="payment.php" class="pay-panel__reject"
                    data-confirm="Reject this payment? The applicant is emailed the reason.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="type" value="<?= e($srcType) ?>">
                <input type="hidden" name="id" value="<?= $payId ?>">
                <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                <input type="hidden" name="return" value="<?= e($srcReturn) ?>">
                <label class="visually-hidden" for="reason-<?= (int) $payment['id'] ?>">Reason</label>
                <input id="reason-<?= (int) $payment['id'] ?>" name="reason" type="text" maxlength="255"
                       placeholder="Why? (goes in the email)">
                <button type="submit" class="btn btn--danger"><i class="bi bi-x-lg"></i> Reject</button>
              </form>
            </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <div class="pay-panel__actions">
    <?php if ($payTotals['settled']): ?>
      <p class="pay-panel__note pay-panel__note--ok">
        <i class="bi bi-patch-check"></i>
        Paid in full — <?= count($payList) ?> transfer<?= count($payList) === 1 ? '' : 's' ?> verified,
        receipts emailed. Nothing further to do.
      </p>
    <?php else: ?>
      <?php if (!$payWaiting): ?>
        <p class="pay-panel__note">
          <?= e(money((float) $payTotals['balance'])) ?> still owed and nothing waiting to be checked.
        </p>
      <?php endif; ?>

      <?php /* the reminder button itself lives on the table row */ ?>
      <?php if ((int) ($srcRow['reminder_count'] ?? 0) > 0): ?>
        <span class="pay-panel__note">
          <i class="bi bi-bell" aria-hidden="true"></i>
          <?= (int) $srcRow['reminder_count'] ?> reminder<?= (int) $srcRow['reminder_count'] === 1 ? '' : 's' ?> sent ·
          last <?= e(format_datetime($srcRow['reminded_at'])) ?>
        </span>
      <?php else: ?>
        <span class="pay-panel__note">Use the bell on the row to send a payment reminder.</span>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
