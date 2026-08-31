<?php
/**
 * Distributors: who signs dealers up, and what each of them is owed.
 *
 * A distributor earns two ways — an override on every sale one of their dealers
 * makes, and the full share on a sale they make themselves. Both figures are
 * frozen onto the application when it arrives, so nothing here recalculates.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Distributors';
$pageLead   = 'Who runs the dealers, the link they share, and what each of them is owed.';
$activeType = 'distributors';

$error = '';

/* carried across the redirect that follows every successful action */
$flash = (string) ($_SESSION['distributors_flash'] ?? '');
unset($_SESSION['distributors_flash']);

/** Finish an action: remember what happened, then reload as a plain GET. */
function distributors_done(string $message): void
{
    $_SESSION['distributors_flash'] = $message;

    header('Location: distributors.php');
    exit;
}

$editing        = null;
$isEdit         = false;
$openDistModal  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? 'save');
    $id     = (int) ($_POST['id'] ?? 0);
    $values = [];

    if ($action === 'save') {
        [$values, $error] = partner_values($_POST);

        if ($error === '' && $id > 0) {
            $set = implode(' = ?, ', PARTNER_FIELDS) . ' = ?';
            db()->prepare('UPDATE distributors SET ' . $set . ' WHERE id = ?')
                ->execute([...array_values($values), $id]);

            distributors_done('Distributor updated.');
        } elseif ($error === '') {
            $values['distributor_code'] = make_distributor_code();
            $values['created_by']       = (int) $user['id'];

            $names        = array_keys($values);
            $placeholders = implode(', ', array_fill(0, count($names), '?'));

            db()->prepare('INSERT INTO distributors (`' . implode('`, `', $names) . '`) VALUES ('
                . $placeholders . ')')->execute(array_values($values));

            distributors_done($values['full_name'] . ' added, with code ' . $values['distributor_code'] . '.');
        }
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE distributors SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);

        $now = db()->prepare('SELECT full_name, is_active FROM distributors WHERE id = ?');
        $now->execute([$id]);
        $dist = $now->fetch() ?: ['full_name' => 'That distributor', 'is_active' => 0];

        distributors_done($dist['is_active']
            ? $dist['full_name'] . ' is active again — their code works.'
            : $dist['full_name'] . ' is switched off. Their code no longer books commission.');
    } elseif ($action === 'payout') {
        $amount = str_replace(',', '', trim((string) ($_POST['amount'] ?? '')));
        $note   = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
        $dist   = distributor_by_id($id);

        if (!$dist) {
            $error = 'That distributor no longer exists.';
        } elseif (!is_numeric($amount) || (float) $amount <= 0) {
            $error = 'Enter the amount transferred, greater than zero.';
        } else {
            db()->prepare(
                'INSERT INTO distributor_payouts (distributor_id, amount, note, paid_by) VALUES (?, ?, ?, ?)'
            )->execute([$id, (float) $amount, $note !== '' ? $note : null, (int) $user['id']]);

            distributors_done(money((float) $amount) . ' recorded against ' . $dist['full_name'] . '.');
        }
    } elseif ($action === 'payout_delete') {
        /* a mistyped amount has to be removable, or the running total lies for good */
        db()->prepare('DELETE FROM distributor_payouts WHERE id = ? AND distributor_id = ?')
            ->execute([(int) ($_POST['payout_id'] ?? 0), $id]);

        distributors_done('Payout removed.');
    } elseif ($action === 'add_dealer') {
        /* the office adds a dealer straight under a distributor — no queue,
           because the office approving its own entry would be theatre. The
           dialog carries the full dealer form, and the distributor on it is
           required: every dealer answers to one. */
        $id   = (int) ($_POST['distributor_id'] ?? 0);
        $dist = $id > 0 ? distributor_by_id($id) : null;

        [$values, $error] = partner_values($_POST);

        if (!$dist) {
            $error = 'Pick the distributor this dealer answers to.';
        } elseif ($error === '' && !distributor_has_room($id)) {
            $error = $dist['full_name'] . ' already holds ' . distributor_dealer_count($id)
                . ' dealers, which is the limit. Raise it under Settings, or take one out first.';
        } elseif ($error === '') {
            $values['dealer_code']     = make_dealer_code();
            $values['distributor_id']  = $id;
            $values['approval_status'] = 'approved';
            $values['decided_at']      = date('Y-m-d H:i:s');
            $values['decided_by']      = (int) $user['id'];
            $values['created_by']      = (int) $user['id'];

            $names        = array_keys($values);
            $placeholders = implode(', ', array_fill(0, count($names), '?'));

            db()->prepare('INSERT INTO dealers (`' . implode('`, `', $names) . '`) VALUES ('
                . $placeholders . ')')->execute(array_values($values));

            distributors_done($values['full_name'] . ' added under ' . $dist['full_name']
                . ', with code ' . $values['dealer_code'] . '.');
        }
    } elseif ($action === 'approve_dealer' || $action === 'reject_dealer') {
        /* A dealer a distributor asked for. Approving is what makes their code
           work — until then it books nothing, so nothing is lost by taking time
           over it. */
        $dealerId = (int) ($_POST['dealer_id'] ?? 0);
        $verdict  = $action === 'approve_dealer' ? 'approved' : 'rejected';
        $dealer   = dealer_by_id($dealerId);

        if (!$dealer || $dealer['approval_status'] !== 'pending') {
            $error = 'That request has already been decided.';
        } elseif ($verdict === 'approved' && !distributor_has_room((int) $dealer['distributor_id'])) {
            $error = 'That distributor is already at the dealer limit. Raise it under Settings first.';
        } else {
            db()->prepare(
                'UPDATE dealers SET approval_status = ?, decided_at = NOW(), decided_by = ? WHERE id = ?'
            )->execute([$verdict, (int) $user['id'], $dealerId]);

            distributors_done($verdict === 'approved'
                ? $dealer['full_name'] . ' is approved. Their code books commission from now on.'
                : $dealer['full_name'] . ' was turned down. Their code stays dead.');
        }
    } elseif ($action === 'delete') {
        /* Every dealer answers to a distributor, so deleting one that still
           holds dealers would leave them answering to nobody. Move them first;
           the database refuses this too. */
        /* every dealer row counts here, turned-down ones included: the foreign
           key does not care what their approval status is */
        $heldStmt = db()->prepare('SELECT COUNT(*) FROM dealers WHERE distributor_id = ?');
        $heldStmt->execute([$id]);
        $held = (int) $heldStmt->fetchColumn();

        if ($held > 0) {
            $error = 'That distributor still holds ' . $held . ' dealer' . ($held === 1 ? '' : 's')
                . '. Move them to another distributor first — a dealer cannot be left without one.';
        } else {
            db()->prepare('DELETE FROM distributors WHERE id = ?')->execute([$id]);

            distributors_done('Distributor deleted. Their sales are kept.');
        }
    } else {
        $error = 'Unknown action.';
    }

    /* nothing was saved: reopen the dialog on what was typed, not on a blank form */
    if ($error !== '' && $action === 'save') {
        $existing      = $id > 0 ? distributor_by_id($id) : null;
        $editing       = $values + ['id' => $id, 'distributor_code' => $existing['distributor_code'] ?? ''];
        $isEdit        = $id > 0;
        $openDistModal = true;
    }
}

