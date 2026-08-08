<?php
/**
 * Manifold Clean Energy — shared admin helpers.
 * Sessions, CSRF, authentication guard, status vocabulary, output escaping.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/** Workflow states for contact enquiries and newsletter signups. */
const STATUSES = ['new', 'accepted', 'contacted', 'rejected'];

/** Workflow states for product applications, in the order they happen. */
const APPLICATION_STATUSES = ['new', 'pending', 'confirmed', 'payment_pending', 'complete', 'rejected'];

/** The stages an applicant sees in the portal timeline (rejected sits outside). */
const APPLICATION_STAGES = ['new', 'pending', 'confirmed', 'payment_pending', 'complete'];

/** Which status list applies to a submission type. */
function statuses_for(string $type): array
{
    return type_config($type)['table'] === 'applications' ? APPLICATION_STATUSES : STATUSES;
}

/**
 * Human label for a status. The same state reads differently to the two
 * audiences: staff need to know there is a receipt waiting, the applicant
 * needs to know their payment landed.
 */
function status_label(string $status, string $audience = 'admin'): string
{
    $labels = [
        'new'             => 'New',
        'pending'         => 'Under review',
        'confirmed'       => 'Confirmed — awaiting payment',
        'payment_pending' => $audience === 'applicant'
            ? 'Payment submitted — verifying'
            : 'Payment received — verify',
        'complete'        => 'Complete',
        'accepted'        => 'Accepted',
        'contacted'       => 'Contacted',
        'rejected'        => 'Rejected',
    ];

    return $labels[$status] ?? ucfirst($status);
}

/** Short label used in tables, pills and tiles. */
function status_short(string $status): string
{
    $short = [
        'payment_pending' => 'payment received',
        'confirmed'       => 'confirmed',
        'complete'        => 'complete',
    ];

    return $short[$status] ?? $status;
}

/** What the applicant is told at each stage. */
function stage_copy(string $status): array
{
    $copy = [
        'new' => ['Application received',
                  'We have your application and it is queued for review.'],
        'pending' => ['Under review',
                      'Our team is checking your details and working out the technical assessment.'],
        'confirmed' => ['Confirmed — payment due',
                        'Your application is approved. Pay using the QR code we emailed you, then upload the receipt below.'],
        'payment_pending' => ['Payment submitted',
                              'We have your receipt and are verifying it. Nothing more is needed from you.'],
        'complete' => ['Complete',
                       'Payment verified. We will contact you to schedule installation.'],
        'rejected' => ['Not proceeding',
                       'This application is not moving forward. Contact us if you think that is a mistake.'],
    ];

    return $copy[$status] ?? [status_label($status, 'applicant'), ''];
}

