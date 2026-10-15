<?php
/**
 * C&F desk payouts: the middle of the pass-down.
 *
 * A Principal C&F receives from the office everything released in their
 * network; their own lines are paid by that arrival and the rest is passed to
 * each zonal (or straight to a distributor or dealer whose sale had no zonal).
 * A Zonal C&F does the same one tier lower. Only the money actually held, and
 * only to the partner the sale names next — commission_transfer() decides both.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

[$tier, $me] = require_cf_partner();

$pageTitle = 'Payouts';
$pageLead  = 'What has reached you, and what you owe each partner below you.';
$activeNav = 'payouts';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ((string) ($_POST['action'] ?? '') === 'passdown') {
        [$done, $error] = commission_transfer_post($tier, (int) $me['id'], (string) $me['full_name']);

        if ($error === '') {
            cf_done($done, 'payouts', $tier);
        }
    }
}

$flash       = cf_flash();
$payoutLines = commission_payout_lines([$tier], (int) $me['id']);
$receiptUrl  = 'receipt';

$earned = $paid = 0.0;

foreach ($payoutLines as $l) {
    $earned += (float) $l['amount'];
    $paid   += $l['payout_status'] === 'disbursed' ? (float) $l['amount'] : 0.0;
}

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?><p class="alert alert--ok"><?= e($flash) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="alert alert--error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="tiles">
  <span class="tile"><span class="eyebrow">Your commission earned</span><strong><?= e(money($earned)) ?></strong></span>
  <span class="tile"><span class="eyebrow">Reached you</span><strong><?= e(money($paid)) ?></strong></span>
  <span class="tile"><span class="eyebrow">Still to reach you</span><strong><?= e(money($earned - $paid)) ?></strong></span>
</div>

<?php
$pdType    = $tier;
$pdId      = (int) $me['id'];
$pdAction  = 'payouts?as=' . $tier;
$pdReceipt = static fn (array $t): string => 'receipt?as=' . $tier . '&transfer=' . (int) $t['id'];
require __DIR__ . '/../admin/partials/commission-passdown.php';
?>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
