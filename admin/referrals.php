<?php
/**
 * Referral payouts.
 *
 * One row per application that quoted somebody's code. Nothing is paid
 * automatically — the office transfers the money and marks the row sent here,
 * which is also what tells the referrer it is on its way.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/emails.php';

$user       = require_login();
$pageTitle  = 'Referrals';
$pageLead   = 'Who was referred by whom, and which rewards still have to be paid.';
$activeType = 'referrals';

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $id     = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $note   = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);

    $stmt = db()->prepare('SELECT * FROM applications WHERE id = ?');
    $stmt->execute([$id]);
    $referral = $stmt->fetch();

    if (!$referral || $referral['referred_by_id'] === null) {
        $error = 'That referral no longer exists.';
    } elseif ($action === 'sent') {
        if (empty($referral['booking_paid_at']) || $referral['status'] === 'rejected') {
            $error = 'Wait until ' . $referral['full_name']
                . ' has had their booking payment verified before sending the reward.';
        } else {
            db()->prepare(
                "UPDATE applications
                    SET referral_reward_status = 'sent', referral_reward_sent_at = NOW(),
                        referral_reward_note = ?, referral_reward_by = ?
                  WHERE id = ?"
            )->execute([$note !== '' ? $note : null, (int) $user['id'], $id]);

            $referrerStmt = db()->prepare('SELECT * FROM applications WHERE id = ?');
            $referrerStmt->execute([(int) $referral['referred_by_id']]);
            $referrer = $referrerStmt->fetch();

            $referral['referral_reward_note'] = $note;
            if ($referrer) {
                after_response(static function () use ($referrer, $referral): void {
                    send_referral_paid_email($referrer, $referral);
                });
            }

            $flash = $referrer
                ? 'Marked as sent and the referrer has been emailed.'
                : 'Marked as sent. The email did not go out — check the SMTP settings.';
        }
    } elseif ($action === 'cancelled' || $action === 'pending') {
        db()->prepare(
            'UPDATE applications
                SET referral_reward_status = ?, referral_reward_sent_at = NULL,
                    referral_reward_note = ?, referral_reward_by = ?
              WHERE id = ?'
        )->execute([$action, $note !== '' ? $note : null, (int) $user['id'], $id]);

        $flash = $action === 'cancelled' ? 'Reward cancelled.' : 'Reward marked pending.';
    } else {
        $error = 'Unknown action.';
    }
}

/* Two things can be filtered here and they are not the same question. The
   payout is where the reward has got to (pending, sent, cancelled); the payment
   is where the referred applicant's own order has got to, which is what decides
   whether the reward may be paid at all. */
$payout = (string) ($_GET['payout'] ?? '');

if (!in_array($payout, ['pending', 'sent', 'cancelled', 'payable'], true)) {
    $payout = '';
}

$payment = (string) ($_GET['payment'] ?? '');

if (!in_array($payment, APPLICATION_STATUSES, true)) {
    $payment = '';
}

$where  = [];
$params = [];

if ($payout === 'payable') {
    /* reward_is_payable() as SQL: pending, their booking verified, not rejected */
    $where[] = "a.referral_reward_status = 'pending'
                AND a.booking_paid_at IS NOT NULL AND a.status <> 'rejected'";
} elseif ($payout !== '') {
    $where[]  = 'a.referral_reward_status = ?';
    $params[] = $payout;
}

/* the payout half on its own: the payment header's counts are taken under it */
$payoutFilter = $where ? ' AND ' . implode(' AND ', $where) : '';
$payoutParams = $params;

if ($payment !== '') {
    $where[]  = 'a.status = ?';
    $params[] = $payment;
}

$filter = $where ? ' AND ' . implode(' AND ', $where) : '';

/* every referral matching the filter, for the page count */
$countStmt = db()->prepare(
    'SELECT COUNT(*) FROM applications a
       JOIN applications r ON r.id = a.referred_by_id
      WHERE 1 = 1' . $filter
);
$countStmt->execute($params);
$referralCount = (int) $countStmt->fetchColumn();

