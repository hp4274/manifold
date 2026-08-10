<?php
/**
 * Values the office can change without editing config.php.
 * Right now that is the referral reward.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Settings';
$pageLead   = 'Numbers the office can change without a developer.';
$activeType = 'settings';

$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $reward = str_replace(',', '', trim((string) ($_POST['referral_reward'] ?? '')));

    if (!is_numeric($reward) || (float) $reward < 0) {
        $error = 'The referral reward has to be a number, zero or more.';
    } else {
        save_setting('referral_reward', number_format((float) $reward, 2, '.', ''));
        $saved = true;
    }
}

$reward = referral_reward();

/* how the programme is doing, for context next to the field */
$stats = db()->query(
    "SELECT COUNT(*) AS referred,
            COALESCE(SUM(CASE WHEN referral_reward_status = 'sent'
                              THEN referral_reward ELSE 0 END), 0) AS paid,
            COALESCE(SUM(CASE WHEN referral_reward_status = 'pending'
                              THEN referral_reward ELSE 0 END), 0) AS pending
       FROM applications
      WHERE referred_by_id IS NOT NULL"
)->fetch() ?: ['referred' => 0, 'paid' => 0, 'pending' => 0];

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($saved): ?>
  <p class="alert alert--ok">Saved. A code used from now on earns its owner <?= e(money($reward)) ?>.</p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error"><?= e($error) ?></p>
<?php endif; ?>

<div class="tiles">
  <a class="tile" href="referrals.php">
    <span class="eyebrow">Referred applications</span>
    <strong><?= (int) $stats['referred'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">open the payout list</span>
    </span>
  </a>
  <span class="tile">
    <span class="eyebrow">Pending payouts</span>
    <strong><?= e(money((float) $stats['pending'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">owed but not yet transferred</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Paid out so far</span>
    <strong><?= e(money((float) $stats['paid'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">marked sent by the office</span>
    </span>
  </span>
</div>

<div class="panel">
  <div class="panel__head">
    <h2>Referral programme</h2>
    <span class="eyebrow">Applies to new applications only</span>
  </div>

  <form method="post" class="panel__body">
    <?= csrf_field() ?>

    <div class="field">
      <label for="referral_reward">Reward paid to the referrer</label>
      <input id="referral_reward" name="referral_reward" type="number" step="0.01" min="0"
             value="<?= e(number_format($reward, 2, '.', '')) ?>" required>
      <span class="field-hint">
        Earned each time somebody applies with an existing customer's code. The new applicant still pays the
        full <?= e(money((float) PAYMENT_AMOUNT)) ?> fee — nothing is discounted. Nothing is transferred
        automatically either: the office pays it and marks the row sent under
        <a href="referrals.php">Referrals</a>. Each referral keeps the figure that applied on the day it came
        in, so changing this never rewrites a payout already owed.
      </span>
    </div>

    <button type="submit" class="btn btn--primary">Save</button>
  </form>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
