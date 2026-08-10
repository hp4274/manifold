<?php
/**
 * Shared chrome for every signed-in admin screen.
 * Expects $user, $pageTitle and optionally $activeType / $pageLead.
 */

declare(strict_types=1);

$types = submission_types();

$activeType = $activeType ?? '';
$pageLead   = $pageLead ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — Manifold admin</title>
<link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/favicon.png">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/figtree/figtree.css">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= asset_url('assets/admin.css') ?>">
</head>
<body>

<div class="shell">

  <aside class="sidebar">
    <a class="sidebar__brand" href="index.php" aria-label="Manifold Clean Energy admin">
      <img src="<?= SITE_URL ?>/assets/images/manifold.png" alt="Manifold Clean Energy">
    </a>

    <div>
      <p class="sidebar__label">Overview</p>
      <nav class="sidebar__nav">
        <a href="index.php" class="<?= $activeType === '' ? 'is-active' : '' ?>">
          <i class="bi bi-grid" aria-hidden="true"></i> Dashboard
        </a>
      </nav>
    </div>

    <div>
      <p class="sidebar__label">Forms</p>
      <nav class="sidebar__nav">
        <?php foreach ($types as $key => $sidebarConfig): ?>
          <a href="list.php?type=<?= e($key) ?>" class="<?= $activeType === $key ? 'is-active' : '' ?>">
            <i class="bi <?= e($sidebarConfig['icon']) ?>" aria-hidden="true"></i>
            <?= e($sidebarConfig['label']) ?>
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
        <a href="<?= SITE_URL ?>/index.html" target="_blank" rel="noopener">
          <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> View website
        </a>
        <a href="logout.php">
          <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out
        </a>
      </nav>
    </div>
  </aside>

  <main class="main">
    <div class="topbar">
      <div>
        <h1><?= e($pageTitle) ?></h1>
        <?php if ($pageLead !== ''): ?><p><?= e($pageLead) ?></p><?php endif; ?>
      </div>
    </div>