$paging = paged($referralCount, $_GET['page'] ?? 1);

/* the URL of this view without a page, for the chips and the pager */
$referralUrl = 'referrals'
    . ($payout !== '' || $payment !== '' ? '?' : '')
    . implode('&', array_filter([
        $payout !== '' ? 'payout=' . urlencode($payout) : '',
        $payment !== '' ? 'payment=' . urlencode($payment) : '',
    ]));

/** One chip's URL, keeping whichever other filter is already on. */
$referralChip = static function (string $key, string $value) use ($payout, $payment): string {
    $parts = [
        'payout'  => $key === 'payout' ? $value : $payout,
        'payment' => $key === 'payment' ? $value : $payment,
    ];

    $query = http_build_query(array_filter($parts, static fn ($v): bool => $v !== ''));

    return 'referrals' . ($query === '' ? '' : '?' . $query);
};

/* Two questions, so two column headers rather than two rows of chips: where
   the reward has got to, and where the referred applicant's own order has. Each
   steps through its own values and leaves the other one alone. */
$payoutSteps  = ['', 'payable', 'pending', 'sent', 'cancelled'];
$paymentSteps = array_merge([''], APPLICATION_STATUSES);

$payoutNext  = $payoutSteps[(array_search($payout, $payoutSteps, true) + 1) % count($payoutSteps)];
$paymentNext = $paymentSteps[(array_search($payment, $paymentSteps, true) + 1) % count($paymentSteps)];

$payoutName = static fn (string $step): string =>
    $step === '' ? 'Payout' : ($step === 'payable' ? 'Ready to pay' : reward_label($step));

$paymentName = static fn (string $step): string =>
    $step === '' ? 'Their payment' : status_short($step);

/* how many sit behind each payout step, whatever the payment filter says */
$payoutCounts = db()->prepare(
    "SELECT COALESCE(SUM(a.referral_reward_status = 'pending'), 0) AS pending,
            COALESCE(SUM(a.referral_reward_status = 'sent'), 0) AS sent,
            COALESCE(SUM(a.referral_reward_status = 'cancelled'), 0) AS cancelled,
            COALESCE(SUM(a.referral_reward_status = 'pending'
                         AND a.booking_paid_at IS NOT NULL
                         AND a.status <> 'rejected'), 0) AS payable,
            COUNT(*) AS total
       FROM applications a
       JOIN applications r ON r.id = a.referred_by_id
      WHERE 1 = 1" . ($payment !== '' ? ' AND a.status = ?' : '')
);
$payoutCounts->execute($payment !== '' ? [$payment] : []);
$payoutCounts = $payoutCounts->fetch() ?: [];

/* one row per payment stage, under whatever the payout filter says */
$paymentCounts = array_fill_keys($paymentSteps, 0);
$paymentTally  = db()->prepare(
    'SELECT a.status, COUNT(*) AS n
       FROM applications a
       JOIN applications r ON r.id = a.referred_by_id
      WHERE 1 = 1' . ($payoutFilter !== '' ? $payoutFilter : '') . '
      GROUP BY a.status'
);
$paymentTally->execute($payoutParams);

foreach ($paymentTally->fetchAll() as $tally) {
    if (array_key_exists($tally['status'], $paymentCounts)) {
        $paymentCounts[$tally['status']] = (int) $tally['n'];
    }

    $paymentCounts[''] += (int) $tally['n'];
}

/* The referred application on the left, the person who earns on the right, and
   newest first: a referral that has just come in is the one somebody is looking
   for. It used to group by reward status, which buried a new one under every
   pending reward already on the list. The status chips above still narrow it. */
