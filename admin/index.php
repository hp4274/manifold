<?php
/**
 * Dashboard: totals per form, per status, and the newest submissions.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';

$user       = require_login();
$pageTitle  = 'Dashboard';
$pageLead   = 'Every submission from the website, grouped by form.';
$activeType = '';

$types     = submission_types();
$savedId   = (int) ($_GET['saved'] ?? 0);
$deletedId = (int) ($_GET['deleted'] ?? 0);

/* Newest ten across all four forms, and only ten — the dashboard is a glance at
   what has just come in, not a place to work through a backlog. Each form's own
   list under Forms pages through everything, ten at a time. */
$recent = db()->query(
    "SELECT product AS type, id, full_name AS title, email, status, created_at,
            reminder_count, reminded_at
       FROM applications
     UNION ALL
     SELECT 'contact', id, name, email, status, created_at, 0, NULL FROM contact_messages
     UNION ALL
     SELECT 'newsletter', id, email, email, status, created_at, 0, NULL FROM newsletter_subscribers
     ORDER BY created_at DESC
     LIMIT 10"
)->fetchAll();

/* full records for the rows above, so Details can open without another page */
$full = ['applications' => [], 'contact_messages' => [], 'newsletter_subscribers' => []];
$ids  = ['applications' => [], 'contact_messages' => [], 'newsletter_subscribers' => []];

foreach ($recent as $row) {
    $table = type_config($row['type'])['table'];
    $ids[$table][] = (int) $row['id'];
}

foreach ($ids as $table => $list) {
    if (!$list) {
        continue;
    }

    $in   = implode(',', array_fill(0, count($list), '?'));
    $stmt = db()->prepare('SELECT * FROM ' . $table . ' WHERE id IN (' . $in . ')');
    $stmt->execute($list);

    foreach ($stmt->fetchAll() as $record) {
        $full[$table][(int) $record['id']] = $record;
    }
}

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($savedId > 0): ?>
  <p class="alert alert--ok">Submission #<?= $savedId ?> updated.</p>
<?php endif; ?>

<?php if ($deletedId > 0): ?>
  <p class="alert alert--error">Submission #<?= $deletedId ?> deleted.</p>
<?php endif; ?>

<?php require __DIR__ . '/partials/mail-flash.php'; ?>

<div class="tiles">
  <?php foreach ($types as $key => $config): ?>
    <?php $counts = status_counts($key); ?>
    <a class="tile" href="list.php?type=<?= e($key) ?>">
      <span class="eyebrow"><?= e($config['label']) ?></span>
      <strong><?= (int) $counts['total'] ?></strong>
      <span class="tile__stats">
        <?php foreach (statuses_for($key) as $s): ?>
          <span class="tile__stat tile__stat--<?= e($s) ?>">
            <b><?= (int) $counts[$s] ?></b> <?= e(status_short($s)) ?>
          </span>
        <?php endforeach; ?>
      </span>
    </a>
  <?php endforeach; ?>
</div>

<div class="panel">
  <div class="panel__head">
    <h2>Latest submissions</h2>
    <span class="eyebrow" data-table-count>The newest 10, across all forms</span>
  </div>

  <?php if (!$recent): ?>
    <p class="empty">Nothing has been submitted yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table" id="latestTable">
        <!-- fixed widths so switching a filter label cannot reflow the columns -->
        <colgroup>
          <col style="width:6%">
          <col style="width:17%">
          <col style="width:24%">
          <col style="width:14%">
          <col style="width:13%">
          <col style="width:17%">
          <col style="width:9%">
        </colgroup>
        <thead>
          <tr>
            <th>#</th>
            <th class="th-filter-cell">
              <button type="button" class="th-filter" data-filter="form" data-default="Form" title="Click to filter by form">
                <span class="th-filter__label">Form</span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
              </button>
            </th>
            <th>Name / email</th>
            <th>Received</th>
            <th class="th-filter-cell">
              <button type="button" class="th-filter" data-filter="status" data-default="Status" title="Click to filter by status">
                <span class="th-filter__label">Status</span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
              </button>
            </th>
            <th>Actions</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php $seq = 0; ?>
          <?php foreach ($recent as $row): ?>
            <?php $seq++; ?>
            <tr id="row-<?= (int) $row['id'] ?>"
                data-form="<?= e($row['type']) ?>"
                data-form-label="<?= e($types[$row['type']]['label']) ?>"
                data-status="<?= e($row['status']) ?>"
                data-status-label="<?= e(status_short($row['status'])) ?>">
              <td class="td-seq" data-seq><?= $seq ?></td>
              <td><?= e($types[$row['type']]['label']) ?></td>
              <td>
                <?php /* a newsletter signup has nothing but an address, so show it once */ ?>
                <?php if ($row['title'] !== $row['email']): ?>
                  <strong><?= e($row['title']) ?></strong><br>
                <?php endif; ?>
                <a href="mailto:<?= e($row['email']) ?>"><?= e($row['email']) ?></a>
              </td>
              <td><?= e(format_datetime($row['created_at'])) ?></td>
              <td><span class="pill pill--<?= e($row['status']) ?>"><?= e(status_short($row['status'])) ?></span></td>
              <td>
                <?php
                  $rowType   = $row['type'];
                  $returnUrl = 'index.php';
                  require __DIR__ . '/partials/row-actions.php';
                ?>
              </td>
              <td class="td-actions">
                <button type="button" class="row-toggle"
                        data-drawer="detail-<?= e($row['type']) ?>-<?= (int) $row['id'] ?>"
                        data-title="<?= e($row['title']) ?>"
                        data-meta="<?= e($types[$row['type']]['label']) ?> · received <?= e(format_datetime($row['created_at'])) ?>"
                        data-status="<?= e($row['status']) ?>"
                        data-status-label="<?= e(status_short($row['status'])) ?>">
                  Details <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="empty" data-table-empty hidden>Nothing matches those filters.</p>
    </div>
  <?php endif; ?>
</div>

<!-- ============ detail drawers ============ -->
<?php foreach ($recent as $row): ?>
  <?php
    $table  = type_config($row['type'])['table'];
    $record = $full[$table][(int) $row['id']] ?? null;

    if (!$record) {
        continue;
    }

    $srcType   = $row['type'];
    $srcRow    = $record;
    $srcReturn = 'index.php';
    require __DIR__ . '/partials/drawer-source.php';
  ?>
<?php endforeach; ?>

<?php require __DIR__ . '/partials/drawer.php'; ?>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
