<?php
/**
 * The applicant's own details, for filling in a second apply form.
 *
 * The apply pages are static HTML and cannot read the PHP session, so they ask
 * here. Only the caller's own newest application is ever described, and only
 * the fields that stay true from one application to the next — nothing about
 * payments, documents, consent or what they asked for last time.
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

if (!$applications) {
    echo json_encode(['signedIn' => true, 'fields' => new stdClass()]);
    exit;
}

/* who they are and where they live — the parts of the form that do not change
   between a stove and a TukTuk kit */
const PREFILL_FIELDS = [
    'full_name', 'date_of_birth', 'nationality', 'gender', 'occupation',
    'mobile_number', 'alt_mobile_number', 'email', 'id_number',
    'house_number', 'street', 'city', 'state', 'country', 'pin_code',
    'property_type', 'property_type_other', 'ownership_status', 'household_members',
];

$latest = $applications[0];
$fields = [];

foreach (PREFILL_FIELDS as $field) {
    $value = $latest[$field] ?? null;

    if ($value !== null && $value !== '') {
        $fields[$field] = (string) $value;
    }
}

echo json_encode([
    'signedIn' => true,
    'from'     => (string) $latest['reference_code'],
    'fields'   => $fields ?: new stdClass(),
]);