$rowStmt = db()->prepare(
    "SELECT a.id, a.reference_code, a.full_name, a.email, a.product, a.status,
            a.created_at, a.booking_paid_at, a.referred_by_code, a.referral_reward,
            a.referral_reward_status, a.referral_reward_sent_at, a.referral_reward_note,
            r.id AS referrer_id, r.full_name AS referrer_name, r.email AS referrer_email,
            r.mobile_number AS referrer_mobile, r.referral_code AS referrer_code
       FROM applications a
       JOIN applications r ON r.id = a.referred_by_id
      WHERE 1 = 1" . $filter . "
      ORDER BY a.created_at DESC
      LIMIT " . LIST_PER_PAGE . ' OFFSET ' . $paging['offset']
);
$rowStmt->execute($params);
$rows = $rowStmt->fetchAll();

/* The tiles are what the office still owes altogether, not what happens to be
   on this page — so they are summed in SQL over every referral rather than over
   $rows. A total that quietly becomes a page subtotal is worse than no total.
   `payable` repeats reward_is_payable() in SQL: pending, their own booking
   payment verified, and not rejected. */
$totalStmt = db()->prepare(
    "SELECT COALESCE(SUM(CASE WHEN a.referral_reward_status = 'pending'
                              THEN a.referral_reward ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN a.referral_reward_status = 'sent'
                              THEN a.referral_reward ELSE 0 END), 0) AS sent,
            COALESCE(SUM(a.referral_reward_status = 'pending'
                         AND a.booking_paid_at IS NOT NULL
                         AND a.status <> 'rejected'), 0) AS payable
       FROM applications a
       JOIN applications r ON r.id = a.referred_by_id
      WHERE 1 = 1" . $filter
);
$totalStmt->execute($params);
$totals = $totalStmt->fetch() ?: ['pending' => 0, 'sent' => 0, 'payable' => 0];

