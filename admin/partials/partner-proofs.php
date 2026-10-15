<?php
/**
 * "Payment proofs to approve" — for the dealer and distributor dashboards.
 *
 * A customer who used a partner's code pays that partner and uploads the proof
 * in their portal. The partner decides it here; the POST is handled by
 * partner_proofs_handle() at the top of the dashboard, and the decision itself
 * runs through payment_decide(), the same path the office uses.
 *
 * Expects: $proofs (partner_pending_proofs() rows).
 */
?>
<div class="panel" id="proofs">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Payment proofs to approve</h2>
      <span class="eyebrow">customers who paid you directly</span>
    </div>
  </div>

  <div class="table-wrap">
    <table class="data-table data-table--stack">
      <thead>
        <tr>
          <th>Client</th>
          <th>Payment</th>
          <th>Amount</th>
          <th>Proof</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$proofs): ?>
          <tr class="row-empty">
            <td colspan="5">No entry found — nothing is waiting on you.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($proofs as $proof): ?>
          <tr>
            <td data-lead>
              <div class="cell-stack">
                <strong><?= e($proof['full_name']) ?></strong>
                <span class="cell-sub"><?= e($proof['reference_code']) ?> · <?= e(product_label((string) $proof['product'])) ?></span>
              </div>
            </td>
            <td data-label="Payment">
              <div class="cell-stack">
                <span><?= e(payment_stage_label((string) $proof['stage'])) ?></span>
                <span class="cell-sub"><?= e(format_datetime($proof['uploaded_at'])) ?></span>
              </div>
            </td>
            <td class="td-amount" data-label="Amount"><strong><?= e(money((float) $proof['amount'])) ?></strong></td>
            <td data-label="Proof">
              <div class="cell-stack">
                <?php if (!empty($proof['proof_path'])): ?>
                  <a class="link-arrow" data-viewer="Proof of payment"
                     href="payment-proof.php?payment=<?= (int) $proof['id'] ?>">
                    <i class="bi bi-paperclip" aria-hidden="true"></i> Proof
                  </a>
                <?php else: ?>
                  <span class="cell-sub">none uploaded</span>
                <?php endif; ?>
                <?php if (!empty($proof['reference'])): ?>
                  <span class="cell-sub">ref <?= e($proof['reference']) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td data-block="Decide">
              <div class="decide">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="proof_verify">
                  <input type="hidden" name="payment_id" value="<?= (int) $proof['id'] ?>">
                  <button type="submit" class="btn btn--primary btn--sm">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Payment received
                  </button>
                </form>

                <form method="post" data-confirm="Turn this payment down? The customer is emailed your reason.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="proof_reject">
                  <input type="hidden" name="payment_id" value="<?= (int) $proof['id'] ?>">
                  <div class="decide__reason">
                    <label class="visually-hidden" for="proof-reason-<?= (int) $proof['id'] ?>">
                      Why this payment is being turned down
                    </label>
                    <input id="proof-reason-<?= (int) $proof['id'] ?>" name="reason" type="text" maxlength="255"
                           required placeholder="Why? They see this">
                    <button type="submit" class="btn btn--ghost btn--sm">Turn down</button>
                  </div>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