/** MF-2026-00042 style reference, unique per application. */
function make_reference_code(int $id): string
{
    return 'MF-' . date('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

/** The four submission types shown in the sidebar. */
function submission_types(): array
{
    return [
        'stove' => [
            'label' => 'Stove applications',
            'icon'  => 'bi-fire',
            'table' => 'applications',
            'entity' => 'application',
        ],
        'tuktuk' => [
            'label' => 'TukTuk applications',
            'icon'  => 'bi-truck-front',
            'table' => 'applications',
            'entity' => 'application',
        ],
        'contact' => [
            'label' => 'Contact enquiries',
            'icon'  => 'bi-envelope',
            'table' => 'contact_messages',
            'entity' => 'contact',
        ],
        'newsletter' => [
            'label' => 'Newsletter signups',
            'icon'  => 'bi-send',
            'table' => 'newsletter_subscribers',
            'entity' => 'newsletter',
        ],
    ];
}

function type_config(string $type): array
{
    $types = submission_types();

    if (!isset($types[$type])) {
        http_response_code(404);
        exit('Unknown submission type.');
    }

    return $types[$type];
}

/**
 * Admin asset URL with a cache-busting stamp, so a changed stylesheet or
 * script is picked up without a hard refresh.
 */
function asset_url(string $relative): string
{
    $file = __DIR__ . '/' . ltrim($relative, '/');
    $stamp = is_file($file) ? filemtime($file) : time();

    return $relative . '?v=' . $stamp;
}

/** Escape for HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';

    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Session expired. Go back, reload the page and try again.');
    }
}

/* ---------- Authentication ---------- */

function current_user(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function require_login(): array
{
    $user = current_user();

    if (!$user) {
        header('Location: login.php');
        exit;
    }

    return $user;
}

function log_status_change(string $entity, int $id, ?string $old, string $new, ?int $userId): void
{
    $stmt = db()->prepare(
        'INSERT INTO status_log (entity, entity_id, old_status, new_status, changed_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$entity, $id, $old, $new, $userId]);
}

/** Counts per status for one submission type, plus the total. */
function status_counts(string $type): array
{
    $config   = type_config($type);
    $statuses = statuses_for($type);
    $counts   = array_fill_keys($statuses, 0);

    if ($config['table'] === 'applications') {
        $stmt = db()->prepare(
            'SELECT status, COUNT(*) AS n FROM applications WHERE product = ? GROUP BY status'
        );
        $stmt->execute([$type]);
    } else {
        $stmt = db()->query('SELECT status, COUNT(*) AS n FROM ' . $config['table'] . ' GROUP BY status');
    }

    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }

    $counts['total'] = array_sum(array_intersect_key($counts, array_flip($statuses)));

    return $counts;
}

/** Human label for a record, used in list rows and page titles. */
function record_title(string $type, array $row): string
{
    if ($type === 'newsletter') {
        return $row['email'];
    }

    return $row['full_name'] ?? $row['name'] ?? ('#' . $row['id']);
}

/**
 * Field groups shown in the expanded row, per submission type.
 * Keys are column names, values are the labels.
 */
function field_groups(string $type): array
{
    $config = type_config($type);

    if ($config['table'] === 'applications') {
        return [
            'Applicant' => ['full_name' => 'Full name', 'date_of_birth' => 'Date of birth', 'nationality' => 'Nationality',
                            'gender' => 'Gender', 'occupation' => 'Occupation', 'mobile_number' => 'Mobile',
                            'alt_mobile_number' => 'Alternative mobile', 'email' => 'Email'],
            'Identification' => ['id_number' => 'ID / passport number', 'id_document_path' => 'ID document',
                                 'residence_proof_path' => 'Residence proof'],
            'Address' => ['house_number' => 'House / unit', 'street' => 'Street', 'city' => 'City',
                          'state' => 'State', 'country' => 'Country', 'pin_code' => 'Pin code'],
            'Property' => ['property_type' => 'Property type', 'property_type_other' => 'Property type (other)',
                           'ownership_status' => 'Ownership', 'household_members' => 'Household members',
                           'existing_fuel' => 'Existing fuel', 'existing_fuel_other' => 'Existing fuel (other)'],
            'Requirement' => ['units_required' => 'Units required', 'intended_usage' => 'Intended usage',
                              'expected_daily_usage' => 'Expected daily usage', 'preferred_install_date' => 'Preferred install date'],
            'Water supply' => ['water_source' => 'Water source', 'water_source_other' => 'Water source (other)',
                               'continuous_water' => 'Continuous supply', 'water_storage' => 'Storage tank'],
            'Technical assessment' => ['dedicated_kitchen' => 'Dedicated space', 'countertop_space' => 'Level / counter space',
                                       'existing_gas' => 'Existing fuel connection', 'existing_electric' => 'Electrical supply'],
            'Payment' => ['payment_method' => 'Payment method', 'financing_option' => 'Financing option', 'bank_name' => 'Bank'],
            'Referral' => ['referral_source' => 'Heard about us via', 'referral_other' => 'Referral (other)'],
            'Environmental' => ['monthly_gas_consumption' => 'Monthly fuel', 'monthly_electric_consumption' => 'Monthly electricity',
                                'carbon_interest' => 'Carbon interest'],
            'Consent' => ['declaration_accepted' => 'Declaration accepted', 'testimonial_consent' => 'Testimonial consent',
                          'terms_accepted' => 'Terms accepted'],
            'Payment &amp; tracking' => ['reference_code' => 'Reference', 'payment_reference' => 'Payment reference',
                                         'payment_proof_path' => 'Payment proof', 'payment_uploaded_at' => 'Proof uploaded',
                                         'payment_verified_at' => 'Payment verified', 'confirmed_at' => 'Confirmed on',
                                         'completed_at' => 'Completed on'],
        ];
    }

    if ($type === 'contact') {
        return [
            'Enquiry' => ['name' => 'Name', 'company' => 'Company', 'email' => 'Email', 'phone' => 'Phone',
                          'interest' => 'Interest', 'city' => 'City', 'message' => 'Message', 'consent' => 'Contact consent'],
        ];
    }

    return [
        'Subscriber' => ['email' => 'Email'],
    ];
}

/** One field value, rendered for reading. */
function render_value(string $key, $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    if (in_array($key, ['declaration_accepted', 'testimonial_consent', 'terms_accepted', 'consent'], true)) {
        return ((int) $value) === 1 ? 'Yes' : 'No';
    }

    if (in_array($key, ['id_document_path', 'residence_proof_path'], true)) {
        return '<a class="link-arrow" href="file.php?path=' . e(rawurlencode((string) $value))
            . '" target="_blank" rel="noopener">Open file <i class="bi bi-box-arrow-up-right"></i></a>';
    }

    if ($key === 'payment_proof_path') {
        return '<a class="link-arrow" href="file.php?path=' . e(rawurlencode((string) $value))
            . '&amp;dir=payments" target="_blank" rel="noopener">Open receipt <i class="bi bi-box-arrow-up-right"></i></a>';
    }

    if (in_array($key, ['payment_uploaded_at', 'payment_verified_at', 'confirmed_at', 'completed_at'], true)) {
        return e(format_datetime((string) $value));
    }

    return nl2br(e((string) $value));
}

function format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }

    return date('j M Y, H:i', strtotime($value));
}
