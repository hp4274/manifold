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
$openAccountModal = false;

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

    if ($action === 'commission') {
        /* three rates, saved together or not at all: a half-applied change would
           leave the office paying out under two schemes at once */
        $rates = [
            'dealer_rate'               => 'The dealer share',
            'distributor_override_rate' => "The distributor's share of a dealer sale",
            'distributor_direct_rate'   => 'The distributor share',
        ];

        $clean = [];

        foreach ($rates as $name => $label) {
            $value = str_replace([',', '%'], '', trim((string) ($_POST[$name] ?? '')));

            if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                $error = $label . ' has to be a percentage between 0 and 100.';
                break;
            }

            $clean[$name] = number_format((float) $value, 2, '.', '');
        }

        if ($error === '' && (float) $clean['dealer_rate'] + (float) $clean['distributor_override_rate'] > 100) {
            $error = 'The dealer share and the override together come to more than the whole sale.';
        }

        if ($error === '') {
            foreach ($clean as $name => $value) {
                save_setting($name, $value);
            }

            settings_done('Saved. A dealer sale from now on pays the dealer '
                . rtrim(rtrim($clean['dealer_rate'], '0'), '.') . '% and their distributor '
                . rtrim(rtrim($clean['distributor_override_rate'], '0'), '.') . '%.');
        }
    } elseif ($action === 'dealer_limit') {
        $limit = trim((string) ($_POST['dealer_limit'] ?? ''));

        if (!ctype_digit($limit) || (int) $limit < 1) {
            $error = 'The dealer limit has to be a whole number, one or more.';
        } else {
            save_setting('dealer_limit', (string) (int) $limit);
            settings_done('Saved. A distributor may hold ' . (int) $limit . ' dealers.');
        }
    } elseif ($action === 'stock_prices') {
        /* What a partner pays the tier above them for one unit. Frozen onto
           every order at the moment it is raised, so changing these never
           rewrites an order already placed. */
        $priceKeys = [
            'stock_price_distributor_stove'  => 'the distributor stove price',
            'stock_price_distributor_tuktuk' => 'the distributor TukTuk price',
            'stock_price_dealer_stove'       => 'the dealer stove price',
            'stock_price_dealer_tuktuk'      => 'the dealer TukTuk price',
        ];

        $clean = [];

        foreach ($priceKeys as $key => $label) {
            $value = str_replace(',', '', trim((string) ($_POST[$key] ?? '')));

            if (!is_numeric($value) || (float) $value < 0) {
                $error = ucfirst($label) . ' has to be a number, zero or more.';
                break;
            }

            $clean[$key] = number_format((float) $value, 2, '.', '');
        }

        if ($error === '') {
            foreach ($clean as $key => $value) {
                save_setting($key, $value);
            }

            settings_done('Saved. Stock ordered from now on is priced at these figures.');
        }
    } elseif ($action === 'reward') {
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

$limit = dealer_limit();

/* the distributor closest to the ceiling, so the figure is not abstract */
$fullest = db()->query(
    "SELECT x.full_name, COUNT(d.id) AS held
       FROM distributors x
       LEFT JOIN dealers d ON d.distributor_id = x.id AND d.approval_status <> 'rejected'
      GROUP BY x.id ORDER BY held DESC LIMIT 1"
)->fetch() ?: [];

/* shown as whole percentages, stored the same way */
$rateValues = [
    'dealer_rate'               => dealer_rate() * 100,
    'distributor_override_rate' => distributor_override_rate() * 100,
    'distributor_direct_rate'   => distributor_direct_rate() * 100,
];

/* what the rates come to on a real order, so a percentage is not abstract */
$ratePlan = payment_plan('stove');
$rateSale = (float) $ratePlan['booking'] + (float) $ratePlan['delivery'];

if (($_GET['admin'] ?? '') !== '') {
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([(int) $_GET['admin']]);
    $editingAdmin = $stmt->fetch() ?: null;
    $openAccountModal = $editingAdmin !== null;
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
        Earned once somebody who applied with an existing customer's code has their booking payment
        verified. The new applicant pays the published price in full — nothing is discounted. Nothing is
        transferred automatically either: the office pays it and marks the row sent under
        <a href="referrals.php">Referrals</a>. Each referral keeps the figure that applied on the day it came
        in, so changing this never rewrites a payout already owed.
      </span>
    </div>

    <button type="submit" class="btn btn--primary">Save</button>
  </form>
</div>

<div class="panel">
  <div class="panel__head">
    <h2>Commission</h2>
    <span class="eyebrow">Applies to new sales only</span>
  </div>

  <form method="post" class="panel__body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="commission">

    <?php /* Two ways a sale can arrive, and who is paid out of each. Stating it
             in full is the only way the three fields below read as one scheme
             rather than three unrelated numbers. */ ?>
    <div class="rate-rules">
      <p class="rate-rule">
        <strong>A dealer sells.</strong> The dealer keeps their share and the distributor who signed
        them up takes the override. Every dealer answers to a distributor, so the
        override is owed to nobody.
      </p>
      <p class="rate-rule">
        <strong>A distributor sells.</strong> They keep the distributor share and no dealer is involved.
      </p>
    </div>

    <div class="form-grid">
      <div class="field">
        <label for="dealer_rate">Dealer share</label>
        <div class="rate-input">
          <input id="dealer_rate" name="dealer_rate" type="number" step="0.01" min="0" max="100"
                 value="<?= e(rtrim(rtrim(number_format($rateValues['dealer_rate'], 2, '.', ''), '0'), '.')) ?>"
                 required>
          <span class="rate-input__unit">%</span>
        </div>
        <span class="field-hint">
          <?= e(money($rateSale * $rateValues['dealer_rate'] / 100)) ?> on a
          <?= e(money($rateSale)) ?> stove
        </span>
      </div>

      <div class="field">
        <label for="distributor_override_rate">Distributor override</label>
        <div class="rate-input">
          <input id="distributor_override_rate" name="distributor_override_rate" type="number"
                 step="0.01" min="0" max="100"
                 value="<?= e(rtrim(rtrim(number_format($rateValues['distributor_override_rate'], 2, '.', ''), '0'), '.')) ?>"
                 required>
          <span class="rate-input__unit">%</span>
        </div>
        <span class="field-hint">
          <?= e(money($rateSale * $rateValues['distributor_override_rate'] / 100)) ?> on the same sale,
          to their distributor
        </span>
      </div>

      <div class="field">
        <label for="distributor_direct_rate">Distributor share</label>
        <div class="rate-input">
          <input id="distributor_direct_rate" name="distributor_direct_rate" type="number"
                 step="0.01" min="0" max="100"
                 value="<?= e(rtrim(rtrim(number_format($rateValues['distributor_direct_rate'], 2, '.', ''), '0'), '.')) ?>"
                 required>
          <span class="rate-input__unit">%</span>
        </div>
        <span class="field-hint">
          <?= e(money($rateSale * $rateValues['distributor_direct_rate'] / 100)) ?> when a distributor
          sells it themselves
        </span>
      </div>
    </div>

    <span class="field-hint">
      A share of what the sale is worth — the booking amount plus the delivery amount — and earned only
      once the order is <strong>complete</strong>, with both payments verified. Nothing is transferred
      automatically: the office pays it and records the transfer under
      <a href="dealers.php">Dealers</a> or <a href="distributors.php">Distributors</a>. Every sale keeps
      the figures that applied on the day it came in, so changing these never rewrites commission already
      owed.
    </span>

    <button type="submit" class="btn btn--primary">Save</button>
  </form>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Stock prices</h2>
      <span class="eyebrow">What a partner pays for one unit</span>
    </div>
  </div>

  <form method="post" class="panel__body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="stock_prices">

    <?php foreach (['distributor' => 'A distributor buys from the office',
                    'dealer'      => 'A dealer buys from their distributor'] as $tier => $tierLabel): ?>
      <section class="form-section">
        <div class="form-section__head">
          <h3 class="form-section__title"><?= e($tierLabel) ?></h3>
          <span class="form-section__note">per unit</span>
        </div>

        <div class="form-grid">
          <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk kit'] as $stockProduct => $productLabel): ?>
            <?php
              $priceKey  = 'stock_price_' . $tier . '_' . $stockProduct;
              $retail    = payment_plan($stockProduct);
              $retailSum = (float) $retail['booking'] + (float) $retail['delivery'];
            ?>
            <div class="field">
              <label for="<?= e($priceKey) ?>"><?= e($productLabel) ?></label>
              <input id="<?= e($priceKey) ?>" name="<?= e($priceKey) ?>" type="number" step="0.01" min="0"
                     value="<?= e(number_format(stock_price($tier, $stockProduct), 2, '.', '')) ?>" required>
              <span class="field-hint">Sells to a customer for <?= e(money($retailSum)) ?>.</span>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

    <span class="field-hint">
      A partner pays this before the stock is released, and what they make on it is the difference
      between this and what they sell it for. Every order keeps the price that applied on the day it was
      raised, so changing these never rewrites an order already placed.
    </span>

    <button type="submit" class="btn btn--primary">Save</button>
  </form>
</div>

<div class="panel">
  <div class="panel__head">
    <h2>Dealer limit</h2>
    <span class="eyebrow">Applies the next time somebody adds one</span>
  </div>

  <form method="post" class="panel__body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="dealer_limit">

    <div class="field">
      <label for="dealer_limit">Dealers one distributor may hold</label>
      <input id="dealer_limit" name="dealer_limit" type="number" step="1" min="1"
             value="<?= (int) $limit ?>" required>
      <span class="field-hint">
        Counts dealers waiting for approval as well as approved ones — otherwise a distributor could
        queue up any number of requests against the limit.
        <?php if ($fullest): ?>
          Most held right now: <strong><?= e($fullest['full_name']) ?></strong>
          with <?= (int) $fullest['held'] ?>.
        <?php endif; ?>
        Lowering this never removes anybody; it only stops the next one being added.
      </span>
    </div>

    <button type="submit" class="btn btn--primary">Save</button>
  </form>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Who can sign in</h2>
      <span class="eyebrow"><?= count($admins) ?> account<?= count($admins) === 1 ? '' : 's' ?></span>
    </div>
    <button type="button" class="btn-add" data-modal-open="accountModal">
      <i class="bi bi-plus-lg" aria-hidden="true"></i> Add an account
    </button>
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

<!-- the account form lives in a dialog, opened by the + on the list above -->
<div class="modal-x<?= $openAccountModal ? ' is-open' : '' ?>" id="accountModal" role="dialog" aria-modal="true" aria-labelledby="accountModalTitle">
  <div class="modal-x__backdrop" data-modal-close></div>

  <div class="modal-x__card">
    <div class="modal-x__head">
      <h2 id="accountModalTitle"><?= $editingAdmin ? 'Edit account' : 'Add an account' ?></h2>
      <button type="button" class="modal-x__close" data-modal-close aria-label="Close">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>

    <form method="post" class="modal-x__body">
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
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
