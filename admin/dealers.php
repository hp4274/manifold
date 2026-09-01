<?php
/**
 * Dealers: everyone selling on our behalf, and the code each one hands out.
 *
 * The code is allocated once, when the dealer is added, and never changes —
 * it is printed on their link and quoted by every customer they bring in, so
 * rewriting it would orphan the sales already attributed to them.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Dealers';
$pageLead   = 'Who sells for us, the link they share, and what each of them is owed.';
$activeType = 'dealers';

$error = '';

/* carried across the redirect that follows every successful action */
$flash = (string) ($_SESSION['dealers_flash'] ?? '');
unset($_SESSION['dealers_flash']);

/** Finish an action: remember what happened, then reload as a plain GET. */
function dealers_done(string $message): void
{
    $_SESSION['dealers_flash'] = $message;

    header('Location: dealers');
    exit;
}

$editing         = null;
$isEdit          = false;
$openDealerModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? 'save');
    $id     = (int) ($_POST['id'] ?? 0);
    $values = [];

    if ($action === 'save') {
        [$values, $error] = partner_values($_POST);

        /* every dealer answers to a distributor — there is no such thing as
           one without, so this is checked here and not only in the form */
        $values['distributor_id'] = (int) ($_POST['distributor_id'] ?? 0);

        if ($error === '' && $values['distributor_id'] < 1) {
            $error = 'Pick the distributor this dealer answers to.';
        } elseif ($error === '' && !distributor_by_id($values['distributor_id'])) {
            $error = 'That distributor no longer exists.';
        }

        if ($error === '' && $id > 0) {
            $columns = array_keys($values);
            $set     = implode(' = ?, ', $columns) . ' = ?';

            db()->prepare('UPDATE dealers SET ' . $set . ' WHERE id = ?')
                ->execute([...array_values($values), $id]);

            dealers_done('Dealer updated.');
        } elseif ($error === '') {
            $values['dealer_code'] = make_dealer_code();
            $values['created_by']  = (int) $user['id'];

            $names        = array_keys($values);
            $placeholders = implode(', ', array_fill(0, count($names), '?'));

            db()->prepare('INSERT INTO dealers (`' . implode('`, `', $names) . '`) VALUES ('
                . $placeholders . ')')->execute(array_values($values));

            dealers_done($values['full_name'] . ' added, with code ' . $values['dealer_code'] . '.');
        }
    } elseif ($action === 'approve_dealer' || $action === 'reject_dealer') {
        /* A dealer a distributor asked for. The same decision the distributor's
           own drawer offers — the office should not have to go looking for the
           request in a drawer to answer it. */
        $done = dealer_decide($id, $action === 'approve_dealer' ? 'approved' : 'rejected', (int) $user['id']);

        if (isset($done['error'])) {
            $error = $done['error'];
        } else {
            dealers_done($done['message']);
        }
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE dealers SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);

        $now = db()->prepare('SELECT full_name, is_active FROM dealers WHERE id = ?');
        $now->execute([$id]);
        $dealer = $now->fetch() ?: ['full_name' => 'That dealer', 'is_active' => 0];

        dealers_done($dealer['is_active']
            ? $dealer['full_name'] . ' is active again — their code works.'
            : $dealer['full_name'] . ' is switched off. Their code no longer books commission.');
    } elseif ($action === 'delete') {
        /* the sales stay: the foreign key nulls dealer_id rather than removing
           applications, so the customers are never lost with the dealer */
        db()->prepare('DELETE FROM dealers WHERE id = ?')->execute([$id]);

        dealers_done('Dealer deleted. Their customers keep their applications.');
    } else {
        $error = 'Unknown action.';
    }

    /* nothing was saved: reopen the dialog on what was typed, not on a blank
       form — one wrong character should not cost sixteen fields */
    if ($error !== '' && $action === 'save') {
        $existing        = $id > 0 ? dealer_by_id($id) : null;
        $editing         = $values + ['id' => $id, 'dealer_code' => $existing['dealer_code'] ?? ''];
        $isEdit          = $id > 0;
        $openDealerModal = true;
    }
}

