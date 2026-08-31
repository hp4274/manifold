<?php
/**
 * The one button on the right of the portal navbar.
 *
 * Signed in, it signs you out of whichever portal you are in. Signed out, it is
 * the dealer door — the applicant sign-in is this page, so pointing at it from
 * here would be a button back to where you already are.
 *
 * Included twice, once inside the nav and once beside it: the site stylesheet
 * hides `.nav-login-mobile` above the mobile breakpoint and `.nav-login` below
 * it, so exactly one of the two is ever on screen. Expects $portalNav
 * ('applicant' by default, or 'dealer') and $navVariant ('desktop' or 'mobile').
 */

declare(strict_types=1);

$navPortal = $portalNav ?? 'applicant';
$navClass  = ($navVariant ?? 'desktop') === 'mobile' ? 'nav-login-mobile' : 'nav-login';

/* one sign-in for everybody now, so the button only ever says one of two things */
if (portal_roles()) {
    $navHref  = '../portal/logout.php';
    $navLabel = 'Sign out';
} else {
    $navHref  = '../portal/index.php';
    $navLabel = 'Sign in';
}
?>
<a href="<?= e($navHref) ?>" class="btn-pill btn-pill--ghost <?= e($navClass) ?>"><?= e($navLabel) ?></a>
