<?php
/**
 * Settings: the referral reward, and the accounts that can sign in.
 *
 * The rules that keep anyone from locking the office out live here — nobody
 * can switch off or delete themselves, and the last active account cannot go
 * either.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Settings';
$pageLead   = 'What the office can change without a developer: the referral reward, and who can sign in.';
$activeType = 'settings';

$error = '';

/* carried across the redirect that follows every successful action */
$flash = (string) ($_SESSION['settings_flash'] ?? '');
unset($_SESSION['settings_flash']);

/** Finish an action: remember what happened, then reload as a plain GET. */
function settings_done(string $message): void
{
    $_SESSION['settings_flash'] = $message;

    header('Location: settings.php');
    exit;
}

/* the account being edited, if the page was opened that way */
$editingAdmin = null;

/** How many accounts could still sign in if this one were gone. */
function other_active_admins(int $exceptId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM admin_users WHERE is_active = 1 AND id <> ?');
    $stmt->execute([$exceptId]);

    return (int) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? 'reward');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'reward') {
        $reward = str_replace(',', '', trim((string) ($_POST['referral_reward'] ?? '')));

        if (!is_numeric($reward) || (float) $reward < 0) {
            $error = 'The referral reward has to be a number, zero or more.';
        } else {
            save_setting('referral_reward', number_format((float) $reward, 2, '.', ''));
            settings_done('Saved. A code used from now on earns its owner '
                . money(referral_reward()) . '.');
        }
    } elseif ($action === 'admin_save') {
        $name     = trim((string) ($_POST['name'] ?? ''));
        $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        $taken = db()->prepare('SELECT id FROM admin_users WHERE email = ? AND id <> ?');
        $taken->execute([$email, $id]);

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'An account needs a name and a valid email address.';
        } elseif ($taken->fetchColumn()) {
            $error = 'Another account already uses that email address.';
        } elseif ($id === 0 && strlen($password) < 10) {
            $error = 'Give the new account a password of at least 10 characters.';
        } elseif ($password !== '' && strlen($password) < 10) {
            $error = 'A password has to be at least 10 characters.';
        } elseif ($id > 0) {
            $sql = 'UPDATE admin_users SET name = ?, email = ?'
                 . ($password === '' ? '' : ', password_hash = ?')
                 . ' WHERE id = ?';

            $params = [$name, $email];

            if ($password !== '') {
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }

            $params[] = $id;
            db()->prepare($sql)->execute($params);

            /* the sidebar reads the session, so keep it honest about yourself */
            if ($id === (int) $user['id']) {
                $_SESSION['admin']['name']  = $name;
                $_SESSION['admin']['email'] = $email;
                $user = current_user();
            }

            settings_done('Account updated.');
        } else {
            db()->prepare('INSERT INTO admin_users (name, email, password_hash) VALUES (?, ?, ?)')
                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

            settings_done('Account created. ' . $name . ' can sign in now.');
        }
    } elseif ($action === 'admin_toggle') {
        if ($id === (int) $user['id']) {
            $error = 'You cannot switch off the account you are signed in with.';
        } elseif (other_active_admins($id) === 0) {
            $error = 'That is the only account left that can sign in.';
        } else {
            db()->prepare('UPDATE admin_users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);

            $now = db()->prepare('SELECT name, is_active FROM admin_users WHERE id = ?');
            $now->execute([$id]);
            $account = $now->fetch() ?: ['name' => 'That account', 'is_active' => 0];

            settings_done($account['name'] . ($account['is_active'] ? ' can sign in again.' : ' can no longer sign in.'));
        }
    } elseif ($action === 'admin_delete') {
        if ($id === (int) $user['id']) {
            $error = 'You cannot delete the account you are signed in with.';
        } elseif (other_active_admins($id) === 0) {
            $error = 'That is the only account left that can sign in.';
        } else {
            db()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
            settings_done('Account deleted. Anything they marked keeps their name off it.');
        }
    } else {
        $error = 'Unknown action.';
    }
}