if (($_GET['edit'] ?? '') !== '') {
    $editing       = distributor_by_id((int) $_GET['edit']);
    $isEdit        = $editing !== null;
    $openDistModal = $isEdit;
}

/* Sorting, not filtering: the two questions the office asks of this list are
   who holds the most dealers and who is owed the most, and both are answers a
   column header can give. Ordering happens in SQL because the page holds ten
   of however many — sorting the ten on screen would sort a slice and call it
   the list. */
$sort = (string) ($_GET['sort'] ?? '');

/* what "still owed" is, in one place: everything earned on completed sales,
   less everything already transferred */
$owedSql = '((SELECT COALESCE(SUM(CASE WHEN a.status = \'complete\'
                                       THEN a.distributor_commission ELSE 0 END), 0)
                FROM applications a WHERE a.distributor_id = distributors.id)
             - (SELECT COALESCE(SUM(p.amount), 0)
                  FROM distributor_payouts p WHERE p.distributor_id = distributors.id))';

$countSql = '(SELECT COUNT(*) FROM dealers d WHERE d.distributor_id = distributors.id)';

$sorts = [
    'dealers-high' => $countSql . ' DESC',
    'dealers-low'  => $countSql . ' ASC',
    'owed-high'    => $owedSql . ' DESC',
    'owed-low'     => $owedSql . ' ASC',
];

