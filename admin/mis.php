<?php
/**
 * Clients MIS: every application, with the dealer and distributor behind it.
 *
 * The per-form lists already show this one form at a time; this is the one
 * screen that puts every client next to who sold them, so the office can
 * answer "who does this dealer's distributor answer to" without opening a
 * partner's own page.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Clients MIS';
$pageLead   = 'Every client, with the dealer and distributor behind the sale.';
$activeType = 'mis';

$product = (string) ($_GET['product'] ?? '');
if (!in_array($product, ['stove', 'tuktuk'], true)) {
    $product = '';
}

$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, APPLICATION_STATUSES, true)) {
    $status = '';
}

$dealerId      = (int) ($_GET['dealer'] ?? 0);
$distributorId = (int) ($_GET['distributor'] ?? 0);
$q             = trim((string) ($_GET['q'] ?? ''));

$where  = [];
$params = [];

if ($product !== '') {
    $where[]  = 'a.product = ?';
    $params[] = $product;
}

if ($status !== '') {
    $where[]  = 'a.status = ?';
    $params[] = $status;
}

if ($dealerId > 0) {
    $where[]  = 'a.dealer_id = ?';
    $params[] = $dealerId;
}

if ($distributorId > 0) {
    $where[]  = 'a.distributor_id = ?';
    $params[] = $distributorId;
}

if ($q !== '') {
    $where[] = '(a.full_name LIKE ? OR a.email LIKE ? OR a.mobile_number LIKE ? OR a.reference_code LIKE ?)';
    $like    = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare('SELECT COUNT(*) FROM applications a' . $clause);
$countStmt->execute($params);
$paging = paged((int) $countStmt->fetchColumn(), $_GET['page'] ?? 1);

$stmt = db()->prepare(
    'SELECT a.*, d.full_name AS dealer_name, d.dealer_code,
            x.full_name AS distributor_name, x.distributor_code
       FROM applications a
       LEFT JOIN dealers d ON d.id = a.dealer_id
       LEFT JOIN distributors x ON x.id = a.distributor_id'
    . $clause . ' ORDER BY a.created_at DESC LIMIT ' . $paging['perPage'] . ' OFFSET ' . $paging['offset']
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

/* the tiles count the whole table, not this page of it */
$totals = db()->query(
    "SELECT COUNT(*) AS total,
            SUM(dealer_id IS NOT NULL) AS via_dealer,
            SUM(distributor_id IS NOT NULL AND dealer_id IS NULL) AS via_distributor,
            SUM(dealer_id IS NULL AND distributor_id IS NULL) AS direct
       FROM applications"
)->fetch();

$dealerChoices = db()->query('SELECT id, full_name, dealer_code FROM dealers ORDER BY full_name')->fetchAll();
$distributorChoices = db()->query('SELECT id, full_name, distributor_code FROM distributors ORDER BY full_name')->fetchAll();

/** The same list with one control changed — paging starts over. */
$misFilterUrl = static function (string $nextProduct) use ($status, $dealerId, $distributorId, $q): string {
    $query = array_filter([
        'product'     => $nextProduct,
        'status'      => $status,
        'dealer'      => $dealerId > 0 ? (string) $dealerId : '',
        'distributor' => $distributorId > 0 ? (string) $distributorId : '',
        'q'           => $q,
    ], static fn (string $v): bool => $v !== '');

    return 'mis' . ($query ? '?' . http_build_query($query) : '');
};

$misUrl = static fn (): string => $misFilterUrl($product);

$productSteps = ['', 'stove', 'tuktuk'];
$productNext  = $productSteps[(array_search($product, $productSteps, true) + 1) % count($productSteps)];
$productLabel = ['stove' => 'Stove', 'tuktuk' => 'TukTuk'];

require __DIR__ . '/partials/layout-top.php';
?>

