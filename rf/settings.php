<?php
/**
 * R&F: the rates every claim is worked out from.
 *
 * R&F pays the partners, so the three commission rates are theirs to set. They
 * are the same three settings the office edits — one scheme, saved in one place
 * — and changing them here changes what future sales are worth, never a sale
 * already made.
 */

declare(strict_types=1);

require_once __DIR__ . '/../admin/lib.php';

$user      = require_rf();
$pageTitle = 'Settings';
$pageLead  = 'What a sale pays the partners who made it.';
$activeNav = 'settings';

$error = '';

$flash = (string) ($_SESSION['rf_flash'] ?? '');
unset($_SESSION['rf_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ((string) ($_POST['action'] ?? '') === 'commission') {
        $done = commission_values_save($_POST);

        if (isset($done['error'])) {
            $error = $done['error'];
        } else {
            /* reload as a plain GET, so a refresh cannot save the same rates
               twice and the address stays clean */
            $_SESSION['rf_flash'] = $done['message'];

            header('Location: settings');
            exit;
        }
    } else {
        $error = 'Unknown action.';
    }
}

/* R&F has no Dealers or Distributors page to send anyone to */
$rateHasPartnerPages = false;

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
      <h2>Commission</h2>
      <span class="eyebrow">Applies to new sales only</span>
    </div>
  </div>

  <?php require __DIR__ . '/../admin/partials/commission-rates.php'; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
