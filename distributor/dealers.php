<?php
/**
 * The dealers under one distributor, and how each of them is doing.
 *
 * Reading only. Who answers to whom is the office's decision — a distributor
 * asks them to assign a dealer, and it happens under Distributors in the admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dist      = require_distributor();
$distId    = (int) $dist['id'];
$pageTitle = 'Dealers';
$pageLead  = 'Who sells under you, and what they have brought in.';
$activeNav = 'dealers';

$allDealers = distributor_own_dealers($distId);

/* how much of their allowance is used, counting the ones still waiting */
$held  = distributor_dealer_count($distId);
$limit = dealer_limit();
$room  = $held < $limit;

/* the table shows one page; State and "Earned you" are worked in the header */
$paging  = paged(count($allDealers), $_GET['page'] ?? 1);
$dealers = array_slice($allDealers, $paging['offset'], LIST_PER_PAGE);

$activeCount = count(array_filter($allDealers, static fn (array $d): bool => $d['is_active']));

$active            = 0;
$overrideTotal     = 0.0;
$overridePipeline  = 0.0;

foreach ($allDealers as $dealer) {
    if ($dealer['is_active']) {
        $active++;
    }

    $overrideTotal    += $dealer['override'];
    $overridePipeline += $dealer['pipeline'];
}

$flash = (string) ($_SESSION['distributor_flash'] ?? '');
unset($_SESSION['distributor_flash']);

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?>
  <p class="alert alert--ok"><?= e($flash) ?></p>
<?php endif; ?>

<div class="tiles">
  <span class="tile">
    <span class="eyebrow">Dealers</span>
    <strong><?= count($allDealers) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= (int) $activeCount ?> currently selling</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Earned by your dealers</span>
    <strong><?= e(money($overrideTotal)) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money($overridePipeline)) ?> riding on sales in progress</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Your override</span>
    <strong><?= e(money_short(commission_value('override', 'stove'))) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">on every stove one of them sells,
        <?= e(money_short(commission_value('override', 'tuktuk'))) ?> on a kit</span>
    </span>
  </span>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Dealers</h2>
      <span class="eyebrow" data-table-count><?= (int) $paging['from'] ?>–<?= (int) $paging['to'] ?>
        of <?= (int) $paging['total'] ?> · <?= (int) $held ?> of <?= (int) $limit ?> allowed</span>
    </div>
    <?php if ($room): ?>
      <a class="btn-add" href="add-dealer">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a dealer
      </a>
    <?php else: ?>
      <span class="eyebrow">At the limit of <?= (int) $limit ?></span>
    <?php endif; ?>
  </div>

  <?php if (!$dealers): ?>
    <p class="empty">
      No dealers under you yet. The office assigns them — ask them to put a dealer under
      <?= e($dist['distributor_code']) ?>.
    </p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--dealer-clients is-filterable">
        <colgroup>
          <col style="width:23%">
          <col style="width:12%">
          <col style="width:11%">
          <col style="width:12%">
          <col style="width:13%">
          <col style="width:19%">
          <col style="width:10%">
        </colgroup>
        <thead>
          <tr>
            <th>Dealer</th>
            <th>Code</th>
            <th>Where</th>
            <?php /* the header is the control: click it to step through the
                     states actually present, and again to clear */ ?>
            <th class="th-filter-cell">
              <button type="button" class="th-filter" data-filter="state" data-default="State"
                      title="Click to filter by state">
                <span class="th-filter__label">State</span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
              </button>
            </th>
            <th>Completed sales</th>
            <?php /* and this one sorts: high to low, low to high, then back */ ?>
            <th class="th-filter-cell">
              <button type="button" class="th-filter" data-sort="override" data-default="Commission earned you"
                      title="Click to sort by what each dealer has earned you">
                <span class="th-filter__label">Commission earned you</span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
              </button>
            </th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dealers as $dealer): ?>
            <tr data-state="<?= $dealer['is_active'] ? 'active' : 'stopped' ?>"
                data-state-label="<?= $dealer['is_active'] ? 'Selling' : 'Stopped' ?>"
                data-override="<?= e(number_format($dealer['override'], 2, '.', '')) ?>">
              <td>
                <div class="cell-stack">
                  <strong><?= e($dealer['full_name']) ?></strong>
                  <?php if ($dealer['company'] !== ''): ?>
                    <span class="cell-sub"><?= e($dealer['company']) ?></span>
                  <?php endif; ?>
                  <span class="cell-sub">
                    <?php if ($dealer['mobile_number'] !== ''): ?>
                      <?= e($dealer['mobile_number']) ?> ·
                    <?php endif; ?>
                    <?= e($dealer['email'] ?: 'no email') ?>
                  </span>
                </div>
              </td>
              <td>
                <?php /* a dealer still waiting has no code — the office issues one
                         when it approves them */ ?>
                <?php if ($dealer['dealer_code']): ?>
                  <span class="drawer__code"><?= e((string) $dealer['dealer_code']) ?></span>
                <?php else: ?>
                  <span class="cell-sub">on approval</span>
                <?php endif; ?>
              </td>
              <td><?= e($dealer['city'] ?: '—') ?></td>
              <td>
                <?php if ($dealer['approval_status'] !== 'approved'): ?>
                  <?php /* their code books nothing until the office decides */ ?>
                  <span class="pill pill--<?= $dealer['approval_status'] === 'pending'
                      ? 'booking_review' : 'rejected' ?>">
                    <?= e(approval_label((string) $dealer['approval_status'])) ?>
                  </span>
                <?php else: ?>
                  <span class="pill pill--<?= $dealer['is_active'] ? 'accepted' : 'rejected' ?>">
                    <?= $dealer['is_active'] ? 'Active' : 'Stopped' ?>
                  </span>
                <?php endif; ?>
              </td>
              <td class="td-amount">
                <strong><?= (int) $dealer['confirmed'] ?></strong>
                <span class="cell-sub">of <?= (int) $dealer['sales'] ?> applied</span>
              </td>
              <td class="td-amount">
                <strong><?= e(money($dealer['override'])) ?></strong>
                <?php if ($dealer['pipeline'] > 0): ?>
                  <?php /* what is still riding on their sales in progress — not owed
                           yet, but the reason to keep chasing this dealer */ ?>
                  <span class="cell-sub"><?= e(money($dealer['pipeline'])) ?> in progress</span>
                <?php elseif ($dealer['override'] <= 0): ?>
                  <span class="cell-sub">nothing yet</span>
                <?php endif; ?>
              </td>
              <td class="td-actions">
                <?php /* they signed this dealer up, so a wrong pin code or a
                         changed account is theirs to fix */ ?>
                <a class="btn btn--ghost btn--sm" href="edit-dealer?id=<?= (int) $dealer['id'] ?>">
                  Edit
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php
      $pagerPage  = $paging['page'];
      $pagerPages = $paging['pages'];
      $pagerTotal = $paging['total'];
      $pagerFrom  = $paging['from'];
      $pagerTo    = $paging['to'];
      $pagerBase  = 'dealers';
      require __DIR__ . '/../admin/partials/pager.php';
    ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
