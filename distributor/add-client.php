<?php
/**
 * A distributor recording a customer they sold to and were paid by directly.
 *
 * The money went to the distributor, not to Manifold, so the application is
 * created complete and their full share is earned at once. No dealer is
 * involved — a sale a distributor makes themselves cuts the dealer out, the
 * same as one through their own link.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dist      = require_distributor();
$distId    = (int) $dist['id'];
$pageTitle = 'Add a client';
$pageLead  = 'Record a sale you have already been paid for.';
$activeNav = 'clients';

$error      = '';
$saleValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_client') {
    csrf_check();

    [$saleValues, $error] = direct_sale_values($_POST);

    $product = (string) ($_POST['product'] ?? 'stove');
    $saleValues['product'] = $product;

    if (!in_array($product, ['stove', 'tuktuk'], true)) {
        $error = 'Pick which product they bought.';
    }

    /* the same rule as a dealer's: you cannot sell a unit you are not holding */
    $wanted = max(1, (int) ($saleValues['units_required'] ?? 1));

    if ($error === '' && stock_units('distributor', $distId, $product) < $wanted) {
        $error = 'You have ' . stock_units('distributor', $distId, $product) . ' '
            . product_label($product) . ' in stock and this sale is for ' . $wanted
            . '. Order more before recording it.';
    }

    if ($error === '') {
        $id = create_direct_sale($saleValues, $product, null, $dist);

        stock_take_for_sale('distributor', $distId, $product, $wanted, $id);

        $_SESSION['distributor_flash'] = 'Sale recorded. ' . $saleValues['full_name']
            . ' can now sign in to their portal with ' . $saleValues['email'] . '. '
            . $wanted . ' ' . product_label($product) . ' taken off your stock.';

        header('Location: clients.php');
        exit;
    }
}

$saleKind = 'distributor';

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error"><?= e($error) ?></p>
<?php endif; ?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>A sale you took yourself</h2>
      <span class="eyebrow">
        earns you <?= e(rtrim(rtrim(number_format(distributor_direct_rate() * 100, 2, '.', ''), '0'), '.')) ?>%
      </span>
    </div>
    <a class="btn btn--ghost btn--sm" href="clients.php">Back to clients</a>
  </div>

  <div class="panel__body">
    <?php require __DIR__ . '/../admin/partials/direct-sale-form.php'; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
