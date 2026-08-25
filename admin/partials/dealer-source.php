<?php
/**
 * The hidden markup one dealer's Details button opens.
 *
 * Same two-tab drawer the submissions use: who the dealer is on the first tab,
 * everyone they have brought in on the second, so the office never has to leave
 * the list to see a dealer's customers. Expects $srcDealer.
 */

declare(strict_types=1);

$srcDealerId = (int) $srcDealer['id'];
$srcClients  = dealer_clients($srcDealerId);
$srcTotals   = $srcDealer['totals'] ?? dealer_totals($srcDealerId);
$srcPayouts  = dealer_payouts($srcDealerId);
?>
<div class="drawer-source" id="detail-dealer-<?= $srcDealerId ?>" hidden>

  <nav class="detail-tabs" role="tablist" aria-label="Sections">
    <button type="button" class="detail-tab is-active" data-tab="0" role="tab" aria-selected="true">Details</button>
    <button type="button" class="detail-tab" data-tab="1" role="tab" aria-selected="false">
      Clients <span class="detail-tab__count"><?= count($srcClients) ?></span>
    </button>
    <button type="button" class="detail-tab" data-tab="2" role="tab" aria-selected="false">
      Payouts <span class="detail-tab__count"><?= count($srcPayouts) ?></span>
    </button>
  </nav>

  <div class="detail-panels">
    <section class="detail-panel is-active" data-panel="0" role="tabpanel">

      <div class="detail-block">
        <p class="detail-block__title">Money</p>
        <dl class="detail-fields">
          <div class="detail-field">
            <dt>Units sold</dt>
            <dd><?= (int) $srcTotals['confirmed'] ?> of <?= (int) $srcTotals['sales'] ?> applied</dd>
          </div>
          <div class="detail-field">
            <dt>Commission earned</dt>
            <dd><?= e(money($srcTotals['earned'])) ?></dd>
          </div>
          <div class="detail-field">
            <dt>Paid out</dt>
            <dd><?= e(money($srcTotals['paid'])) ?></dd>
          </div>
          <div class="detail-field">
            <dt>Still owed</dt>
            <dd><strong><?= e(money($srcTotals['remaining'])) ?></strong></dd>
          </div>
        </dl>
        <div class="drawer-actions">
          <button type="button" class="btn btn--primary btn--sm" data-tab-go="2">Record a payout</button>
          <a class="btn btn--ghost btn--sm" href="dealers.php?edit=<?= $srcDealerId ?>">Edit dealer</a>
        </div>
      </div>

      <div class="detail-block">
        <p class="detail-block__title">Share link</p>
        <div class="cell-stack">
          <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $srcProduct => $srcLabel): ?>
            <?php $srcLink = referral_link((string) $srcDealer['dealer_code'], $srcProduct); ?>
            <div class="dealer-link">
              <code><?= e($srcLink) ?></code>
              <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= e($srcLink) ?>">
                <i class="bi bi-link-45deg" aria-hidden="true"></i> Copy <?= e($srcLabel) ?>
              </button>
            </div>
          <?php endforeach; ?>
          <span class="field-hint">
            Anybody opening one of these finds <?= e($srcDealer['dealer_code']) ?> already in the referral box
            and cannot change it, so the sale is attributed to <?= e($srcDealer['full_name']) ?>.
          </span>
        </div>
      </div>

      <?php foreach (dealer_field_groups() as $srcSections): ?>
        <?php foreach ($srcSections as $srcSectionLabel => $srcFields): ?>
          <div class="detail-block">
            <p class="detail-block__title"><?= $srcSectionLabel ?></p>
            <dl class="detail-fields">
              <?php foreach ($srcFields as $srcKey => $srcFieldLabel): ?>
                <?php if (!array_key_exists($srcKey, $srcDealer)) { continue; } ?>
                <div class="detail-field">
                  <dt><?= e($srcFieldLabel) ?></dt>
                  <dd><?= render_value($srcKey, $srcDealer[$srcKey]) ?></dd>
                </div>
              <?php endforeach; ?>
            </dl>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </section>

    <section class="detail-panel" data-panel="1" role="tabpanel">
      <div class="detail-block">
        <p class="detail-block__title">Everyone who applied with <?= e($srcDealer['dealer_code']) ?></p>

        <?php if (!$srcClients): ?>
          <p class="empty">Nobody has applied with this dealer's code yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Client</th>
                  <th>Booking number</th>
                  <th>Product</th>
                  <th>Status</th>
                  <th>Commission</th>
                  <th>Applied</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($srcClients as $srcClient): ?>
                  <?php /* commission only counts once their booking payment has cleared */ ?>
                  <?php $srcEarned = !empty($srcClient['booking_paid_at'])
                      && $srcClient['status'] !== 'rejected'; ?>
                  <tr>
                    <td>
                      <div class="cell-stack">
                        <strong><?= e($srcClient['full_name']) ?></strong>
                        <span class="cell-sub"><?= e($srcClient['email']) ?></span>
                        <?php if ($srcClient['mobile_number']): ?>
                          <span class="cell-sub"><?= e($srcClient['mobile_number']) ?></span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td><span class="drawer__code"><?= e($srcClient['reference_code']) ?></span></td>
                    <td><?= e(ucfirst((string) $srcClient['product'])) ?></td>
                    <td>
                      <span class="pill pill--<?= e($srcClient['status']) ?>">
                        <?= e(status_short((string) $srcClient['status'])) ?>
                      </span>
                    </td>
                    <td class="td-amount">
                      <strong><?= e(money((float) $srcClient['dealer_commission'])) ?></strong>
                      <?php if (!$srcEarned): ?>
                        <span class="cell-sub">not earned yet</span>
                      <?php endif; ?>
                    </td>
                    <td><span class="cell-sub"><?= e(format_datetime($srcClient['created_at'])) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="detail-panel" data-panel="2" role="tabpanel">
      <div class="detail-block">
        <p class="detail-block__title">Record a payout</p>

        <form method="post" class="payout-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="payout">
          <input type="hidden" name="id" value="<?= $srcDealerId ?>">

          <div class="form-grid">
            <div class="field">
              <label for="payout_amount_<?= $srcDealerId ?>">Amount transferred</label>
              <input id="payout_amount_<?= $srcDealerId ?>" name="amount" type="number" step="0.01" min="0.01"
                     value="<?= e(number_format($srcTotals['remaining'], 2, '.', '')) ?>" required>
            </div>

            <div class="field">
              <label for="payout_note_<?= $srcDealerId ?>">Reference</label>
              <input id="payout_note_<?= $srcDealerId ?>" name="note" type="text" maxlength="255"
                     placeholder="UPI / UTR reference">
            </div>
          </div>

          <span class="field-hint">
            Starts at everything outstanding. Pay less and the difference stays owed —
            <?= e(money($srcTotals['earned'])) ?> earned, <?= e(money($srcTotals['paid'])) ?> paid,
            <?= e(money($srcTotals['remaining'])) ?> left.
          </span>

          <button type="submit" class="btn btn--primary">Record payout</button>
        </form>
      </div>

      <div class="detail-block">
        <p class="detail-block__title">
          <?= count($srcPayouts) ?> transfer<?= count($srcPayouts) === 1 ? '' : 's' ?> so far
        </p>

        <?php if (!$srcPayouts): ?>
          <p class="empty">Nothing has been paid to this dealer yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Paid on</th>
                  <th>Amount</th>
                  <th>Reference</th>
                  <th>Recorded by</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($srcPayouts as $srcPayout): ?>
                  <tr>
                    <td><?= e(format_datetime($srcPayout['paid_at'])) ?></td>
                    <td class="td-amount"><strong><?= e(money((float) $srcPayout['amount'])) ?></strong></td>
                    <td><?= e($srcPayout['note'] ?: '—') ?></td>
                    <td><span class="cell-sub"><?= e($srcPayout['paid_by_name'] ?: 'a deleted account') ?></span></td>
                    <td class="td-actions">
                      <form method="post"
                            data-confirm="Remove this payout of <?= e(money((float) $srcPayout['amount'])) ?>?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="payout_delete">
                        <input type="hidden" name="id" value="<?= $srcDealerId ?>">
                        <input type="hidden" name="payout_id" value="<?= (int) $srcPayout['id'] ?>">
                        <button type="submit" class="icon-btn is-delete" title="Remove this payout">
                          <i class="bi bi-trash" aria-hidden="true"></i>
                          <span class="visually-hidden">Remove this payout</span>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>
