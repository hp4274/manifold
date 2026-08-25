<?php
/**
 * Dealer sign-in: email address, then a one-time code sent to it.
 *
 * The same two steps the applicant portal uses, asked for with the `dealer`
 * audience — so the address has to belong to an active dealer before a code
 * goes out, and again before the code signs anybody in.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

if (dealer_user()) {
    header('Location: index.php');
    exit;
}

$step  = 'email';
$email = (string) ($_SESSION['dealer_otp_email'] ?? '');
$error = '';
$note  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'request') {
        $email = trim((string) ($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter the email address the office holds for you.';
        } else {
            $error = issue_otp($email, 'dealer');

            if ($error === '') {
                $_SESSION['dealer_otp_email'] = $email;
                $step = 'code';
                $note = 'A six-digit code is on its way to ' . $email . '. '
                    . 'It is valid for ' . OTP_TTL_MINUTES . ' minutes.';
            }
        }
    }

    if ($action === 'verify') {
        $email = (string) ($_SESSION['dealer_otp_email'] ?? '');
        $code  = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
        $step  = 'code';

        if ($email === '') {
            $step  = 'email';
            $error = 'Start again with your email address.';
        } elseif ($code === '') {
            $error = 'Enter the six-digit code.';
        } else {
            $error = verify_otp($email, $code, 'dealer');

            if ($error === '') {
                unset($_SESSION['dealer_otp_email']);
                header('Location: index.php');
                exit;
            }
        }
    }

    if ($action === 'restart') {
        unset($_SESSION['dealer_otp_email']);
        $step  = 'email';
        $email = '';
    }
} elseif ($email !== '') {
    $step = 'code';
}

$pageTitle = 'Dealer sign in';
$portalNav = 'dealer';
require __DIR__ . '/../portal/partials/head.php';
?>

<section class="section portal-auth">
  <div class="container-x">
    <div class="portal-card">
      <p class="eyebrow eyebrow--rule">Dealer sign in</p>
      <h1>Your sales, your commission.</h1>
      <p class="u-sub portal-card__lead">
        No password. Enter the email address the office holds for you and we send a one-time code.
      </p>

      <?php if ($error !== ''): ?>
        <p class="portal-alert portal-alert--error"><?= e($error) ?></p>
      <?php endif; ?>

      <?php if ($note !== ''): ?>
        <p class="portal-alert portal-alert--ok"><?= e($note) ?></p>
      <?php endif; ?>

      <?php if (!mail_configured()): ?>
        <p class="portal-alert portal-alert--warn">
          Email is not configured on this server yet, so codes cannot be delivered.
          Call <a href="tel:+919725154186">+91 97251 54186</a> and we will sort it out.
        </p>
      <?php endif; ?>

      <?php if ($step === 'email'): ?>
        <form class="form-x" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="request">

          <div class="field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="<?= e($email) ?>"
                   autocomplete="email" required autofocus placeholder="you@example.com">
          </div>

          <button type="submit" class="btn-pill btn-pill--accent form-x__submit">
            Send me a code <i class="bi bi-arrow-right"></i>
          </button>
        </form>
      <?php else: ?>
        <form class="form-x" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="verify">

          <div class="field">
            <label for="code">Six-digit code sent to <?= e($email) ?></label>
            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*"
                   maxlength="6" autocomplete="one-time-code" required autofocus
                   class="portal-code" placeholder="000000">
          </div>

          <button type="submit" class="btn-pill btn-pill--accent form-x__submit">
            Sign in <i class="bi bi-arrow-right"></i>
          </button>
        </form>

        <form method="post" class="portal-restart">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="restart">
          <button type="submit" class="portal-link">Use a different email address</button>
        </form>
      <?php endif; ?>

      <p class="portal-card__foot">
        Applying for a unit yourself? <a href="../portal/index.php">Track your application</a> instead.
      </p>
    </div>
  </div>
</section>

<?php require __DIR__ . '/../portal/partials/foot.php'; ?>