if (!array_key_exists($sort, $sorts)) {
    $sort = '';
}

$order = $sort === '' ? 'is_active DESC, full_name' : $sorts[$sort] . ', full_name';

$distCount = (int) db()->query('SELECT COUNT(*) FROM distributors')->fetchColumn();
$paging    = paged($distCount, $_GET['page'] ?? 1);

$distributors = db()->query(
    'SELECT * FROM distributors
      ORDER BY ' . $order . '
      LIMIT ' . LIST_PER_PAGE . ' OFFSET ' . $paging['offset']
)->fetchAll();

$distUrl = 'distributors.php' . ($sort === '' ? '' : '?sort=' . urlencode($sort));

/** The same list ordered another way — paging starts over, as it must. */
$distUrlFor = static function (string $next): string {
    return 'distributors.php' . ($next === '' ? '' : '?sort=' . urlencode($next));
};

/* a header click steps high to low, then low to high, then back to the
   ordinary order */
$sortStep = static function (string $column) use ($sort): string {
    $steps = ['', $column . '-high', $column . '-low'];
    $at    = array_search($sort, $steps, true);

    /* sorted by the other column: this one starts at the top of its own cycle */
    return $at === false ? $steps[1] : $steps[($at + 1) % count($steps)];
};

$sortLabel = static function (string $column, string $default) use ($sort): string {
    if ($sort === $column . '-high') {
        return 'High to low';
    }

    return $sort === $column . '-low' ? 'Low to high' : $default;
};

/* what every distributor's Dealers tab needs to know about the ceiling */
$dealerLimit = dealer_limit();

/* who a new dealer can be put under, offered in the Add-a-dealer dialog */
$distributorChoices = db()->query(
    'SELECT id, full_name, distributor_code FROM distributors WHERE is_active = 1 ORDER BY full_name'
)->fetchAll();

/* the page's own rows carry their figures for the table */
foreach ($distributors as $i => $dist) {
    $distributors[$i]['totals']  = distributor_totals((int) $dist['id']);
    $distributors[$i]['dealers'] = distributor_dealers((int) $dist['id']);
}

/* the tiles are the whole business, not this page of it — summed in SQL so
   paging can never quietly turn a total into a subtotal */
$totals = db()->query(
    'SELECT COALESCE(SUM(CASE WHEN ' . COMMISSION_EARNED_SQL . '
                              THEN distributor_commission ELSE 0 END), 0) AS earned,
            COALESCE(SUM(' . COMMISSION_EARNED_SQL . '), 0) AS sales
       FROM applications WHERE distributor_id IS NOT NULL'
)->fetch() ?: ['earned' => 0, 'sales' => 0];

