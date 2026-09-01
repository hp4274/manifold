<?php
/**
 * Shared chrome for every signed-in dealer screen.
 *
 * The admin's stylesheet, three items in the rail instead of ten, and the two
 * things a dealer actually opens this for in the header: the link they share
 * and a way to start a new customer on it.
 *
 * Expects $dealer, $pageTitle and optionally $activeNav / $pageLead.
 */

declare(strict_types=1);

$activeNav = $activeNav ?? '';
$pageLead  = $pageLead ?? '';
$navItems  = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => 'bi-grid',        'href' => './'],
    'stock'     => ['label' => 'Stock',     'icon' => 'bi-box-seam',    'href' => 'stock'],
    'clients'   => ['label' => 'Clients',   'icon' => 'bi-people',      'href' => 'clients'],
    'payouts'   => ['label' => 'Payouts',   'icon' => 'bi-cash-coin',   'href' => 'payouts'],
    'profile'   => ['label' => 'Profile',   'icon' => 'bi-person-gear', 'href' => 'profile'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> - Manifold dealer</title>
<link rel="icon" type="image/png" href="../assets/images/favicon.png">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="../assets/vendor/figtree/figtree.css">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="../admin/<?= asset_url('assets/admin.css') ?>">
</head>
<body>

<div class="shell">

  <aside class="sidebar">
    <a class="sidebar__brand" href="./" aria-label="Manifold Clean Energy dealer portal">
      <img src="../assets/images/manifold-white.webp" alt="Manifold Clean Energy">
    </a>

    <div>
      <p class="sidebar__label">Your business</p>
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
        <strong><?= e($dealer['full_name']) ?></strong>
        <?= e($dealer['dealer_code']) ?>
      </p>
      <nav class="sidebar__nav">
        <a href="../" target="_blank" rel="noopener">
          <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> View website
        </a>
        <a href="logout">
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

      <?php /* Both header actions are the same two links: one copies, one opens.
               Every sale a dealer makes has to start from one of them, or the
               commission is never attributed to them. */ ?>
      <div class="topbar__actions">
        <details class="menu">
          <summary class="btn btn--ghost">
            <i class="bi bi-link-45deg" aria-hidden="true"></i> Copy link
            <i class="bi bi-chevron-down menu__caret" aria-hidden="true"></i>
          </summary>
          <div class="menu__list">
            <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $navProduct => $navLabel): ?>
              <button type="button" class="menu__item"
                      data-copy="<?= e(referral_link((string) $dealer['dealer_code'], $navProduct)) ?>">
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
            <?php /* Two different sales, and the difference is who took the
                     money. Recording one you were paid for is not the same as
                     sending somebody to pay Manifold, so they are not the same
                     button. */ ?>
            <a class="menu__item" href="add-client">
              <i class="bi bi-cash-coin" aria-hidden="true"></i> I was paid — record it
            </a>
            <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $navProduct => $navLabel): ?>
              <a class="menu__item" target="_blank" rel="noopener"
                 href="<?= e(referral_link((string) $dealer['dealer_code'], $navProduct)) ?>">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                They pay Manifold — <?= e($navLabel) ?> form
              </a>
            <?php endforeach; ?>
            <p class="menu__note">
              The form opens with <?= e($dealer['dealer_code']) ?> filled in and locked, so the sale counts as yours.
            </p>
          </div>
        </details>
      </div>
    </div>