<div class="tiles">
  <span class="tile">
    <span class="eyebrow">All clients</span>
    <strong><?= (int) $totals['total'] ?></strong>
  </span>
  <span class="tile">
    <span class="eyebrow">Via a dealer</span>
    <strong><?= (int) $totals['via_dealer'] ?></strong>
  </span>
  <span class="tile">
    <span class="eyebrow">Via a distributor</span>
    <strong><?= (int) $totals['via_distributor'] ?></strong>
  </span>
  <span class="tile">
    <span class="eyebrow">Direct / website</span>
    <strong><?= (int) $totals['direct'] ?></strong>
  </span>
</div>

<div data-live-list data-live-quiet>
<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Clients</h2>
      <span class="eyebrow">
        <?= (int) $paging['from'] ?>–<?= (int) $paging['to'] ?> of <?= (int) $paging['total'] ?>
      </span>
    </div>
    <div class="panel__head-tools">
      <form method="get" action="mis" class="admin-search-box" data-live-form>
        <?php if ($product !== ''): ?><input type="hidden" name="product" value="<?= e($product) ?>"><?php endif; ?>
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <?php if ($dealerId > 0): ?><input type="hidden" name="dealer" value="<?= $dealerId ?>"><?php endif; ?>
        <?php if ($distributorId > 0): ?><input type="hidden" name="distributor" value="<?= $distributorId ?>"><?php endif; ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input type="search" name="q" class="admin-search-input" value="<?= e($q) ?>" placeholder="Search by name, email, phone or code...">
      </form>

      <form method="get" class="th-picker" data-live-form data-base="mis.php">
        <?php if ($product !== ''): ?><input type="hidden" name="product" value="<?= e($product) ?>"><?php endif; ?>
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <?php if ($dealerId > 0): ?><input type="hidden" name="dealer" value="<?= $dealerId ?>"><?php endif; ?>
        <?php if ($distributorId > 0): ?><input type="hidden" name="distributor" value="<?= $distributorId ?>"><?php endif; ?>
        <label class="visually-hidden" for="statusPick">Filter by status</label>
        <select id="statusPick" name="status" class="th-select<?= $status !== '' ? ' is-filtered' : '' ?>">
          <option value="">All statuses</option>
          <?php foreach (APPLICATION_STATUSES as $s): ?>
            <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn--ghost btn--sm">Go</button></noscript>
      </form>

      <form method="get" class="th-picker" data-live-form data-base="mis.php">
        <?php if ($product !== ''): ?><input type="hidden" name="product" value="<?= e($product) ?>"><?php endif; ?>
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <?php if ($distributorId > 0): ?><input type="hidden" name="distributor" value="<?= $distributorId ?>"><?php endif; ?>
        <label class="visually-hidden" for="dealerPick">Filter by dealer</label>
        <select id="dealerPick" name="dealer" class="th-select<?= $dealerId > 0 ? ' is-filtered' : '' ?>">
          <option value="0">All dealers</option>
          <?php foreach ($dealerChoices as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= $dealerId === (int) $d['id'] ? 'selected' : '' ?>>
              <?= e($d['full_name']) ?><?= $d['dealer_code'] ? ' (' . e($d['dealer_code']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn--ghost btn--sm">Go</button></noscript>
      </form>

      <form method="get" class="th-picker" data-live-form data-base="mis.php">
        <?php if ($product !== ''): ?><input type="hidden" name="product" value="<?= e($product) ?>"><?php endif; ?>
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <?php if ($dealerId > 0): ?><input type="hidden" name="dealer" value="<?= $dealerId ?>"><?php endif; ?>
        <label class="visually-hidden" for="distributorPick">Filter by distributor</label>
        <select id="distributorPick" name="distributor" class="th-select<?= $distributorId > 0 ? ' is-filtered' : '' ?>">
          <option value="0">All distributors</option>
          <?php foreach ($distributorChoices as $x): ?>
            <option value="<?= (int) $x['id'] ?>" <?= $distributorId === (int) $x['id'] ? 'selected' : '' ?>>
              <?= e($x['full_name']) ?><?= $x['distributor_code'] ? ' (' . e($x['distributor_code']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn--ghost btn--sm">Go</button></noscript>
      </form>

      <?php $exportKind = 'clients'; require __DIR__ . '/partials/export-bar.php'; ?>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table--fixed">
      <colgroup>
        <col style="width:5%">
        <col style="width:17%">
        <col style="width:16%">
        <col style="width:10%">
        <col style="width:15%">
        <col style="width:15%">
        <col style="width:10%">
        <col style="width:12%">
      </colgroup>
      <thead>
        <tr>
          <th>#</th>
          <th>Client</th>
          <th>Contact</th>
          <th class="th-filter-cell">
            <a class="th-filter<?= $product === '' ? '' : ' is-filtered' ?>"
               href="<?= e($misFilterUrl($productNext)) ?>"
               title="Click to filter by product">
              <span class="th-filter__label"><?= $product === '' ? 'Product' : e($productLabel[$product]) ?></span>
              <i class="bi bi-chevron-expand" aria-hidden="true"></i>
            </a>
          </th>
          <th>Dealer</th>
          <th>Distributor</th>
          <th>Status</th>
          <th>Received</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr class="row-empty">
            <td colspan="8">No entry found — nothing matches this filter.</td>
          </tr>
        <?php endif; ?>

        <?php $seq = $paging['offset']; ?>
        <?php foreach ($rows as $row): ?>
          <?php $rowId = (int) $row['id']; $seq++; ?>
          <tr id="row-<?= $rowId ?>">
            <td class="td-seq"><?= $seq ?></td>
            <td>
              <strong title="<?= e($row['full_name']) ?>"><?= e($row['full_name']) ?></strong>
              <div class="cell-sub"><?= e($row['reference_code'] ?: '—') ?></div>
            </td>
            <td>
              <a href="mailto:<?= e($row['email']) ?>"><?= e($row['email']) ?></a>
              <?php if (!empty($row['mobile_number'])): ?>
                <a href="tel:<?= e(preg_replace('/\s+/', '', (string) $row['mobile_number'])) ?>"><?= e($row['mobile_number']) ?></a>
              <?php endif; ?>
            </td>
            <td><?= e($productLabel[$row['product']] ?? $row['product']) ?></td>
            <td>
              <?php if ($row['dealer_name']): ?>
                <div class="cell-stack">
                  <span><?= e($row['dealer_name']) ?></span>
                  <span class="cell-sub"><?= e((string) $row['dealer_code']) ?></span>
                </div>
              <?php else: ?>
                <span class="cell-none">-</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($row['distributor_name']): ?>
                <div class="cell-stack">
                  <span><?= e($row['distributor_name']) ?></span>
                  <span class="cell-sub"><?= e((string) $row['distributor_code']) ?></span>
                </div>
              <?php else: ?>
                <span class="cell-none">-</span>
              <?php endif; ?>
            </td>
            <td><span class="pill pill--<?= e($row['status']) ?>"><?= e(status_short($row['status'])) ?></span></td>
            <td class="td-when"><?= e(format_datetime($row['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($rows): ?>
    <?php
      $pagerPage  = $paging['page'];
      $pagerPages = $paging['pages'];
      $pagerTotal = $paging['total'];
      $pagerFrom  = $paging['from'];
      $pagerTo    = $paging['to'];
      $pagerBase  = $misUrl();
      require __DIR__ . '/partials/pager.php';
    ?>
  <?php endif; ?>
</div>
</div>

<?php
  $exportKind  = 'clients';
  $exportExtra = array_filter([
      'product'     => $product,
      'status'      => $status,
      'dealer'      => $dealerId > 0 ? (string) $dealerId : '',
      'distributor' => $distributorId > 0 ? (string) $distributorId : '',
      'q'           => $q,
  ], static fn (string $v): bool => $v !== '');
  require __DIR__ . '/partials/export-modal.php';
?>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