if (($_GET['edit'] ?? '') !== '') {
    $editing         = dealer_by_id((int) $_GET['edit']);
    $isEdit          = $editing !== null;
    $openDealerModal = $isEdit;
}

/* one query for the list, one per dealer for the money — the list is short and
   staying with dealer_totals() keeps a single definition of what is owed */
/* Three questions the office actually asks of this list: who is still selling,
   who has been stopped, and who is not under a distributor — that last one
   matters because their sales earn nobody the override. */
/* Two independent questions, so two headers rather than one row of chips:
   the Dealer column steps through selling and stopped, the Distributor column
   through everyone and nobody. Both are real URLs the server answers — the
   browser only swaps the block in rather than reloading the page. */
$show = (string) ($_GET['show'] ?? '');
$dist = (string) ($_GET['dist'] ?? '');

if (!in_array($show, ['waiting', 'active', 'stopped'], true)) {
    $show = '';
}

/* every distributor, in one query: the filter steps through all of them and
   the form's own select takes the active ones out of the same list, so a
   distributor added today appears in both without anything here changing */
$distributorAll = db()->query(
    'SELECT id, full_name, distributor_code, is_active FROM distributors ORDER BY full_name'
)->fetchAll();

$distributorChoices = array_values(array_filter(
    $distributorAll,
    static fn (array $d): bool => (bool) $d['is_active']
));

/* what the Distributor header steps through: everyone, each distributor by
   name, then the dealers nobody signed up */
$distOptions = ['' => 'Distributor'];

foreach ($distributorAll as $choice) {
    $distOptions[(string) $choice['id']] = $choice['full_name'];
}

if (!array_key_exists($dist, $distOptions)) {
    $dist = '';
}

$where = [];

if ($show !== '') {
    $where[] = $show === 'active' ? 'd.is_active = 1' : 'd.is_active = 0';
}

if ($dist !== '') {
    /* $dist is one of the ids just read out of the table, so it is a number */
    $where[] = 'd.distributor_id = ' . (int) $dist;
}

$filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';

/* Each header's counts ignore its own setting but respect the other one, so
   the number on offer is the number you will actually get. */
$distFilter = $dist === '' ? '' : 'd.distributor_id = ' . (int) $dist;
$showFilter = $show === ''
    ? ''
    : ($show === 'waiting' ? "d.approval_status = 'pending'" : 'd.is_active = ' . ($show === 'active' ? '1' : '0'));

$statusCounts = [
    ''        => (int) db()->query('SELECT COUNT(*) FROM dealers d'
                     . ($distFilter ? ' WHERE ' . $distFilter : ''))->fetchColumn(),
    'waiting' => (int) db()->query("SELECT COUNT(*) FROM dealers d WHERE d.approval_status = 'pending'"
                     . ($distFilter ? ' AND ' . $distFilter : ''))->fetchColumn(),
    'active'  => (int) db()->query('SELECT COUNT(*) FROM dealers d WHERE d.is_active = 1'
                     . ($distFilter ? ' AND ' . $distFilter : ''))->fetchColumn(),
    'stopped' => (int) db()->query('SELECT COUNT(*) FROM dealers d WHERE d.is_active = 0'
                     . ($distFilter ? ' AND ' . $distFilter : ''))->fetchColumn(),
];

/* one row per distributor rather than one query per distributor */
$distCounts = array_fill_keys(array_keys($distOptions), 0);
$distTally  = db()->query(
    'SELECT COALESCE(d.distributor_id, 0) AS who, COUNT(*) AS n
       FROM dealers d' . ($showFilter ? ' WHERE ' . $showFilter : '') . '
      GROUP BY COALESCE(d.distributor_id, 0)'
)->fetchAll();

foreach ($distTally as $tally) {
    $key = (string) $tally['who'];

    if (array_key_exists($key, $distCounts)) {
        $distCounts[$key] = (int) $tally['n'];
    }

    $distCounts[''] += (int) $tally['n'];
}