$reward = referral_reward();

if (($_GET['admin'] ?? '') !== '') {
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([(int) $_GET['admin']]);
    $editingAdmin = $stmt->fetch() ?: null;
}

$admins = db()->query(
    'SELECT id, name, email, is_active, last_login_at, created_at
       FROM admin_users ORDER BY is_active DESC, name'
)->fetchAll();

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

<?php if ($flash !== ''): ?>
  <p class="alert alert--ok"><?= e($flash) ?></p>
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
<div class="panel">
  <div class="panel__head">
    <h2>Who can sign in</h2>
    <span class="eyebrow"><?= count($admins) ?> account<?= count($admins) === 1 ? '' : 's' ?></span>
  </div>

  <div class="table-wrap">
    <table class="data-table data-table--admins">
      <colgroup>
        <col style="width:30%">
        <col style="width:14%">
        <col style="width:26%">
        <col style="width:30%">
      </colgroup>
      <thead>
        <tr>
          <th>Account</th>
          <th>State</th>
          <th>Last signed in</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($admins as $account): ?>
          <?php $isSelf = (int) $account['id'] === (int) $user['id']; ?>
          <tr>
            <td>
              <div class="cell-stack">
                <strong><?= e($account['name']) ?><?= $isSelf ? ' (you)' : '' ?></strong>
                <span class="cell-sub"><?= e($account['email']) ?></span>
                <span class="cell-sub">added <?= e(format_datetime($account['created_at'])) ?></span>
              </div>
            </td>
            <td>
              <span class="pill pill--<?= $account['is_active'] ? 'accepted' : 'rejected' ?>">
                <?= $account['is_active'] ? 'Active' : 'Switched off' ?>
              </span>
            </td>
            <td>
              <span class="cell-sub">
                <?= $account['last_login_at'] ? e(format_datetime($account['last_login_at'])) : 'never' ?>
              </span>
            </td>
            <td>
              <div class="blog-actions">
                <a class="btn btn--ghost btn--sm" href="settings.php?admin=<?= (int) $account['id'] ?>">Edit</a>

                <?php if (!$isSelf): ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                    <button type="submit" name="action" value="admin_toggle" class="btn btn--ghost btn--sm">
                      <?= $account['is_active'] ? 'Switch off' : 'Switch on' ?>
                    </button>
                  </form>

                  <form method="post"
                        data-confirm="Delete the account for <?= e($account['name']) ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                    <button type="submit" name="action" value="admin_delete" class="btn btn--danger btn--sm">
                      Delete
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel panel--open">
  <div class="panel__head">
    <h2><?= $editingAdmin ? 'Edit account' : 'Add an account' ?></h2>
    <?php if ($editingAdmin): ?>
      <a class="eyebrow" href="settings.php">Cancel and add a new one</a>
    <?php endif; ?>
  </div>

  <form method="post" class="panel__body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="admin_save">
    <input type="hidden" name="id" value="<?= $editingAdmin ? (int) $editingAdmin['id'] : 0 ?>">

    <div class="field">
      <label for="admin_name">Name</label>
      <input id="admin_name" name="name" type="text" maxlength="120" required
             value="<?= e($editingAdmin['name'] ?? '') ?>">
    </div>

    <div class="field">
      <label for="admin_email">Email</label>
      <input id="admin_email" name="email" type="email" maxlength="190" required
             value="<?= e($editingAdmin['email'] ?? '') ?>">
      <span class="field-hint">They sign in with this, or with their name.</span>
    </div>

    <div class="field">
      <label for="admin_password">Password</label>
      <input id="admin_password" name="password" type="password" autocomplete="new-password"
             <?= $editingAdmin ? '' : 'required' ?>>
      <span class="field-hint">
        At least 10 characters.
        <?= $editingAdmin ? 'Leave it blank to keep the current one.' : '' ?>
      </span>
    </div>

    <button type="submit" class="btn btn--primary"><?= $editingAdmin ? 'Save account' : 'Create account' ?></button>
  </form>
</div>



<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
