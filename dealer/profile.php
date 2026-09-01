<?php
/**
 * A dealer's own details, theirs to keep current.
 *
 * The office needs a live address and a live bank account to pay them, and
 * asking them to email a change and wait is how both go stale. What they cannot
 * touch is anything that decides money or identity: their code, their
 * distributor, and whether they are active. `partner_values()` only ever
 * returns the sixteen detail fields, so nothing else can be posted in.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dealer    = require_dealer();
$dealerId  = (int) $dealer['id'];
$pageTitle = 'Profile';
$pageLead  = 'Your details, as the office holds them.';
$activeNav = 'profile';

$error = '';
$flash = (string) ($_SESSION['dealer_flash'] ?? '');
unset($_SESSION['dealer_flash']);

$editing = $dealer;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile') {
    csrf_check();

    [$values, $error] = partner_values($_POST);

    if ($error === '') {
        $columns = array_keys($values);
        $set     = implode(' = ?, ', $columns) . ' = ?';

        db()->prepare('UPDATE dealers SET ' . $set . ' WHERE id = ?')
            ->execute([...array_values($values), $dealerId]);

        $_SESSION['dealer_flash'] = 'Your details are saved.';

        header('Location: profile');
        exit;
    }

    /* nothing was saved: come back to what was typed, not to the old row */
    $editing = $values;
}

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?>
  <p class="alert alert--ok"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error"><?= e($error) ?></p>
<?php endif; ?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Your details</h2>
      <span class="eyebrow">Your code and who you answer to are the office's to change</span>
    </div>
    <span class="drawer__code"><?= e((string) $dealer['dealer_code']) ?></span>
  </div>

  <form method="post">
    <div class="panel__body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_profile">

      <?php
        $partnerKind   = 'dealer';
        $partnerEdit   = $editing;
        $partnerIsEdit = true;
        $partnerSelf   = true;
        $partnerCode   = (string) $dealer['dealer_code'];
        require __DIR__ . '/../admin/partials/partner-fields.php';
      ?>
    </div>

    <div class="modal-x__foot">
      <span class="field-hint">
        The bank details here are the ones a commission voucher is paid into, so keep them right.
      </span>
      <button type="submit" class="btn btn--primary">Save my details</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
