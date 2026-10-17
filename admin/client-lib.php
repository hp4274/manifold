<?php
/**
 * Editing a client's own details — the shared engine.
 *
 * Three desks edit the same record: the office, the dealer who sold it and the
 * distributor above them. All three may change the same fields, which is what
 * was asked for. What differs is only whether the record is theirs to touch, and
 * how long they may keep touching it.
 *
 * ---------------------------------------------------------------------------
 * What is NOT editable here, and why
 * ---------------------------------------------------------------------------
 * A client's details are one thing; their order is another. Status, the two
 * amounts, commission, which dealer the sale belongs to, the booking number,
 * every verified-on and paid-on stamp, and the uploaded documents
 * are all left alone — each has a workflow of its own, with its own emails and
 * its own audit trail, and a free-text form over the top of them would be a way
 * to walk round all of it. `product` is out for the same reason: it sets the
 * price and the commission, so changing it is an order change, not a correction.
 *
 * ---------------------------------------------------------------------------
 * The email field
 * ---------------------------------------------------------------------------
 * Email is how a client signs in: otp_owner() finds an applicant with
 * `SELECT id FROM applications WHERE email = ?`. Changing it therefore moves the
 * portal — the documents, the receipts, the payment history — to whatever
 * address is typed. That was raised and the decision was that all three desks
 * may change it, so they can; what this file adds is that it cannot happen
 * quietly. Every change is written to client_edits with who made it, and an
 * email address change is announced to the old address as well as the new one,
 * so the person losing the account hears about it from us.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/emails.php';

/**
 * The statuses after which a partner stops editing.
 *
 * An address is usually found to be wrong exactly when somebody is trying to
 * deliver to it, so a partner keeps the pen right through the order. Once it is
 * finished or called off there is nothing left to correct and the record becomes
 * history, which only the office may rewrite.
 */
const CLIENT_EDIT_CLOSED = ['complete', 'cancelled', 'refunded', 'rejected'];

/**
 * Every field one of these forms may write, with how to check it.
 *
 * Labels are taken from the same wording the drawer uses, so a field is called
 * the same thing wherever somebody meets it.
 */
function client_fields(): array
{
    static $fields = null;

    if ($fields !== null) {
        return $fields;
    }

    $t = static fn (string $label, int $max): array => ['label' => $label, 'kind' => 'text', 'max' => $max];

    return $fields = [
        'Contact details' => [
            'full_name'         => ['label' => 'Full name', 'kind' => 'text', 'max' => 160, 'required' => true],
            'date_of_birth'     => ['label' => 'Date of birth', 'kind' => 'date'],
            'nationality'       => $t('Nationality', 80),
            'gender'            => $t('Gender', 30),
            'occupation'        => $t('Occupation', 120),
            'mobile_number'     => ['label' => 'Mobile', 'kind' => 'mobile'],
            'alt_mobile_number' => ['label' => 'Alternative mobile', 'kind' => 'mobile'],
            'email'             => ['label' => 'Email', 'kind' => 'email', 'max' => 190, 'required' => true],
            'id_number'         => $t('ID / passport number', 80),
        ],

        'Address' => [
            'house_number' => $t('House / unit', 120),
            'street'       => $t('Street', 160),
            'city'         => $t('City', 120),
            'state'        => $t('State', 120),
            'country'      => $t('Country', 120),
            'pin_code'     => $t('Pin code', 20),
        ],

        'Property' => [
            'property_type'       => $t('Property type', 80),
            'property_type_other' => $t('Property type (other)', 160),
            'ownership_status'    => $t('Ownership', 40),
            'household_members'   => ['label' => 'Household members', 'kind' => 'int'],
            'existing_fuel'       => $t('Existing fuel', 80),
            'existing_fuel_other' => $t('Existing fuel (other)', 160),
        ],

        'Water supply' => [
            'water_source'       => $t('Water source', 80),
            'water_source_other' => $t('Water source (other)', 160),
            'continuous_water'   => ['label' => 'Continuous supply', 'kind' => 'yesno'],
            'water_storage'      => ['label' => 'Storage tank', 'kind' => 'yesno'],
        ],

        'Technical assessment' => [
            'dedicated_kitchen' => ['label' => 'Dedicated space', 'kind' => 'yesno'],
            'countertop_space'  => ['label' => 'Level / counter space', 'kind' => 'yesno'],
            'existing_gas'      => ['label' => 'Existing fuel connection', 'kind' => 'yesno'],
            'existing_electric' => ['label' => 'Electrical supply', 'kind' => 'yesno'],
        ],

        'What they need' => [
            'units_required'         => ['label' => 'Units required', 'kind' => 'int'],
            'intended_usage'         => $t('Intended usage', 60),
            'expected_daily_usage'   => $t('Expected daily usage', 120),
            'preferred_install_date' => ['label' => 'Preferred install date', 'kind' => 'date'],
        ],

        'Current consumption' => [
            'monthly_gas_consumption'      => $t('Monthly fuel', 120),
            'monthly_electric_consumption' => $t('Monthly electricity', 120),
            'carbon_interest'              => $t('Carbon interest', 20),
        ],

        'Preferences' => [
            'payment_method'      => $t('Preferred payment method', 60),
            'financing_option'    => $t('Financing option', 160),
            'bank_name'           => $t('Bank', 160),
            'referral_source'     => $t('Heard about us via', 80),
            'referral_other'      => $t('Referral (other)', 160),
            'testimonial_consent' => ['label' => 'Testimonial consent', 'kind' => 'bool'],
        ],
    ];
}

