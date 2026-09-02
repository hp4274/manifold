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
$pageLead  = 'Record a client you signed up. They pay Manifold, the same as anybody else.';
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
        /* The units stay on the shelf for now. They come off when the customer
           has paid us in full and taken delivery — an application the office
           turns down should not cost anybody stock. */

        $_SESSION['dealer_flash'] = 'Client recorded. The office reviews it, and '
            . $saleValues['full_name'] . ' then pays the booking amount in their own portal — '
            . 'they sign in with ' . $saleValues['email'] . '. Your '
            . $wanted . ' ' . product_label($product)
            . ' stays on your shelf until they take delivery.';

        /* whoever sent them is named back: a reward booked in silence is a
           reward somebody rings up about a month later */
        if (!empty($saleValues['referred_by'])) {
            $_SESSION['dealer_flash'] .= ' ' . $saleValues['referred_by']['full_name']
                . ' is booked for the ' . money_short(referral_reward()) . ' referral reward.';
        }

        header('Location: clients');
        exit;
    }
}

$saleKind = 'dealer';

/* Nothing to sell, nothing to record: a direct sale takes a unit off this
   partner's own shelf, so with an empty shelf the form has no honest outcome.
   The page says so instead of opening it and failing on submit. */
$shelf = stock_balance('dealer', $dealerId);

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error"><?= e($error) ?></p>
<?php endif; ?>

<?php if ((int) $shelf['units'] < 1): ?>
  <div class="panel">
    <div class="panel__body">
      <div class="nostock">
        <span class="nostock__icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
        <h2>There is nothing on your shelf</h2>
        <p>
          Recording a sale takes a unit off your own stock, and you have none - not a
          stove, not a TukTuk kit. Order from your distributor first; the moment an order is
          approved the units land here and this form opens.
        </p>
        <div class="nostock__actions">
          <a class="btn btn--primary" href="stock">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Order stock
          </a>
          <a class="btn btn--ghost" href="clients">Back to clients</a>
        </div>
      </div>
    </div>
  </div>
<?php else: ?>
<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>A client you signed up</h2>
      <span class="eyebrow">
        the customer pays Manifold; you earn <?= e(money_short(commission_value('dealer', 'stove'))) ?> on a stove,
        <?= e(money_short(commission_value('dealer', 'tuktuk'))) ?> on a kit
      </span>
    </div>
    <a class="btn btn--ghost btn--sm" href="clients">Back to clients</a>
  </div>

  <div class="panel__body">
    <?php require __DIR__ . '/../admin/partials/direct-sale-form.php'; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
