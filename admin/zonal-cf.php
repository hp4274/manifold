<?php
/**
 * Zonal C&F: Regional hubs reporting to Principal C&F, managing territory distributors and stock.
 *
 * Tier 3 in the 5-tier hierarchy: Manifold (Office) -> Principal C&F -> Zonal C&F -> Distributor -> Dealer.
 * Zonal C&Fs earn 15% commission on direct bookings/sales and oversee assigned distributors.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Zonal C&F';
$pageLead   = 'Regional hubs reporting to Principal C&F, managing territory distributors and stock.';
$activeType = 'zonal_cf';

$error = '';
$flash = (string) ($_SESSION['zonal_cf_flash'] ?? '');
unset($_SESSION['zonal_cf_flash']);

function zonal_cf_done(string $message): void
{
    $_SESSION['zonal_cf_flash'] = $message;
    header('Location: zonal-cf');
    exit;
}

$editing        = null;
$isEdit         = false;
$openZonalModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? 'save');
    $id     = (int) ($_POST['id'] ?? 0);
    $values = [];

    if ($action === 'save') {
        [$values, $error] = partner_values($_POST, 'zonal_cf', $id);
        $principalId = (int) ($_POST['principal_cf_id'] ?? 0);

        if ($error === '' && $principalId < 1) {
            $error = 'Pick the Principal C&F this Zonal C&F reports to.';
        } elseif ($error === '' && !principal_cf_by_id($principalId)) {
            $error = 'The selected Principal C&F no longer exists.';
        }

        /* how many distributors this zonal may hold */
        [$values['max_children'], $capError] = child_cap_parse($_POST['max_children'] ?? '');
        $error = $error ?: $capError;

        /* a new zonal, or one moved to another principal, takes up a place there */
        $before = $id > 0 ? zonal_cf_by_id($id) : null;

        if ($error === '' && (int) ($before['principal_cf_id'] ?? 0) !== $principalId) {
            $error = child_cap_error('principal_cf', $principalId, $id);
        }

        if ($error === '' && $id > 0) {
            $set = implode(' = ?, ', [...PARTNER_FIELDS, 'max_children']) . ' = ?, principal_cf_id = ?';
            db()->prepare('UPDATE zonal_cf SET ' . $set . ' WHERE id = ?')
                ->execute([...array_values($values), $principalId, $id]);

            zonal_cf_done('Zonal C&F updated.');
        } elseif ($error === '') {
            $values['zonal_code']       = make_zonal_cf_code();
            $values['principal_cf_id']  = $principalId;
            $values['approval_status']  = 'approved';
            $values['decided_at']       = date('Y-m-d H:i:s');
            $values['decided_by_label'] = $user['name'] ?? 'Admin';
            $values['created_by']       = (int) $user['id'];

            $names        = array_keys($values);
            $placeholders = implode(', ', array_fill(0, count($names), '?'));

            db()->prepare('INSERT INTO zonal_cf (`' . implode('`, `', $names) . '`) VALUES ('
                . $placeholders . ')')->execute(array_values($values));

            zonal_cf_done($values['full_name'] . ' added, with code ' . $values['zonal_code'] . '.');
        }
    } elseif ($action === 'approve_zonal' || $action === 'reject_zonal') {
        $done = zonal_cf_decide($id, $action === 'approve_zonal' ? 'approved' : 'rejected', (int) $user['id']);

        if (isset($done['error'])) {
            $error = $done['error'];
        } else {
            zonal_cf_done($done['message']);
        }
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE zonal_cf SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);

        $now = db()->prepare('SELECT full_name, is_active FROM zonal_cf WHERE id = ?');
        $now->execute([$id]);
        $z = $now->fetch() ?: ['full_name' => 'That Zonal C&F', 'is_active' => 0];

        zonal_cf_done($z['is_active']
            ? $z['full_name'] . ' is active again — their code works.'
            : $z['full_name'] . ' is switched off. Their code no longer books commission.');
    } elseif ($action === 'delete') {
        $heldStmt = db()->prepare('SELECT COUNT(*) FROM distributors WHERE zonal_cf_id = ?');
        $heldStmt->execute([$id]);
        $held = (int) $heldStmt->fetchColumn();

        if ($held > 0) {
            $error = 'That Zonal C&F still holds ' . $held . ' distributor' . ($held === 1 ? '' : 's')
                . '. Reassign or delete those distributors first.';
        } else {
            db()->prepare('DELETE FROM zonal_cf WHERE id = ?')->execute([$id]);
            zonal_cf_done('Zonal C&F deleted.');
        }
    } else {
        $error = 'Unknown action.';
    }

    if ($error !== '' && $action === 'save') {
        $existing       = $id > 0 ? zonal_cf_by_id($id) : null;
        $editing        = $values + [
            'id'              => $id,
            'zonal_code'      => $existing['zonal_code'] ?? '',
            'principal_cf_id' => $principalId,
        ];
        $isEdit         = $id > 0;
        $openZonalModal = true;
    }
}