/** The same list flattened, name => spec. */
function client_field_specs(): array
{
    $flat = [];

    foreach (client_fields() as $fieldsInGroup) {
        foreach ($fieldsInGroup as $name => $spec) {
            $flat[$name] = $spec;
        }
    }

    return $flat;
}

/* ==========================================================================
 * Who may edit what
 * ======================================================================= */

/**
 * The application this actor is allowed to open, or null.
 *
 * Ownership is a condition on the query, not a check after it: a dealer asking
 * for somebody else's id gets nothing back rather than a row they are then
 * trusted not to look at.
 */
function client_for_actor(int $id, array $actor): ?array
{
    if ($actor['type'] === 'office') {
        $stmt = db()->prepare('SELECT * FROM applications WHERE id = ?');
        $stmt->execute([$id]);
    } elseif ($actor['type'] === 'dealer') {
        $stmt = db()->prepare('SELECT * FROM applications WHERE id = ? AND dealer_id = ?');
        $stmt->execute([$id, (int) $actor['id']]);
    } else {
        /* A distributor's clients are their own sales and their dealers' — the
           same set their clients list already shows them. */
        $stmt = db()->prepare('SELECT * FROM applications WHERE id = ? AND distributor_id = ?');
        $stmt->execute([$id, (int) $actor['id']]);
    }

    return $stmt->fetch() ?: null;
}

/** Whether this actor may still write to this record, and what to say if not. */
function client_edit_closed_reason(array $app, array $actor): string
{
    if ($actor['type'] === 'office') {
        return '';
    }

    if (in_array((string) $app['status'], CLIENT_EDIT_CLOSED, true)) {
        return 'This order is ' . status_label((string) $app['status']) . ', so its details are now '
            . 'the office\'s to change. Ask them and they will put it right.';
    }

    return '';
}

/* ==========================================================================
 * Reading the form
 * ======================================================================= */

/**
 * A stored number split back into its country code and the national digits.
 *
 * Stored as one string with the code in front, which is what makes a row
 * dialable as it stands — but nobody edits a number that way. The longest
 * prefix that is a real dial code and leaves a plausible number behind is the
 * code; anything unrecognisable falls back to the one this business is run from.
 */
function client_split_mobile(?string $stored): array
{
    $digits = preg_replace('/\D/', '', (string) $stored) ?? '';

    if ($digits === '') {
        return ['', ''];
    }

    for ($length = 4; $length >= 1; $length--) {
        $code = substr($digits, 0, $length);

        if (!isset(DIAL_DIGITS[$code])) {
            continue;
        }

        [$min, $max] = dial_digits($code);
        $rest        = substr($digits, $length);

        if (strlen($rest) >= $min && strlen($rest) <= $max) {
            return [$code, $rest];
        }
    }

    return [DEFAULT_DIAL_CODE, $digits];
}

/**
 * What the form is asking to change, checked, or a reason it cannot be.
 *
 * Returns [changes, error]. `changes` is field => [old, new] and holds only what
 * actually differs — a form posted back untouched writes nothing, logs nothing
 * and emails nobody.
 */
