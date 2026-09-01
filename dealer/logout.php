<?php
/** Ends the dealer session and returns to the sign-in page. */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

unset($_SESSION['dealer_id'], $_SESSION['dealer_otp_email']);

header('Location: login');
exit;