$dealerCount = $dist === '' ? $statusCounts[$show] : $distCounts[$dist];
$paging      = paged($dealerCount, $_GET['page'] ?? 1);

/* what one more click on each header lands on */
$showLabels = ['waiting' => 'Waiting', 'active' => 'Selling', 'stopped' => 'Stopped'];
$showSteps  = ['', 'waiting', 'active', 'stopped'];
$showNext   = $showSteps[(array_search($show, $showSteps, true) + 1) % count($showSteps)];


/* Two more things a header can answer: who has sold the most and who is owed
   the most. Ordered in SQL, because the page holds ten of however many —
   sorting the ten on screen would sort a slice and call it the list. */
$sort = (string) ($_GET['sort'] ?? '');

$soldSql = '(SELECT COUNT(*) FROM applications a
              WHERE a.dealer_id = d.id AND a.status = \'complete\')';

$owedSql = '((SELECT COALESCE(SUM(CASE WHEN a.status = \'complete\'
                                       THEN a.dealer_commission ELSE 0 END), 0)
                FROM applications a WHERE a.dealer_id = d.id)
             - (SELECT COALESCE(SUM(p.amount), 0)
                  FROM dealer_payouts p WHERE p.dealer_id = d.id))';

$sorts = [
    'sales-high' => $soldSql . ' DESC',
    'sales-low'  => $soldSql . ' ASC',
    'owed-high'  => $owedSql . ' DESC',
    'owed-low'   => $owedSql . ' ASC',
];

if (!array_key_exists($sort, $sorts)) {
    $sort = '';
}

$order = $sort === '' ? 'd.is_active DESC, d.full_name' : $sorts[$sort] . ', d.full_name';

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

/** The same list with one control changed — paging starts over, as it must. */
$dealerFilterUrl = static function (string $nextShow, string $nextDist, ?string $nextSort = null) use ($sort): string {
    $query = array_filter(
        ['show' => $nextShow, 'dist' => $nextDist, 'sort' => $nextSort ?? $sort],
        static fn (string $v): bool => $v !== ''
    );

    return 'dealers' . ($query ? '?' . http_build_query($query) : '');
};

/* the money tiles count every dealer, the table shows one page of the filter */
$dealers = db()->query(
    'SELECT d.*, x.full_name AS distributor_name, x.distributor_code
       FROM dealers d
       LEFT JOIN distributors x ON x.id = d.distributor_id' . $filter . '
      ORDER BY ' . $order . '
      LIMIT ' . LIST_PER_PAGE . ' OFFSET ' . $paging['offset']
)->fetchAll();

$dealerUrl = $dealerFilterUrl($show, $dist);

/* the page's own rows carry their figures for the table */
foreach ($dealers as $i => $dealer) {
    $dealers[$i]['totals'] = dealer_totals((int) $dealer['id']);
}

/* the tiles are the whole business, not this page of it — summed in SQL so
   paging can never quietly turn a total into a subtotal */
$totals = db()->query(
    'SELECT COALESCE(SUM(CASE WHEN ' . COMMISSION_EARNED_SQL . '
                              THEN dealer_commission ELSE 0 END), 0) AS earned,
            COALESCE(SUM(' . COMMISSION_EARNED_SQL . '), 0) AS sales
       FROM applications WHERE dealer_id IS NOT NULL'
)->fetch() ?: ['earned' => 0, 'sales' => 0];

