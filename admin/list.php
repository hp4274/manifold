<?php
/**
 * One form's submissions, filterable by status.
 * Each row switches its own status and expands to show the full record.
 * list.php?type=stove|tuktuk|contact|newsletter&status=new|accepted|contacted|rejected
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';

$user   = require_login();
$type   = (string) ($_GET['type'] ?? 'stove');
$config = type_config($type);

$status = (string) ($_GET['status'] ?? '');
if ($status !== '' && !in_array($status, statuses_for($type), true)) {
    $status = '';
}

$where  = [];
$params = [];

if ($config['table'] === 'applications') {
    $where[]  = 'product = ?';
    $params[] = $type;
}

if ($status !== '') {
    $where[]  = 'status = ?';
    $params[] = $status;
}

$sql = 'SELECT * FROM ' . $config['table'];
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY created_at DESC LIMIT 300';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$groups    = field_groups($type);
$counts    = status_counts($type);
$savedId   = (int) ($_GET['saved'] ?? 0);
$deletedId = (int) ($_GET['deleted'] ?? 0);
$returnUrl = 'list.php?type=' . urlencode($type) . ($status !== '' ? '&status=' . urlencode($status) : '');

$pageTitle  = $config['label'];
$pageLead   = $counts['total'] . ' total · ' . $counts['new'] . ' waiting to be reviewed';
$activeType = $type;

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($savedId > 0): ?>
  <p class="alert alert--ok">Submission #<?= $savedId ?> updated.</p>
<?php endif; ?>

<?php if ($deletedId > 0): ?>
  <p class="alert alert--error">Submission #<?= $deletedId ?> deleted.</p>
<?php endif; ?>

<?php require __DIR__ . '/partials/mail-flash.php'; ?>

<div class="filters">
  <a href="list.php?type=<?= e($type) ?>" class="<?= $status === '' ? 'is-active' : '' ?>">
    All <?= (int) $counts['total'] ?>
  </a>
  <?php foreach (statuses_for($type) as $s): ?>
    <a href="list.php?type=<?= e($type) ?>&amp;status=<?= e($s) ?>" class="<?= $status === $s ? 'is-active' : '' ?>">
      <?= e(status_label($s)) ?> <?= (int) $counts[$s] ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="panel">
  <div class="panel__head">
    <h2><?= $status === '' ? 'All submissions' : e(status_label($status)) ?></h2>
    <span class="eyebrow"><?= count($rows) ?> shown</span>
  </div>

  <?php if (!$rows): ?>
    <p class="empty">Nothing here yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th><?= $type === 'newsletter' ? 'Email' : 'Name' ?></th>
            <?php if ($type !== 'newsletter'): ?>
              <th>Contact</th>
            <?php endif; ?>
            <th>Received</th>
            <th>Status</th>
            <th>Actions</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php $seq = 0; ?>
          <?php foreach ($rows as $row): ?>
            <?php $rowId = (int) $row['id']; $seq++; ?>
            <tr id="row-<?= $rowId ?>" class="<?= $savedId === $rowId ? 'is-flagged' : '' ?>">
              <td class="td-seq"><?= $seq ?></td>
              <td><strong><?= e(record_title($type, $row)) ?></strong></td>
              <?php if ($type !== 'newsletter'): ?>
                <td>
                  <a href="mailto:<?= e($row['email']) ?>"><?= e($row['email']) ?></a>
                  <?php $phone = $row['mobile_number'] ?? $row['phone'] ?? ''; ?>
                  <?php if ($phone !== ''): ?>
                    <br><a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>"><?= e($phone) ?></a>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
              <td><?= e(format_datetime($row['created_at'])) ?></td>
              <td><span class="pill pill--<?= e($row['status']) ?>"><?= e(status_short($row['status'])) ?></span></td>
              <td>
                <?php $rowType = $type; require __DIR__ . '/partials/row-actions.php'; ?>
              </td>
              <td class="td-actions">
                <button type="button" class="row-toggle" data-drawer="detail-<?= e($type) ?>-<?= $rowId ?>"
                        data-title="<?= e(record_title($type, $row)) ?>"
                        data-meta="<?= e($config['label']) ?> · received <?= e(format_datetime($row['created_at'])) ?>"
                        data-status="<?= e($row['status']) ?>"
                        data-status-label="<?= e(status_short($row['status'])) ?>">
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

<!-- ============ detail drawers ============ -->
<?php if ($rows): ?>
  <?php foreach ($rows as $row): ?>
    <?php
      $srcType   = $type;
      $srcRow    = $row;
      require __DIR__ . '/partials/drawer-source.php';
    ?>
  <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/drawer.php'; ?>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
