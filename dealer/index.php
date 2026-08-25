<?php
/**
 * Dealer dashboard: what they have sold, what it is worth, what is still owed.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dealer    = require_dealer();
$dealerId  = (int) $dealer['id'];
$pageTitle = 'Dashboard';
$pageLead  = 'Everything booked against ' . $dealer['dealer_code'] . '.';
$activeNav = 'dashboard';

$totals  = dealer_totals($dealerId);
$clients = dealer_own_clients($dealerId);
$payouts = dealer_payouts($dealerId);
$recent  = array_slice($clients, 0, 5);

require __DIR__ . '/partials/layout-top.php';
?>

<div class="tiles">
  <span class="tile">
    <span class="eyebrow">Units sold</span>
    <strong><?= (int) $totals['confirmed'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= (int) $totals['sales'] ?> applied, <?= (int) $totals['confirmed'] ?>
        have paid their booking</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Commission earned</span>
    <strong><?= e(money($totals['earned'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">counted once a customer's booking payment clears</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Paid to you</span>
    <strong><?= e(money($totals['paid'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= count($payouts) ?> transfer<?= count($payouts) === 1 ? '' : 's' ?></span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Still owed to you</span>
    <strong><?= e(money($totals['remaining'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">earned minus paid</span>
    </span>
  </span>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Your link</h2>
      <span class="eyebrow">every sale has to start here</span>
    </div>
  </div>

  <div class="panel__body">
    <div class="cell-stack">
      <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $product => $label): ?>
        <?php $link = referral_link((string) $dealer['dealer_code'], $product); ?>
        <div class="dealer-link">
          <code><?= e($link) ?></code>
          <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= e($link) ?>">
            <i class="bi bi-clipboard" aria-hidden="true"></i> Copy
          </button>
          <a class="btn btn--ghost btn--sm" href="<?= e($link) ?>" target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Open
          </a>
        </div>
      <?php endforeach; ?>
      <span class="field-hint">
        Anybody who opens one of these finds <?= e($dealer['dealer_code']) ?> already in the referral box
        and cannot change it. A customer who fills the form in any other way will not be counted as yours.
      </span>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Latest clients</h2>
      <span class="eyebrow"><?= count($clients) ?> in total</span>
    </div>
    <a class="btn btn--ghost btn--sm" href="clients.php">See all</a>
  </div>

  <?php if (!$recent): ?>
    <p class="empty">Nobody has applied through your link yet. Share it and they will appear here.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--dealer-clients">
        <colgroup>
          <col style="width:30%">
          <col style="width:18%">
          <col style="width:12%">
          <col style="width:22%">
          <col style="width:18%">
        </colgroup>
        <thead>
          <tr>
            <th>Client</th>
            <th>Booking number</th>
            <th>Product</th>
            <th>Progress</th>
            <th>Your commission</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $client): ?>
            <?php $progress = dealer_progress($client['status']); ?>
            <tr>
              <td>
                <div class="cell-stack">
                  <strong><?= e($client['full_name']) ?></strong>
                  <span class="cell-sub"><?= e(format_datetime($client['created_at'])) ?></span>
                </div>
              </td>
              <td><span class="drawer__code"><?= e($client['reference_code']) ?></span></td>
              <td><?= e(product_label($client['product'])) ?></td>
              <td>
                <span class="pill pill--<?= e($client['status']) ?>"><?= e($progress['label']) ?></span>
              </td>
              <td class="td-amount">
                <strong><?= e(money($client['dealer_commission'])) ?></strong>
                <?php if (!$client['earned']): ?>
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

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
