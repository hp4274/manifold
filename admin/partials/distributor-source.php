<?php
/**
 * The hidden markup one distributor's Details button opens.
 *
 * Four tabs, because a distributor is four questions: who they are, which
 * dealers answer to them, what has been sold under them, and what they have
 * been paid. Expects $srcDist and $dealerLimit.
 */

declare(strict_types=1);

$srcDistId  = (int) $srcDist['id'];
$srcDealers = $srcDist['dealers'] ?? distributor_dealers($srcDistId);
$srcTotals  = $srcDist['totals'] ?? distributor_totals($srcDistId);
$srcClients = distributor_clients($srcDistId);
$srcClientAll = partner_client_count('distributor', $srcDistId);
$srcPays    = distributor_payouts($srcDistId);
$srcStock   = stock_balance('distributor', $srcDistId);
$srcMoves   = stock_history('distributor', $srcDistId);
$srcOrders  = stock_orders_for('distributor', $srcDistId);
$srcWaiting = array_values(array_filter(
    $srcDealers,
    static fn (array $d): bool => $d['approval_status'] === 'pending'
));
$srcHeld    = distributor_dealer_count($srcDistId);
$srcRoom    = $srcHeld < ($dealerLimit ?? dealer_limit());
?>
<div class="drawer-source" id="detail-distributor-<?= $srcDistId ?>" hidden>

  <nav class="detail-tabs" role="tablist" aria-label="Sections">
    <button type="button" class="detail-tab is-active" data-tab="0" role="tab" aria-selected="true">Details</button>
    <button type="button" class="detail-tab" data-tab="1" role="tab" aria-selected="false">
      Dealers <span class="detail-tab__count"><?= count($srcDealers) ?></span>
      <?php if ($srcWaiting): ?><span class="detail-tab__flag" title="waiting for approval">
        <?= count($srcWaiting) ?> new</span><?php endif; ?>
    </button>
    <button type="button" class="detail-tab" data-tab="2" role="tab" aria-selected="false">
      Clients <span class="detail-tab__count"><?= (int) $srcClientAll ?></span>
    </button>
    <button type="button" class="detail-tab" data-tab="3" role="tab" aria-selected="false">
      Payouts <span class="detail-tab__count"><?= count($srcPays) ?></span>
    </button>
    <button type="button" class="detail-tab" data-tab="4" role="tab" aria-selected="false">
      Stock <span class="detail-tab__count"><?= (int) $srcStock['units'] ?></span>
    </button>
    <button type="button" class="detail-tab" data-tab="5" role="tab" aria-selected="false">
      Export MIS
    </button>
  </nav>

  <div class="detail-panels">
    <section class="detail-panel is-active" data-panel="0" role="tabpanel">
      <div class="detail-block">
        <p class="detail-block__title">Money</p>
        <dl class="detail-fields">
          <div class="detail-field">
            <dt>Completed sales</dt>
            <dd><?= (int) $srcTotals['confirmed'] ?> of <?= (int) $srcTotals['sales'] ?> attributed</dd>
          </div>
          <div class="detail-field">
            <dt>Commission earned</dt>
            <dd><?= e(money($srcTotals['earned'])) ?></dd>
          </div>
          <div class="detail-field">
            <dt>Riding on sales in progress</dt>
            <dd><?= e(money($srcTotals['pipeline'])) ?></dd>
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
          Commission is paid against a voucher, not from here: they bundle their own claim with their
          dealers' approved ones, C&amp;F presents it and pays it once the office funds it. What lands
          that way is listed under Payouts.
        </span>

        <div class="drawer-actions">
          <a class="btn btn--ghost btn--sm" href="vouchers">Open the claims</a>
          <a class="btn btn--ghost btn--sm" href="distributors?edit=<?= $srcDistId ?>">Edit distributor</a>
        </div>
      </div>

      <div class="detail-block">
        <p class="detail-block__title">Share link</p>
        <div class="cell-stack">
          <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $srcProduct => $srcLabel): ?>
            <?php $srcLink = referral_link((string) $srcDist['distributor_code'], $srcProduct); ?>
            <div class="dealer-link">
              <code><?= e($srcLink) ?></code>
              <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= e($srcLink) ?>">
                <i class="bi bi-link-45deg" aria-hidden="true"></i> Copy <?= e($srcLabel) ?>
              </button>
            </div>
          <?php endforeach; ?>
          <span class="field-hint">
            A sale through this link is theirs directly — they keep the full distributor share and no
            dealer is involved.
          </span>
        </div>
      </div>

      <?php foreach (distributor_field_groups() as $srcSections): ?>
        <?php foreach ($srcSections as $srcSectionLabel => $srcFields): ?>
          <div class="detail-block">
            <p class="detail-block__title"><?= $srcSectionLabel ?></p>
            <dl class="detail-fields">
              <?php foreach ($srcFields as $srcKey => $srcFieldLabel): ?>
                <?php if (!array_key_exists($srcKey, $srcDist)) { continue; } ?>
                <div class="detail-field">
                  <dt><?= e($srcFieldLabel) ?></dt>
                  <dd><?= render_value($srcKey, $srcDist[$srcKey]) ?></dd>
                </div>
              <?php endforeach; ?>
            </dl>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </section>

    <section class="detail-panel" data-panel="1" role="tabpanel">
      <div class="detail-block">
        <p class="detail-block__title">Dealers under <?= e($srcDist['distributor_code']) ?></p>

        <?php if (!$srcDealers): ?>
          <p class="empty">No dealers assigned yet. Put one under them below.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data-table" data-paged="10">
              <thead>
                <tr>
                  <th>Dealer</th>
                  <th>Code</th>
                  <th>State</th>
                  <th>Still owed to them</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($srcDealers as $srcDealer): ?>
                  <?php $srcDealerTotals = dealer_totals((int) $srcDealer['id']); ?>
                  <tr>
                    <td>
                      <div class="cell-stack">
                        <strong><?= e($srcDealer['full_name']) ?></strong>
                        <span class="cell-sub"><?= e($srcDealer['email'] ?: 'no email') ?></span>
                      </div>
                    </td>
                    <td>
                      <?php if ($srcDealer['dealer_code']): ?>
                        <span class="drawer__code"><?= e((string) $srcDealer['dealer_code']) ?></span>
                      <?php elseif ($srcDealer['approval_status'] === 'pending'): ?>
                        <span class="cell-sub">on approval</span>
                      <?php else: ?>
                        <span class="cell-none">-</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($srcDealer['approval_status'] !== 'approved'): ?>
                        <?php /* their code books nothing until the office decides */ ?>
                        <span class="pill pill--<?= $srcDealer['approval_status'] === 'pending'
                            ? 'booking_review' : 'rejected' ?>">
                          <?= e(approval_label((string) $srcDealer['approval_status'])) ?>
                        </span>
                      <?php else: ?>
                        <span class="pill pill--<?= $srcDealer['is_active'] ? 'accepted' : 'rejected' ?>">
                          <?= $srcDealer['is_active'] ? 'Active' : 'Stopped' ?>
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="td-amount"><?= e(money($srcDealerTotals['remaining'])) ?></td>
                    <td class="td-actions">
                      <div class="row-actions">
                        <?php if ($srcDealer['approval_status'] === 'pending'): ?>
                          <?php /* icons, so the decision and Details sit on one
                                   line rather than wrapping under each other */ ?>
                          <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve_dealer">
                            <input type="hidden" name="id" value="<?= $srcDistId ?>">
                            <input type="hidden" name="dealer_id" value="<?= (int) $srcDealer['id'] ?>">
                            <button type="submit" class="icon-btn is-accept"
                                    title="Approve <?= e($srcDealer['full_name']) ?>">
                              <i class="bi bi-check-lg" aria-hidden="true"></i>
                              <span class="visually-hidden">Approve <?= e($srcDealer['full_name']) ?></span>
                            </button>
                          </form>
                          <form method="post"
                                data-confirm="Turn down <?= e($srcDealer['full_name']) ?>? No code is issued.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject_dealer">
                            <input type="hidden" name="id" value="<?= $srcDistId ?>">
                            <input type="hidden" name="dealer_id" value="<?= (int) $srcDealer['id'] ?>">
                            <button type="submit" class="icon-btn is-reject"
                                    title="Turn down <?= e($srcDealer['full_name']) ?>">
                              <i class="bi bi-x-lg" aria-hidden="true"></i>
                              <span class="visually-hidden">Turn down <?= e($srcDealer['full_name']) ?></span>
                            </button>
                          </form>
                        <?php endif; ?>

                        <?php /* The same drawer the Dealers page opens, from here:
                                 the office should not have to go and find them in
                                 another list to read their details. */ ?>
                        <button type="button" class="btn btn--ghost btn--sm"
                                data-drawer="detail-dealer-<?= (int) $srcDealer['id'] ?>"
                                data-drawer-url="drawer.php?kind=dealer&amp;id=<?= (int) $srcDealer['id'] ?>"
                                data-title="<?= e($srcDealer['full_name']) ?>"
                                data-code="<?= e((string) $srcDealer['dealer_code']) ?>"
                                data-meta="Dealer · under <?= e($srcDist['full_name']) ?>"
                                data-status="<?= $srcDealer['approval_status'] === 'pending'
                                    ? 'pending'
                                    : ($srcDealer['approval_status'] === 'rejected'
                                        ? 'rejected'
                                        : ($srcDealer['is_active'] ? 'accepted' : 'rejected')) ?>"
                                data-status-label="<?= $srcDealer['approval_status'] === 'approved'
                                    ? ($srcDealer['is_active'] ? 'Active' : 'Stopped')
                                    : e(approval_label((string) $srcDealer['approval_status'])) ?>">
                          Details <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="detail-block">
        <p class="detail-block__title">
          Add a dealer under them
          <span class="detail-block__note"><?= (int) $srcHeld ?> of <?= (int) ($dealerLimit ?? dealer_limit()) ?> used</span>
        </p>

        <?php if (!$srcRoom): ?>
          <p class="empty">
            <?= e($srcDist['full_name']) ?> already holds <?= (int) $srcHeld ?> dealers, which is the limit.
            Raise it under <a href="settings">Settings</a>, or take one out first.
          </p>
        <?php else: ?>
          <?php /* the office adds a dealer outright — approving its own entry
                   would be a step that never says no. The whole dealer form
                   opens in a dialog rather than living in this panel. */ ?>
          <span class="field-hint">
            A dealer added here is approved at once and gets their code with it, and every
            sale they make earns <?= e($srcDist['full_name']) ?> the override —
            <?= e(money_short(commission_value('override', 'stove'))) ?> a stove,
            <?= e(money_short(commission_value('override', 'tuktuk'))) ?> a kit.
          </span>

          <div class="drawer-actions drawer-actions--end">
            <button type="button" class="btn btn--primary btn--sm" data-modal-open="addDealerModal"
                    data-dist-id="<?= $srcDistId ?>">
              <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a dealer under them
            </button>
          </div>
        <?php endif; ?>
      </div>

    </section>

    <section class="detail-panel" data-panel="2" role="tabpanel">
      <div class="detail-block">
        <p class="detail-block__title">
          Sales earning <?= e($srcDist['full_name']) ?> something
          <?php if ($srcClientAll > count($srcClients)): ?>
            <span class="detail-block__note">newest <?= count($srcClients) ?> of <?= (int) $srcClientAll ?></span>
          <?php endif; ?>
        </p>

        <?php if (!$srcClients): ?>
          <p class="empty">Nothing has been sold under this distributor yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data-table" data-paged="10">
              <thead>
                <tr>
                  <th>Client</th>
                  <th>Booking number</th>
                  <th>Sold by</th>
                  <th>Status</th>
                  <th>Their share</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($srcClients as $srcClient): ?>
                  <tr>
                    <td>
                      <div class="cell-stack">
                        <strong><?= e($srcClient['full_name']) ?></strong>
                        <span class="cell-sub"><?= e($srcClient['email']) ?></span>
                      </div>
                    </td>
                    <td><span class="drawer__code"><?= e($srcClient['reference_code']) ?></span></td>
                    <td>
                      <?php if ($srcClient['dealer_name']): ?>
                        <div class="cell-stack">
                          <span><?= e($srcClient['dealer_name']) ?></span>
                          <span class="cell-sub"><?= e($srcClient['dealer_code']) ?> · override</span>
                        </div>
                      <?php else: ?>
                        <span class="cell-sub">Themselves · direct</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="pill pill--<?= e($srcClient['status']) ?>">
                        <?= e(status_short((string) $srcClient['status'])) ?>
                      </span>
                    </td>
                    <td class="td-amount">
                      <strong><?= e(money((float) $srcClient['distributor_commission'])) ?></strong>
                      <?php if (!commission_is_earned($srcClient)): ?>
                        <span class="cell-sub">not earned yet</span>
                      <?php endif; ?>
                    </td>
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
          <?= count($srcPays) ?> transfer<?= count($srcPays) === 1 ? '' : 's' ?> so far
        </p>

        <?php if (!$srcPays): ?>
          <p class="empty">Nothing has been paid to this distributor yet.</p>
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
                <?php foreach ($srcPays as $srcPay): ?>
                  <tr>
                    <td><?= e(format_datetime($srcPay['paid_at'])) ?></td>
                    <td class="td-amount"><strong><?= e(money((float) $srcPay['amount'])) ?></strong></td>
                    <td><?= e($srcPay['note'] ?: '—') ?></td>
                    <td><span class="cell-sub"><?= e($srcPay['paid_by_name'] ?: 'a deleted account') ?></span></td>
                    <td class="td-actions"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="detail-panel" data-panel="4" role="tabpanel">
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
          Orders from the office
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

    <section class="detail-panel" data-panel="5" role="tabpanel">
      <?php
        $exportKind = 'distributors';
        $exportId   = $srcDistId;
        require __DIR__ . '/export-panel.php';
      ?>
    </section>
  </div>
</div>
