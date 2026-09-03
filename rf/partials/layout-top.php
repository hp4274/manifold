<?php
/**
 * The R&F shell.
 *
 * The office's stylesheet and the same furniture — a rail, a topbar, panels —
 * because it is the same product seen by somebody with one job. The rail is
 * short on purpose: R&F pays commission and does nothing else, so there is
 * nothing else to put in it.
 *
 * Expects $user, $pageTitle and optionally $activeNav / $pageLead.
 */

declare(strict_types=1);

$activeNav = $activeNav ?? '';
$navItems  = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => 'bi-grid',      'href' => './'],
    'queue'     => ['label' => 'To check',  'icon' => 'bi-inbox',     'href' => './#queue'],
    'paying'    => ['label' => 'To pay',    'icon' => 'bi-cash-coin', 'href' => './#paying'],
    'history'   => ['label' => 'History',   'icon' => 'bi-clock-history', 'href' => 'history'],
    'settings'  => ['label' => 'Settings',  'icon' => 'bi-sliders',   'href' => 'settings'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> - Manifold R&amp;F</title>
<link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="../assets/vendor/figtree/figtree.css">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
<?php /* the office's stylesheet, with its own cache stamp: asset_url() resolves
         from the admin directory, so the path in front of it is ours */ ?>
<link rel="stylesheet" href="../admin/<?= asset_url('assets/admin.css') ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<div class="shell">

  <aside class="sidebar">
    <a class="sidebar__brand" href="./" aria-label="Manifold Clean Energy R&amp;F portal">
      <img src="../assets/images/manifold-white.webp" srcset="../assets/images/manifold-white-240w.webp 240w, ../assets/images/manifold-white.webp 2173w" sizes="120px" alt="Manifold Clean Energy" width="2173" height="724">
    </a>

    <div>
      <p class="sidebar__label">Commission</p>
      <nav class="sidebar__nav">
        <?php foreach ($navItems as $key => $item): ?>
          <a href="<?= e($item['href']) ?>" class="<?= $activeNav === $key ? 'is-active' : '' ?>">
            <i class="bi <?= e($item['icon']) ?>" aria-hidden="true"></i> <?= e($item['label']) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <div class="sidebar__foot">
      <p class="sidebar__user">
        <strong><?= e($user['name']) ?></strong>
        <?= e($user['email']) ?>
      </p>
      <nav class="sidebar__nav">
        <a href="../admin/logout">
          <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out
        </a>
      </nav>
    </div>
  </aside>

  <main class="main" id="main">
    <div class="topbar">
      <div>
        <h1><?= e($pageTitle) ?></h1>
        <?php if (!empty($pageLead)): ?>
          <p class="topbar__lead"><?= e($pageLead) ?></p>
        <?php endif; ?>
      </div>
    </div>