if (($_GET['edit'] ?? '') !== '') {
    $editing        = zonal_cf_by_id((int) $_GET['edit']);
    $isEdit         = $editing !== null;
    $openZonalModal = $isEdit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$whereClause = '';
if ($q !== '') {
    $qEscaped    = db()->quote('%' . $q . '%');
    $whereClause = ' WHERE z.full_name LIKE ' . $qEscaped . ' OR z.zonal_code LIKE ' . $qEscaped . ' OR z.company LIKE ' . $qEscaped;
}

$zCount = (int) db()->query('SELECT COUNT(*) FROM zonal_cf z' . $whereClause)->fetchColumn();
$paging = paged($zCount, $_GET['page'] ?? 1);

$zonals = db()->query(
    'SELECT z.*, p.full_name AS principal_name, p.principal_code,
            (SELECT COUNT(*) FROM distributors d WHERE d.zonal_cf_id = z.id) AS dist_count
       FROM zonal_cf z
       LEFT JOIN principal_cf p ON p.id = z.principal_cf_id'
      . $whereClause . '
      ORDER BY z.is_active DESC, z.full_name
      LIMIT ' . LIST_PER_PAGE . ' OFFSET ' . $paging['offset']
)->fetchAll();

$zonalUrl = 'zonal-cf' . ($q === '' ? '' : '?' . http_build_query(['q' => $q]));

$tier = db()->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(is_active = 1 AND approval_status = 'approved'), 0) AS live,
            COALESCE(SUM(approval_status = 'pending'), 0) AS pending
       FROM zonal_cf"
)->fetch();
$tierDistributors = (int) db()->query('SELECT COUNT(*) FROM distributors WHERE zonal_cf_id IS NOT NULL')->fetchColumn();

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?>
  <p class="alert alert--ok"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error"><?= e($error) ?></p>
<?php endif; ?>

