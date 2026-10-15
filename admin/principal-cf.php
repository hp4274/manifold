<?php
/**
 * Principal C&F: State and multi-region hubs managing Zonal C&Fs and partner stock allocations.
 *
 * Tier 2 in the 5-tier hierarchy: Manifold (Office) -> Principal C&F -> Zonal C&F -> Distributor -> Dealer.
 * Principal C&Fs earn 20% commission on direct bookings/sales and oversee regional distribution.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Principal C&F';
$pageLead   = 'State and multi-region hubs managing Zonal C&Fs and partner stock allocations.';
$activeType = 'principal_cf';

$error = '';
$flash = (string) ($_SESSION['principal_cf_flash'] ?? '');
unset($_SESSION['principal_cf_flash']);

function principal_cf_done(string $message): void
{
    $_SESSION['principal_cf_flash'] = $message;
    header('Location: principal-cf');
    exit;
}

$editing            = null;
$isEdit             = false;
$openPrincipalModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? 'save');
    $id     = (int) ($_POST['id'] ?? 0);
    $values = [];

    if ($action === 'save') {
        [$values, $error] = partner_values($_POST, 'principal_cf', $id);

        /* their own rate: blank = the tier default, NULL in the column */
        $rawRate = str_replace(['%', ','], '', trim((string) ($_POST['commission_rate'] ?? '')));

        if ($rawRate !== '' && (!is_numeric($rawRate) || (float) $rawRate < 0 || (float) $rawRate > 100)) {
            $error = $error ?: 'The commission rate has to be between 0 and 100.';
        }

        $values['commission_rate'] = $rawRate === '' ? null : number_format((float) $rawRate, 2, '.', '');

        /* how many Zonal C&Fs they may hold: the office's call alone */
        [$values['max_children'], $capError] = child_cap_parse($_POST['max_children'] ?? '');
        $error = $error ?: $capError;

        if ($error === '' && $id > 0) {
            $set = implode(' = ?, ', [...PARTNER_FIELDS, 'commission_rate', 'max_children']) . ' = ?';
            db()->prepare('UPDATE principal_cf SET ' . $set . ' WHERE id = ?')
                ->execute([...array_values($values), $id]);

            principal_cf_done('Principal C&F updated.');
        } elseif ($error === '') {
            $values['principal_code']   = make_principal_cf_code();
            $values['approval_status']  = 'approved';
            $values['decided_at']       = date('Y-m-d H:i:s');
            $values['decided_by_label'] = $user['name'] ?? 'Admin';
            $values['created_by']       = (int) $user['id'];

            $names        = array_keys($values);
            $placeholders = implode(', ', array_fill(0, count($names), '?'));

            db()->prepare('INSERT INTO principal_cf (`' . implode('`, `', $names) . '`) VALUES ('
                . $placeholders . ')')->execute(array_values($values));

            principal_cf_done($values['full_name'] . ' added, with code ' . $values['principal_code'] . '.');
        }
    } elseif ($action === 'approve_principal' || $action === 'reject_principal') {
        $done = principal_cf_decide($id, $action === 'approve_principal' ? 'approved' : 'rejected', (int) $user['id']);

        if (isset($done['error'])) {
            $error = $done['error'];
        } else {
            principal_cf_done($done['message']);
        }
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE principal_cf SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);

        $now = db()->prepare('SELECT full_name, is_active FROM principal_cf WHERE id = ?');
        $now->execute([$id]);
        $p = $now->fetch() ?: ['full_name' => 'That Principal C&F', 'is_active' => 0];

        principal_cf_done($p['is_active']
            ? $p['full_name'] . ' is active again — their code works.'
            : $p['full_name'] . ' is switched off. Their code no longer books commission.');
    } elseif ($action === 'delete') {
        $heldStmt = db()->prepare('SELECT COUNT(*) FROM zonal_cf WHERE principal_cf_id = ?');
        $heldStmt->execute([$id]);
        $held = (int) $heldStmt->fetchColumn();

        if ($held > 0) {
            $error = 'That Principal C&F still holds ' . $held . ' Zonal C&F' . ($held === 1 ? '' : 's')
                . '. Reassign or delete those Zonal C&Fs first.';
        } else {
            db()->prepare('DELETE FROM principal_cf WHERE id = ?')->execute([$id]);
            principal_cf_done('Principal C&F deleted.');
        }
    } else {
        $error = 'Unknown action.';
    }

    if ($error !== '' && $action === 'save') {
        $existing           = $id > 0 ? principal_cf_by_id($id) : null;
        $editing            = $values + ['id' => $id, 'principal_code' => $existing['principal_code'] ?? ''];
        $isEdit             = $id > 0;
        $openPrincipalModal = true;
    }
}

