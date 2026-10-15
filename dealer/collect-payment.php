<?php
/**
 * Dealer direct payment collection desk.
 *
 * Enforces:
 * 1. Customer must belong to this dealer.
 * 2. KYC documents (Aadhaar & Electricity Bill) MUST be verified before payment can be accepted.
 * 3. 48-Hour payment deadline check.
 * 4. Permanent Cancellation Lock on collection.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../admin/client-lib.php';

$dealer = require_dealer();

$actor = [
    'type'  => 'dealer',
    'id'    => (int) $dealer['id'],
    'label' => (string) $dealer['full_name'] . ' (' . (string) $dealer['dealer_code'] . ')',
];

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$app = $id > 0 ? client_for_actor($id, $actor) : null;

$pageTitle = 'Collect Payment';
$activeNav = 'clients';
$error     = '';
$success   = '';

if (!$app) {
    $pageLead = '';
    require __DIR__ . '/partials/layout-top.php';
    echo '<p class="alert alert--error">Client application not found or does not belong to your account.</p>';
    require __DIR__ . '/partials/layout-bottom.php';
    exit;
}

$totals = payment_totals($app);
/* a full payer has no finance documents, so nothing holds collection back */
$isKycVerified = is_full_payment($app) || !empty($app['kyc_verified_at']) || !empty($app['docs_verified_at']);
$isExpired     = is_application_expired($app) || $app['status'] === 'expired';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $stage       = (string) ($_POST['stage'] ?? '');
    $amount      = (float) ($_POST['amount'] ?? 0.0);
    $paymentMode = (string) ($_POST['payment_mode'] ?? 'cash');
    $reference   = trim((string) ($_POST['reference'] ?? ''));

    if (!$isKycVerified) {
        $error = 'Customer KYC documents (Aadhaar Card and Electricity Bill) must be verified before collecting payment.';
    } elseif ($isExpired) {
        $error = 'The 48-hour payment deadline has expired for this booking.';
    } elseif (!in_array($stage, ['booking', 'delivery', 'full'], true)) {
        $error = 'Please select a valid payment stage.';
    } elseif ($amount <= 0) {
        $error = 'Payment amount must be greater than zero.';
    } else {
        $proofPath = null;
        if (!empty($_FILES['proof_file']) && (int) $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
            $proofPath = store_upload('proof_file', PAYMENT_PROOF_DIR);
        }

        $res = partner_collect_payment(
            (int) $app['id'],
            'dealer',
            (int) $dealer['id'],
            $stage,
            $amount,
            $paymentMode,
            $reference,
            $proofPath
        );

        if (!empty($res['error'])) {
            $error = $res['error'];
        } else {
            header('Location: clients?collected=' . (int) $app['id']);
            exit;
        }
    }
}

$pageTitle = 'Collect Payment — ' . $app['full_name'];
$pageLead  = 'Accept and record customer payments directly with immediate receipt generation.';
$backHref  = 'clients';
$backLabel = 'Back to clients';

require __DIR__ . '/partials/layout-top.php';
?>

