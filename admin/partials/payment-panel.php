<?php
/**
 * Payment block at the top of an application's Details drawer.
 *
 * Every application is two transfers: the booking amount with the application,
 * then the delivery amount once the unit is ready. Each is uploaded by the
 * applicant with its own proof and decided here on its own — a verified one
 * gets a receipt, a rejected one puts that stage back to due.
 *
 * Expects $srcType, $srcRow, $srcReturn.
 */

declare(strict_types=1);

$payId      = (int) $srcRow['id'];
$payStatus  = (string) $srcRow['status'];
$payList    = payments_for($payId);
$payTotals  = payment_totals($srcRow, $payList);
$payWaiting = array_filter($payList, static fn (array $p): bool => $p['status'] === 'pending');
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
       aria-label="<?= (int) $payTotals['percent'] ?>% of the price has been verified">
    <span style="width:<?= (int) $payTotals['percent'] ?>%"></span>
  </div>

  <?php /* Between the two payments: the finance team's check of the paperwork.
           Until it is done the delivery payment stays shut, which is what the
           applicant sees in their portal too. */ ?>
  <?php $docsDone = !empty($srcRow['docs_verified_at']); ?>
  <?php if ($payTotals['stages']['booking']['settled'] || $docsDone): ?>
    <section class="pay-stage pay-stage--<?= $docsDone ? 'paid' : 'due' ?>">
      <header class="pay-stage__head">
        <h4 class="pay-stage__title">Finance documents</h4>
        <span class="pay-stage__state">
          <?php if ($docsDone): ?>
            <i class="bi bi-patch-check" aria-hidden="true"></i>
            verified <?= e(format_datetime((string) $srcRow['docs_verified_at'])) ?>
          <?php else: ?>
            <i class="bi bi-hourglass-split" aria-hidden="true"></i> waiting on finance
          <?php endif; ?>
        </span>
      </header>

      <?php if (!$docsDone): ?>
        <p class="pay-stage__note">
          The delivery payment opens for the applicant the moment this is verified, and they are
          emailed. On a sale a partner recorded as paid in full, this is the only step left.
        </p>

        <form method="post" action="payment"
              data-confirm="Mark the finance documents verified for <?= e(record_title($srcType, $srcRow)) ?>?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="docs">
          <input type="hidden" name="type" value="<?= e($srcType) ?>">
          <input type="hidden" name="id" value="<?= $payId ?>">
          <input type="hidden" name="return" value="<?= e($srcReturn) ?>">
          <button type="submit" class="btn btn--primary btn--sm">
            <i class="bi bi-check-lg" aria-hidden="true"></i> Documents verified
          </button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php foreach (PAYMENT_STAGES as $stageKey): ?>
    <?php $stage = $payTotals['stages'][$stageKey]; ?>
    <section class="pay-stage pay-stage--<?= e($stage['state']) ?>">

      <header class="pay-stage__head">
        <h4 class="pay-stage__title">
          <?= e($stage['label']) ?>
          <span class="pay-stage__amount"><?= e(money((float) $stage['amount'])) ?></span>
        </h4>

        <span class="pay-stage__state">
          <?php if ($stage['state'] === 'paid'): ?>
            <i class="bi bi-patch-check" aria-hidden="true"></i> verified
          <?php elseif ($stage['state'] === 'checking'): ?>
            <i class="bi bi-hourglass-split" aria-hidden="true"></i> receipt waiting
          <?php elseif ($stage['state'] === 'locked'): ?>
            <i class="bi bi-lock" aria-hidden="true"></i> not yet due
          <?php else: ?>
            <i class="bi bi-exclamation-circle" aria-hidden="true"></i> due
          <?php endif; ?>
        </span>
      </header>

      <?php if (!$stage['payments']): ?>
        <p class="pay-panel__note">
          <?php if ($stage['state'] !== 'locked'): ?>
            Nothing uploaded yet for this payment.
          <?php elseif ($payTotals['stages']['booking']['settled']): ?>
            Behind the finance check: it opens for the applicant when the documents above are verified.
          <?php else: ?>
            Opens for the applicant once the booking payment is verified.
          <?php endif; ?>
        </p>
      <?php else: ?>
        <ul class="pay-list">
          <?php foreach ($stage['payments'] as $payment): ?>
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
                  <?php /* opens over the page rather than in a tab of its own —
                           the receipt is looked at next to the decision it is
                           for, and the link still works without JavaScript */ ?>
                  <a class="pay-item__proof" data-viewer="Proof of payment"
                     href="file.php?path=<?= e(rawurlencode((string) $payment['proof_path'])) ?>&amp;dir=payments">
                    <i class="bi bi-paperclip" aria-hidden="true"></i> Proof of payment
                  </a>
                <?php elseif (($srcRow['sale_channel'] ?? 'online') === 'direct'): ?>
                  <?php /* A partner collected this themselves and recorded it; there
                           never was a receipt to upload, so "proof removed" read as
                           though something had gone missing. */ ?>
                  <span class="pay-item__proof pay-item__proof--gone">
                    <i class="bi bi-person-check" aria-hidden="true"></i>
                    paid to <?= e($payment['reference'] !== null && str_starts_with((string) $payment['reference'], 'Paid to ')
                        ? substr((string) $payment['reference'], 8)
                        : 'the partner') ?>
                  </span>
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
                        data-confirm="Verify this <?= e(money((float) $payment['amount'])) ?> <?= e(strtolower($stage['label'])) ?>? The applicant is emailed a receipt.">
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
                           required placeholder="Why? (goes in the email)">
                    <button type="submit" class="btn btn--danger"><i class="bi bi-x-lg"></i> Reject</button>
                  </form>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

    </section>
  <?php endforeach; ?>

  <?php /* what each partner has earned on this sale so far, tranche by tranche */ ?>
  <?php $payLines = commission_lines_for($payId); ?>

  <?php if ($payLines): ?>
    <section class="pay-stage">
      <header class="pay-stage__head">
        <h4 class="pay-stage__title">Commission earned on this sale</h4>
      </header>

      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Who</th>
              <th>Tranche</th>
              <th>Paid</th>
              <th>Earned</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payLines as $payLine): ?>
              <?php
                $payWho = $payLine['party_type'] === 'dealer'
                    ? dealer_by_id((int) $payLine['party_id'])
                    : distributor_by_id((int) $payLine['party_id']);
              ?>
              <tr>
                <td>
                  <div class="cell-stack">
                    <strong><?= e($payWho['full_name'] ?? 'a deleted partner') ?></strong>
                    <?php /* a percentage only where there was one: the flat amounts
                             that replaced the rates leave the column at zero */ ?>
                    <span class="cell-sub"><?= e(ucfirst((string) $payLine['party_type'])) ?><?php
                      if ((float) $payLine['rate'] > 0): ?>
                      · <?= e(rtrim(rtrim(number_format((float) $payLine['rate'], 2, '.', ''), '0'), '.')) ?>%<?php
                      endif; ?></span>
                  </div>
                </td>
                <td><?= e(tranche_label((string) $payLine['stage'])) ?></td>
                <td class="td-amount stock-figure"><?= e(money((float) $payLine['paid_amount'])) ?></td>
                <td class="td-amount stock-figure"><strong><?= e(money((float) $payLine['amount'])) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

  <div class="pay-panel__actions">
    <?php if ($payTotals['settled']): ?>
      <p class="pay-panel__note pay-panel__note--ok">
        <i class="bi bi-patch-check"></i>
        Both payments verified, receipts emailed. Nothing further to do.
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
