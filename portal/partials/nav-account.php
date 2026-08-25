<?php
/**
 * The one button on the right of the portal navbar.
 *
 * Signed in, it signs you out of whichever portal you are in. Signed out, it is
 * the dealer door — the applicant sign-in is this page, so pointing at it from
 * here would be a button back to where you already are. Expects $portalNav,
 * 'applicant' (the default) or 'dealer'.
 */

declare(strict_types=1);

$navPortal = $portalNav ?? 'applicant';
?>
<?php if ($navPortal === 'dealer'): ?>
  <?php if (function_exists('dealer_user') && dealer_user()): ?>
    <a href="logout.php" class="btn-pill btn-pill--ghost nav-login">Sign out</a>
  <?php else: ?>
    <a href="../portal/index.php" class="btn-pill btn-pill--ghost nav-login">Applicant login</a>
  <?php endif; ?>
<?php elseif (applicant()): ?>
  <a href="logout.php" class="btn-pill btn-pill--ghost nav-login">Sign out</a>
<?php else: ?>
  <a href="../dealer/login.php" class="btn-pill btn-pill--ghost nav-login">Dealer login</a>
<?php endif; ?>
