<?php
/**
 * One form's submissions, filterable by status, ten to a page.
 * Each row switches its own status and expands to show the full record.
 * list.php?type=stove|tuktuk|contact|newsletter&status=new|...&page=2
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

$clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

/* how many there are before deciding which ten to ask for */
$countStmt = db()->prepare('SELECT COUNT(*) FROM ' . $config['table'] . $clause);
$countStmt->execute($params);

$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / LIST_PER_PAGE));

/* a page number out of range lands on the nearest real one, which is also what
   happens when the last row on the last page is deleted */
$page   = max(1, min((int) ($_GET['page'] ?? 1), $pages));
$offset = ($page - 1) * LIST_PER_PAGE;

/* both are integers we worked out ourselves; MySQL will not take them bound */
$stmt = db()->prepare(
    'SELECT ' . ($config['list'] ?? '*') . ' FROM ' . $config['table'] . $clause
    . ' ORDER BY created_at DESC LIMIT ' . LIST_PER_PAGE . ' OFFSET ' . $offset
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$groups    = field_groups($type);
$counts    = status_counts($type);
/* what the action that redirected here left behind — read once, so a reload
   does not repeat a confirmation for something that happened once */
$flash     = admin_flash_take();
/* still an id as well as a sentence: it is what flags the row that changed */
$savedId   = (int) ($flash['saved'] ?? 0);
/* who it was and what was done to them — "Submission #554 updated" named
   neither, and after approving four in a row that is the only thing worth
   reading. Composed where the action happened, which is the only place both
   halves are known. */
$savedNote   = (string) ($flash['saved_note'] ?? '');
$deletedNote = (string) ($flash['deleted_note'] ?? '');
$mailFlash = (string) ($flash['mail'] ?? '');
$payFlash  = (string) ($flash['pay'] ?? '');
/* without the page, saving a row on page 3 would come back to page 1 */
$listUrl   = 'list?type=' . urlencode($type) . ($status !== '' ? '&status=' . urlencode($status) : '');
$returnUrl = $listUrl . ($page > 1 ? '&page=' . $page : '');

[$attentionKeys, $attentionLabel] = attention_status($type);

/* The column headers are the filters, the same as on the dashboard — but this
   list pages through everything rather than showing the newest ten, so a header
   here navigates instead of hiding rows. Filtering in the browser would only
   ever filter the ten rows on screen and quietly lie about the rest.

   Each header steps to the next value and wraps back to all, so one control
   does the whole job of a row of chips. */
$statusKeys = array_merge([''], statuses_for($type));
$statusNext = $statusKeys[(array_search($status, $statusKeys, true) + 1) % count($statusKeys)];

/** Where a header click goes: this list, one step along the status column. */
$stepUrl = static function (string $toType, string $toStatus): string {
    return 'list?type=' . urlencode($toType)
        . ($toStatus === '' ? '' : '&status=' . urlencode($toStatus));
};

$attentionCount = array_sum(array_map(
    static fn (string $key): int => (int) ($counts[$key] ?? 0),
    $attentionKeys
));

$pageTitle  = $config['label'];
$pageLead   = $counts['total'] . ' total · ' . $attentionCount . ' ' . $attentionLabel;
$activeType = $type;

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($savedNote !== ''): ?>
  <p class="alert alert--ok"><?= e($savedNote) ?></p>
<?php endif; ?>

<?php if ($deletedNote !== ''): ?>
  <p class="alert alert--error"><?= e($deletedNote) ?></p>
<?php endif; ?>

<?php require __DIR__ . '/partials/mail-flash.php'; ?>

<?php /* Everything a filter changes, swapped as one: the table and the hidden
         drawer markup its Details buttons open. Swapping only the table would
         leave the new rows pointing at the old rows' drawers. */ ?>
<div data-live-list>
<div class="panel">
  <div class="panel__head">
    <h2><?= $status === '' ? 'All submissions' : e(status_label($status)) ?></h2>
    <span class="eyebrow">
      <?php if ($total === 0): ?>
        none
      <?php elseif ($pages > 1): ?>
        <?= $offset + 1 ?>–<?= $offset + count($rows) ?> of <?= (int) $total ?> · page <?= $page ?> of <?= $pages ?>
      <?php else: ?>
        <?= (int) $total ?> shown
      <?php endif; ?>
    </span>
  </div>

  <?php /* The table is drawn whether or not it has rows: a filter that matches
           nothing still has to show which columns it filtered, and the header
           carries the status filter itself — hiding it left no way back. */ ?>
    <div class="table-wrap">
      <?php /* Fixed widths, so switching a filter cannot move the columns: the
               rows behind one status are shorter or longer than another's, and
               an empty result has no cells to size at all. */ ?>
      <table class="table--fixed">
        <?php
          $hasSource = $type !== 'newsletter' && $type !== 'contact';

          /* One share per column, in the order the row renders them, so the
             Actions share can be read against what actually sits in it: a new
             contact enquiry carries four icons and needs 168px of them, and at
             the 14% it used to have there was room for three — the fourth wrapped
             onto a line of its own under the others. */
          $columnWidths = $type === 'newsletter'
              /*  #   email  received  status  actions  details  */
              ? [5,   33,              18,     12,      18,      14]
              : ($hasSource
                  /*  #  name  contact  source  received  status  actions  details */
                  ? [5,  15,   17,      11,     11,       13,     17,      11]
                  /*  #  name  contact  received  status  actions  details */
                  : [5,  17,   20,      12,       12,     20,      14]);
        ?>
        <colgroup>
          <?php foreach ($columnWidths as $width): ?>
            <col style="width:<?= $width ?>%">
          <?php endforeach; ?>
        </colgroup>
        <thead>
          <tr>
            <th>#</th>
            <th><?= $type === 'newsletter' ? 'Email' : 'Name' ?></th>
            <?php if ($type !== 'newsletter'): ?>
              <th>Contact</th>
            <?php endif; ?>
            <?php if ($type !== 'newsletter' && $type !== 'contact'): ?>
              <th>Source</th>
            <?php endif; ?>
            <th>Received</th>
            <?php /* click to step through this form's own statuses, then back to all */ ?>
            <th class="th-filter-cell">
              <a class="th-filter<?= $status === '' ? '' : ' is-filtered' ?>"
                 href="<?= e($stepUrl($type, $statusNext)) ?>"
                 title="Click to filter by status — next: <?= e($statusNext === ''
                     ? 'all ' . $counts['total'] : status_label($statusNext) . ' ' . $counts[$statusNext]) ?>">
                <span class="th-filter__label">
                  <?= $status === '' ? 'Status' : e(status_label($status)) ?>
                  <?= $status === '' ? '' : (int) $counts[$status] ?>
                </span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
              </a>
            </th>
            <th>Actions</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <?php /* an empty filter and an empty form are different facts */ ?>
            <tr class="row-empty">
              <td colspan="<?= $type === 'newsletter' ? 6 : ($hasSource ? 8 : 7) ?>">
                <?php /* the status is named in the panel heading above, and
                         some of them carry a dash of their own — repeating one
                         here read as two sentences run together */ ?>
                <?= $status === ''
                    ? 'No entry found — nothing here yet.'
                    : 'No entry found at this status. <a href="'
                      . e($stepUrl($type, '')) . '">Show all</a>.' ?>
              </td>
            </tr>
          <?php endif; ?>

          <?php /* keep counting where the previous page left off */ ?>
          <?php $seq = $offset; ?>
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
              <?php if ($type !== 'newsletter' && $type !== 'contact'): ?>
                <?php $rowSource = sale_source($row); ?>
                <td>
                  <div class="cell-stack">
                    <span><?= e($rowSource['label']) ?></span>
                    <?php if ($rowSource['code'] !== ''): ?>
                      <span class="cell-sub"><?= e($rowSource['code']) ?></span>
                    <?php endif; ?>
                  </div>
                </td>
              <?php endif; ?>
              <td class="td-when"><?= e(format_datetime($row['created_at'])) ?></td>
              <td><span class="pill pill--<?= e($row['status']) ?>"><?= e(status_short($row['status'])) ?></span></td>
              <td>
                <?php $rowType = $type; require __DIR__ . '/partials/row-actions.php'; ?>
              </td>
              <td class="td-actions">
                <button type="button" class="row-toggle" data-drawer="detail-<?= e($type) ?>-<?= $rowId ?>"
                        data-drawer-url="drawer.php?type=<?= e($type) ?>&amp;id=<?= $rowId ?>&amp;return=<?= e(rawurlencode($returnUrl)) ?>"
                        data-title="<?= e(record_title($type, $row)) ?>"
                        data-code="<?= e((string) ($row['reference_code'] ?? '')) ?>"
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

    <?php if ($rows): ?>
      <?php
        $pagerPage  = $page;
        $pagerPages = $pages;
        $pagerTotal = $total;
        $pagerFrom  = $offset + 1;
        $pagerTo    = $offset + count($rows);
        $pagerBase  = $listUrl;
        require __DIR__ . '/partials/pager.php';
      ?>
    <?php endif; ?>
</div>

</div><?php /* end of the swapped region */ ?>

<?php /* the drawer itself is a fixture of the page, not of the filter */ ?>
<?php require __DIR__ . '/partials/drawer.php'; ?>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
