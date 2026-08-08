<?php
/**
 * Admin login. Throttles to 8 failed attempts per email in 15 minutes.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

if (current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $ip       = $_SERVER['REMOTE_ADDR'] ?? null;

    $recent = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE email = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $recent->execute([$email]);

    if ((int) $recent->fetchColumn() >= 8) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } elseif ($email === '' || $password === '') {
        $error = 'Enter both an email address and a password.';
    } else {
        /* the seeded account signs in as "admin" as well as by its address */
        $stmt = db()->prepare('SELECT * FROM admin_users WHERE (email = ? OR name = ?) AND is_active = 1');
        $stmt->execute([$email, $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);

            $_SESSION['admin'] = [
                'id'    => (int) $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
            ];

            db()->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')
                ->execute([$user['id']]);

            db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);

            header('Location: index.php');
            exit;
        }

        db()->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)')
            ->execute([$email, $ip]);

        $error = 'Those details do not match an account.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin sign in — Manifold Clean Energy</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/figtree/figtree.css">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= asset_url('assets/admin.css') ?>">
</head>
<body>

<div class="login-wrap">
  <div class="login-card">
    <img class="login-card__brand" src="<?= SITE_URL ?>/assets/images/manifold.png" alt="Manifold Clean Energy">

    <p class="eyebrow">Admin</p>
    <h1>Sign in</h1>
    <p class="login-card__lead">Submissions from the two application forms, the contact form and the newsletter box.</p>

    <?php if ($error !== ''): ?>
      <p class="alert alert--error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="login.php">
      <?= csrf_field() ?>

      <div class="field">
        <label for="email">Username or email</label>
        <input id="email" name="email" type="text" value="<?= e($email) ?>" autocomplete="username" required autofocus>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
      </div>

      <button type="submit" class="btn btn--primary btn--block">Sign in <i class="bi bi-arrow-right"></i></button>
    </form>
  </div>
</div>

</body>
</html>
