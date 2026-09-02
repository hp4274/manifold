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
/* what the action that redirected here left behind — read once, so a reload
   does not repeat a confirmation for something that happened once */
$flash     = admin_flash_take();
/* the name and what was done to them, composed where it happened — see list.php */
$savedNote   = (string) ($flash['saved_note'] ?? '');
$deletedNote = (string) ($flash['deleted_note'] ?? '');
$mailFlash = (string) ($flash['mail'] ?? '');
$payFlash  = (string) ($flash['pay'] ?? '');

/* Newest ten across all four forms, and only ten — the dashboard is a glance at
   what has just come in, not a place to work through a backlog. Each form's own
   list under Forms pages through everything, ten at a time. */
$recent = db()->query(
    "SELECT product AS type, id, full_name AS title, email, status, created_at,
            reminder_count, reminded_at, reference_code
       FROM applications
     UNION ALL
     SELECT 'contact', id, name, email, status, created_at, 0, NULL, '' FROM contact_messages
     UNION ALL
     SELECT 'newsletter', id, email, email, status, created_at, 0, NULL, '' FROM newsletter_subscribers
     ORDER BY created_at DESC
     LIMIT 10"
)->fetchAll();

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($savedNote !== ''): ?>
  <p class="alert alert--ok"><?= e($savedNote) ?></p>
<?php endif; ?>

<?php if ($deletedNote !== ''): ?>
  <p class="alert alert--error"><?= e($deletedNote) ?></p>
<?php endif; ?>

<?php require __DIR__ . '/partials/mail-flash.php'; ?>

<div class="tiles">
  <?php foreach ($types as $key => $config): ?>
    <?php $counts = status_counts($key); ?>
    <a class="tile" href="list?type=<?= e($key) ?>">
      <span class="eyebrow"><?= e($config['label']) ?></span>
      <strong><?= (int) $counts['total'] ?></strong>
      <?php /* A tile is a glance, not the pipeline: for an application it shows
               only the four states somebody acts on - waiting for approval,
               cancelled, turned down and done. The stages in between are worked
               through on the form's own list, which this tile links to. */ ?>
      <?php $tileStats = type_config($key)['table'] === 'applications'
          ? ['submitted', 'complete', 'cancelled', 'rejected']
          : statuses_for($key); ?>
      <span class="tile__stats">
        <?php foreach ($tileStats as $s): ?>
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

  <?php /* the header carries this table's filters, so it stays either way */ ?>
  <div class="table-wrap">
      <table class="data-table is-filterable" id="latestTable">
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
          <?php if (!$recent): ?>
            <tr class="row-empty">
              <td colspan="7">No entry found — nothing has been submitted yet.</td>
            </tr>
          <?php endif; ?>

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
                  $returnUrl = './';
                  require __DIR__ . '/partials/row-actions.php';
                ?>
              </td>
              <td class="td-actions">
                <button type="button" class="row-toggle"
                        data-drawer="detail-<?= e($row['type']) ?>-<?= (int) $row['id'] ?>"
                        data-drawer-url="drawer.php?type=<?= e($row['type']) ?>&amp;id=<?= (int) $row['id'] ?>&amp;return=index.php"
                        data-title="<?= e($row['title']) ?>"
                        data-code="<?= e((string) ($row['reference_code'] ?? '')) ?>"
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
</div>

<?php require __DIR__ . '/partials/drawer.php'; ?>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
