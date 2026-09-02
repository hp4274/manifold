<?php
/**
 * Shared chrome for every signed-in distributor screen.
 *
 * The admin's stylesheet, four items in the rail, and the two things a
 * distributor opens this for in the header: the link they share and a way to
 * start a customer on it.
 *
 * Expects $dist, $pageTitle and optionally $activeNav / $pageLead.
 */

declare(strict_types=1);

$activeNav = $activeNav ?? '';
$pageLead  = $pageLead ?? '';
$navItems  = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => 'bi-grid',      'href' => './'],
    'dealers'   => ['label' => 'Dealers',   'icon' => 'bi-shop',      'href' => 'dealers'],
    'stock'     => ['label' => 'Stock',     'icon' => 'bi-box-seam', 'href' => 'stock'],
    'clients'   => ['label' => 'Clients',   'icon' => 'bi-people',    'href' => 'clients'],
    'payouts'   => ['label' => 'Payouts',   'icon' => 'bi-cash-coin', 'href' => 'payouts'],
    'profile'   => ['label' => 'Profile',   'icon' => 'bi-person-gear', 'href' => 'profile'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> - Manifold distributor</title>
<link rel="icon" type="image/png" href="../assets/images/favicon.png">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="../assets/vendor/figtree/figtree.css">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="../admin/<?= asset_url('assets/admin.css') ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<div class="shell">

  <aside class="sidebar">
    <a class="sidebar__brand" href="./" aria-label="Manifold Clean Energy distributor portal">
      <img src="../assets/images/manifold-white.webp" srcset="../assets/images/manifold-white-240w.webp 240w, ../assets/images/manifold-white.webp 2173w" sizes="120px" alt="Manifold Clean Energy" width="2173" height="724">
    </a>

    <div>
      <p class="sidebar__label">Your network</p>
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
        <strong><?= e($dist['full_name']) ?></strong>
        <?= e($dist['distributor_code']) ?>
      </p>
      <nav class="sidebar__nav">
        <a href="../" target="_blank" rel="noopener">
          <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> View website
        </a>
        <a href="../portal/logout">
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

      <div class="topbar__actions">
        <details class="menu">
          <summary class="btn btn--ghost">
            <i class="bi bi-link-45deg" aria-hidden="true"></i> Copy link
            <i class="bi bi-chevron-down menu__caret" aria-hidden="true"></i>
          </summary>
          <div class="menu__list">
            <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $navProduct => $navLabel): ?>
              <button type="button" class="menu__item"
                      data-copy="<?= e(referral_link((string) $dist['distributor_code'], $navProduct)) ?>">
                <i class="bi bi-clipboard" aria-hidden="true"></i> <?= e($navLabel) ?> link
              </button>
            <?php endforeach; ?>
          </div>
        </details>

        <details class="menu">
          <summary class="btn btn--primary">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a client
            <i class="bi bi-chevron-down menu__caret" aria-hidden="true"></i>
          </summary>
          <div class="menu__list">
            <?php /* One way in, and it is the customer's own: the form, with
                     this partner's code locked into it. Money is never taken by
                     a dealer or a distributor, so there is nothing else to
                     record here. */ ?>
            <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $navProduct => $navLabel): ?>
              <a class="menu__item" target="_blank" rel="noopener"
                 href="<?= e(referral_link((string) $dist['distributor_code'], $navProduct)) ?>">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                They pay Manifold — <?= e($navLabel) ?> form
              </a>
            <?php endforeach; ?>
            <p class="menu__note">
              The form opens with <?= e($dist['distributor_code']) ?> filled in and locked, so the sale counts as yours.
            </p>
          </div>
        </details>
      </div>
    </div>
