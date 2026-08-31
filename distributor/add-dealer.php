<?php
/**
 * A distributor asking for a dealer to be put under them.
 *
 * They fill the form; the office decides. The dealer is created straight away
 * so nothing is lost, but at 'pending' — and a pending dealer's code books
 * nothing, wherever it is typed, because dealer_for_code() insists on an
 * approved one. Nothing here can make a dealer live.
 *
 * The allowance counts pending requests too, so queueing them is not a way
 * around the limit. It is checked here as well as in the page that offers the
 * button: a form can be posted without ever seeing that page.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dist      = require_distributor();
$distId    = (int) $dist['id'];
$pageTitle = 'Add a dealer';
$pageLead  = 'Ask the office to put a dealer under you.';
$activeNav = 'dealers';

$held  = distributor_dealer_count($distId);
$limit = dealer_limit();
$room  = $held < $limit;

$error   = '';
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_dealer') {
    csrf_check();

    [$values, $error] = partner_values($_POST);

    if (!$room) {
        $error = 'You already hold ' . $held . ' dealers, which is the limit. '
            . 'Ask the office to raise it, or to take one out first.';
    }

    if ($error === '') {
        /* the code is issued now and never changes — it simply books nothing
           until the office says yes */
        $values['dealer_code']     = make_dealer_code();
        $values['distributor_id']  = $distId;
        $values['requested_by']    = $distId;
        $values['approval_status'] = 'pending';

        $names        = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($names), '?'));

        db()->prepare('INSERT INTO dealers (`' . implode('`, `', $names) . '`) VALUES ('
            . $placeholders . ')')->execute(array_values($values));

        $_SESSION['distributor_flash'] = $values['full_name']
            . ' has been sent to the office. Their code is ' . $values['dealer_code']
            . ', and it starts booking your override once they are approved.';

        header('Location: dealers.php');
        exit;
    }

    /* nothing was saved: come back to what was typed, not to a blank form */
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
      <h2>A dealer under you</h2>
      <span class="eyebrow">
        <?= (int) $held ?> of <?= (int) $limit ?> used · every completed sale of theirs earns you
        <?= e(rtrim(rtrim(number_format(distributor_override_rate() * 100, 2, '.', ''), '0'), '.')) ?>%
      </span>
    </div>
    <a class="btn btn--ghost btn--sm" href="dealers.php">Back to dealers</a>
  </div>

  <?php if (!$room): ?>
    <p class="empty">
      You already hold <?= (int) $held ?> dealers, which is the limit.
      Ask the office to raise it, or to take one out first.
    </p>
  <?php else: ?>
    <form method="post">
      <div class="panel__body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_dealer">

        <?php
          $partnerKind   = 'dealer';
          $partnerEdit   = $editing;
          $partnerIsEdit = false;
          $partnerCode   = '';
          require __DIR__ . '/../admin/partials/partner-fields.php';
        ?>
      </div>

      <div class="modal-x__foot">
        <span class="field-hint">
          Everything except the name can be added later. The office checks the request before
          their code starts earning.
        </span>
        <a class="btn btn--ghost" href="dealers.php">Cancel</a>
        <button type="submit" class="btn btn--primary">Send to the office</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
