<?php
/**
 * One list of bundles, and whatever R&F can do with them at this stage.
 *
 * Every bundle opens to show who is inside it and what each of them is owed,
 * because that is what R&F is actually paying — the total on the outside is
 * only a sum of those. Expects $rfBundles and $rfMode
 * ('check' | 'waiting' | 'pay').
 */

declare(strict_types=1);
?>
<?php if (!$rfBundles): ?>
  <p class="empty">
    <?= $rfMode === 'check'
        ? 'Nothing to check. Claims appear here as distributors send them.'
        : ($rfMode === 'pay'
            ? 'Nothing funded yet. A bundle lands here once the office has released the money.'
            : 'Nothing with the office right now.') ?>
  </p>
<?php else: ?>
  <div class="panel__body">
    <?php foreach ($rfBundles as $rfBundle): ?>
      <?php
        $rfId       = (int) $rfBundle['id'];
        $rfChildren = voucher_bundle_children($rfId);
        $rfTotal    = voucher_bundle_total($rfId);
        $rfParty    = voucher_party($rfBundle);
        $rfBankable = voucher_has_bank($rfBundle);

        /* a partner with nowhere to send the money is the one thing that stops
           a payment, so it is worked out before the button is drawn */
        $rfMissing = $rfBankable ? [] : [$rfParty['full_name'] ?? 'the distributor'];

        foreach ($rfChildren as $rfChild) {
            if (!voucher_has_bank($rfChild)) {
                $rfChildParty = voucher_party($rfChild);
                $rfMissing[]  = $rfChildParty['full_name'] ?? 'a dealer';
            }
        }
      ?>
      <section class="voucher">
        <header class="voucher__head">
          <div class="voucher__who">
            <p class="eyebrow">Bundle #<?= $rfId ?> · <?= e($rfBundle['party_code']) ?></p>
            <h3><?= e($rfBundle['party_name']) ?></h3>
            <p class="voucher__meta">
              Raised <?= e(format_datetime($rfBundle['raised_at'])) ?> ·
              <?= count($rfChildren) ?> dealer<?= count($rfChildren) === 1 ? '' : 's' ?> in it
            </p>
          </div>

          <div class="voucher__sum">
            <span class="pill pill--<?= e(voucher_status_pill((string) $rfBundle['status'])) ?>">
              <?= e(voucher_status_label((string) $rfBundle['status'])) ?>
            </span>
            <strong class="stock-figure"><?= e(money($rfTotal)) ?></strong>
          </div>
        </header>

        <div class="table-wrap">
          <table class="data-table">
            <colgroup>
              <col style="width:34%">
              <col style="width:20%">
              <col style="width:26%">
              <col style="width:20%">
            </colgroup>
            <thead>
              <tr>
                <th>Who is owed</th>
                <th>Their share</th>
                <th>Where it goes</th>
                <th>Sales in it</th>
              </tr>
            </thead>
            <tbody>
              <?php /* the distributor's own claim rides in the bundle it raised */ ?>
              <tr>
                <td>
                  <div class="cell-stack">
                    <strong><?= e($rfBundle['party_name']) ?></strong>
                    <span class="cell-sub">Distributor · <?= e($rfBundle['party_code']) ?></span>
                  </div>
                </td>
                <td class="td-amount"><strong><?= e(money((float) $rfBundle['amount'])) ?></strong></td>
                <td>
                  <?php if ($rfBankable): ?>
                    <span class="cell-sub">
                      <?= e($rfParty['upi_id'] ?: $rfParty['bank_account'] . ' · ' . $rfParty['bank_ifsc']) ?>
                    </span>
                  <?php else: ?>
                    <span class="pill pill--rejected">No bank details</span>
                  <?php endif; ?>
                </td>
                <td class="td-amount"><?= count(voucher_lines($rfId)) ?></td>
              </tr>

              <?php foreach ($rfChildren as $rfChild): ?>
                <?php $rfChildParty = voucher_party($rfChild); ?>
                <tr>
                  <td>
                    <div class="cell-stack">
                      <strong><?= e($rfChild['party_name']) ?></strong>
                      <span class="cell-sub">Dealer · <?= e($rfChild['party_code']) ?></span>
                    </div>
                  </td>
                  <td class="td-amount"><strong><?= e(money((float) $rfChild['amount'])) ?></strong></td>
                  <td>
                    <?php if (voucher_has_bank($rfChild)): ?>
                      <span class="cell-sub">
                        <?= e($rfChildParty['upi_id']
                            ?: $rfChildParty['bank_account'] . ' · ' . $rfChildParty['bank_ifsc']) ?>
                      </span>
                    <?php else: ?>
                      <span class="pill pill--rejected">No bank details</span>
                    <?php endif; ?>
                  </td>
                  <td class="td-amount"><?= count(voucher_lines((int) $rfChild['id'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($rfMissing): ?>
          <p class="direct-sale__notice">
            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
            <span>
              <strong>Nowhere to send some of this.</strong>
              <?= e(implode(', ', array_unique($rfMissing))) ?>
              <?= count(array_unique($rfMissing)) === 1 ? 'has' : 'have' ?>
              no UPI ID and no bank account on file. Send the bundle back and ask the office to fill
              them in — paying it now would leave a payout recorded against money that never moved.
            </span>
          </p>
        <?php endif; ?>

        <div class="voucher__actions">
          <?php if ($rfMode === 'check'): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="forward">
              <input type="hidden" name="bundle_id" value="<?= $rfId ?>">
              <button type="submit" class="btn btn--primary">
                <i class="bi bi-send" aria-hidden="true"></i> Send to the office
              </button>
            </form>

            <form method="post" class="decide__reason"
                  data-confirm="Send this bundle back? The dealers' vouchers return to their distributor.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="send_back">
              <input type="hidden" name="bundle_id" value="<?= $rfId ?>">
              <label class="visually-hidden" for="back-<?= $rfId ?>">Why it is going back</label>
              <input id="back-<?= $rfId ?>" name="reason" type="text" maxlength="255"
                     placeholder="Why? They see this">
              <button type="submit" class="btn btn--ghost">Send back</button>
            </form>
          <?php elseif ($rfMode === 'pay'): ?>
            <form method="post" class="decide__reason"
                  data-confirm="Record this bundle as paid? A payout is written against every partner in it.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="pay">
              <input type="hidden" name="bundle_id" value="<?= $rfId ?>">
              <label class="visually-hidden" for="ref-<?= $rfId ?>">Payment reference</label>
              <input id="ref-<?= $rfId ?>" name="reference" type="text" maxlength="120"
                     placeholder="UTR / transfer reference">
              <button type="submit" class="btn btn--primary" <?= $rfMissing ? 'disabled' : '' ?>>
                <i class="bi bi-check-lg" aria-hidden="true"></i> Mark paid
              </button>
            </form>
          <?php else: ?>
            <p class="field-hint">
              With the office since <?= e(format_datetime($rfBundle['decided_at'] ?: $rfBundle['raised_at'])) ?>.
              It comes back here to pay once they release the funds.
            </p>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