function client_read_form(array $app, array $post): array
{
    $changes = [];
    $digits  = static fn (string $text): string => preg_replace('/\D/', '', $text) ?? '';

    foreach (client_field_specs() as $name => $spec) {
        if (!array_key_exists($name, $post) && $spec['kind'] !== 'bool') {
            continue;
        }

        $raw = trim((string) ($post[$name] ?? ''));
        $old = $app[$name] ?? null;
        $new = null;

        switch ($spec['kind']) {
            case 'email':
                if ($raw === '') {
                    return [[], 'An email address is needed — it is how they sign in to their portal.'];
                }

                if (!filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    return [[], '"' . $raw . '" is not an email address we can write to.'];
                }

                $new = mb_substr($raw, 0, 190);
                break;

            case 'mobile':
                $code = $digits((string) ($post[$name . '_code'] ?? ''));
                $code = $code === '' || strlen($code) > 4 ? DEFAULT_DIAL_CODE : $code;
                $rest = $digits($raw);

                if ($rest === '') {
                    $new = null;
                    break;
                }

                /* Somebody who pastes the whole stored number back in has the
                   code twice over; taking it off is kinder than refusing. */
                [$min, $max] = dial_digits($code);

                if (strlen($rest) > $max && str_starts_with($rest, $code)) {
                    $rest = substr($rest, strlen($code));
                }

                if (strlen($rest) < $min || strlen($rest) > $max) {
                    $says = $min === $max ? $min . ' digits' : $min . ' to ' . $max . ' digits';

                    return [[], $spec['label'] . ' has to be ' . $says . ' for +' . $code
                        . ', or be left empty.'];
                }

                $new = '+' . $code . $rest;
                break;

            case 'date':
                if ($raw === '') {
                    $new = null;
                    break;
                }

                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

                if ($date === false || $date->format('Y-m-d') !== $raw) {
                    return [[], $spec['label'] . ' has to be a date, as YYYY-MM-DD.'];
                }

                $new = $raw;
                break;

            case 'int':
                if ($raw === '') {
                    $new = null;
                    break;
                }

                if (!ctype_digit($raw)) {
                    return [[], $spec['label'] . ' has to be a whole number.'];
                }

                $new = (int) $raw;
                break;

            case 'yesno':
                $new = in_array($raw, ['yes', 'no'], true) ? $raw : null;
                break;

            case 'bool':
                $new = isset($post[$name]) ? 1 : 0;
                break;

            default:
                if (!empty($spec['required']) && $raw === '') {
                    return [[], $spec['label'] . ' cannot be empty.'];
                }

                $new = $raw === '' ? null : mb_substr($raw, 0, (int) $spec['max']);
        }

        /* Compared as strings so 4 and "4" are the same answer, and a field that
           was null and came back empty is not counted as a change. */
        if ((string) $old !== (string) $new) {
            $changes[$name] = [$old, $new];
        }
    }

    return [$changes, ''];
}

/**
 * Other applications already signed in to by an address.
 *
 * The portal shows somebody every application their address holds, so moving a
 * record onto an address that already has one puts both in the same portal. That
 * is right when it is the same person ordering twice and wrong when it is not,
 * and no rule can tell those apart — so it is put in front of whoever is editing
 * instead, and they say which it is.
 */
function client_email_shared_with(string $email, int $exceptId): array
{
    $stmt = db()->prepare(
        'SELECT reference_code, full_name FROM applications WHERE email = ? AND id <> ? ORDER BY id'
    );
    $stmt->execute([$email, $exceptId]);

    return $stmt->fetchAll();
}

/* ==========================================================================
 * Writing
 * ======================================================================= */

/** Saves the changes, writes the audit trail and tells whoever should hear. */
function client_save(array $app, array $changes, array $actor): void
{
    if ($changes === []) {
        return;
    }

    $sets   = [];
    $values = [];

    foreach ($changes as $name => [$old, $new]) {
        $sets[]   = '`' . $name . '` = ?';
        $values[] = $new;
    }

    $values[] = (int) $app['id'];

    db()->prepare('UPDATE applications SET ' . implode(', ', $sets) . ' WHERE id = ?')
        ->execute($values);

    client_log_edits((int) $app['id'], $changes, $actor);

    $after = $app;

    foreach ($changes as $name => [$old, $new]) {
        $after[$name] = $new;
    }

    client_announce($app, $after, $changes, $actor);
}