/* PDO hands decimals back as strings; the tiles want numbers */
$totals['pending'] = (float) $totals['pending'];
$totals['sent']    = (float) $totals['sent'];
$totals['payable'] = (int) $totals['payable'];

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
    <span class="eyebrow">Ready to pay</span>
    <strong><?= (int) $totals['payable'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">referred applicant has paid in full</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Pending payouts</span>
    <strong><?= e(money_short($totals['pending'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">including referrals not yet paid up</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Paid out so far</span>
    <strong><?= e(money_short($totals['sent'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">across every referral marked sent</span>
    </span>
  </span>
</div>

<?php /* the panel is swapped in whole when a header changes, so the page
         never reloads under the office's cursor */ ?>
<div data-live-list data-live-quiet>
<div class="panel">
  <div class="panel__head">
    <h2>Referrals</h2>
    <span class="eyebrow">
      <?= (int) $paging['from'] ?>–<?= (int) $paging['to'] ?> of <?= (int) $paging['total'] ?>
    </span>
  </div>

  <?php /* The table is drawn even with nothing in it: its headers are the
           filters, and taking them away when a filter matches nothing leaves
           no way back except the browser. */ ?>
    <div class="table-wrap">
      <table class="data-table data-table--referrals">
        <!-- fixed layout, so the columns need telling how to share the width -->
        <colgroup>
          <col style="width:20%">
          <col style="width:20%">
          <col style="width:15%">
          <col style="width:8%">
          <col style="width:16%">
          <col style="width:21%">
        </colgroup>
        <thead>
          <tr>
            <th>Referrer (earns)</th>
            <th>Applied with the code</th>
            <?php /* where the referred applicant's own order has got to */ ?>
            <th class="th-filter-cell">
              <a class="th-filter<?= $payment === '' ? '' : ' is-filtered' ?>"
                 href="<?= e($referralChip('payment', $paymentNext)) ?>"
                 title="Click to filter by payment stage — next: <?= e($paymentNext === ''
                     ? 'any stage' : $paymentName($paymentNext) . ' ' . $paymentCounts[$paymentNext]) ?>">
                <span class="th-filter__label">
                  <?= e($paymentName($payment)) ?>
                  <?= $payment === '' ? '' : (int) $paymentCounts[$payment] ?>
                </span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
              </a>
            </th>
            <th>Reward</th>
            <?php /* and where their referrer's reward has — a different question */ ?>
            <th class="th-filter-cell">
              <a class="th-filter<?= $payout === '' ? '' : ' is-filtered' ?>"
                 href="<?= e($referralChip('payout', $payoutNext)) ?>"
                 title="Click to filter by payout — next: <?= e($payoutNext === ''
                     ? 'all payouts' : $payoutName($payoutNext) . ' ' . ($payoutCounts[$payoutNext] ?? 0)) ?>">
                <span class="th-filter__label">
                  <?= e($payoutName($payout)) ?>
                  <?= $payout === '' ? '' : (int) ($payoutCounts[$payout] ?? 0) ?>
                </span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
              </a>
            </th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <?php /* an empty filter and an empty page are different facts */ ?>
            <tr class="row-empty">
              <td colspan="6">
                <?= $payout === '' && $payment === ''
                    ? 'No entry found — nobody has applied with a referral code yet.'
                    : 'No entry found. <a href="referrals">Show all</a>.' ?>
              </td>
            </tr>
          <?php endif; ?>

          <?php foreach ($rows as $row): ?>
            <?php $payable = reward_is_payable($row); ?>
            <tr>
              <td>
                <div class="cell-stack">
                  <strong><?= e($row['referrer_name']) ?></strong>
                  <span class="cell-sub"><?= e($row['referrer_email']) ?></span>
                  <span class="cell-sub">
                    <?php if ($row['referrer_mobile']): ?><?= e($row['referrer_mobile']) ?> · <?php endif; ?>
                    code <?= e($row['referrer_code']) ?>
                  </span>
                </div>
              </td>
              <td>
                <div class="cell-stack">
                  <strong><?= e($row['full_name']) ?></strong>
                  <span class="cell-sub"><?= e($row['reference_code']) ?> · <?= e($row['product']) ?></span>
                  <span class="cell-sub"><?= e(format_datetime($row['created_at'])) ?></span>
                </div>
              </td>
              <td>
                <span class="pill pill--<?= e($row['status']) ?>"><?= e(status_short((string) $row['status'])) ?></span>
              </td>
              <td class="td-amount"><strong><?= e(money((float) $row['referral_reward'])) ?></strong></td>
              <td>
                <div class="cell-stack">
                  <span class="pill pill--reward-<?= e($row['referral_reward_status']) ?>">
                    <?= e(reward_label((string) $row['referral_reward_status'])) ?>
                  </span>
                  <?php if ($row['referral_reward_sent_at']): ?>
                    <span class="cell-sub"><?= e(format_datetime($row['referral_reward_sent_at'])) ?></span>
                  <?php endif; ?>
                  <?php if ($row['referral_reward_note']): ?>
                    <span class="cell-sub cell-sub--note" title="<?= e($row['referral_reward_note']) ?>">
                      <?= e($row['referral_reward_note']) ?>
                    </span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?php if ($row['referral_reward_status'] === 'pending'): ?>
                  <form method="post" class="reward-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input type="text" name="note" maxlength="255" placeholder="UPI / UTR reference"
                           <?= $payable ? '' : 'disabled' ?>>
                    <button type="submit" name="action" value="sent" class="btn btn--primary btn--sm"
                            <?= $payable ? '' : 'disabled title="Waiting for their own payment"' ?>>
                      Mark sent
                    </button>
                    <button type="submit" name="action" value="cancelled" class="btn btn--ghost btn--sm">
                      Cancel
                    </button>
                  </form>
                <?php else: ?>
                  <form method="post" class="reward-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button type="submit" name="action" value="pending" class="btn btn--ghost btn--sm">
                      Mark pending
                    </button>
                  </form>
                <?php endif; ?>
              </td>
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
      $pagerBase  = $referralUrl;
      require __DIR__ . '/partials/pager.php';
    ?>
  <?php endif; ?>
</div>
</div><!-- /data-live-list -->

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
