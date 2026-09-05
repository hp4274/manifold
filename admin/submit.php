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

    /* the address never carries the file name: index.html is the site root and
       every other page drops its extension */
    $clean = $page === 'index.html' ? '' : preg_replace('/\.html$/i', '', $page);

    return SITE_URL . '/' . $clean . '?' . ($ok ? 'sent=1' : 'error=1');
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

/**
 * Refuses a sixth post in an hour from one address.
 *
 * Counted off what is already stored rather than out of a table of its own:
 * every one of these forms writes a row carrying the IP and the time it
 * arrived, so the count is the record itself and there is nothing to keep
 * tidy. Each accepted post sends mail, so this is the sending reputation of a
 * shared mailbox as much as it is database spam.
 *
 * A request with no address behind it is not throttled — there is nothing to
 * throttle it by, and refusing everybody over one missing header is worse.
 */
const SUBMIT_MAX_PER_HOUR = 5;

function throttled(string $table, ?string $ip): bool
{
    if ($ip === null || $ip === '') {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM `' . $table . '`
          WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
    );
    $stmt->execute([$ip]);

    return (int) $stmt->fetchColumn() >= SUBMIT_MAX_PER_HOUR;
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

/* five an hour, per form and per address */
$tables = ['stove' => 'applications', 'tuktuk' => 'applications',
           'contact' => 'contact_messages', 'newsletter' => 'newsletter_subscribers'];

try {
    if (isset($tables[$form]) && throttled($tables[$form], $ip)) {
        respond(false, 'That is a lot of submissions from one connection in a short time. '
            . 'Wait an hour, or call +91 97251 54186 and we will take it down for you.', 429);
    }

    if ($form === 'stove' || $form === 'tuktuk') {
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

        /* ---------- what an application cannot arrive without ----------
           The browser marks these required too, but a request that did not come
           from our form has to meet the same bar — the office cannot verify an
           applicant it has no identity, address or paperwork for. */
        $required = [
            'full_name'            => 'full name',
            'date_of_birth'        => 'date of birth',
            'nationality'          => 'nationality',
            'gender'               => 'gender',
            'occupation'           => 'occupation',
            'mobile_number'        => 'mobile number',
            'email'                => 'email address',
            'id_number'            => 'National ID or passport number',
            'id_document_path'     => 'copy of your ID',
            'residence_proof_path' => 'residence proof',
            'house_number'         => 'house, villa or apartment number',
            'street'               => 'street name',
            'city'                 => 'city',
            'state'                => 'state',
            'country'              => 'country',
            'pin_code'             => 'pin code',
        ];

        foreach ($required as $column => $label) {
            if (($columns[$column] ?? null) === null || $columns[$column] === '') {
                respond(false, 'Your ' . $label . ' is missing. Applicant information, '
                    . 'identification and residential address are all required.');
            }
        }

        /* ---------- the shape of what was typed ----------
           The browser marks each of these with a pattern, which is a courtesy to
           somebody typing rather than a rule: a request that did not come from
           our form has to meet the same bar. */
        $digits = static fn (?string $value): string => preg_replace('/\D/', '', (string) $value);

        /* The country code is chosen beside the number, and follows the
           nationality unless the applicant says otherwise. It is stored in
           front of the number so a call can be made from what is on the row;
           the national digits stay the national digits. */
        $dial = static function (string $field) use ($digits): string {
            $code = $digits(field($field, 6));

            /* an empty or absurd code means the form was not ours: fall back to
               the one this business is run from rather than refusing over it */
            return $code === '' || strlen($code) > 4 ? DEFAULT_DIAL_CODE : $code;
        };

        /* How long a number runs to is the country's business, not ours: ten
           digits is India, nine is a British mobile, eight is Singapore. The
           form holds somebody to the same figures while they type. */
        $lengthOk = static function (string $number, string $code) use ($digits): bool {
            [$min, $max] = dial_digits($code);
            $length      = strlen($digits($number));

            return $length >= $min && $length <= $max;
        };

        $mobileDial = $dial('mobile_code');
        $altDial    = $dial('alt_mobile_code');

        if (!$lengthOk($columns['mobile_number'], $mobileDial)) {
            [$min, $max] = dial_digits($mobileDial);

            respond(false, 'The mobile number has to be ' . ($min === $max ? $min . ' digits' : $min . ' to ' . $max . ' digits')
                . ' for +' . $mobileDial . ' — no country code, no spaces.');
        }

        if ($columns['alt_mobile_number'] !== null
            && !$lengthOk($columns['alt_mobile_number'], $altDial)) {
            [$min, $max] = dial_digits($altDial);

            respond(false, 'The alternative mobile number has to be '
                . ($min === $max ? $min . ' digits' : $min . ' to ' . $max . ' digits')
                . ' for +' . $altDial . ', or left empty.');
        }

        $columns['mobile_number'] = '+' . $mobileDial . $digits($columns['mobile_number']);

        if ($columns['alt_mobile_number'] !== null) {
            $columns['alt_mobile_number'] = '+' . $altDial . $digits($columns['alt_mobile_number']);
        }

        if (!filter_var((string) $columns['email'], FILTER_VALIDATE_EMAIL)) {
            respond(false, 'That email address does not look right — we send the payment link to it.');
        }

        if (strlen($digits($columns['pin_code'])) !== 6) {
            respond(false, 'The pin code has to be six digits.');
        }

        $columns['pin_code'] = $digits($columns['pin_code']);

        if (!preg_match('#^[A-Za-z0-9\-/]{4,20}$#', (string) $columns['id_number'])) {
            respond(false, 'The ID number should be the number as printed — letters and digits, four to twenty.');
        }

        /* ---------- dates ----------
           Nobody is born tomorrow, and the first installations are built for
           2027. The picker greys both out; this is the copy of the rule that a
           hand-made request cannot get past. */
        if ($columns['date_of_birth'] !== null
            && strtotime((string) $columns['date_of_birth']) > strtotime('today 23:59:59')) {
            respond(false, 'That date of birth is in the future — please check it.');
        }

        if ($columns['preferred_install_date'] !== null
            && strtotime((string) $columns['preferred_install_date']) < strtotime(INSTALL_FROM)) {
            respond(false, 'Installations start on ' . date('j F Y', strtotime(INSTALL_FROM))
                . '. Please pick that day or later.');
        }

        /* ---------- referral ----------
           Quoting a code costs this applicant nothing and saves them nothing —
           it books a reward for whoever's code it is, which the office pays by
           hand. Only a code whose owner has paid in full counts. An unknown
           code is still stored as typed so the office can see what was meant. */
        $quoted   = normalise_referral_code(field('referral_code', 20));
        $referrer = $quoted === '' ? null : referrer_for_code($quoted);

        /* Nobody refers themselves. Somebody who bought a stove and comes back
           for a kit is the same customer, not a customer they found, so quoting
           their own code books no reward — matched on the email and the mobile,
           because the code is the one thing they can copy off their own portal.

           The partner behind that first sale is still inherited below: the
           dealer who found this customer keeps the second sale, which is what
           the code was quoted for in the first place. */
        $selfReferred = $referrer && (
            strcasecmp((string) ($referrer['email'] ?? ''), (string) $columns['email']) === 0
            || (
                $columns['mobile_number'] !== null
                && substr($digits($referrer['mobile_number'] ?? ''), -10) !== ''
                && substr($digits($referrer['mobile_number'] ?? ''), -10)
                   === substr($digits($columns['mobile_number']), -10)
            )
        );

        $reward = $referrer && !$selfReferred ? referral_reward() : 0.0;

        $columns['referred_by_code']       = $quoted === '' ? null : $quoted;
        $columns['referred_by_id']         = $referrer && !$selfReferred ? (int) $referrer['id'] : null;
        $columns['referral_reward']        = $reward;
        $columns['referral_reward_status'] = $referrer && !$selfReferred ? 'pending' : 'none';

        if ($selfReferred) {
            /* the office should still see what was typed, and why it paid
               nothing, rather than wondering where the reward went */
            $columns['referral_reward_note'] = 'Own code — a customer cannot refer themselves.';
        }

        /* the price list is frozen onto the row, so a later change to the
           published price never rewrites what this application owes */
        $plan = payment_plan($form);

        /* frozen with the prices, so a rate change never rewrites this sale */
        $columns['booking_amount']  = (float) $plan['booking'];
        $columns['delivery_amount'] = (float) $plan['delivery'];
        $columns['payment_amount']  = (float) $plan['booking'];

        /* The same box takes a partner's code, and the prefix decides which kind
           it is — MF a customer, MD a dealer, MX a distributor. A code is only
           ever one of the three, so a partner sale books commission instead of a
           customer reward.

           A dealer's code also books their distributor's override: it follows
           whoever signed that dealer up, never the form. A distributor's own
           code cuts the dealer out of the sale entirely.

           Both shares are frozen here for the same reason the price is: raising
           a rate later must not rewrite what this sale was worth. */
        /* ---------- who sold it ----------
           A second box on the form takes the partner's own code, because who
           sold a sale and who referred it are two different questions and one
           box could only ever answer one of them. Answered here it wins: a
           customer of one dealer can send somebody to another, and the sale
           belongs to whoever closed it while the reward still goes to whoever
           sent them.

           Left empty, the answer falls back to where it always came from — the
           partner behind the referring customer's own sale, or the code in the
           referral box if that turned out to be a partner's. */
        $quotedPartner = normalise_referral_code(field('partner_code', 20));

        $dealer      = $quotedPartner === '' ? null : dealer_for_code($quotedPartner);
        $distributor = $dealer
            ? distributor_for_dealer($dealer)
            : ($quotedPartner === '' ? null : distributor_for_code($quotedPartner));

        if (!$dealer && !$distributor && $referrer) {
            /* A customer's code and no partner named, so the partner who made
               that first sale keeps this one too. Without this the dealer who
               found the customer earns nothing on the customer they went on to
               find, and the sale books commission to nobody at all.

               Re-checked rather than copied: a dealer switched off since the
               first sale books no more, and if theirs is the one that went, the
               distributor takes an override on a dealer sale that has no dealer,
               so they take nothing either. A distributor's own direct sale
               carries down as a direct sale. */
            $dealer = dealer_by_id((int) ($referrer['dealer_id'] ?? 0));

            if ($dealer && ((int) $dealer['is_active'] !== 1 || $dealer['approval_status'] !== 'approved')) {
                $dealer = null;
            }

            if ($dealer) {
                $distributor = distributor_for_dealer($dealer);
            } elseif (empty($referrer['dealer_id'])) {
                $distributor = distributor_by_id((int) ($referrer['distributor_id'] ?? 0));
            }
        } elseif (!$dealer && !$distributor && !$referrer) {
            /* nothing in the partner box and nothing a customer holds: the
               referral box may still carry a partner's code, which is how every
               shared link worked before there were two boxes */
            $dealer      = $quoted === '' ? null : dealer_for_code($quoted);
            $distributor = $dealer
                ? distributor_for_dealer($dealer)
                : ($quoted === '' ? null : distributor_for_code($quoted));
        }

        if ($distributor && (int) $distributor['is_active'] !== 1) {
            $distributor = null;
        }

        $split = commission_split($form, $dealer, $distributor);

        /* A partner code that matched nothing is worth saying out loud: without
           it the office sees a sale attributed to nobody and has to guess
           whether somebody mistyped a code or never had one. No column of its
           own — the note is where a human reads it. */
        if ($quotedPartner !== '' && !$dealer && !$distributor) {
            $columns['admin_note'] = trim(($columns['admin_note'] ?? '')
                . ' Partner code ' . $quotedPartner . ' was quoted and matched no active partner.');
        }

        $columns['dealer_id']              = $dealer ? (int) $dealer['id'] : null;
        $columns['dealer_commission']      = $split['dealer'];
        $columns['distributor_id']         = $distributor ? (int) $distributor['id'] : null;
        $columns['distributor_commission'] = $split['distributor'];

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

        /* The office looks at it before anything is asked of the applicant, so
           no payment email goes out here — approving the application in the
           admin is what sends it, and what opens their portal. */
        $columns['id']             = $id;
        $columns['reference_code'] = $ref;
        $columns['created_at']     = date('Y-m-d H:i:s');

        /* the applicant hears that it arrived, and the office that it is
           waiting — neither should depend on somebody refreshing a page */
        after_response(static function () use ($columns): void {
            send_application_received_email($columns);
            send_new_application_admin($columns);
        });

        respond(true, 'Application received — your booking number is ' . $ref . '. '
            . 'Our team reviews it first, and we email you the payment details once it is approved. '
            . 'Nothing to pay yet.'
            . ($referrer && !$selfReferred ? ' Your referral code has been recorded.' : ''));
    }

    if ($form === 'contact') {
        if (field('name') === null || field('email') === null || field('phone') === null || field('message', 5000) === null) {
            respond(false, 'Name, email, phone and message are required.');
        }

        /* The country code is chosen beside the number here too, and is stored in
           front of it, so a row in the admin can be dialled as it stands. Same
           rules as the application forms: an absurd code falls back to the one
           the business is run from, and how long a number runs to is the
           country's business. */
        $digits = static fn (?string $value): string => preg_replace('/\D/', '', (string) $value);

        $phoneDial = $digits(field('phone_code', 6));
        if ($phoneDial === '' || strlen($phoneDial) > 4) {
            $phoneDial = DEFAULT_DIAL_CODE;
        }

        [$phoneMin, $phoneMax] = dial_digits($phoneDial);
        $phoneLength           = strlen($digits(field('phone', 32)));

        if ($phoneLength < $phoneMin || $phoneLength > $phoneMax) {
            respond(false, 'The phone number has to be '
                . ($phoneMin === $phoneMax ? $phoneMin . ' digits' : $phoneMin . ' to ' . $phoneMax . ' digits')
                . ' for +' . $phoneDial . ' — no country code, no spaces.');
        }

        $enquiry = [
            'name'     => field('name', 160),
            'company'  => field('company', 160),
            'email'    => field('email', 190),
            'phone'    => '+' . $phoneDial . $digits(field('phone', 32)),
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
            after_response(static function () use ($enquiry): void {
                send_contact_thanks_email($enquiry);
            });
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
            after_response(static function () use ($email): void {
                send_newsletter_welcome_email($email);
            });
        }

        respond(true, 'You are on the list.');
    }

    respond(false, 'Unknown form.');
} catch (Throwable $e) {
    error_log('[manifold submit] ' . $e->getMessage());
    respond(false, 'Something went wrong saving that. Please try again or call +91 97251 54186.', 500);
}
