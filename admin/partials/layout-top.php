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
<title><?= e($pageTitle) ?> - Manifold admin</title>
<link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/favicon.png">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/figtree/figtree.css">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= asset_url('assets/admin.css') ?>">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/datepicker.css">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<div class="shell">

  <aside class="sidebar">
    <a class="sidebar__brand" href="./" aria-label="Manifold Clean Energy admin">
      <img src="<?= SITE_URL ?>/assets/images/manifold-white.webp" alt="Manifold Clean Energy">
    </a>

    <div>
      <p class="sidebar__label">Overview</p>
      <nav class="sidebar__nav">
        <a href="./" class="<?= $activeType === '' ? 'is-active' : '' ?>">
          <i class="bi bi-grid" aria-hidden="true"></i> Dashboard
        </a>
      </nav>
    </div>

    <div>
      <p class="sidebar__label">Forms</p>
      <nav class="sidebar__nav">
        <?php foreach ($types as $key => $sidebarConfig): ?>
          <a href="list?type=<?= e($key) ?>" class="<?= $activeType === $key ? 'is-active' : '' ?>">
            <i class="bi <?= e($sidebarConfig['icon']) ?>" aria-hidden="true"></i>
            <?= e($sidebarConfig['label']) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <div>
      <p class="sidebar__label">Configuration</p>
      <nav class="sidebar__nav">
        <a href="blog" class="<?= $activeType === 'blog' ? 'is-active' : '' ?>">
          <i class="bi bi-journal-text" aria-hidden="true"></i> Blog
        </a>
        <a href="referrals" class="<?= $activeType === 'referrals' ? 'is-active' : '' ?>">
          <i class="bi bi-people" aria-hidden="true"></i> Referrals
        </a>
        <a href="distributors" class="<?= $activeType === 'distributors' ? 'is-active' : '' ?>">
          <i class="bi bi-diagram-3" aria-hidden="true"></i> Distributors
        </a>
        <a href="dealers" class="<?= $activeType === 'dealers' ? 'is-active' : '' ?>">
          <i class="bi bi-shop" aria-hidden="true"></i> Dealers
        </a>
        <a href="stock" class="<?= $activeType === 'stock' ? 'is-active' : '' ?>">
          <i class="bi bi-box-seam" aria-hidden="true"></i> Stock
        </a>
        <a href="vouchers" class="<?= $activeType === 'vouchers' ? 'is-active' : '' ?>">
          <i class="bi bi-receipt-cutoff" aria-hidden="true"></i> Commission
        </a>
        <a href="raffle" class="<?= $activeType === 'raffle' ? 'is-active' : '' ?>">
          <i class="bi bi-ticket-perforated" aria-hidden="true"></i> Raffle
        </a>
        <a href="settings" class="<?= $activeType === 'settings' ? 'is-active' : '' ?>">
          <i class="bi bi-sliders" aria-hidden="true"></i> Settings
        </a>
      </nav>
    </div>

    <div class="sidebar__foot">
      <p class="sidebar__user">
        <strong><?= e($user['name']) ?></strong>
        <?= e($user['email']) ?>
      </p>
      <nav class="sidebar__nav">
        <a href="<?= SITE_URL ?>/" target="_blank" rel="noopener">
          <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> View website
        </a>
        <a href="logout">
          <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out
        </a>
      </nav>
    </div>
  </aside>

  <main class="main" id="main">
    <div class="topbar">
      <div>
        <h1><?= e($pageTitle) ?></h1>
        <?php if ($pageLead !== ''): ?><p><?= e($pageLead) ?></p><?php endif; ?>
      </div>
    </div>