if (($_GET['edit'] ?? '') !== '') {
    $editing            = principal_cf_by_id((int) $_GET['edit']);
    $isEdit             = $editing !== null;
    $openPrincipalModal = $isEdit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$whereClause = '';
if ($q !== '') {
    $qEscaped    = db()->quote('%' . $q . '%');
    $whereClause = ' WHERE full_name LIKE ' . $qEscaped . ' OR principal_code LIKE ' . $qEscaped . ' OR company LIKE ' . $qEscaped;
}

$pCount = (int) db()->query('SELECT COUNT(*) FROM principal_cf' . $whereClause)->fetchColumn();
$paging = paged($pCount, $_GET['page'] ?? 1);

$principals = db()->query(
    'SELECT principal_cf.*,
            (SELECT COUNT(*) FROM zonal_cf z WHERE z.principal_cf_id = principal_cf.id) AS zonal_count
       FROM principal_cf'
      . $whereClause . '
      ORDER BY is_active DESC, full_name
      LIMIT ' . LIST_PER_PAGE . ' OFFSET ' . $paging['offset']
)->fetchAll();

$principalUrl = 'principal-cf' . ($q === '' ? '' : '?' . http_build_query(['q' => $q]));

$tier = db()->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(is_active = 1 AND approval_status = 'approved'), 0) AS live,
            COALESCE(SUM(approval_status = 'pending'), 0) AS pending
       FROM principal_cf"
)->fetch();
$tierZonals = (int) db()->query('SELECT COUNT(*) FROM zonal_cf WHERE principal_cf_id IS NOT NULL')->fetchColumn();

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
    <span class="eyebrow">Principal partners</span>
    <strong><?= (int) $tier['total'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= (int) $tier['live'] ?> live under the office</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Zonal C&amp;Fs under them</span>
    <strong><?= $tierZonals ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">each one's distributors and dealers sell through this tier</span>
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
      <h2>Principal C&amp;F Partners</h2>
      <span class="eyebrow">
        <?= (int) $paging['from'] ?>&ndash;<?= (int) $paging['to'] ?> of <?= (int) $paging['total'] ?>
      </span>
    </div>
    <div class="panel__head-tools">
      <form method="get" action="principal-cf" class="admin-search-box" data-live-form>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input type="search" name="q" class="admin-search-input" value="<?= e($q) ?>"
               placeholder="Search by name, code or company..." aria-label="Search Principal C&amp;F">
      </form>
      <button type="button" class="btn-add" data-modal-open="principalModal">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a Principal C&amp;F
      </button>
    </div>
  </div>

<div class="table-wrap">
  <table class="data-table data-table--dealers data-table--stack">
    <colgroup>
      <col style="width:24%">
      <col style="width:10%">
      <col style="width:15%">
      <col style="width:10%">
      <col style="width:13%">
      <col style="width:13%">
      <col style="width:15%">
    </colgroup>
    <thead>
      <tr>
        <th>Principal C&amp;F</th>
        <th>Code</th>
        <th>Link</th>
        <th>Zonal C&amp;Fs</th>
        <th>Terms</th>
        <th class="th-actions">Action</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$principals): ?>
        <tr class="row-empty">
          <td colspan="7">
            No entry found &mdash; no Principal C&amp;F partners yet. Add one and they get a code,
            and Zonal C&amp;Fs to put under it.
          </td>
        </tr>
      <?php endif; ?>

      <?php foreach ($principals as $p): ?>
        <?php $pId = (int) $p['id']; ?>
        <tr>
          <td data-lead>
            <div class="cell-stack">
              <strong><?= e($p['full_name']) ?></strong>
              <?php if ($p['company']): ?>
                <span class="cell-sub"><?= e($p['company']) ?></span>
              <?php endif; ?>
              <span class="cell-sub">
                <?php if ($p['mobile_number']): ?><?= e($p['mobile_number']) ?> · <?php endif; ?><?= e($p['email'] ?: 'no email') ?>
              </span>
              <?php if (($p['approval_status'] ?? '') === 'pending'): ?>
                <span class="pill pill--warning"><i class="bi bi-clock"></i> Pending approval</span>
              <?php elseif (($p['approval_status'] ?? '') === 'rejected'): ?>
                <span class="pill pill--rejected">Turned down</span>
              <?php elseif (!$p['is_active']): ?>
                <span class="pill pill--rejected">Switched off</span>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Code"><span class="drawer__code"><?= e($p['principal_code'] ?: 'MP••••••') ?></span></td>
          <td data-block="Apply links">
            <div class="copy-links">
              <?php if (!empty($p['principal_code'])): ?>
                <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $product => $label): ?>
                  <?php $link = referral_link((string) $p['principal_code'], $product); ?>
                  <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= e($link) ?>"
                          title="Copy the <?= e($label) ?> apply link">
                    <i class="bi bi-link-45deg" aria-hidden="true"></i> <?= e($label) ?>
                    <span class="visually-hidden">apply link for <?= e($p['full_name']) ?></span>
                  </button>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="field-hint">Awaiting approval</span>
              <?php endif; ?>
            </div>
          </td>
          <td class="td-amount" data-label="Zonal C&amp;Fs">
            <strong><?= e(child_cap_usage('principal_cf', $pId)) ?></strong>
          </td>
          <td data-label="Terms">
            <div class="cell-stack">
              <span class="cell-sub"><i class="bi bi-shield-check"></i> Ahmedabad</span>
              <?php if (!empty($p['terms_accepted'])): ?>
                <span class="pill pill--good"><i class="bi bi-check2"></i> T&amp;C Accepted</span>
              <?php else: ?>
                <span class="pill pill--warning">T&amp;C Pending</span>
              <?php endif; ?>
            </div>
          </td>
          <td data-block="Actions">
            <div class="row-actions">
              <?php if (($p['approval_status'] ?? '') === 'pending'): ?>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $pId ?>">
                  <button type="submit" name="action" value="approve_principal"
                          class="icon-btn is-accept" title="Approve <?= e($p['full_name']) ?>">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                    <span class="visually-hidden">Approve <?= e($p['full_name']) ?></span>
                  </button>
                </form>

                <form method="post"
                      data-confirm="Turn down <?= e($p['full_name']) ?>? Their code stays inactive.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $pId ?>">
                  <button type="submit" name="action" value="reject_principal"
                          class="icon-btn is-reject" title="Turn down <?= e($p['full_name']) ?>">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                    <span class="visually-hidden">Turn down <?= e($p['full_name']) ?></span>
                  </button>
                </form>
              <?php else: ?>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $pId ?>">
                  <button type="submit" name="action" value="toggle"
                          class="icon-btn <?= $p['is_active'] ? 'is-reject' : 'is-accept' ?>"
                          title="<?= $p['is_active']
                              ? 'Stop this Principal C&F — their code stops booking commission'
                              : 'Start this Principal C&F again' ?>">
                    <i class="bi <?= $p['is_active'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"
                       aria-hidden="true"></i>
                    <span class="visually-hidden">
                      <?= $p['is_active'] ? 'Stop' : 'Start' ?> <?= e($p['full_name']) ?>
                    </span>
                  </button>
                </form>
              <?php endif; ?>

              <form method="post"
                    data-confirm="Delete <?= e($p['full_name']) ?>? Only possible once no Zonal C&Fs report to them.">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $pId ?>">
                <button type="submit" name="action" value="delete" class="icon-btn is-delete" title="Delete">
                  <i class="bi bi-trash" aria-hidden="true"></i>
                  <span class="visually-hidden">Delete <?= e($p['full_name']) ?></span>
                </button>
              </form>
            </div>
          </td>
          <td class="td-actions" data-block="Details &amp; edit">
            <button type="button" class="row-toggle" data-drawer="detail-principal_cf-<?= $pId ?>"
                    data-drawer-url="drawer.php?kind=principal_cf&amp;id=<?= $pId ?>"
                    data-title="<?= e($p['full_name']) ?>"
                    data-code="<?= e((string) $p['principal_code']) ?>"
                    data-meta="Principal C&amp;F · added <?= e(format_datetime($p['created_at'])) ?>"
                    data-status="<?= $p['is_active'] ? 'accepted' : 'rejected' ?>"
                    data-status-label="<?= $p['is_active'] ? 'Active' : 'Stopped' ?>">
              Details <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
            <a href="?edit=<?= $pId ?>" class="row-toggle" title="Edit details">
              <i class="bi bi-pencil" aria-hidden="true"></i>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($principals): ?>
  <?php
    $pagerPage  = $paging['page'];
    $pagerPages = $paging['pages'];
    $pagerTotal = $paging['total'];
    $pagerFrom  = $paging['from'];
    $pagerTo    = $paging['to'];
    $pagerBase  = $principalUrl;
    require __DIR__ . '/partials/pager.php';
  ?>
