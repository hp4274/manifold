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
const APPLICATION_STATUSES = ['payment_pending', 'payment_review', 'complete', 'rejected'];

/** The stages an applicant sees in the portal timeline (rejected sits outside). */
const APPLICATION_STAGES = ['payment_pending', 'payment_review', 'complete'];

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
        'payment_pending' => $audience === 'applicant' ? 'Payment due' : 'Payment pending',
        'payment_review'  => $audience === 'applicant'
            ? 'Payment submitted — verifying'
            : 'Payment received — verify',
        'complete'        => $audience === 'applicant' ? 'Complete' : 'Paid and complete',
        'new'             => 'New',
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
        'payment_pending' => 'payment pending',
        'payment_review'  => 'payment received',
        'complete'        => 'complete',
    ];

    return $short[$status] ?? $status;
}

/** What the applicant is told at each stage. */
function stage_copy(string $status): array
{
    $copy = [
        'payment_pending' => ['Payment due',
                              'Pay the balance below using the QR code — in one go or in instalments — '
                              . 'and upload a receipt for each transfer.'],
        'payment_review'  => ['Payment submitted',
                              'We are checking your latest receipt. Nothing more is needed for that transfer.'],
        'complete'        => ['Complete',
                              'Payment verified. Your receipt is on its way and we will call you to schedule installation.'],
        'rejected'        => ['Not proceeding',
                              'This application is not moving forward. Contact us if you think that is a mistake.'],
    ];

    return $copy[$status] ?? [status_label($status, 'applicant'), ''];
}

/* ---------- payments ---------- */

/** Every transfer on one application, oldest first. */
function payments_for(int $applicationId): array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE application_id = ? ORDER BY uploaded_at, id');
    $stmt->execute([$applicationId]);

    return $stmt->fetchAll();
}

/**
 * What has been paid, what is waiting to be checked and what is still owed.
 * Rejected transfers count for nothing.
 */
function payment_totals(array $app, ?array $payments = null): array
{
    $payments = $payments ?? payments_for((int) $app['id']);
    $due      = (float) ($app['payment_amount'] ?? PAYMENT_AMOUNT);

    $paid    = 0.0;
    $waiting = 0.0;

    foreach ($payments as $payment) {
        if ($payment['status'] === 'verified') {
            $paid += (float) $payment['amount'];
        } elseif ($payment['status'] === 'pending') {
            $waiting += (float) $payment['amount'];
        }
    }

    return [
        'due'      => $due,
        'paid'     => $paid,
        'waiting'  => $waiting,
        'balance'  => max($due - $paid, 0),
        'settled'  => $paid + 0.001 >= $due,
        'percent'  => $due > 0 ? min(100, (int) round($paid / $due * 100)) : 100,
    ];
}

/**
 * The status an application should be on, worked out from its payments.
 * A rejected application stays rejected — that is an admin decision.
 */
function status_from_payments(array $app, ?array $payments = null): string
{
    if ($app['status'] === 'rejected') {
        return 'rejected';
    }

    $payments = $payments ?? payments_for((int) $app['id']);
    $totals   = payment_totals($app, $payments);

    if ($totals['settled']) {
        return 'complete';
    }

    foreach ($payments as $payment) {
        if ($payment['status'] === 'pending') {
            return 'payment_review';
        }
    }

    return 'payment_pending';
}

/** Writes the derived status back, and stamps completion the first time. */
function sync_application_status(int $applicationId): string
{
    $stmt = db()->prepare('SELECT * FROM applications WHERE id = ?');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch();

    if (!$app) {
        return '';
    }

    $payments = payments_for($applicationId);
    $next     = status_from_payments($app, $payments);
    $totals   = payment_totals($app, $payments);

    if ($next !== $app['status']) {
        db()->prepare('UPDATE applications SET status = ? WHERE id = ?')->execute([$next, $applicationId]);
        log_status_change('application', $applicationId, (string) $app['status'], $next, null);
    }

    db()->prepare(
        'UPDATE applications
            SET payment_verified_at = ?, completed_at = ?
          WHERE id = ?'
    )->execute([
        $totals['paid'] > 0 ? ($app['payment_verified_at'] ?? date('Y-m-d H:i:s')) : null,
        $next === 'complete' ? ($app['completed_at'] ?? date('Y-m-d H:i:s')) : null,
        $applicationId,
    ]);

    return $next;
}