$totals['paid']      = (float) db()->query('SELECT COALESCE(SUM(amount), 0) FROM dealer_payouts')->fetchColumn();
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
    <span class="eyebrow">Confirmed sales</span>
    <strong><?= (int) $totals['sales'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">customers who have paid their booking</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Commission earned</span>
    <strong><?= e(money_short($totals['earned'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money_short(commission_value('dealer', 'stove'))) ?> a stove,
        <?= e(money_short(commission_value('dealer', 'tuktuk'))) ?> a kit</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Paid out so far</span>
    <strong><?= e(money_short($totals['paid'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">across every transfer recorded</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Still owed</span>
    <strong><?= e(money_short($totals['remaining'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">earned but not yet transferred</span>
    </span>
  </span>
</div>

<?php /* Everything a filter changes, swapped as one: the table and the hidden
         drawer markup its Details buttons open. */ ?>
<div data-live-list data-live-quiet>
<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Dealers</h2>
      <span class="eyebrow">
        <?= (int) $paging['from'] ?>–<?= (int) $paging['to'] ?> of <?= (int) $paging['total'] ?>
      </span>
    </div>
    <button type="button" class="btn-add" data-modal-open="dealerModal">
      <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a dealer
    </button>
  </div>

  <?php /* The table is drawn even with nothing in it: its header carries the
           filters, so hiding it would take away the way back. */ ?>
  <div class="table-wrap">
      <table class="data-table data-table--dealers has-distributor">
        <?php /* fixed layout, so the columns need telling how to share the width.
                 Action carries three icons and the Details button, which is why
                 it takes the largest share. */ ?>
        <colgroup>
          <col style="width:16%">
          <col style="width:8%">
          <col style="width:12%">
          <col style="width:17%">
          <col style="width:9%">
          <col style="width:11%">
          <?php /* a dealer waiting on the office carries two more icons in
                   here — the decision — so the column is sized for five */ ?>
          <col style="width:17%">
          <col style="width:10%">
        </colgroup>
        <thead>
          <tr>
            <?php /* click to step through selling and stopped, then back to all */ ?>
            <th class="th-filter-cell">
              <a class="th-filter<?= $show === '' ? '' : ' is-filtered' ?>"
                 href="<?= e($dealerFilterUrl($showNext, $dist)) ?>"
                 title="Click to filter — next: <?= e($showNext === '' ? 'all dealers' : $showLabels[$showNext]) ?>">
                <span class="th-filter__label">
                  <?= $show === '' ? 'Dealer' : e($showLabels[$show]) ?>
                  <?= $show === '' ? '' : (int) $statusCounts[$show] ?>
                </span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
              </a>
            </th>
            <th>Code</th>
<?php /* A list that grows with the business: stepping through twenty
                     distributors one click at a time is not a filter, so this
                     one is a picker. It posts as a plain GET without JS. */ ?>
            <th class="th-filter-cell">
              <form method="get" class="th-picker" data-live-form data-base="dealers.php">
                <input type="hidden" name="show" value="<?= e($show) ?>">
                <input type="hidden" name="sort" value="<?= e($sort) ?>">

                <label class="visually-hidden" for="distPick">Filter by distributor</label>
                <select id="distPick" name="dist"
                        class="th-select<?= $dist === '' ? '' : ' is-filtered' ?>">
                  <?php foreach ($distOptions as $optValue => $optLabel): ?>
                    <option value="<?= e((string) $optValue) ?>"
                            <?= (string) $optValue === $dist ? 'selected' : '' ?>>
                      <?= e($optValue === '' ? 'All distributors' : $optLabel) ?>
                      <?= (int) ($distCounts[$optValue] ?? 0) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <noscript><button type="submit" class="btn btn--ghost btn--sm">Go</button></noscript>
              </form>
            </th>
            <th>Link</th>
            <?php /* click to order by how many sales each one has completed */ ?>
            <th class="th-filter-cell">
              <a class="th-filter<?= str_starts_with($sort, 'sales-') ? ' is-filtered' : '' ?>"
                 href="<?= e($dealerFilterUrl($show, $dist, $sortStep('sales'))) ?>"
                 title="Click to sort by completed sales">
                <span class="th-filter__label"><?= e($sortLabel('sales', 'Sales')) ?></span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
              </a>
            </th>
            <?php /* and by what each one is still owed */ ?>
            <th class="th-filter-cell">
              <a class="th-filter<?= str_starts_with($sort, 'owed-') ? ' is-filtered' : '' ?>"
                 href="<?= e($dealerFilterUrl($show, $dist, $sortStep('owed'))) ?>"
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
          <?php if (!$dealers): ?>
            <?php /* an empty filter and an empty business are different facts */ ?>
            <tr class="row-empty">
              <td colspan="8">
                <?= $show === '' && $dist === ''
                    ? 'No entry found — no dealers yet. Add one and they get a code to share.'
                    : 'No entry found. <a href="dealers">Show all</a>.' ?>
              </td>
            </tr>
          <?php endif; ?>

          <?php foreach ($dealers as $dealer): ?>
            <?php $dealerId = (int) $dealer['id']; ?>
            <tr>
              <?php
                /* A dealer the office has not answered yet is not active, whatever
                   is_active says — that flag is for stopping one who is already
                   selling. One reading, used by the pill, the drawer header and
                   what this row is allowed to do. */
                $dealerWaiting = $dealer['approval_status'] === 'pending';
                $dealerState   = $dealerWaiting
                    ? ['pill' => 'pending',  'label' => 'Waiting for approval']
                    : ($dealer['approval_status'] === 'rejected'
                        ? ['pill' => 'rejected', 'label' => 'Turned down']
                        : ($dealer['is_active']
                            ? ['pill' => 'accepted', 'label' => 'Active']
                            : ['pill' => 'rejected', 'label' => 'Stopped']));
              ?>
              <td>
                <div class="cell-stack">
                  <strong><?= e($dealer['full_name']) ?></strong>
                  <?php if ($dealer['company']): ?>
                    <span class="cell-sub"><?= e($dealer['company']) ?></span>
                  <?php endif; ?>
                  <span class="cell-sub">
                    <?php if ($dealer['mobile_number']): ?><?= e($dealer['mobile_number']) ?> · <?php endif; ?>
                    <?= e($dealer['email'] ?: 'no email') ?>
                  </span>
                  <?php if ($dealerWaiting || $dealer['approval_status'] === 'rejected' || !$dealer['is_active']): ?>
                    <span class="pill pill--<?= e($dealerState['pill']) ?>"><?= e($dealerState['label']) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?php /* A dealer who was turned down never got a code, so the cell
                         says so with a dash rather than sitting empty. */ ?>
                <?php if ($dealerWaiting): ?>
                  <span class="cell-sub">on approval</span>
                <?php elseif ($dealer['dealer_code']): ?>
                  <span class="drawer__code"><?= e((string) $dealer['dealer_code']) ?></span>
                <?php else: ?>
                  <span class="cell-none">-</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="cell-stack">
                  <span><?= e($dealer['distributor_name']) ?></span>
                  <span class="cell-sub"><?= e($dealer['distributor_code']) ?></span>
                </div>
              </td>
              <td>
                <?php /* no code, no links to hand out - a turned down dealer had
                         buttons here that copied a URL with nothing in it */ ?>
                <?php if (!$dealer['dealer_code']): ?>
                  <span class="cell-none">-</span>
                <?php else: ?>
                <div class="copy-links">
                  <?php /* the full URLs, spelled out, are a click away under Details */ ?>
                  <?php foreach ($dealerWaiting ? [] : ['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $product => $label): ?>
                    <?php $link = referral_link((string) $dealer['dealer_code'], $product); ?>
                    <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= e($link) ?>"
                            title="Copy the <?= e($label) ?> apply link">
                      <i class="bi bi-link-45deg" aria-hidden="true"></i> <?= e($label) ?>
                      <span class="visually-hidden">apply link for <?= e($dealer['full_name']) ?></span>
                    </button>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </td>
              <td class="td-amount"><strong><?= (int) $dealer['totals']['confirmed'] ?></strong></td>
              <td class="td-amount"><strong><?= e(money($dealer['totals']['remaining'])) ?></strong></td>
              <td>
                <div class="row-actions">
                  <?php if ($dealer['approval_status'] === 'pending'): ?>
                    <?php /* the request a distributor raised, answered here rather
                             than only inside their drawer */ ?>
                    <form method="post">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= $dealerId ?>">
                      <button type="submit" name="action" value="approve_dealer"
                              class="icon-btn is-accept" title="Approve <?= e($dealer['full_name']) ?>">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        <span class="visually-hidden">Approve <?= e($dealer['full_name']) ?></span>
                      </button>
                    </form>

                    <form method="post"
                          data-confirm="Turn down <?= e($dealer['full_name']) ?>? Their code stays dead.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= $dealerId ?>">
                      <button type="submit" name="action" value="reject_dealer"
                              class="icon-btn is-reject" title="Turn down <?= e($dealer['full_name']) ?>">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                        <span class="visually-hidden">Turn down <?= e($dealer['full_name']) ?></span>
                      </button>
                    </form>
                  <?php endif; ?>

                  <?php /* nothing can be stopped while the request is still open */ ?>
                  <?php if (!$dealerWaiting): ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $dealerId ?>">
                    <button type="submit" name="action" value="toggle"
                            class="icon-btn <?= $dealer['is_active'] ? 'is-reject' : 'is-accept' ?>"
                            title="<?= $dealer['is_active']
                                ? 'Stop this dealer — their code stops booking commission'
                                : 'Start this dealer again' ?>">
                      <i class="bi <?= $dealer['is_active'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"
                         aria-hidden="true"></i>
                      <span class="visually-hidden">
                        <?= $dealer['is_active'] ? 'Stop' : 'Start' ?> <?= e($dealer['full_name']) ?>
                      </span>
                    </button>
                  </form>
                  <?php endif; ?>

                  <form method="post"
                        data-confirm="Delete <?= e($dealer['full_name']) ?>? Their customers keep their applications.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $dealerId ?>">
                    <button type="submit" name="action" value="delete" class="icon-btn is-delete" title="Delete">
                      <i class="bi bi-trash" aria-hidden="true"></i>
                      <span class="visually-hidden">Delete <?= e($dealer['full_name']) ?></span>
                    </button>
                  </form>

                </div>
              </td>
              <td class="td-actions">
                <button type="button" class="row-toggle" data-drawer="detail-dealer-<?= $dealerId ?>"
                        data-drawer-url="drawer.php?kind=dealer&amp;id=<?= $dealerId ?>"
                        data-title="<?= e($dealer['full_name']) ?>"
                        data-code="<?= e((string) $dealer['dealer_code']) ?>"
                        data-meta="Dealer · added <?= e(format_datetime($dealer['created_at'])) ?>"
                        data-status="<?= e($dealerState['pill']) ?>"
                        data-status-label="<?= e($dealerState['label']) ?>">
                  Details <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($dealers): ?>
      <?php
        $pagerPage  = $paging['page'];
        $pagerPages = $paging['pages'];
        $pagerTotal = $paging['total'];
        $pagerFrom  = $paging['from'];
        $pagerTo    = $paging['to'];
        $pagerBase  = $dealerUrl;
        require __DIR__ . '/partials/pager.php';
      ?>
    <?php endif; ?>
</div>

</div><!-- /data-live-list -->

<?php require __DIR__ . "/partials/drawer.php"; ?>

<!-- the dealer form lives in a dialog, opened by the + on the list above -->
<div class="modal-x<?= $openDealerModal ? ' is-open' : '' ?>" id="dealerModal" role="dialog" aria-modal="true"
     aria-labelledby="dealerModalTitle">
  <div class="modal-x__backdrop" data-modal-close></div>

  <div class="modal-x__card modal-x__card--wide">
    <div class="modal-x__head">
      <h2 id="dealerModalTitle"><?= $isEdit ? 'Edit dealer' : 'Add a dealer' ?></h2>
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
          $partnerKind   = 'dealer';
          $partnerEdit   = $editing;
          $partnerIsEdit = $isEdit;
          $partnerCode   = $isEdit ? (string) $editing['dealer_code'] : '';
          $partnerExtra  = 'dealer-distributor-field.php';
          require __DIR__ . '/partials/partner-fields.php';
        ?>
      </div>

      <div class="modal-x__foot">
        <?php if (!$isEdit): ?>
          <span class="field-hint">Everything except the name can be added later.</span>
        <?php endif; ?>
        <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Save dealer' : 'Create dealer' ?></button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
