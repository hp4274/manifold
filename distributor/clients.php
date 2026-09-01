<?php
/**
 * Every sale under one distributor — their own and their dealers'.
 *
 * Read-only by design. distributor_client_view() is what decides that: receipts,
 * payment proofs, UTR references, identity documents and home addresses stay in
 * the admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dist      = require_distributor();
$distId    = (int) $dist['id'];
$pageTitle = 'Clients';
$pageLead  = 'Everything sold under you, and who sold it.';
$activeNav = 'clients';

$allClients = distributor_own_clients($distId);

/* the table shows one page; Progress is worked in the header */
$paging  = paged(count($allClients), $_GET['page'] ?? 1);
$clients = array_slice($allClients, $paging['offset'], LIST_PER_PAGE);

$counts = ['all' => count($allClients), 'earned' => 0, 'waiting' => 0, 'rejected' => 0, 'direct' => 0];

foreach ($allClients as $client) {
    if ($client['status'] === 'rejected') {
        $counts['rejected']++;
    } elseif ($client['earned']) {
        $counts['earned']++;
    } else {
        $counts['waiting']++;
    }

    if ($client['dealer_code'] === '') {
        $counts['direct']++;
    }
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
    <span class="eyebrow">Sales under you</span>
    <strong><?= (int) $counts['all'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= (int) $counts['direct'] ?> of them sold by you directly</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Earning you commission</span>
    <strong><?= (int) $counts['earned'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">complete, both payments verified</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Still in progress</span>
    <strong><?= (int) $counts['waiting'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">worth a call — commission follows completion</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Not proceeding</span>
    <strong><?= (int) $counts['rejected'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">closed by the office</span>
    </span>
  </span>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Clients</h2>
      <span class="eyebrow" data-table-count><?= (int) $paging['from'] ?>–<?= (int) $paging['to'] ?>
        of <?= (int) $paging['total'] ?></span>
    </div>
  </div>

  <?php if (!$clients): ?>
    <p class="empty">
      Nothing has been sold under you yet. Use <strong>Add a client</strong> above to open the form with
      your code in it, or ask the office to put a dealer under you.
    </p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--dealer-clients is-filterable">
        <colgroup>
          <col style="width:24%">
          <col style="width:14%">
          <col style="width:16%">
          <col style="width:26%">
          <col style="width:20%">
        </colgroup>
        <thead>
          <tr>
            <th>Client</th>
            <th>Booking number</th>
            <th>Sold by</th>
            <?php /* the header is the control: click it to step through the
                     stages actually present, and again to clear */ ?>
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
          <?php foreach ($clients as $client): ?>
            <?php $progress = partner_progress($client['status']); ?>
            <tr data-progress="<?= e($client['status']) ?>"
                data-progress-label="<?= e(status_short($client['status'])) ?>">
              <td>
                <div class="cell-stack">
                  <strong><?= e($client['full_name']) ?></strong>
                  <span class="cell-sub"><?= e($client['email']) ?></span>
                  <?php if ($client['mobile_number'] !== ''): ?>
                    <span class="cell-sub"><?= e($client['mobile_number']) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="cell-stack">
                  <span class="drawer__code"><?= e($client['reference_code']) ?></span>
                  <span class="cell-sub"><?= e(format_datetime($client['created_at'])) ?></span>
                </div>
              </td>
              <td>
                <?php if ($client['dealer_code'] !== ''): ?>
                  <div class="cell-stack">
                    <span><?= e($client['dealer_name']) ?></span>
                    <span class="cell-sub"><?= e($client['dealer_code']) ?> · override</span>
                  </div>
                <?php else: ?>
                  <span class="cell-sub">You · direct</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="cell-stack">
                  <span class="pill pill--<?= e($client['status']) ?>"><?= e($progress['label']) ?></span>
                  <?php if ($progress['step'] > 0): ?>
                    <?php /* how far along, never how much is outstanding */ ?>
                    <span class="progress-bar" role="img"
                          aria-label="Stage <?= (int) $progress['step'] ?> of <?= (int) $progress['of'] ?>">
                      <span class="progress-bar__fill"
                            style="width:<?= (int) round($progress['step'] / $progress['of'] * 100) ?>%"></span>
                    </span>
                    <span class="cell-sub">Stage <?= (int) $progress['step'] ?> of
                      <?= (int) $progress['of'] ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="td-amount">
                <strong><?= e(money($client['distributor_commission'])) ?></strong>
                <span class="cell-sub">
                  <?= $client['earned'] ? 'earned' : 'once the sale completes' ?>
                </span>
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
      $pagerBase  = 'clients';
      require __DIR__ . '/../admin/partials/pager.php';
    ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
