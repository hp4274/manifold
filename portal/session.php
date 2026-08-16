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

/* the reward is the same public figure the promo popup quotes, so it goes out
   either way */
$reward = ['reward' => money(referral_reward())];

$email = applicant();

if ($email === null) {
    echo json_encode(['signedIn' => false] + $reward);
    exit;
}

$applications = applications_for($email);
$name         = '';
$canRefer     = false;

foreach ($applications as $application) {
    if ($name === '') {
        $name = (string) $application['full_name'];
    }

    if (!empty($application['booking_paid_at']) && $application['status'] !== 'rejected'
        && !empty($application['referral_code'])) {
        $canRefer = true;
    }
}

/* "Priya Sharma" reads better than the full name in a 200px header slot */
$first = $name === '' ? 'My account' : (explode(' ', trim($name))[0] ?: $name);

echo json_encode([
    'signedIn' => true,
    'name'     => $name,
    'first'    => $first,
    'email'    => $email,
    'canRefer' => $canRefer,
] + $reward);
