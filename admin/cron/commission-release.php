<?php
/**
 * Releases commission 48 hours after the customer's payment was verified.
 *
 * Safe to run any number of times. Schedule it hourly:
 *
 *   schtasks /create /tn "Manifold commission release" /sc hourly ^
 *     /tr "C:\xampp\php\php.exe C:\xampp\htdocs\manifold\admin\cron\commission-release.php"
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This is a command-line job.\n");
}

require_once __DIR__ . '/../lib.php';

echo commission_release_due() . " commission line(s) released.\n";

