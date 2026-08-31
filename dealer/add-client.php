<?php
/**
 * A dealer recording a customer they sold to and were paid by directly.
 *
 * The money went to the dealer, not to Manifold, so there is nothing left for
 * the customer to pay: the application is created complete and the commission
 * is earned at once. It is marked as a direct sale so the office can always
 * tell it apart from one that came through the website.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dealer    = require_dealer();
$dealerId  = (int) $dealer['id'];
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

    /* A unit sold out of your own hand is a unit off your own shelf, so the
       stock has to be there before the sale is written. Checked here rather
       than after, because a sale recorded against stock nobody has is a figure
       that can never be reconciled. */
    $wanted = max(1, (int) ($saleValues['units_required'] ?? 1));

    if ($error === '' && stock_units('dealer', $dealerId, $product) < $wanted) {
        $error = 'You have ' . stock_units('dealer', $dealerId, $product) . ' '
            . product_label($product) . ' in stock and this sale is for ' . $wanted
            . '. Order more before recording it.';
    }

    if ($error === '') {
        /* the dealer's own distributor takes the override, exactly as it would
           had the customer used the dealer's link */
        $id = create_direct_sale($saleValues, $product, $dealer, distributor_for_dealer($dealer));

        /* and the units leave the shelf at what they cost, against this sale */
        stock_take_for_sale('dealer', $dealerId, $product, $wanted, $id);

        $_SESSION['dealer_flash'] = 'Sale recorded. ' . $saleValues['full_name']
            . ' can now sign in to their portal with ' . $saleValues['email'] . '. '
            . $wanted . ' ' . product_label($product) . ' taken off your stock.';

        header('Location: clients.php');
        exit;
    }
}

$saleKind = 'dealer';

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
        earns you <?= e(rtrim(rtrim(number_format(dealer_rate() * 100, 2, '.', ''), '0'), '.')) ?>%
      </span>
    </div>
    <a class="btn btn--ghost btn--sm" href="clients.php">Back to clients</a>
  </div>

  <div class="panel__body">
    <?php require __DIR__ . '/../admin/partials/direct-sale-form.php'; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
