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

/* The newest updates across every form — not just what came in, but what
   moved: a receipt to verify, documents to accept, an order cancelled. A glance
   at what needs a look, newest first; each form's own list is where the backlog
   is worked. */
$activity = recent_activity(15);

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
    <h2>Latest activity</h2>
    <span class="eyebrow">The newest updates across every form</span>
  </div>

  <div class="table-wrap">
    <table class="table--fixed">
      <?php /* Action carries up to four icons for a new application — the same
               reason list.php gives that column the largest share; at less it
               wrapped the fourth icon onto a line of its own. */ ?>
      <colgroup>
        <col style="width:5%">
        <col style="width:15%">
        <col style="width:17%">
        <col style="width:11%">
        <col style="width:11%">
        <col style="width:13%">
        <col style="width:17%">
        <col style="width:11%">
      </colgroup>
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Contact</th>
          <th>Source</th>
          <th>Received</th>
          <th>Status</th>
          <th class="th-actions">Actions</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$activity): ?>
          <tr class="row-empty">
            <td colspan="8">No entry found — nothing has happened yet.</td>
          </tr>
        <?php endif; ?>

        <?php $seq = 0; ?>
        <?php foreach ($activity as $item): ?>
          <?php
            $type  = $item['type'];
            $row   = $item['row'];
            $rowId = (int) $row['id'];
            $seq++;

            $isApp = $type !== 'contact' && $type !== 'newsletter';
            $label = $types[$type]['label'] ?? ucfirst($type);
            $title = record_title($type, $row);
            $phone = $row['mobile_number'] ?? $row['phone'] ?? '';
          ?>
          <tr id="row-<?= e($type) ?>-<?= $rowId ?>">
            <td class="td-seq"><?= $seq ?></td>
            <td><strong title="<?= e($title) ?>"><?= e($title) ?></strong></td>
            <td>
              <?php if ($type !== 'newsletter'): ?>
                <a href="mailto:<?= e($row['email']) ?>"><?= e($row['email']) ?></a>
                <?php if ($phone !== ''): ?>
                  <a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>"><?= e($phone) ?></a>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isApp): ?>
                <?php $rowSource = sale_source($row); ?>
                <div class="cell-stack">
                  <span><?= e($rowSource['label']) ?></span>
                  <?php if ($rowSource['code'] !== ''): ?>
                    <span class="cell-sub"><?= e($rowSource['code']) ?></span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="td-when"><?= e(format_datetime($row['created_at'])) ?></td>
            <td><span class="pill pill--<?= e($row['status']) ?>"><?= e(status_short($row['status'])) ?></span></td>
            <td>
              <?php $rowType = $type; $returnUrl = './'; require __DIR__ . '/partials/row-actions.php'; ?>
            </td>
            <td class="td-actions">
              <button type="button" class="row-toggle" data-drawer="detail-<?= e($type) ?>-<?= $rowId ?>"
                      data-drawer-url="drawer.php?type=<?= e($type) ?>&amp;id=<?= $rowId ?>&amp;return=index.php"
                      data-title="<?= e($title) ?>"
                      data-code="<?= e((string) ($row['reference_code'] ?? '')) ?>"
                      data-meta="<?= e($label) ?> · received <?= e(format_datetime($row['created_at'])) ?>"
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
</div>

<?php require __DIR__ . '/partials/drawer.php'; ?>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
