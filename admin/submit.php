<?php
/**
 * Public endpoint for all four website forms.
 *
 * POST admin/submit.php with a `form` field:
 *   form=stove | tuktuk   → applications
 *   form=contact          → contact_messages
 *   form=newsletter       → newsletter_subscribers
 *
 * Answers JSON when asked (fetch), otherwise redirects back with a flag.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/emails.php';

/* ---------- helpers ---------- */

function wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $ajax   = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    return $ajax || strpos($accept, 'application/json') !== false;
}

/**
 * Where to send the browser after a non-fetch post.
 *
 * The `return` field holds a page name such as "contact.html". This script
 * lives in /admin, so a bare relative value would resolve to /admin/contact.html
 * — it has to be prefixed with SITE_URL. Only plain .html page names from this
 * site are accepted, so the field cannot be used as an open redirect.
 */
function return_url(bool $ok): string
{
    $page = (string) ($_POST['return'] ?? '');
    $page = basename(strtok($page, '?#') ?: '');

    if ($page === '' || !preg_match('/^[a-z0-9._-]+\.html$/i', $page) || !is_file(__DIR__ . '/../' . $page)) {
        $page = 'index.html';
    }

    return SITE_URL . '/' . $page . '?' . ($ok ? 'sent=1' : 'error=1');
}

function respond(bool $ok, string $message, int $code = 200): void
{
    http_response_code($ok ? $code : ($code === 200 ? 422 : $code));

    if (wants_json()) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'message' => $message]);
        exit;
    }

    header('Location: ' . return_url($ok));
    exit;
}

function field(string $name, int $max = 255): ?string
{
    $value = trim((string) ($_POST[$name] ?? ''));

    if ($value === '') {
        return null;
    }

    return mb_substr($value, 0, $max);
}

function yes_no(string $name): ?string
{
    $value = strtolower((string) ($_POST[$name] ?? ''));

    return in_array($value, ['yes', 'no'], true) ? $value : null;
}

function checkbox(string $name): int
{
    return isset($_POST[$name]) ? 1 : 0;
}

function date_or_null(string $name): ?string
{
    $value = field($name);

    if ($value === null) {
        return null;
    }

    $d = DateTime::createFromFormat('Y-m-d', $value);

    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

function int_or_null(string $name): ?int
{
    $value = field($name);

    return ($value === null || !is_numeric($value)) ? null : (int) $value;
}

/**
 * Moves one uploaded file into admin/uploads and returns its stored name.
 */
function store_upload(string $field): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > UPLOAD_MAX_BYTES) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    if (!isset(UPLOAD_ALLOWED_MIME[$mime])) {
        return null;
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true) && !is_dir(UPLOAD_DIR)) {
        return null;
    }

    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . UPLOAD_ALLOWED_MIME[$mime];

    return move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name) ? $name : null;
}

/* ---------- routing ---------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Use POST.', 405);
}

$form = (string) ($_POST['form'] ?? '');
$ip   = $_SERVER['REMOTE_ADDR'] ?? null;

/* honeypot: bots fill every field, people never see this one */
if (!empty($_POST['website'])) {
    respond(true, 'Thank you.');
}

