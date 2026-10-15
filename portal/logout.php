<?php
/** Ends every portal session this browser holds, whichever role it came in as. */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

unset(
    $_SESSION['applicant_email'],
    $_SESSION['dealer_id'],
    $_SESSION['distributor_id'],
    $_SESSION['principal_cf_id'],
    $_SESSION['zonal_cf_id'],
    $_SESSION['portal_roles'],
    $_SESSION['otp_email'],
    /* so the next sign-in dates a fresh cookie instead of trusting this one's
       stamp and leaving a browser-session cookie in place for a day */
    $_SESSION['cookie_dated_at']
);
clear_authenticated();

header('Location: ./');
exit;