<div class="tiles">
  <span class="tile">
    <span class="eyebrow">Zonal partners</span>
    <strong><?= (int) $tier['total'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= (int) $tier['live'] ?> live under a Principal C&amp;F</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Distributors under them</span>
    <strong><?= $tierDistributors ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">each one's dealers sell through this tier</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Awaiting approval</span>
    <strong><?= (int) $tier['pending'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">their code stays inactive until approved</span>
    </span>
  </span>
</div>

<?php /* the table and the hidden drawer markup its Details buttons open are swapped as one when the search runs */ ?>
<div data-live-list data-live-quiet>
<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Zonal C&amp;F Partners</h2>
      <span class="eyebrow">
        <?= (int) $paging['from'] ?>&ndash;<?= (int) $paging['to'] ?> of <?= (int) $paging['total'] ?>
      </span>
    </div>
    <div class="panel__head-tools">
      <form method="get" action="zonal-cf" class="admin-search-box" data-live-form>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input type="search" name="q" class="admin-search-input" value="<?= e($q) ?>"
               placeholder="Search by name, code or company..." aria-label="Search Zonal C&amp;F">
      </form>
      <button type="button" class="btn-add" data-modal-open="zonalModal">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a Zonal C&amp;F
      </button>
    </div>
  </div>

<div class="table-wrap">
  <table class="data-table data-table--dealers data-table--stack">
    <colgroup>
      <col style="width:19%">
      <col style="width:12%">
      <col style="width:9%">
      <col style="width:14%">
      <col style="width:10%">
      <col style="width:12%">
      <col style="width:11%">
      <col style="width:13%">
    </colgroup>
    <thead>
      <tr>
        <th>Zonal C&amp;F</th>
        <th>Principal C&amp;F</th>
        <th>Code</th>
        <th>Link</th>
        <th>Distributors</th>
        <th>Terms</th>
        <th class="th-actions">Action</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$zonals): ?>
        <tr class="row-empty">
          <td colspan="8">
            No entry found &mdash; no Zonal C&amp;F partners yet. Add one and they get a code,
            and distributors to put under it.
          </td>
        </tr>
      <?php endif; ?>

      <?php foreach ($zonals as $z): ?>
        <?php $zId = (int) $z['id']; ?>
        <tr>
          <td data-lead>
            <div class="cell-stack">
              <strong><?= e($z['full_name']) ?></strong>
              <?php if ($z['company']): ?>
                <span class="cell-sub"><?= e($z['company']) ?></span>
              <?php endif; ?>
              <span class="cell-sub">
                <?php if ($z['mobile_number']): ?><?= e($z['mobile_number']) ?> · <?php endif; ?><?= e($z['email'] ?: 'no email') ?>
              </span>
              <?php if (($z['approval_status'] ?? '') === 'pending'): ?>
                <span class="pill pill--warning"><i class="bi bi-clock"></i> Pending approval</span>
              <?php elseif (($z['approval_status'] ?? '') === 'rejected'): ?>
                <span class="pill pill--rejected">Turned down</span>
              <?php elseif (!$z['is_active']): ?>
                <span class="pill pill--rejected">Switched off</span>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Principal C&amp;F">
            <div class="cell-stack">
              <strong><?= e($z['principal_name'] ?: 'None / Unassigned') ?></strong>
              <?php if ($z['principal_code']): ?>
                <span class="cell-sub"><?= e($z['principal_code']) ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Code"><span class="drawer__code"><?= e($z['zonal_code'] ?: 'MZ••••••') ?></span></td>
          <td data-block="Apply links">
            <div class="copy-links">
              <?php if (!empty($z['zonal_code'])): ?>
                <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $product => $label): ?>
                  <?php $link = referral_link((string) $z['zonal_code'], $product); ?>
                  <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= e($link) ?>"
                          title="Copy the <?= e($label) ?> apply link">
                    <i class="bi bi-link-45deg" aria-hidden="true"></i> <?= e($label) ?>
                    <span class="visually-hidden">apply link for <?= e($z['full_name']) ?></span>
                  </button>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="field-hint">Awaiting approval</span>
              <?php endif; ?>
            </div>
          </td>
          <td class="td-amount" data-label="Distributors">
            <strong><?= e(child_cap_usage('zonal_cf', (int) $z['id'])) ?></strong>
          </td>
          <td data-label="Terms">
            <div class="cell-stack">
              <span class="cell-sub"><i class="bi bi-shield-check"></i> Ahmedabad</span>
              <?php if (!empty($z['terms_accepted'])): ?>
                <span class="pill pill--good"><i class="bi bi-check2"></i> T&amp;C Accepted</span>
              <?php else: ?>
                <span class="pill pill--warning">T&amp;C Pending</span>
              <?php endif; ?>
            </div>
          </td>
          <td data-block="Actions">
            <div class="row-actions">
              <?php if (($z['approval_status'] ?? '') === 'pending'): ?>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $zId ?>">
                  <button type="submit" name="action" value="approve_zonal"
                          class="icon-btn is-accept" title="Approve <?= e($z['full_name']) ?>">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                    <span class="visually-hidden">Approve <?= e($z['full_name']) ?></span>
                  </button>
                </form>

                <form method="post"
                      data-confirm="Turn down <?= e($z['full_name']) ?>? Their code stays inactive.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $zId ?>">
                  <button type="submit" name="action" value="reject_zonal"
                          class="icon-btn is-reject" title="Turn down <?= e($z['full_name']) ?>">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                    <span class="visually-hidden">Turn down <?= e($z['full_name']) ?></span>
                  </button>
                </form>
              <?php else: ?>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $zId ?>">
                  <button type="submit" name="action" value="toggle"
                          class="icon-btn <?= $z['is_active'] ? 'is-reject' : 'is-accept' ?>"
                          title="<?= $z['is_active']
                              ? 'Stop this Zonal C&F — their code stops booking commission'
                              : 'Start this Zonal C&F again' ?>">
                    <i class="bi <?= $z['is_active'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"
                       aria-hidden="true"></i>
                    <span class="visually-hidden">
                      <?= $z['is_active'] ? 'Stop' : 'Start' ?> <?= e($z['full_name']) ?>
                    </span>
                  </button>
                </form>
              <?php endif; ?>

              <form method="post"
                    data-confirm="Delete <?= e($z['full_name']) ?>? Only possible once no distributors report to them.">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $zId ?>">
                <button type="submit" name="action" value="delete" class="icon-btn is-delete" title="Delete">
                  <i class="bi bi-trash" aria-hidden="true"></i>
                  <span class="visually-hidden">Delete <?= e($z['full_name']) ?></span>
                </button>
              </form>
            </div>
          </td>
          <td class="td-actions" data-block="Details &amp; edit">
            <button type="button" class="row-toggle" data-drawer="detail-zonal_cf-<?= $zId ?>"
                    data-drawer-url="drawer.php?kind=zonal_cf&amp;id=<?= $zId ?>"
                    data-title="<?= e($z['full_name']) ?>"
                    data-code="<?= e((string) $z['zonal_code']) ?>"
                    data-meta="Zonal C&amp;F · added <?= e(format_datetime($z['created_at'])) ?>"
                    data-status="<?= $z['is_active'] ? 'accepted' : 'rejected' ?>"
                    data-status-label="<?= $z['is_active'] ? 'Active' : 'Stopped' ?>">
              Details <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
            <a href="?edit=<?= $zId ?>" class="row-toggle" title="Edit details">
              <i class="bi bi-pencil" aria-hidden="true"></i>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($zonals): ?>
  <?php
    $pagerPage  = $paging['page'];
    $pagerPages = $paging['pages'];
    $pagerTotal = $paging['total'];
    $pagerFrom  = $paging['from'];
    $pagerTo    = $paging['to'];
    $pagerBase  = $zonalUrl;
    require __DIR__ . '/partials/pager.php';
  ?>
