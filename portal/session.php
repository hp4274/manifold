<?php
/**
 * Who is signed in, for the site header.
 *
 * The public pages are static HTML and cannot read the PHP session, so they
 * ask here instead. Only ever describes the caller's own session — there is
 * nothing to look up and no parameters to pass.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, private');

$email = applicant();

if ($email === null) {
    echo json_encode(['signedIn' => false]);
    exit;
}

$applications = applications_for($email);
$name         = '';

foreach ($applications as $application) {
    if ($name === '') {
        $name = (string) $application['full_name'];
    }
}

/* "Priya Sharma" reads better than the full name in a 200px header slot */
$first = $name === '' ? 'My account' : (explode(' ', trim($name))[0] ?: $name);

echo json_encode([
    'signedIn' => true,
    'name'     => $name,
    'first'    => $first,
    'email'    => $email,
]);
