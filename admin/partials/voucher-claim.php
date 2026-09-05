<?php
/**
 * Raising a commission voucher, and what has happened to the ones already
 * raised. Shared by the dealer and distributor portals, because the claim is
 * the same on both sides — only who it goes to differs.
 *
 * A distributor also gets the two things a dealer never sees: their dealers'
 * claims to decide on, and the bundle that carries the approved ones to C&F.
 *
 * Expects $voucherKind ('dealer' or 'distributor'), $claimable, $claimTotal,
 * $openVoucher, $myVouchers — and for a distributor, $dealerAsks and
 * $approvedAsks.
 */

declare(strict_types=1);

$vcIsDist = ($voucherKind ?? 'dealer') === 'distributor';
?>

<?php if ($vcIsDist): ?>
  <?php /* the first check in the chain: a distributor knows whether their own
           dealers' sales are real, which is why the claim stops here first */ ?>
  <div class="panel">
    <div class="panel__head">
      <div class="panel__head-text">
        <h2>Your dealers' claims</h2>
        <span class="eyebrow">
          <?= count($dealerAsks) ?> to decide ·
          <?= count($approvedAsks) ?> approved and waiting for your next bundle
        </span>
      </div>
    </div>

    <div class="table-wrap">
      <table class="data-table">
        <colgroup>
          <col style="width:26%">
          <col style="width:16%">
          <col style="width:14%">
          <col style="width:16%">
          <col style="width:28%">
        </colgroup>
        <thead>
          <tr>
            <th>Dealer</th>
            <th>Claimed</th>
            <th>Sales</th>
            <th>Raised</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$dealerAsks && !$approvedAsks): ?>
            <tr class="row-empty">
              <td colspan="5">No entry found — none of your dealers has claimed anything.</td>
            </tr>
          <?php endif; ?>

          <?php foreach (array_merge($dealerAsks, $approvedAsks) as $vcAsk): ?>
            <tr>
              <td>
                <div class="cell-stack">
                  <strong><?= e($vcAsk['party_name']) ?></strong>
                  <span class="cell-sub"><?= e($vcAsk['party_code']) ?></span>
                </div>
              </td>
              <td class="td-amount stock-figure">
                <strong><?= e(money((float) $vcAsk['amount'])) ?></strong>
              </td>
              <td class="td-amount"><?= count(voucher_lines((int) $vcAsk['id'])) ?></td>
              <td><span class="cell-sub"><?= e(format_datetime($vcAsk['raised_at'])) ?></span></td>
              <td>
                <?php if ($vcAsk['status'] === 'with_distributor'): ?>
                  <div class="decide">
                    <form method="post">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="approve_dealer_voucher">
                      <input type="hidden" name="voucher_id" value="<?= (int) $vcAsk['id'] ?>">
                      <button type="submit" class="btn btn--primary btn--sm">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Approve
                      </button>
                    </form>

                    <form method="post"
                          data-confirm="Turn this claim down? Those sales become claimable again.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="reject_dealer_voucher">
                      <input type="hidden" name="voucher_id" value="<?= (int) $vcAsk['id'] ?>">
                      <div class="decide__reason">
                        <label class="visually-hidden" for="vcreason-<?= (int) $vcAsk['id'] ?>">
                          Why this claim is being turned down
                        </label>
                        <input id="vcreason-<?= (int) $vcAsk['id'] ?>" name="reason" type="text"
                               maxlength="255" placeholder="Why? They see this">
                        <button type="submit" class="btn btn--ghost btn--sm">Turn down</button>
                      </div>
                    </form>
                  </div>
                <?php else: ?>
                  <span class="pill pill--<?= e(voucher_status_pill((string) $vcAsk['status'])) ?>">
                    Approved — in your next bundle
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2><?= $vcIsDist ? 'Send a bundle to C&amp;F' : 'Claim your commission' ?></h2>
      <span class="eyebrow">
        <?= $vcIsDist
            ? 'Your own claim and every dealer voucher you have approved, as one document'
            : 'It goes to your distributor first' ?>
      </span>
    </div>
  </div>

  <div class="panel__body">
    <?php if ($openVoucher): ?>
      <?php /* one open claim at a time, or the same sale could be claimed twice
               and the office would be looking at two documents for one debt */ ?>
      <p class="direct-sale__notice">
        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
        <span>
          <strong>You have a claim in flight.</strong>
          <?= e(money((float) $openVoucher['amount'])) ?> raised
          <?= e(format_datetime($openVoucher['raised_at'])) ?>, currently
          <strong><?= e(strtolower(voucher_status_label((string) $openVoucher['status']))) ?></strong>.
          Anything earned since then goes on your next one, which you can raise as soon as this is
          settled.
        </span>
      </p>
    <?php elseif (!$claimable && (!$vcIsDist || !$approvedAsks)): ?>
      <p class="empty">
        Nothing to claim yet. Commission becomes claimable when a sale is complete —
        both payments verified.
      </p>
    <?php else: ?>
      <div class="stock-order__total">
        <span class="eyebrow"><?= $vcIsDist ? 'Your own share' : 'To claim' ?></span>
        <strong><?= e(money($claimTotal)) ?></strong>
        <span class="field-hint">
          <?= count($claimable) ?> completed sale<?= count($claimable) === 1 ? '' : 's' ?> not yet
          claimed<?= $vcIsDist && $approvedAsks
              ? ', plus ' . count($approvedAsks) . ' approved dealer voucher'
                . (count($approvedAsks) === 1 ? '' : 's')
              : '' ?>.
          <?= $vcIsDist
              ? 'C&amp;F check it, the office funds it, and C&amp;F pay everybody in it.'
              : 'Your distributor checks it first, then it travels to C&amp;F and the office.' ?>
        </span>
      </div>

      <?php if ($claimable): ?>
        <div class="table-wrap">
          <table class="data-table" data-paged="10">
            <colgroup>
              <col style="width:24%">
              <col style="width:30%">
              <col style="width:22%">
              <col style="width:24%">
            </colgroup>
            <thead>
              <tr>
                <th>Booking number</th>
                <th>Client</th>
                <th>Completed</th>
                <th>Your share</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($claimable as $vcRow): ?>
                <tr>
                  <td><span class="drawer__code"><?= e($vcRow['reference_code']) ?></span></td>
                  <td><?= e($vcRow['full_name']) ?></td>
                  <td><span class="cell-sub"><?= e(format_date($vcRow['completed_at'])) ?></span></td>
                  <td class="td-amount stock-figure">
                    <strong><?= e(money((float) $vcRow['amount'])) ?></strong>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <form method="post" class="direct-sale__foot">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $vcIsDist ? 'bundle' : 'raise' ?>">
        <button type="submit" class="btn btn--primary">
          <i class="bi bi-send" aria-hidden="true"></i>
          <?= $vcIsDist ? 'Send the bundle to C&amp;F' : 'Raise the voucher' ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Your claims</h2>
      <span class="eyebrow">Every voucher you have raised and where it got to</span>
    </div>
  </div>

  <div class="table-wrap">
    <table class="data-table" data-paged="10">
      <colgroup>
        <col style="width:10%">
        <col style="width:20%">
        <col style="width:22%">
        <col style="width:22%">
        <col style="width:26%">
      </colgroup>
      <thead>
        <tr>
          <th>#</th>
          <th>Amount</th>
          <th>Raised</th>
          <th>Where it is</th>
          <th>Reference / reason</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$myVouchers): ?>
          <tr class="row-empty">
            <td colspan="5">No entry found — you have not raised a claim yet.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($myVouchers as $vcMine): ?>
          <tr>
            <td><?= (int) $vcMine['id'] ?></td>
            <td class="td-amount stock-figure">
              <strong><?= e(money((float) $vcMine['amount'])) ?></strong>
            </td>
            <td><span class="cell-sub"><?= e(format_datetime($vcMine['raised_at'])) ?></span></td>
            <td>
              <span class="pill pill--<?= e(voucher_status_pill((string) $vcMine['status'])) ?>">
                <?= e(voucher_status_label((string) $vcMine['status'])) ?>
              </span>
            </td>
            <td>
              <span class="cell-sub">
                <?= e($vcMine['payment_reference'] ?: ($vcMine['reject_reason'] ?: '—')) ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
