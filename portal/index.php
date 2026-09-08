<?php
/**
 * The one sign-in for everybody: applicants, dealers and distributors.
 *
 * Email address, then a one-time code. Nobody picks a role — the address is
 * already registered as something, and that is what decides where they land.
 * Somebody who is two things (a dealer who also bought a stove) is asked which
 * they meant rather than being guessed at.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../dealer/lib.php';
require_once __DIR__ . '/../distributor/lib.php';

/* already signed in: straight to wherever they belong */
$signedIn = portal_roles();

if ($signedIn && ($_GET['stay'] ?? '') === '') {
    if (count($signedIn) === 1) {
        header('Location: ' . role_home($signedIn[0]));
        exit;
    }
}

$step  = 'email';
$email = (string) ($_SESSION['otp_email'] ?? '');
$error = '';
$note  = '';
$roles = $signedIn;
/* a guard sent them here because a signed-in session had expired, not because
   they arrived cold — say so rather than showing a bare form */
$expired = $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['expired']) && !$signedIn;
/* Seconds the resend button waits before it can be pressed, but only when a
   code has just gone out this request: 20 after the first send, 60 after a
   resend. 0 on a plain reload (a wrong code typed in between) so the countdown
   already running in the browser is left to carry on rather than reset. */
$resendFresh = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'request') {
        $email = trim((string) ($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter the email address you are registered with.';
        } else {
            $error = issue_otp($email, 'any');

            if ($error === '') {
                /* Reached only for a known address now — issue_otp returns a
                   message for an unknown or still-pending one, which keeps the
                   form on the email step rather than advancing to the code. */
                $_SESSION['otp_email'] = $email;
                $step = 'code';
                $note = OTP_SENT_NOTICE;
                $resendFresh = 20;
            }
        }
    }

    if ($action === 'resend') {
        $email = (string) ($_SESSION['otp_email'] ?? '');

        if ($email === '') {
            /* nothing to resend to — send them back to the start */
            $step  = 'email';
            $email = '';
        } else {
            $step  = 'code';
            $error = issue_otp($email, 'any');

            if ($error === '') {
                $note        = 'A new code is on its way.';
                $resendFresh = 60;
            }
        }
    }

    if ($action === 'verify') {
        $email = (string) ($_SESSION['otp_email'] ?? '');
        $code  = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
        $step  = 'code';

        if ($email === '') {
            $step  = 'email';
            $error = 'Start again with your email address.';
        } elseif ($code === '') {
            $error = 'Enter the six-digit code.';
        } else {
            $error = verify_otp($email, $code, 'any');

            if ($error === '') {
                unset($_SESSION['otp_email']);
                mark_authenticated();
                $roles = portal_roles();

                /* one role is not a choice, so do not present it as one */
                if (count($roles) === 1) {
                    header('Location: ' . role_home($roles[0]));
                    exit;
                }

                $step = 'choose';
            }
        }
    }

    if ($action === 'restart') {
        unset($_SESSION['otp_email']);
        $step  = 'email';
        $email = '';
    }
} elseif ($roles && count($roles) > 1) {
    $step = 'choose';
} elseif ($email !== '') {
    $step = 'code';
}

$pageTitle = 'Sign in';
$portalNav = 'universal';
require __DIR__ . '/partials/head.php';
?>

<section class="section portal-auth">
  <div class="container-x">
    <div class="portal-card">
      <p class="eyebrow eyebrow--rule">Sign in</p>
      <h1><?= $step === 'choose' ? 'Where to?' : 'One address, one code.' ?></h1>
      <p class="u-sub portal-card__lead">
        <?= $step === 'choose'
            ? 'That address is registered more than once. Pick what you came here for — you can come back and switch.'
            : 'No password. Applicants, dealers and distributors all sign in here: enter your email address and we send a one-time code.' ?>
      </p>

      <?php if ($expired && $step === 'email'): ?>
        <p class="portal-alert portal-alert--warn">
          <i class="bi bi-clock-history" aria-hidden="true"></i>
          Your session ended after a spell of inactivity, for safety. Sign in again to pick up
          where you left off.
        </p>
      <?php endif; ?>

      <?php if ($error !== ''): ?>
        <p class="portal-alert portal-alert--error"><?= e($error) ?></p>
      <?php endif; ?>

      <?php if ($note !== ''): ?>
        <p class="portal-alert portal-alert--ok"><?= e($note) ?></p>
      <?php endif; ?>

      <?php if (!mail_configured() && $step !== 'choose'): ?>
        <p class="portal-alert portal-alert--warn">
          Email is not configured on this server yet, so codes cannot be delivered.
          Call <a href="tel:+919725154186">+91 97251 54186</a> and we will look you up.
        </p>
      <?php endif; ?>

      <?php if ($step === 'choose'): ?>
        <div class="role-picker">
          <?php foreach ($roles as $role): ?>
            <a class="role-card" href="<?= e(role_home($role)) ?>">
              <span class="role-card__icon">
                <i class="bi bi-<?= $role === 'dealer' ? 'shop'
                    : ($role === 'distributor' ? 'diagram-3' : 'clipboard-check') ?>" aria-hidden="true"></i>
              </span>
              <span class="role-card__text">
                <strong><?= e(role_label($role)) ?></strong>
                <span><?= $role === 'applicant'
                    ? 'Your own order, its progress and receipts'
                    : ($role === 'dealer'
                        ? 'Your clients, your link and what you are owed'
                        : 'Your dealers, your sales and what you are owed') ?></span>
              </span>
              <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
          <?php endforeach; ?>
        </div>

        <form method="post" class="portal-restart" action="logout.php">
          <button type="submit" class="portal-link">Sign out instead</button>
        </form>

      <?php elseif ($step === 'email'): ?>
        <form class="form-x" method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="request">

          <div class="field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="<?= e($email) ?>"
                   autocomplete="email" required autofocus placeholder="you@example.com">
            <span class="field-hint">
              Whatever you are registered as — the address you applied with, or the one the office holds
              for you as a dealer or distributor.
            </span>
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
            <div class="otp-field" data-otp>
              <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*"
                     maxlength="6" autocomplete="one-time-code" required autofocus
                     class="portal-code" data-otp-fallback placeholder="6-digit code">
            </div>
            <span class="field-hint">
              Nothing after a couple of minutes? Check the spam folder, then try the other address
              you might be registered under.
            </span>
          </div>

          <button type="submit" class="btn-pill btn-pill--accent form-x__submit">
            Sign in <i class="bi bi-arrow-right"></i>
          </button>
        </form>

        <form method="post" class="portal-resend">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="resend">
          <button type="submit" class="portal-link" data-resend data-resend-fresh="<?= (int) $resendFresh ?>">
            Resend code
          </button>
        </form>

        <form method="post" class="portal-restart">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="restart">
          <button type="submit" class="portal-link">Use a different email address</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php require __DIR__ . '/partials/foot.php'; ?>
