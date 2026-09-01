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
$srcClientAll = partner_client_count('dealer', $srcDealerId);
$srcTotals   = $srcDealer['totals'] ?? dealer_totals($srcDealerId);
$srcPayouts  = dealer_payouts($srcDealerId);

/* A dealer the office has not answered yet has no code, so no link to share, no
   clients, and nothing earned or owed. What there is to see is who they are and
   which distributor asked for them — and the decision. */
$srcStock   = stock_balance('dealer', $srcDealerId);
$srcMoves   = stock_history('dealer', $srcDealerId);
$srcOrders  = stock_orders_for('dealer', $srcDealerId);
$srcWaiting = ($srcDealer['approval_status'] ?? 'approved') === 'pending';
$srcAskedBy = $srcWaiting
    ? distributor_by_id((int) ($srcDealer['requested_by'] ?: $srcDealer['distributor_id']))
    : null;
?>
<div class="drawer-source" id="detail-dealer-<?= $srcDealerId ?>" hidden>

  <?php if (!$srcWaiting): ?>
    <nav class="detail-tabs" role="tablist" aria-label="Sections">
      <button type="button" class="detail-tab is-active" data-tab="0" role="tab" aria-selected="true">Details</button>
      <button type="button" class="detail-tab" data-tab="1" role="tab" aria-selected="false">
        Clients <span class="detail-tab__count"><?= (int) $srcClientAll ?></span>
      </button>
      <button type="button" class="detail-tab" data-tab="2" role="tab" aria-selected="false">
        Payouts <span class="detail-tab__count"><?= count($srcPayouts) ?></span>
      </button>
      <button type="button" class="detail-tab" data-tab="3" role="tab" aria-selected="false">
        Stock <span class="detail-tab__count"><?= (int) $srcStock['units'] ?></span>
      </button>
    </nav>
  <?php endif; ?>

  <div class="detail-panels">
    <section class="detail-panel is-active" data-panel="0" role="tabpanel">

      <?php if ($srcWaiting): ?>
        <div class="decide-bar">
          <div class="decide-bar__text">
            <p class="decide-bar__title">Waiting for your approval</p>
            <p class="decide-bar__note">
              <?= e($srcAskedBy['full_name'] ?? 'A distributor') ?> asked for this dealer<?php
                if ($srcAskedBy): ?> · <?= e($srcAskedBy['distributor_code']) ?><?php endif; ?>.
              Approving issues their code and starts their links working; turning them down issues nothing.
            </p>
          </div>

          <div class="decide-bar__actions">
            <form method="post" action="dealers">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $srcDealerId ?>">
              <button type="submit" name="action" value="approve_dealer" class="btn btn--primary btn--sm">
                <i class="bi bi-check-lg" aria-hidden="true"></i> Approve
              </button>
            </form>

            <form method="post" action="dealers"
                  data-confirm="Turn down <?= e($srcDealer['full_name']) ?>? No code is issued.">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $srcDealerId ?>">
              <button type="submit" name="action" value="reject_dealer" class="btn btn--ghost btn--sm is-reject">
                Turn down
              </button>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!$srcWaiting): ?>
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
        <span class="field-hint">
          Commission is paid against a voucher, not from here: the dealer claims it, their
          distributor approves it, R&amp;F presents it and pays it once the office funds it.
          What lands that way is listed under Payouts.
        </span>

        <div class="drawer-actions">
          <a class="btn btn--ghost btn--sm" href="vouchers">Open the claims</a>
          <a class="btn btn--ghost btn--sm" href="dealers?edit=<?= $srcDealerId ?>">Edit dealer</a>
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
            Anybody opening one of these finds <?= e((string) $srcDealer['dealer_code']) ?> already in the
            referral box and cannot change it, so the sale is attributed to
            <?= e($srcDealer['full_name']) ?>.
          </span>
        </div>
      </div>
      <?php endif; ?>

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

    <?php if (!$srcWaiting): ?>
    <section class="detail-panel" data-panel="1" role="tabpanel">
      <div class="detail-block">
        <p class="detail-block__title">
          Everyone who applied with <?= e((string) $srcDealer['dealer_code']) ?>
          <?php if ($srcClientAll > count($srcClients)): ?>
            <span class="detail-block__note">newest <?= count($srcClients) ?> of <?= (int) $srcClientAll ?></span>
          <?php endif; ?>
        </p>

        <?php if (!$srcClients): ?>
          <p class="empty">Nobody has applied with this dealer's code yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data-table" data-paged="10">
              <thead>
                <tr>
                  <th>Client</th>
                  <th>Booking number</th>
                  <th>Product</th>
                  <th>Status</th>
                  <th>Commission</th>
                  <th>Applied</th>
                  <th></th>
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
                    <td class="td-actions">
                      <?php /* the client's own drawer, opened from here: reading
                               their application should not mean going to find
                               them in the applications list */ ?>
                      <button type="button" class="btn btn--ghost btn--sm"
                              data-drawer="detail-<?= e((string) $srcClient['product']) ?>-<?= (int) $srcClient['id'] ?>"
                              data-drawer-url="drawer.php?type=<?= e((string) $srcClient['product']) ?>&amp;id=<?= (int) $srcClient['id'] ?>"
                              data-title="<?= e($srcClient['full_name']) ?>"
                              data-code="<?= e((string) $srcClient['reference_code']) ?>"
                              data-meta="<?= e(ucfirst((string) $srcClient['product'])) ?> application · <?= e(format_datetime($srcClient['created_at'])) ?>"
                              data-status="<?= e((string) $srcClient['status']) ?>"
                              data-status-label="<?= e(status_short((string) $srcClient['status'])) ?>">
                        Details <i class="bi bi-chevron-right" aria-hidden="true"></i>
                      </button>
                    </td>
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
        <p class="detail-block__title">
          <?= count($srcPayouts) ?> transfer<?= count($srcPayouts) === 1 ? '' : 's' ?> so far
        </p>

        <?php if (!$srcPayouts): ?>
          <p class="empty">Nothing has been paid to this dealer yet. A payout appears here when R&amp;F
            settles a voucher they are on.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data-table" data-paged="10">
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
                    <td class="td-actions"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="detail-panel" data-panel="3" role="tabpanel">
      <div class="detail-block">
        <p class="detail-block__title">
          What they hold
          <span class="detail-block__note"><?= e(money($srcStock['value'])) ?> at cost</span>
        </p>

        <dl class="detail-fields">
          <div class="detail-field">
            <dt>Stoves</dt>
            <dd><strong><?= (int) $srcStock['stove']['units'] ?></strong>
              · <?= e(money($srcStock['stove']['value'])) ?></dd>
          </div>
          <div class="detail-field">
            <dt>TukTuk kits</dt>
            <dd><strong><?= (int) $srcStock['tuktuk']['units'] ?></strong>
              · <?= e(money($srcStock['tuktuk']['value'])) ?></dd>
          </div>
          <div class="detail-field">
            <dt>Altogether</dt>
            <dd><strong><?= (int) $srcStock['units'] ?></strong> units</dd>
          </div>
        </dl>
      </div>

      <div class="detail-block">
        <p class="detail-block__title">
          Orders
          <span class="detail-block__note"><?= count($srcOrders) ?> raised</span>
        </p>

        <?php if (!$srcOrders): ?>
          <p class="empty">No entry found — they have not ordered any stock yet.</p>
        <?php else: ?>
          <?php $ownOrders = $srcOrders; require __DIR__ . '/stock-orders-table.php'; ?>
        <?php endif; ?>
      </div>

      <div class="detail-block">
        <p class="detail-block__title">Every movement</p>

        <?php if (!$srcMoves): ?>
          <p class="empty">No entry found — nothing has moved in or out yet.</p>
        <?php else: ?>
          <?php $ledgerRows = $srcMoves; require __DIR__ . '/stock-ledger-table.php'; ?>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