$totals['paid']      = (float) db()->query('SELECT COALESCE(SUM(amount), 0) FROM distributor_payouts')->fetchColumn();
$totals['dealers']   = (int) db()->query('SELECT COUNT(*) FROM dealers WHERE distributor_id IS NOT NULL')->fetchColumn();
$totals['earned']    = (float) $totals['earned'];
$totals['sales']     = (int) $totals['sales'];
$totals['remaining'] = max(0.0, $totals['earned'] - $totals['paid']);

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
    <span class="eyebrow">Dealers under them</span>
    <strong><?= (int) $totals['dealers'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">every sale of theirs earns the override</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Completed sales</span>
    <strong><?= (int) $totals['sales'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">their own and their dealers'</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Commission earned</span>
    <strong><?= e(money($totals['earned'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(rtrim(rtrim(number_format(distributor_override_rate() * 100, 2, '.', ''), '0'), '.')) ?>%
        override, <?= e(rtrim(rtrim(number_format(distributor_direct_rate() * 100, 2, '.', ''), '0'), '.')) ?>% direct</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Still owed</span>
    <strong><?= e(money($totals['remaining'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money($totals['paid'])) ?> paid so far</span>
    </span>
  </span>
</div>

<?php /* Everything a sort changes, swapped as one: the table and the hidden
         drawer markup its Details buttons open. */ ?>
<div data-live-list data-live-quiet>
<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Distributors</h2>
      <span class="eyebrow">
        <?= (int) $paging['from'] ?>–<?= (int) $paging['to'] ?> of <?= (int) $paging['total'] ?>
      </span>
    </div>
    <button type="button" class="btn-add" data-modal-open="distributorModal">
      <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a distributor
    </button>
  </div>

  <?php if (!$distributors): ?>
    <?php /* an empty filter and an empty business are different facts */ ?>
    <p class="empty">
      No distributors yet. Add one and they get a code, and dealers to put under it.
    </p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--dealers">
        <?php /* the two sortable headers carry a chevron as well as their label,
                 so they need more room than the numbers under them do */ ?>
        <colgroup>
          <col style="width:17%">
          <col style="width:11%">
          <col style="width:19%">
          <col style="width:13%">
          <col style="width:14%">
          <col style="width:14%">
          <col style="width:12%">
        </colgroup>
        <thead>
          <tr>
            <th>Distributor</th>
            <th>Code</th>
            <th>Link</th>
            <?php /* click to order by how many dealers each one holds */ ?>
            <th class="th-filter-cell">
              <a class="th-filter<?= str_starts_with($sort, 'dealers-') ? ' is-filtered' : '' ?>"
                 href="<?= e($distUrlFor($sortStep('dealers'))) ?>"
                 title="Click to sort by dealers held">
                <span class="th-filter__label"><?= e($sortLabel('dealers', 'Dealers')) ?></span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
              </a>
            </th>
            <?php /* and by what each one is still owed */ ?>
            <th class="th-filter-cell">
              <a class="th-filter<?= str_starts_with($sort, 'owed-') ? ' is-filtered' : '' ?>"
                 href="<?= e($distUrlFor($sortStep('owed'))) ?>"
                 title="Click to sort by what is still owed">
                <span class="th-filter__label"><?= e($sortLabel('owed', 'Still owed')) ?></span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
              </a>
            </th>
            <th>Action</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($distributors as $dist): ?>
            <?php $distId = (int) $dist['id']; ?>
            <tr>
              <td>
                <div class="cell-stack">
                  <strong><?= e($dist['full_name']) ?></strong>
                  <?php if ($dist['company']): ?>
                    <span class="cell-sub"><?= e($dist['company']) ?></span>
                  <?php endif; ?>
                  <span class="cell-sub">
                    <?php if ($dist['mobile_number']): ?><?= e($dist['mobile_number']) ?> · <?php endif; ?>
                    <?= e($dist['email'] ?: 'no email') ?>
                  </span>
                  <?php if (!$dist['is_active']): ?>
                    <span class="pill pill--rejected">Switched off</span>
                  <?php endif; ?>
                </div>
              </td>
              <td><span class="drawer__code"><?= e($dist['distributor_code']) ?></span></td>
              <td>
                <div class="copy-links">
                  <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $product => $label): ?>
                    <?php $link = referral_link((string) $dist['distributor_code'], $product); ?>
                    <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= e($link) ?>"
                            title="Copy the <?= e($label) ?> apply link">
                      <i class="bi bi-link-45deg" aria-hidden="true"></i> <?= e($label) ?>
                      <span class="visually-hidden">apply link for <?= e($dist['full_name']) ?></span>
                    </button>
                  <?php endforeach; ?>
                </div>
              </td>
              <td class="td-amount"><strong><?= count($dist['dealers']) ?></strong></td>
              <td class="td-amount"><strong><?= e(money($dist['totals']['remaining'])) ?></strong></td>
              <td>
                <div class="row-actions">
                  <button type="button" class="icon-btn is-accept"
                          data-drawer="detail-distributor-<?= $distId ?>" data-tab-index="3"
                          data-title="<?= e($dist['full_name']) ?>"
                          data-code="<?= e($dist['distributor_code']) ?>"
                          data-meta="Distributor · added <?= e(format_datetime($dist['created_at'])) ?>"
                          data-status="<?= $dist['is_active'] ? 'accepted' : 'rejected' ?>"
                          data-status-label="<?= $dist['is_active'] ? 'Active' : 'Stopped' ?>"
                          title="Record a payout — <?= e(money($dist['totals']['remaining'])) ?> owed">
                    <i class="bi bi-cash-coin" aria-hidden="true"></i>
                    <span class="visually-hidden">Record a payout for <?= e($dist['full_name']) ?></span>
                  </button>

                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $distId ?>">
                    <button type="submit" name="action" value="toggle"
                            class="icon-btn <?= $dist['is_active'] ? 'is-reject' : 'is-accept' ?>"
                            title="<?= $dist['is_active']
                                ? 'Stop this distributor — their code stops booking commission'
                                : 'Start this distributor again' ?>">
                      <i class="bi <?= $dist['is_active'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"
                         aria-hidden="true"></i>
                      <span class="visually-hidden">
                        <?= $dist['is_active'] ? 'Stop' : 'Start' ?> <?= e($dist['full_name']) ?>
                      </span>
                    </button>
                  </form>

                  <form method="post"
                        data-confirm="Delete <?= e($dist['full_name']) ?>? Their sales are kept. Only possible once no dealers are under them.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $distId ?>">
                    <button type="submit" name="action" value="delete" class="icon-btn is-delete" title="Delete">
                      <i class="bi bi-trash" aria-hidden="true"></i>
                      <span class="visually-hidden">Delete <?= e($dist['full_name']) ?></span>
                    </button>
                  </form>
                </div>
              </td>
              <td class="td-actions">
                <button type="button" class="row-toggle" data-drawer="detail-distributor-<?= $distId ?>"
                        data-title="<?= e($dist['full_name']) ?>"
                        data-code="<?= e($dist['distributor_code']) ?>"
                        data-meta="Distributor · added <?= e(format_datetime($dist['created_at'])) ?>"
                        data-status="<?= $dist['is_active'] ? 'accepted' : 'rejected' ?>"
                        data-status-label="<?= $dist['is_active'] ? 'Active' : 'Stopped' ?>">
                  Details <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </button>
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
      $pagerBase  = $distUrl;
      require __DIR__ . '/partials/pager.php';
    ?>
  <?php endif; ?>
</div>

<!-- ============ detail drawers ============ -->
<?php foreach ($distributors as $dist): ?>
  <?php $srcDist = $dist; require __DIR__ . '/partials/distributor-source.php'; ?>
<?php endforeach; ?>

</div><!-- /data-live-list -->

<?php require __DIR__ . '/partials/drawer.php'; ?>

<!-- the distributor form lives in a dialog, opened by the + on the list above -->
<div class="modal-x<?= $openDistModal ? ' is-open' : '' ?>" id="distributorModal" role="dialog" aria-modal="true"
     aria-labelledby="distributorModalTitle">
  <div class="modal-x__backdrop" data-modal-close></div>

  <div class="modal-x__card modal-x__card--wide">
    <div class="modal-x__head">
      <h2 id="distributorModalTitle"><?= $isEdit ? 'Edit distributor' : 'Add a distributor' ?></h2>
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
          $partnerKind   = 'distributor';
          $partnerEdit   = $editing;
          $partnerIsEdit = $isEdit;
          $partnerCode   = $isEdit ? (string) $editing['distributor_code'] : '';
          require __DIR__ . '/partials/partner-fields.php';
        ?>
      </div>

      <div class="modal-x__foot">
        <?php if (!$isEdit): ?>
          <span class="field-hint">Everything except the name can be added later.</span>
        <?php endif; ?>
        <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Save distributor' : 'Create distributor' ?></button>
      </div>
    </form>
  </div>
</div>

<!-- the whole dealer form, opened by the button on a distributor's Dealers tab.
     One dialog for the page: the button it was opened from names the distributor,
     and the select inside can still be changed before saving. -->
<div class="modal-x" id="addDealerModal" role="dialog" aria-modal="true" aria-labelledby="addDealerModalTitle">
  <div class="modal-x__backdrop" data-modal-close></div>

  <div class="modal-x__card modal-x__card--wide">
    <div class="modal-x__head">
      <h2 id="addDealerModalTitle">Add a dealer</h2>
      <button type="button" class="modal-x__close" data-modal-close aria-label="Close">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>

    <form method="post">
      <div class="modal-x__body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_dealer">

        <?php
          $partnerKind   = 'dealer';
          $partnerEdit   = null;
          $partnerIsEdit = false;
          $partnerCode   = '';
          $partnerExtra  = 'dealer-distributor-field.php';
          require __DIR__ . '/partials/partner-fields.php';
        ?>
      </div>

      <div class="modal-x__foot">
        <span class="field-hint">Everything except the name can be added later.</span>
        <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn--primary">Create dealer</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
