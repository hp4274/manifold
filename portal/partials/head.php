<?php
/** Shared header for the portal. Expects $pageTitle. */

declare(strict_types=1);

/* portal_roles() asks the dealer and distributor guards whether their sessions
   are still good, so both have to be loaded before the header renders */
require_once __DIR__ . '/../../dealer/lib.php';
require_once __DIR__ . '/../../distributor/lib.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> - Manifold Clean Energy</title>
<link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png">
<link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="../assets/vendor/figtree/figtree.css">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="../assets/css/style.css?v=1788434754">
</head>
<body>
<a class="skip-link" href="#top">Skip to content</a>

<div class="top-bar" role="banner">
  <div class="container-x top-bar__inner">
    <a class="top-bar__email" href="mailto:info@manifoldcleanenergy.co.in">
      <i class="bi bi-envelope"></i>
      <span>info@manifoldcleanenergy.co.in</span>
    </a>

    <div class="top-bar__social" aria-label="Social media links">
      <a href="https://www.facebook.com/people/Manifold-Clean-Energy/61593136808932/" target="_blank" rel="noopener" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
      <a href="https://www.instagram.com/manifoldcleanenergy" target="_blank" rel="noopener" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
    </div>
  </div>
</div>

<header class="site-header" id="siteHeader">
  <div class="nav-wrap">
    <a class="brand" href="../" aria-label="Manifold Clean Energy home">
      <img class="brand-logo" src="../assets/images/manifold.webp" srcset="../assets/images/manifold-360w.webp 360w, ../assets/images/manifold.webp 1524w" sizes="180px" alt="Manifold Clean Energy Pvt. Ltd." width="1524" height="471">
    </a>

    <nav class="main-nav" id="mainNav" aria-label="Main">
      <a href="../#products">Products</a>
      <a href="../#technology">Technology</a>
      <a href="../#about">About Us</a>
      <details class="nav-dropdown">
        <summary>Apply Now <i class="bi bi-chevron-down" aria-hidden="true"></i></summary>
        <div class="nav-dropdown__menu">
          <a href="../apply-tuktuk">TukTuk</a>
          <a href="../apply-stove">Stove</a>
        </div>
      </details>
      <a href="../contact">Contact</a>

      <div class="nav-actions">
        <?php /* the copy inside the nav is the one the mobile panel shows */ ?>
        <?php $navVariant = 'mobile'; require __DIR__ . '/nav-account.php'; ?>
      </div>
    </nav>

    <div class="nav-actions nav-actions--desktop">
      <?php $navVariant = 'desktop'; require __DIR__ . '/nav-account.php'; ?>
    </div>

    <button class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="mainNav">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>

<main id="top">
