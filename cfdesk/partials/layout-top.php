<?php
/**
 * Chrome for every C&F desk screen. Expects $tier, $me, $pageTitle, $activeNav.
 * Every link carries ?as= so an address holding both tiers stays on the one it chose.
 */

declare(strict_types=1);

$isPrincipal = $tier === 'principal_cf';
$label       = $isPrincipal ? 'Principal C&F' : 'Zonal C&F';
$code        = (string) ($isPrincipal ? $me['principal_code'] : $me['zonal_code']);
$q           = '?as=' . $tier;
$navItems    = ['dashboard' => ['Dashboard', 'bi-grid', './']];

if ($isPrincipal) {
    $navItems['zonals'] = ['Zonal C&F', 'bi-geo-alt', 'zonals'];
}

$navItems['distributors'] = ['Distributors', 'bi-diagram-3', 'distributors'];
$navItems['stock']        = ['Stock', 'bi-box-seam', 'stock'];
$navItems['payouts']      = ['Payouts', 'bi-cash-coin', 'payouts'];
$navItems['settings']     = ['Settings', 'bi-sliders', 'settings'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> - Manifold <?= e($label) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="../assets/vendor/figtree/figtree.css">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="../admin/<?= asset_url('assets/admin.css') ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="shell shell--partner">
  <aside class="sidebar">
    <a class="sidebar__brand" href="./<?= e($q) ?>" aria-label="Manifold Clean Energy">
      <img src="../assets/images/manifold-white.webp" alt="Manifold Clean Energy" width="2173" height="724">
    </a>
    <div>
      <p class="sidebar__label">Your network</p>
      <nav class="sidebar__nav">
        <?php foreach ($navItems as $key => [$text, $icon, $href]): ?>
          <a href="<?= e($href . $q) ?>" class="<?= $activeNav === $key ? 'is-active' : '' ?>">
            <i class="bi <?= e($icon) ?>" aria-hidden="true"></i> <?= e($text) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>
    <div class="sidebar__foot">
      <p class="sidebar__user"><strong><?= e($me['full_name']) ?></strong> <?= e($code) ?></p>
      <nav class="sidebar__nav">
        <a href="../portal/?stay=1"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Switch role</a>
        <a href="../portal/logout"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out</a>
      </nav>
    </div>
  </aside>

  <main class="main" id="main">
    <div class="topbar">
      <div>
        <h1><?= e($pageTitle) ?></h1>
        <?php if (($pageLead ?? '') !== ''): ?><p><?= e($pageLead) ?></p><?php endif; ?>
      </div>
    </div>
