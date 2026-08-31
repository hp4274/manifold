<?php
/** Ends every portal session this browser holds, whichever role it came in as. */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

unset(
    $_SESSION['applicant_email'],
    $_SESSION['dealer_id'],
    $_SESSION['distributor_id'],
    $_SESSION['portal_roles'],
    $_SESSION['otp_email']
);

header('Location: index.php');
exit;