try {
    if ($form === 'stove' || $form === 'tuktuk') {
        if (field('full_name') === null || field('email') === null || field('mobile_number') === null) {
            respond(false, 'Name, mobile number and email are required.');
        }

        if (!checkbox('declaration_accepted') || !checkbox('terms_accepted')) {
            respond(false, 'The declaration and the terms must both be accepted.');
        }

        $columns = [
            'product'                      => $form,
            'full_name'                    => field('full_name', 160),
            'date_of_birth'                => date_or_null('date_of_birth'),
            'nationality'                  => field('nationality', 80),
            'gender'                       => field('gender', 30),
            'occupation'                   => field('occupation', 120),
            'mobile_number'                => field('mobile_number', 32),
            'alt_mobile_number'            => field('alt_mobile_number', 32),
            'email'                        => field('email', 190),
            'id_number'                    => field('id_number', 80),
            'id_document_path'             => store_upload('id_document_file'),
            'residence_proof_path'         => store_upload('residence_proof_file'),
            'house_number'                 => field('house_number', 120),
            'street'                       => field('street', 160),
            'city'                         => field('city', 120),
            'state'                        => field('state', 120),
            'country'                      => field('country', 120),
            'pin_code'                     => field('pin_code', 20),
            'property_type'                => field('property_type', 80),
            'property_type_other'          => field('property_type_other', 160),
            'ownership_status'             => field('ownership_status', 40),
            'household_members'            => int_or_null('household_members'),
            'existing_fuel'                => field('existing_fuel', 80),
            'existing_fuel_other'          => field('existing_fuel_other', 160),
            'units_required'               => int_or_null('units_required'),
            'intended_usage'               => field('intended_usage', 60),
            'expected_daily_usage'         => field('expected_daily_usage', 120),
            'preferred_install_date'       => date_or_null('preferred_install_date'),
            'water_source'                 => field('water_source', 80),
            'water_source_other'           => field('water_source_other', 160),
            'continuous_water'             => yes_no('continuous_water'),
            'water_storage'                => yes_no('water_storage'),
            'dedicated_kitchen'            => yes_no('dedicated_kitchen'),
            'countertop_space'             => yes_no('countertop_space'),
            'existing_gas'                 => yes_no('existing_gas'),
            'existing_electric'            => yes_no('existing_electric'),
            'payment_method'               => field('payment_method', 60),
            'financing_option'             => field('financing_option', 160),
            'bank_name'                    => field('bank_name', 160),
            'referral_source'              => field('referral_source', 80),
            'referral_other'               => field('referral_other', 160),
            'monthly_gas_consumption'      => field('monthly_gas_consumption', 120),
            'monthly_electric_consumption' => field('monthly_electric_consumption', 120),
            'carbon_interest'              => field('carbon_interest', 20),
            'declaration_accepted'         => checkbox('declaration_accepted'),
            'testimonial_consent'          => checkbox('testimonial_consent'),
            'terms_accepted'               => checkbox('terms_accepted'),
            'ip_address'                   => $ip,
        ];

        /* ---------- dates ----------
           Nobody is born tomorrow, and an installation cannot be arranged for
           a day that has gone. The picker greys both out; this is the copy of
           the rule that a hand-made request cannot get past. */
        if ($columns['date_of_birth'] !== null
            && strtotime((string) $columns['date_of_birth']) > strtotime('today 23:59:59')) {
            respond(false, 'That date of birth is in the future — please check it.');
        }

        if ($columns['preferred_install_date'] !== null
            && strtotime((string) $columns['preferred_install_date']) < strtotime('today')) {
            respond(false, 'The preferred installation date has already passed. Please pick a later day.');
        }

        /* ---------- referral ----------
           Quoting a code costs this applicant nothing and saves them nothing —
           it books a reward for whoever's code it is, which the office pays by
           hand. Only a code whose owner has paid in full counts. An unknown
           code is still stored as typed so the office can see what was meant. */
        $quoted   = normalise_referral_code(field('referral_code', 20));
        $referrer = $quoted === '' ? null : referrer_for_code($quoted);
        $reward   = $referrer ? referral_reward() : 0.0;

        $columns['referred_by_code']       = $quoted === '' ? null : $quoted;
        $columns['referred_by_id']         = $referrer ? (int) $referrer['id'] : null;
        $columns['referral_reward']        = $reward;
        $columns['referral_reward_status'] = $referrer ? 'pending' : 'none';

        /* The same box takes a dealer's code. A code is one or the other, never
           both — the MF/MD prefix decides — so a dealer sale books commission
           instead of a customer reward. The rate is frozen here for the same
           reason the reward is: raising it later must not rewrite this sale. */
        $dealer = $referrer || $quoted === '' ? null : dealer_for_code($quoted);

        $columns['dealer_id']         = $dealer ? (int) $dealer['id'] : null;
        $columns['dealer_commission'] = $dealer ? dealer_commission() : 0.0;
        /* the price list is frozen onto the row, so a later change to the
           published price never rewrites what this application owes */
        $plan = payment_plan($form);

        $columns['booking_amount']  = (float) $plan['booking'];
        $columns['delivery_amount'] = (float) $plan['delivery'];
        $columns['payment_amount']  = (float) $plan['booking'];

        /* their own code, theirs for good */
        $columns['referral_code'] = make_referral_code();

        /* placeholder, replaced with the MF-00000000 booking number once the row has an id */
        $columns['reference_code'] = 'tmp-' . bin2hex(random_bytes(6));

        $names        = array_keys($columns);
        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $sql          = 'INSERT INTO applications (`' . implode('`, `', $names) . '`) VALUES (' . $placeholders . ')';

        db()->prepare($sql)->execute(array_values($columns));

        $id  = (int) db()->lastInsertId();
        $ref = make_reference_code($id);

        db()->prepare('UPDATE applications SET reference_code = ? WHERE id = ?')->execute([$ref, $id]);

        /* the applicant pays first, so the payment email goes out immediately */
        $columns['id']             = $id;
        $columns['reference_code'] = $ref;
        send_payment_email($columns);

        $payable = (float) $columns['booking_amount'];

        respond(true, 'Application received. We have emailed you the payment details — pay the '
            . money($payable) . ' booking amount and upload the receipt to reserve your place. '
            . 'The ' . money((float) $columns['delivery_amount']) . ' delivery payment follows later.'
            . ($referrer ? ' Your referral code has been recorded.' : ''));
    }

    if ($form === 'contact') {
        if (field('name') === null || field('email') === null || field('phone') === null || field('message', 5000) === null) {
            respond(false, 'Name, email, phone and message are required.');
        }

        $enquiry = [
            'name'     => field('name', 160),
            'company'  => field('company', 160),
            'email'    => field('email', 190),
            'phone'    => field('phone', 32),
            'interest' => field('interest', 60),
            'city'     => field('city', 120),
            'message'  => field('message', 5000),
            'consent'  => checkbox('consent'),
        ];

        db()->prepare(
            'INSERT INTO contact_messages (name, company, email, phone, interest, city, message, consent, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array_merge(array_values($enquiry), [$ip]));

        /* a thank you goes back straight away, so nobody is left wondering
           whether the form worked; a bad address only costs a line in email_log */
        if (filter_var((string) $enquiry['email'], FILTER_VALIDATE_EMAIL)) {
            send_contact_thanks_email($enquiry);
        }

        respond(true, 'Thank you — your enquiry has reached the Ahmedabad team. We have emailed you a copy.');
    }

    if ($form === 'newsletter') {
        $email = field('email', 190);

        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(false, 'Enter a valid email address.');
        }

        /* re-subscribing an existing address just refreshes the record */
        $stmt = db()->prepare(
            'INSERT INTO newsletter_subscribers (email, source_page, ip_address)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE source_page = VALUES(source_page), updated_at = NOW()'
        );
        $stmt->execute([$email, field('source_page', 120), $ip]);

        /* MySQL reports 1 affected row for a fresh insert and 2 for a row it
           updated instead, so only somebody genuinely new gets welcomed —
           signing up twice does not send the same email twice */
        if ($stmt->rowCount() === 1) {
            send_newsletter_welcome_email($email);
        }

        respond(true, 'You are on the list.');
    }

    respond(false, 'Unknown form.');
} catch (Throwable $e) {
    error_log('[manifold submit] ' . $e->getMessage());
    respond(false, 'Something went wrong saving that. Please try again or call +91 97251 54186.', 500);
}
