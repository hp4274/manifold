<?php
/**
 * A distributor correcting the details of a dealer under them.
 *
 * They signed the dealer up, so a wrong pin code or a changed bank account is
 * theirs to fix rather than a mail to the office. Only the details: the code,
 * the approval, the distributor they answer to and whether they are selling all
 * stay with the office, and `partner_values()` returns nothing else.
 *
 * The dealer is fetched by id *and* by distributor, so a guessed id in the
 * address opens somebody else's dealer no more than it opens a stranger's.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dist   = require_distributor();
$distId = (int) $dist['id'];

$dealerId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt     = db()->prepare('SELECT * FROM dealers WHERE id = ? AND distributor_id = ?');
$stmt->execute([$dealerId, $distId]);
$dealer = $stmt->fetch();

if (!$dealer) {
    $_SESSION['distributor_flash'] = 'That dealer is not one of yours.';

    header('Location: dealers');
    exit;
}

$pageTitle = 'Edit ' . $dealer['full_name'];
$pageLead  = 'Their details, as the office holds them.';
$activeNav = 'dealers';

$error   = '';
$editing = $dealer;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_dealer') {
    csrf_check();

    [$values, $error] = partner_values($_POST);

    if ($error === '') {
        $columns = array_keys($values);
        $set     = implode(' = ?, ', $columns) . ' = ?';

        /* the distributor is in the WHERE as well as the id: a dealer moved out
           from under them between opening the form and saving it is no longer
           theirs to change */
        db()->prepare('UPDATE dealers SET ' . $set . ' WHERE id = ? AND distributor_id = ?')
            ->execute([...array_values($values), $dealerId, $distId]);

        $_SESSION['distributor_flash'] = $values['full_name'] . "'s details are saved.";

        header('Location: dealers');
        exit;
    }

    /* nothing was saved: come back to what was typed, not to the old row */
    $editing = $values;
}

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error"><?= e($error) ?></p>
<?php endif; ?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2><?= e($dealer['full_name']) ?></h2>
      <span class="eyebrow">
        <?php if ($dealer['approval_status'] === 'pending'): ?>
          Still with the office · their code comes with the approval
        <?php else: ?>
          Their code and their approval are the office's to change
        <?php endif; ?>
      </span>
    </div>
    <a class="btn btn--ghost btn--sm" href="dealers">Back to dealers</a>
  </div>

  <form method="post">
    <div class="panel__body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_dealer">
      <input type="hidden" name="id" value="<?= (int) $dealer['id'] ?>">

      <?php
        $partnerKind   = 'dealer';
        $partnerEdit   = $editing;
        $partnerIsEdit = true;
        $partnerCode   = (string) $dealer['dealer_code'];
        require __DIR__ . '/../admin/partials/partner-fields.php';
      ?>
    </div>

    <div class="modal-x__foot">
      <span class="field-hint">
        The bank details here are the ones their commission voucher is paid into.
      </span>
      <a class="btn btn--ghost" href="dealers">Cancel</a>
      <button type="submit" class="btn btn--primary">Save their details</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
