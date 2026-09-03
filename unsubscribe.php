<?php
/**
 * One-click unsubscribe from the newsletter.
 *
 * Reached two ways, and both have to work:
 *
 *   POST, from the mail client itself, because the message carried
 *         `List-Unsubscribe-Post: List-Unsubscribe=One-Click`. Nobody sees a
 *         page; the answer only has to be a 200.
 *   GET,  from somebody clicking the link in the footer of the email.
 *
 * The address is proved by the token beside it, not by being typed: without it
 * this address would take anybody off the list who could guess an email
 * address, which is everybody.
 *
 * The row is kept and marked rather than deleted — the office should be able to
 * see that somebody left, and a deleted row would be silently welcomed back the
 * next time the address was entered anywhere.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin/lib.php';
require_once __DIR__ . '/admin/mailer.php';

$email = mb_strtolower(trim((string) ($_REQUEST['e'] ?? '')));
$token = (string) ($_REQUEST['t'] ?? '');
$done  = false;

if ($email !== '' && $token !== '' && hash_equals(unsubscribe_token($email), $token)) {
    db()->prepare(
        "UPDATE newsletter_subscribers
            SET status = 'rejected',
                admin_note = CONCAT(COALESCE(NULLIF(admin_note, ''), ''), 'Unsubscribed from the email link.')
          WHERE email = ? AND status <> 'rejected'"
    )->execute([$email]);

    /* An address that was never on the list, or is already off it, still gets
       the same answer: the link is not a way to ask whether we hold it. */
    $done = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* the mail client is not reading prose */
    http_response_code($done ? 200 : 400);
    exit;
}

if (!$done) {
    http_response_code(400);
}

$pageTitle = 'Unsubscribe';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Unsubscribe - Manifold Clean Energy</title>
<meta name="robots" content="noindex">
<link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32.png">
<link rel="stylesheet" href="assets/vendor/figtree/figtree.css">
<link rel="stylesheet" href="assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<main id="main" class="section">
  <div class="container-x">
    <div class="portal-card">
      <p class="eyebrow eyebrow--rule">Newsletter</p>

      <?php if ($done): ?>
        <h1>You are off the list.</h1>
        <p class="u-sub">
          We will not email you about the stove or the conversion kit again. Anything you have
          already applied for is untouched — receipts, payment notices and portal codes are not
          part of the newsletter and keep coming.
        </p>
      <?php else: ?>
        <h1>That link did not work.</h1>
        <p class="u-sub">
          It may have been cut in half by the email client. Copy the whole address out of the
          message, or write to
          <a href="mailto:info@manifoldcleanenergy.co.in">info@manifoldcleanenergy.co.in</a> and we
          will take you off by hand.
        </p>
      <?php endif; ?>

      <p><a class="btn-pill btn-pill--accent" href="./">Back to home <i class="bi bi-arrow-right"></i></a></p>
    </div>
  </div>
</main>

</body>
</html>
