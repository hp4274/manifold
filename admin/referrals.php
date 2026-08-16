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
            $flash = $referrer && send_referral_paid_email($referrer, $referral)
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

/* the referred application on the left, the person who earns on the right */
$rows = db()->query(
    "SELECT a.id, a.reference_code, a.full_name, a.email, a.product, a.status,
            a.created_at, a.booking_paid_at, a.referred_by_code, a.referral_reward,
            a.referral_reward_status, a.referral_reward_sent_at, a.referral_reward_note,
            r.id AS referrer_id, r.full_name AS referrer_name, r.email AS referrer_email,
            r.mobile_number AS referrer_mobile, r.referral_code AS referrer_code
       FROM applications a
       JOIN applications r ON r.id = a.referred_by_id
      ORDER BY FIELD(a.referral_reward_status, 'pending', 'sent', 'cancelled'), a.created_at DESC"
)->fetchAll();

$totals = ['pending' => 0.0, 'sent' => 0.0, 'payable' => 0];

foreach ($rows as $row) {
    if ($row['referral_reward_status'] === 'pending') {
        $totals['pending'] += (float) $row['referral_reward'];

        if (!empty($row['booking_paid_at']) && $row['status'] !== 'rejected') {
            $totals['payable']++;
        }
    } elseif ($row['referral_reward_status'] === 'sent') {
        $totals['sent'] += (float) $row['referral_reward'];
    }
}

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
    <strong><?= e(money($totals['pending'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">including referrals not yet paid up</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Paid out so far</span>
    <strong><?= e(money($totals['sent'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">across every referral marked sent</span>
    </span>
  </span>
</div>

<div class="panel">
  <div class="panel__head">
    <h2>Referrals</h2>
    <span class="eyebrow"><?= count($rows) ?> in total</span>
  </div>

  <?php if (!$rows): ?>
    <p class="empty">Nobody has applied with a referral code yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--referrals">
        <!-- fixed layout, so the columns need telling how to share the width -->
        <colgroup>
          <col style="width:21%">
          <col style="width:21%">
          <col style="width:16%">
          <col style="width:9%">
          <col style="width:17%">
          <col style="width:16%">
        </colgroup>
        <thead>
          <tr>
            <th>Referrer (earns)</th>
            <th>Applied with the code</th>
            <th>Their payment</th>
            <th>Reward</th>
            <th>Payout</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
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
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