<?php endif; ?>
</div>

</div><!-- /data-live-list -->

<?php require __DIR__ . '/partials/drawer.php'; ?>

<!-- Zonal C&F Modal -->
<div class="modal-x<?= $openZonalModal ? ' is-open' : '' ?>" id="zonalModal" role="dialog" aria-modal="true"
     aria-labelledby="zonalModalTitle">
  <div class="modal-x__backdrop" data-modal-close></div>

  <div class="modal-x__card modal-x__card--wide">
    <div class="modal-x__head">
      <h2 id="zonalModalTitle"><?= $isEdit ? 'Edit Zonal C&amp;F' : 'Add a Zonal C&amp;F' ?></h2>
      <button type="button" class="modal-x__close" data-modal-close aria-label="Close">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>

    <form method="post">
      <div class="modal-x__body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $isEdit ? (int) $editing['id'] : 0 ?>">

        <?php
          $partnerKind   = 'zonal_cf';
          $partnerCapEdit = true;
          $partnerEdit   = $editing;
          $partnerIsEdit = $isEdit;
          $partnerCode   = $isEdit ? (string) ($editing['zonal_code'] ?? '') : '';
          require __DIR__ . '/partials/partner-fields.php';
        ?>
      </div>

      <div class="modal-x__foot">
        <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Save Zonal C&amp;F' : 'Create Zonal C&amp;F' ?></button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