<div class="panel" style="max-width:760px;margin:0 auto;">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Collect Customer Payment</h2>
      <span class="eyebrow">Booking <?= e($app['reference_code']) ?> · <?= e(product_name((string) $app['product'])) ?></span>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <p class="alert alert--error"><i class="bi bi-exclamation-triangle"></i> <?= e($error) ?></p>
  <?php endif; ?>

  <!-- Precondition 1: Document Verification Audit Banner -->
  <div style="background:var(--color-bg-subtle, #f8f9fa);border:1px solid var(--color-border);padding:16px;border-radius:8px;margin-bottom:20px;">
    <h3 style="margin-top:0;font-size:15px;display:flex;align-items:center;gap:8px;">
      <i class="bi bi-person-badge"></i> Document & Verification Status
    </h3>
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin:10px 0;">
      <div>
        <span style="font-size:12px;color:var(--color-text-muted);display:block;">KYC Status</span>
        <?php if ($isKycVerified): ?>
          <span class="pill pill--complete"><i class="bi bi-patch-check-fill"></i> <?= is_full_payment($app) ? 'Not needed (full payment)' : 'Documents Verified' ?></span>
        <?php else: ?>
          <span class="pill pill--rejected"><i class="bi bi-exclamation-octagon-fill"></i> Verification Pending</span>
        <?php endif; ?>
      </div>

      <div>
        <span style="font-size:12px;color:var(--color-text-muted);display:block;">Cancel window</span>
        <?php if (cancel_window_open($app)): ?>
          <span class="pill pill--booking_pending"><i class="bi bi-stopwatch"></i> Open until <?= e(format_datetime($app['payment_deadline_at'])) ?></span>
        <?php else: ?>
          <span class="pill pill--neutral">Not open</span>
        <?php endif; ?>
      </div>

      <div>
        <span style="font-size:12px;color:var(--color-text-muted);display:block;">Cancellation Status</span>
        <?php if (!empty($app['cancellation_locked']) || $isKycVerified || ($totals['paid'] > 0 && !cancel_window_open($app))): ?>
          <span class="pill pill--complete"><i class="bi bi-shield-lock-fill"></i> Locked (No Cancellation)</span>
        <?php else: ?>
          <span class="pill pill--booking_pending">Open</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$isKycVerified): ?>
      <p style="color:var(--color-danger, #b91c1c);font-size:13px;margin:8px 0 0 0;line-height:1.4;">
        <i class="bi bi-shield-exclamation"></i>
        <strong>Compliance Precondition:</strong> Customer identity documents (Aadhaar Card and Electricity Bill) must be approved by the admin/compliance desk before payment collection is permitted.
      </p>
    <?php endif; ?>
  </div>

  <?php if (!$isKycVerified): ?>
    <div class="alert alert--warn" style="margin-bottom:20px;">
      <i class="bi bi-lock-fill"></i> Direct payment collection is locked until customer documents are verified.
    </div>
  <?php elseif ($isExpired): ?>
    <div class="alert alert--error" style="margin-bottom:20px;">
      <i class="bi bi-clock-history"></i> The 48-hour payment window for this booking has expired.
    </div>
  <?php else: ?>
    <!-- Payment Collection Form -->
    <form method="post" enctype="multipart/form-data" class="form-x"
          data-confirm="Collect and verify this payment? A receipt will be generated and cancellation permanently locked.">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">

      <div class="field">
        <label for="stage">Payment Stage / Option <span class="req">*</span></label>
        <select id="stage" name="stage" class="form-select" required onchange="updateStageAmount()">
          <?php if (!$totals['stages']['booking']['settled'] && !$totals['stages']['full']['settled']): ?>
            <?php if (!is_full_payment($app)): ?>
            <option value="booking" data-amount="<?= (float) $totals['stages']['booking']['amount'] ?>">
              Booking Payment — <?= e(money((float) $totals['stages']['booking']['amount'])) ?>
            </option>
            <?php endif; ?>
            <option value="full" data-amount="<?= (float) $totals['stages']['full']['amount'] ?>">
              Full Payment (100% Upfront) — <?= e(money((float) $totals['stages']['full']['amount'])) ?>
            </option>
          <?php endif; ?>
          <?php if ($totals['stages']['booking']['settled'] && !$totals['stages']['delivery']['settled'] && !$totals['stages']['full']['settled']): ?>
            <option value="delivery" data-amount="<?= (float) $totals['stages']['delivery']['amount'] ?>">
              Delivery Balance — <?= e(money((float) $totals['stages']['delivery']['amount'])) ?>
            </option>
          <?php endif; ?>
        </select>
      </div>

      <div class="field">
        <label for="amount">Amount Collected (₹) <span class="req">*</span></label>
        <input id="amount" name="amount" type="number" step="0.01" required
               value="<?= (float) (!$totals['stages']['booking']['settled'] ? $totals['stages']['booking']['amount'] : $totals['stages']['delivery']['amount']) ?>">
      </div>

      <div class="field">
        <label for="payment_mode">Payment Mode <span class="req">*</span></label>
        <select id="payment_mode" name="payment_mode" class="form-select" required>
          <option value="cash">Cash Collection</option>
          <option value="upi">UPI / QR Transfer</option>
          <option value="bank_transfer">Direct Bank Transfer / NEFT / IMPS</option>
          <option value="pos">Card / POS Swipe</option>
          <option value="cheque">Cheque / Demand Draft</option>
        </select>
      </div>

      <div class="field">
        <label for="reference">UTR / Transaction / Receipt Reference</label>
        <input id="reference" name="reference" type="text" maxlength="120" placeholder="e.g. UPI Ref, Cheque No, Cash Receipt #">
        <span class="field-hint">Optional reference or receipt number for the customer's payment.</span>
      </div>

      <div class="field">
        <label for="proof_file">Proof / Payment Slip (optional)</label>
        <input id="proof_file" name="proof_file" type="file" accept="image/*,application/pdf">
        <span class="field-hint">JPG, PNG, WebP or PDF slip, up to 10 MB.</span>
      </div>

      <div style="background:var(--color-bg-subtle, #f8f9fa);padding:12px;border-radius:6px;margin:16px 0;font-size:13px;">
        <i class="bi bi-shield-lock-fill"></i> <strong>Important:</strong> Recording this collection will immediately generate a receipt, update the order status, and permanently lock cancellation.
      </div>

      <button type="submit" class="btn btn--primary" style="width:100%;">
        <i class="bi bi-cash-coin"></i> Record Payment & Issue Receipt
      </button>
    </form>

    <script>
    function updateStageAmount() {
      var select = document.getElementById('stage');
      var amountInput = document.getElementById('amount');
      if (select && amountInput) {
        var opt = select.options[select.selectedIndex];
        var amt = opt.getAttribute('data-amount');
        if (amt) {
          amountInput.value = amt;
        }
      }
    }
    </script>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
