<?php
/** Shared header for the portal. Expects $pageTitle. */

declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — Manifold Clean Energy</title>
<link rel="icon" type="image/png" href="../assets/images/favicon.png">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="../assets/vendor/figtree/figtree.css">
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="top-bar" role="banner">
  <div class="container-x top-bar__inner">
    <a class="top-bar__email" href="mailto:info@manifoldcleanenergy.com">
      <i class="bi bi-envelope"></i>
      <span>info@manifoldcleanenergy.com</span>
    </a>

    <div class="top-bar__social" aria-label="Social media links">
      <a href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
      <a href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
      <a href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
      <a href="#" aria-label="YouTube"><i class="bi bi-youtube"></i></a>
    </div>
  </div>
</div>

<header class="site-header" id="siteHeader">
  <div class="nav-wrap">
    <a class="brand" href="../index.html" aria-label="Manifold Clean Energy home">
      <img class="brand-logo" src="../assets/images/manifold.png" alt="Manifold Clean Energy Pvt. Ltd.">
    </a>

    <nav class="main-nav" id="mainNav" aria-label="Main">
      <a href="../index.html#products">Products</a>
      <a href="../index.html#technology">Technology</a>
      <a href="../index.html#about">About Us</a>
      <details class="nav-dropdown">
        <summary>Apply Now <i class="bi bi-chevron-down" aria-hidden="true"></i></summary>
        <div class="nav-dropdown__menu">
          <a href="../apply-tuktuk.html">TukTuk</a>
          <a href="../apply-stove.html">Stove</a>
        </div>
      </details>
      <a href="../contact.html">Contact</a>

      <div class="nav-actions">
        <a href="../contact.html" class="btn-pill btn-pill--white nav-cta-mobile">Get In touch</a>
        <?php if (applicant()): ?>
          <a href="logout.php" class="btn-pill btn-pill--ghost nav-login-mobile">Sign out</a>
        <?php else: ?>
          <a href="index.php" class="btn-pill btn-pill--ghost nav-login-mobile">Login</a>
        <?php endif; ?>
      </div>
    </nav>

    <div class="nav-actions nav-actions--desktop">
      <a href="../contact.html" class="btn-pill btn-pill--white nav-cta">Get In touch</a>
      <?php if (applicant()): ?>
        <a href="logout.php" class="btn-pill btn-pill--ghost nav-login">Sign out</a>
      <?php else: ?>
        <a href="index.php" class="btn-pill btn-pill--ghost nav-login">Login</a>
      <?php endif; ?>
    </div>

    <button class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="mainNav">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>

<main id="top">
