<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

unset($_SESSION['applicant_email'], $_SESSION['otp_email']);
session_regenerate_id(true);

header('Location: index.php');
exit;