/**
 * One row per field changed.
 *
 * Not status_log: its `changed_by` carries a foreign key into admin_users, and
 * two of the three people who can edit a client are not in that table. The actor
 * is recorded by kind and id, with the name written alongside so a log still
 * reads years later when a dealer has been switched off and renamed.
 */
function client_log_edits(int $applicationId, array $changes, array $actor): void
{
    $stmt = db()->prepare(
        'INSERT INTO client_edits
            (application_id, field, old_value, new_value, actor_type, actor_id, actor_label, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($changes as $name => [$old, $new]) {
        $stmt->execute([
            $applicationId,
            $name,
            $old === null ? null : (string) $old,
            $new === null ? null : (string) $new,
            $actor['type'],
            $actor['id'] === null ? null : (int) $actor['id'],
            mb_substr((string) $actor['label'], 0, 160),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}

/**
 * Who hears about an edit.
 *
 * A change of address or a corrected spelling is housekeeping and the office
 * sees it in the log. A change of email address is not: it moves the portal, and
 * the person it moves away from has to be told, at the address it moved away
 * from, or the move is silent. That note goes to the old address and the new one
 * both, and the office is told separately whoever made it.
 */
function client_announce(array $before, array $after, array $changes, array $actor): void
{
    if (!isset($changes['email'])) {
        return;
    }

    [$oldEmail, $newEmail] = $changes['email'];

    after_response(static function () use ($before, $after, $oldEmail, $newEmail, $actor): void {
        send_client_email_changed($after, (string) $oldEmail, (string) $newEmail, $actor);
        send_client_email_changed_admin($after, (string) $oldEmail, (string) $newEmail, $actor);
    });
}

/** How a change reads in the log and in an email. */
function client_value_label(string $field, $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $spec = client_field_specs()[$field] ?? null;

    if (($spec['kind'] ?? '') === 'bool') {
        return $value ? 'Yes' : 'No';
    }

    if (($spec['kind'] ?? '') === 'yesno') {
        return ucfirst((string) $value);
    }

    return (string) $value;
}

/**
 * Runs an edit screen and hands back everything the form needs.
 *
 * `$actor` is built by the page from its own session and is the only thing that
 * decides whose records can be opened. Nothing about who is editing is read from
 * the request, so there is no field to forge.
 */
function client_edit_run(array $actor): array
{
    $id  = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    $app = $id > 0 ? client_for_actor($id, $actor) : null;

    if (!$app) {
        http_response_code(404);

        return ['app' => null, 'error' => '', 'saved' => null, 'closed' => '',
                'shared' => [], 'posted' => null, 'history' => []];
    }

    $state = [
        'app'     => $app,
        'error'   => '',
        'saved'   => null,
        'closed'  => client_edit_closed_reason($app, $actor),
        'shared'  => [],
        'posted'  => null,
        'history' => client_edit_history($id),
    ];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'save') {
        return $state;
    }

    csrf_check();

    /* Checked again on the way in, not only when the form was drawn: a page left
       open while the order completed would otherwise still save. */
    if ($state['closed'] !== '') {
        return $state;
    }

    [$changes, $error] = client_read_form($app, $_POST);

    if ($error !== '') {
        $state['error']  = $error;
        $state['posted'] = $_POST;

        return $state;
    }

    /* Moving a record onto an address that already opens another one is allowed,
       but only once somebody has said out loud that it is the same person. */
    if (isset($changes['email'])) {
        $shared = client_email_shared_with((string) $changes['email'][1], $id);

        if ($shared !== [] && empty($_POST['share_email'])) {
            $state['shared'] = $shared;
            $state['posted'] = $_POST;
            $state['error']  = 'That address already signs in to another application. '
                . 'Say below that it is the same person, or use a different address.';

            return $state;
        }
    }

    client_save($app, $changes, $actor);

    $state['app']     = client_for_actor($id, $actor) ?: $app;
    $state['saved']   = count($changes);
    $state['closed']  = client_edit_closed_reason($state['app'], $actor);
    $state['history'] = client_edit_history($id);

    return $state;
}

/** Everything ever changed on one client, newest first. */
function client_edit_history(int $applicationId, int $limit = 60): array
{
    $stmt = db()->prepare(
        'SELECT * FROM client_edits WHERE application_id = ?
          ORDER BY changed_at DESC, id DESC LIMIT ' . max(1, $limit)
    );
    $stmt->execute([$applicationId]);

    return $stmt->fetchAll();
}
