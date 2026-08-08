<?php
/**
 * One-time bootstrap: creates the first admin account.
 * Refuses to run once an account exists — delete this file after first use.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$existing = (int) db()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();

if ($existing > 0) {
    exit('An admin account already exists. Delete admin/create-admin.php and sign in at login.php.');
}

$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name     = trim((string) ($_POST['name'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a name and a valid email address.';
    } elseif (strlen($password) < 10) {
        $error = 'Use a password of at least 10 characters.';
    } else {
        db()->prepare('INSERT INTO admin_users (name, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create the first admin — Manifold</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/figtree/figtree.css">
<link rel="stylesheet" href="<?= asset_url('assets/admin.css') ?>">
</head>
<body>

<div class="login-wrap">
  <div class="login-card">
    <p class="eyebrow">First run</p>
    <h1>Create the admin account</h1>

    <?php if ($done): ?>
      <p class="alert alert--ok">Account created. Delete <code>admin/create-admin.php</code> now, then sign in.</p>
      <a class="btn btn--primary btn--block" href="login.php">Go to sign in</a>
    <?php else: ?>
      <p class="login-card__lead">This page stops working as soon as one account exists.</p>

      <?php if ($error !== ''): ?>
        <p class="alert alert--error"><?= e($error) ?></p>
      <?php endif; ?>

      <form method="post">
        <?= csrf_field() ?>

        <div class="field">
          <label for="name">Name</label>
          <input id="name" name="name" type="text" required>
        </div>

        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" required>
        </div>

        <div class="field">
          <label for="password">Password (10 characters or more)</label>
          <input id="password" name="password" type="password" required>
        </div>

        <button type="submit" class="btn btn--primary btn--block">Create account</button>
      </form>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