/** MF-2026-00042-R2 — the receipt number for one verified transfer. */
function next_receipt_no(array $app): string
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM payments WHERE application_id = ? AND status = ?');
    $stmt->execute([(int) $app['id'], 'verified']);

    return $app['reference_code'] . '-R' . ((int) $stmt->fetchColumn() + 1);
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

/**
 * The status that means "waiting on us" for a given form, and how to say it.
 * Applications have no `new` — a receipt sitting unverified is the queue.
 */
function attention_status(string $type): array
{
    if (type_config($type)['table'] === 'applications') {
        return ['payment_review', 'waiting on payment verification'];
    }

    return ['new', 'waiting to be reviewed'];
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
 * Fields shown in the Details drawer, as tab => section => [column => label].
 *
 * Related things live under one tab — the site address, what the property is,
 * its water supply and the technical assessment are all "where it goes", so
 * they sit together rather than being five separate tabs.
 */
function field_groups(string $type): array
{
    $config = type_config($type);

    if ($config['table'] === 'applications') {
        return [
            'Applicant' => [
                'Contact details' => ['full_name' => 'Full name', 'date_of_birth' => 'Date of birth',
                                      'nationality' => 'Nationality', 'gender' => 'Gender',
                                      'occupation' => 'Occupation', 'mobile_number' => 'Mobile',
                                      'alt_mobile_number' => 'Alternative mobile', 'email' => 'Email'],
                'Identity &amp; address proofs' => ['id_number' => 'ID / passport number',
                                                   'id_document_path' => 'ID document (proof of identity)',
                                                   'residence_proof_path' => 'Residence proof (proof of address)'],
            ],

            'Site &amp; address' => [
                'Address' => ['house_number' => 'House / unit', 'street' => 'Street', 'city' => 'City',
                              'state' => 'State', 'country' => 'Country', 'pin_code' => 'Pin code'],
                'Property' => ['property_type' => 'Property type', 'property_type_other' => 'Property type (other)',
                               'ownership_status' => 'Ownership', 'household_members' => 'Household members',
                               'existing_fuel' => 'Existing fuel', 'existing_fuel_other' => 'Existing fuel (other)'],
                'Water supply' => ['water_source' => 'Water source', 'water_source_other' => 'Water source (other)',
                                   'continuous_water' => 'Continuous supply', 'water_storage' => 'Storage tank'],
                'Technical assessment' => ['dedicated_kitchen' => 'Dedicated space',
                                           'countertop_space' => 'Level / counter space',
                                           'existing_gas' => 'Existing fuel connection',
                                           'existing_electric' => 'Electrical supply'],
            ],

            'Requirement' => [
                'What they need' => ['units_required' => 'Units required', 'intended_usage' => 'Intended usage',
                                     'expected_daily_usage' => 'Expected daily usage',
                                     'preferred_install_date' => 'Preferred install date'],
                'Current consumption' => ['monthly_gas_consumption' => 'Monthly fuel',
                                          'monthly_electric_consumption' => 'Monthly electricity',
                                          'carbon_interest' => 'Carbon interest'],
            ],

            'Preferences' => [
                /* what they told us on the form — the real payments are their own tab */
                'Payment preference' => ['payment_method' => 'Preferred method',
                                         'financing_option' => 'Financing option', 'bank_name' => 'Bank'],
                'Referral' => ['referral_source' => 'Heard about us via', 'referral_other' => 'Referral (other)'],
                'Consent' => ['declaration_accepted' => 'Declaration accepted',
                              'testimonial_consent' => 'Testimonial consent', 'terms_accepted' => 'Terms accepted'],
                'Tracking' => ['reference_code' => 'Reference', 'payment_amount' => 'Application fee',
                               'completed_at' => 'Completed on'],
            ],
        ];
    }

    if ($type === 'contact') {
        return [
            'Enquiry' => [
                'Who wrote in' => ['name' => 'Name', 'company' => 'Company', 'email' => 'Email',
                                   'phone' => 'Phone', 'city' => 'City'],
                'What they asked' => ['interest' => 'Interest', 'message' => 'Message',
                                      'consent' => 'Contact consent'],
            ],
        ];
    }

    return [
        'Subscriber' => [
            'Signup' => ['email' => 'Email'],
        ],
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
