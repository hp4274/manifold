<?php
/**
 * Distributor dashboard: the network, what it has sold, and what is owed.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dist      = require_distributor();
$distId    = (int) $dist['id'];
$pageTitle = 'Dashboard';
$pageLead  = 'Everything booked under ' . $dist['distributor_code'] . '.';
$activeNav = 'dashboard';

$totals  = distributor_totals($distId);
$dealers = distributor_own_dealers($distId);
$clients = distributor_own_clients($distId);
$payouts = distributor_payouts($distId);

$recent  = array_slice($clients, 0, 5);

/* their own sales: the ones with no dealer on them, which is what the tile
   means by "sold by you". The name is what the trimmed view carries — a
   distributor never sees a dealer id — and it is what the table below reads
   to print "You · direct" on the same rows. */
$direct = count(array_filter(
    $clients,
    static fn (array $client): bool => ($client['dealer_name'] ?? '') === ''
));

$dashStock = stock_balance('distributor', $distId);

require __DIR__ . '/partials/layout-top.php';
?>

<div class="tiles">
  <?php /* what is on the shelf right now, which is the figure that decides
           whether a sale can be recorded at all */ ?>
  <span class="tile">
    <span class="eyebrow">Units in stock</span>
    <strong class="stock-figure"><?= (int) $dashStock['units'] ?></strong>
    <?php /* the total is what you have; the split is what you can actually
             sell, which is the question somebody opens this page with */ ?>
    <span class="stock-split">
      <?php foreach (['stove' => 'stoves', 'tuktuk' => 'kits'] as $tileKey => $tileLabel): ?>
        <span class="stock-split__item<?= (int) $dashStock[$tileKey]['units'] === 0
            ? ' stock-split__item--empty' : '' ?>">
          <b><?= (int) $dashStock[$tileKey]['units'] ?></b> <?= e($tileLabel) ?>
        </span>
      <?php endforeach; ?>
    </span>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money((float) $dashStock['value'])) ?> at cost ·
        <a href="stock.php">Order more</a></span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Dealers</span>
    <strong><?= count($dealers) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">every sale of theirs earns you the override</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Completed sales</span>
    <strong><?= (int) $totals['confirmed'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= (int) $totals['sales'] ?> in total, <?= (int) $direct ?> sold by you</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Commission earned</span>
    <strong><?= e(money($totals['earned'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money($totals['pipeline'])) ?> riding on sales in progress</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Still owed to you</span>
    <strong><?= e(money($totals['remaining'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money($totals['paid'])) ?> paid so far</span>
    </span>
  </span>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Your link</h2>
      <span class="eyebrow">a sale through this one is yours directly</span>
    </div>
  </div>

  <div class="panel__body">
    <div class="cell-stack">
      <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $product => $label): ?>
        <?php $link = referral_link((string) $dist['distributor_code'], $product); ?>
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
        A customer who uses this link is yours directly and no dealer is involved. Your dealers have
        their own links — those earn them their share and you the override.
      </span>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Latest sales</h2>
      <span class="eyebrow" data-table-count><?= count($clients) ?> in total</span>
    </div>
    <a class="btn btn--ghost btn--sm" href="clients.php">See all</a>
  </div>

  <?php if (!$recent): ?>
    <p class="empty">
      Nothing has been sold under you yet. Share your link, or put a dealer under you and share theirs.
    </p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--dealer-clients is-filterable">
        <colgroup>
          <col style="width:28%">
          <col style="width:17%">
          <col style="width:20%">
          <col style="width:18%">
          <col style="width:17%">
        </colgroup>
        <thead>
          <tr>
            <th>Client</th>
            <th>Booking number</th>
            <th>Sold by</th>
            <?php /* the header is the control, the same as on Clients */ ?>
            <th class="th-filter-cell">
              <button type="button" class="th-filter" data-filter="progress" data-default="Progress"
                      title="Click to filter by progress">
                <span class="th-filter__label">Progress</span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
              </button>
            </th>
            <th>Your share</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $client): ?>
            <?php $progress = partner_progress($client['status']); ?>
            <tr data-progress="<?= e($client['status']) ?>"
                data-progress-label="<?= e(status_short($client['status'])) ?>">
              <td>
                <div class="cell-stack">
                  <strong><?= e($client['full_name']) ?></strong>
                  <span class="cell-sub"><?= e(format_datetime($client['created_at'])) ?></span>
                </div>
              </td>
              <td><span class="drawer__code"><?= e($client['reference_code']) ?></span></td>
              <td>
                <?php if ($client['dealer_code'] !== ''): ?>
                  <div class="cell-stack">
                    <span><?= e($client['dealer_name']) ?></span>
                    <span class="cell-sub">override</span>
                  </div>
                <?php else: ?>
                  <span class="cell-sub">You · direct</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="pill pill--<?= e($client['status']) ?>"><?= e($progress['label']) ?></span>
              </td>
              <td class="td-amount">
                <strong><?= e(money($client['distributor_commission'])) ?></strong>
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
