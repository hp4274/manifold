<?php
/**
 * The PHP error log, read from the admin rather than from the server panel.
 *
 * Hosting control panels do not always expose one, so config.php points PHP at
 * admin/logs/php-error.log and this page reads it back. The directory denies
 * web access, so the file itself is never served.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

require_login();

$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (is_file(ERROR_LOG_FILE)) {
        file_put_contents(ERROR_LOG_FILE, '');
    }

    $flash = 'Log cleared.';
}

$lines = (int) ($_GET['lines'] ?? 200);
$lines = max(20, min($lines, 2000));

$exists = is_file(ERROR_LOG_FILE);
$size   = $exists ? (int) filesize(ERROR_LOG_FILE) : 0;
$log    = '';

if ($exists && $size > 0) {
    /* only the tail matters, and the file can grow large */
    $all = preg_split('/\R/', trim((string) file_get_contents(ERROR_LOG_FILE))) ?: [];
    $log = implode("\n", array_slice($all, -$lines));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Error log — Manifold admin</title>
  <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/favicon.png">
  <meta name="robots" content="noindex">
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/figtree/figtree.css">
  <link rel="stylesheet" href="<?= asset_url('assets/admin.css') ?>">
</head>

<body class="admin-body">
  <main class="panel" style="max-width:1000px;margin:40px auto">
    <div class="panel__head">
      <h2>PHP error log</h2>
      <span class="eyebrow">
        <?= $exists ? number_format($size / 1024, 1) . ' KB' : 'no file yet' ?> ·
        last <?= (int) $lines ?> lines
      </span>
    </div>

    <div class="panel__body">
      <?php if ($flash !== ''): ?>
        <p class="alert alert--ok"><?= e($flash) ?></p>
      <?php endif; ?>

      <?php if (!$exists || $log === ''): ?>
        <p class="alert alert--ok">
          Nothing logged. Reproduce the problem once — submit the apply form, open the page that
          fails — then reload this page.
        </p>
      <?php else: ?>
        <pre style="overflow-x:auto;padding:18px;background:var(--tint);border-radius:12px;max-height:60vh"><code><?= e($log) ?></code></pre>

        <form method="post" data-confirm="Clear the error log? The lines cannot be recovered."
              style="margin-top:18px">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn--danger"><i class="bi bi-trash"></i> Clear the log</button>
        </form>
      <?php endif; ?>

      <p style="margin-top:22px">
        <a class="link-arrow" href="?lines=1000">Show 1000 lines</a> ·
        <a class="link-arrow" href="diagnose-submit.php">Submit diagnostics</a> ·
        <a class="link-arrow" href="index.php">Dashboard</a>
      </p>
    </div>
  </main>

  <script src="assets/admin.js"></script>
</body>

</html>