<?php endif; ?>
</div>

</div><!-- /data-live-list -->

<?php require __DIR__ . '/partials/drawer.php'; ?>

<!-- Principal C&F Modal -->
<div class="modal-x<?= $openPrincipalModal ? ' is-open' : '' ?>" id="principalModal" role="dialog" aria-modal="true"
     aria-labelledby="principalModalTitle">
  <div class="modal-x__backdrop" data-modal-close></div>

  <div class="modal-x__card modal-x__card--wide">
    <div class="modal-x__head">
      <h2 id="principalModalTitle"><?= $isEdit ? 'Edit Principal C&amp;F' : 'Add a Principal C&amp;F' ?></h2>
      <button type="button" class="modal-x__close" data-modal-close aria-label="Close">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>

    <form method="post">
      <div class="modal-x__body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $isEdit ? (int) $editing['id'] : 0 ?>">

        <section class="form-section">
          <div class="form-section__head">
            <h3 class="form-section__title">Commission</h3>
            <span class="form-section__note">percent of the sale, excluding GST</span>
          </div>
          <div class="field">
            <label for="commission_rate">Rate for this Principal C&amp;F</label>
            <input id="commission_rate" name="commission_rate" type="number" step="0.01" min="0" max="100"
                   value="<?= isset($editing['commission_rate']) && $editing['commission_rate'] !== null ? e(rtrim(rtrim((string) $editing['commission_rate'], '0'), '.')) : '' ?>"
                   placeholder="<?= e(rtrim(rtrim(number_format(commission_rate('principal_cf'), 2, '.', ''), '0'), '.')) ?>">
            <span class="field-hint">
              Leave blank for the standard <?= e(rate_short(commission_rate('principal_cf'))) ?>.
              New sales use it; sales already made keep theirs.
            </span>
          </div>
        </section>

        <?php
          $partnerKind   = 'principal_cf';
          $partnerCapEdit = true;
          $partnerEdit   = $editing;
          $partnerIsEdit = $isEdit;
          $partnerCode   = $isEdit ? (string) ($editing['principal_code'] ?? '') : '';
          require __DIR__ . '/partials/partner-fields.php';
        ?>
      </div>

      <div class="modal-x__foot">
        <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Save Principal C&amp;F' : 'Create Principal C&amp;F' ?></button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
