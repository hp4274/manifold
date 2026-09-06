<?php
/**
 * Applicant portal — shared session, OTP handling and page chrome.
 * Reuses the admin database layer and mailer; no admin privileges anywhere.
 */

declare(strict_types=1);

require_once __DIR__ . '/../admin/lib.php';
require_once __DIR__ . '/../admin/emails.php';

/** How long a code stays valid, and how many guesses it allows. */
const OTP_TTL_MINUTES = 10;
const OTP_MAX_ATTEMPTS = 5;
const OTP_MAX_PER_HOUR = 6;

/**
 * Every role the person at this browser is currently signed in as.
 *
 * Read back from the sessions rather than from `portal_roles`, so a dealer who
 * was switched off mid-session stops counting as one the moment the office does
 * it, without anybody having to sign out.
 */
function portal_roles(): array
{
    $roles = [];

    if (!empty($_SESSION['applicant_email'])) {
        $roles[] = 'applicant';
    }

    if (function_exists('dealer_user') && dealer_user()) {
        $roles[] = 'dealer';
    }

    if (function_exists('distributor_user') && distributor_user()) {
        $roles[] = 'distributor';
    }

    return $roles;
}

function applicant(): ?string
{
    return $_SESSION['applicant_email'] ?? null;
}

function require_applicant(): string
{
    $email = applicant();

    if (!$email) {
        header('Location: ./');
        exit;
    }

    return $email;
}

/** Applications belonging to one email address, newest first. */
function applications_for(string $email): array
{
    $stmt = db()->prepare('SELECT * FROM applications WHERE email = ? ORDER BY created_at DESC');
    $stmt->execute([$email]);

    return $stmt->fetchAll();
}

/**
 * Who an applicant bought through, as they may see it.
 *
 * A name and a code, and nothing else: a client has no business seeing their
 * dealer's bank details or what the office owes them. Returns null for a sale
 * that came straight through the website, where there is nobody in between.
 */
function sold_by(array $app): ?array
{
    if (!empty($app['dealer_id'])) {
        $stmt = db()->prepare(
            'SELECT d.full_name, d.dealer_code AS code, d.mobile_number,
                    x.full_name AS distributor_name, x.distributor_code
               FROM dealers d
               LEFT JOIN distributors x ON x.id = d.distributor_id
              WHERE d.id = ?'
        );
        $stmt->execute([(int) $app['dealer_id']]);
        $row = $stmt->fetch();

        return $row ? $row + ['kind' => 'Dealer'] : null;
    }

    if (!empty($app['distributor_id'])) {
        $stmt = db()->prepare(
            'SELECT full_name, distributor_code AS code, mobile_number FROM distributors WHERE id = ?'
        );
        $stmt->execute([(int) $app['distributor_id']]);
        $row = $stmt->fetch();

        return $row ? $row + ['kind' => 'Distributor'] : null;
    }

    return null;
}

/**
 * The three things an address can be. One sign-in serves all of them: a code
 * proves somebody reads that mailbox, and what the mailbox is registered as
 * decides where they land.
 *
 * The role is looked up again on verify, never trusted from the form — an
 * address that stopped being a dealer between the code going out and the code
 * coming back does not get a dealer session.
 */
const OTP_ROLES = ['applicant', 'dealer', 'distributor'];

/** Human name for a role, for the chooser and the sign-in copy. */
function role_label(string $role): string
{
    $labels = [
        'applicant'   => 'Track your application',
        'dealer'      => 'Dealer portal',
        'distributor' => 'Distributor portal',
    ];

    return $labels[$role] ?? ucfirst($role);
}

/** Where a role lands once it is signed in. */
function role_home(string $role): string
{
    $homes = [
        'applicant'   => '../portal/status',
        'dealer'      => '../dealer/',
        'distributor' => '../distributor/',
    ];

    return $homes[$role] ?? '../portal/status';
}

/**
 * The row an address holds for one role, or null when it holds none.
 *
 * A switched-off dealer or distributor is nobody: their code books nothing, so
 * their sign-in opens nothing either.
 */
function otp_owner(string $email, string $role): ?array
{
    if ($role === 'dealer') {
        /* A dealer the office has not approved has no code and nothing to do
           here yet, so their sign-in waits on the decision the same way an
           applicant's does. */
        $stmt = db()->prepare(
            "SELECT id, full_name FROM dealers
              WHERE email = ? AND is_active = 1 AND approval_status = 'approved' LIMIT 1"
        );
    } elseif ($role === 'distributor') {
        $stmt = db()->prepare('SELECT id, full_name FROM distributors WHERE email = ? AND is_active = 1 LIMIT 1');
    } else {
        /* An application the office has not approved yet opens nothing: the
           portal is where a payment is made, and nothing is owed until we have
           said yes. Approving it is what lets them in. */
        $stmt = db()->prepare(
            "SELECT id FROM applications WHERE email = ? AND status <> 'submitted' LIMIT 1"
        );
    }

    $stmt->execute([$email]);

    return $stmt->fetch() ?: null;
}

/**
 * Every role one address holds, in the order they are offered.
 *
 * Usually exactly one. A dealer who also bought a stove themselves holds two,
 * and is asked which they meant rather than being guessed at.
 */
function roles_for_email(string $email): array
{
    $roles = [];

    foreach (OTP_ROLES as $role) {
        if (otp_owner($email, $role)) {
            $roles[] = $role;
        }
    }

    return $roles;
}

/**
 * What the sign-in form says whatever was typed at it.
 *
 * The three states an address could be in — unknown, waiting on the office,
 * registered — used to be three different sentences, which with no per-IP
 * throttle in front of the form made it a customer-list harvester: type an
 * address, read which of the three came back. The kindness the middle sentence
 * carried is not lost, it has moved into an email that only the owner of the
 * mailbox can read.
 */
const OTP_SENT_NOTICE = 'If that address is registered with us, a six-digit code is on its way. '
    . 'It is valid for ' . OTP_TTL_MINUTES . ' minutes.';

/**
 * The signature that stands in for a stored code.
 *
 * Nothing about a code is written down: the six digits go out by email, and
 * what stays behind is this HMAC of the address, the expiry and the code. It
 * proves a code that comes back was one we issued, to that address, before it
 * expired — without there being a row anywhere for anybody to read.
 */
function otp_signature(string $email, int $expiresAt, string $code): string
{
    return hash_hmac('sha256', $email . '|' . $expiresAt . '|' . $code, app_secret());
}

/** How many codes this address or this connection has asked for in an hour. */
function otp_recent_count(string $email, ?string $ip): int
{
    /* login_attempts is the throttle table this application already has, and
       an issued code is recorded in it under the address it went to. Counted
       per address and per connection, so clearing a cookie buys nothing: the
       session store alone would make the cap a formality. */
    $sql = 'SELECT COUNT(*) FROM login_attempts
             WHERE attempted_at > (NOW() - INTERVAL 1 HOUR) AND (email = ?';
    $args = ['otp:' . $email];

    if ($ip !== null && $ip !== '') {
        $sql .= ' OR ip_address = ?';
        $args[] = $ip;
    }

    $sql .= ')';

    $stmt = db()->prepare($sql);
    $stmt->execute($args);

    return (int) $stmt->fetchColumn();
}

/**
 * Issues a code and emails it. Returns an error string, or '' on success.
 *
 * '' does not mean a code went out — it means the form should say
 * OTP_SENT_NOTICE, which is the same answer for an address we have never heard
 * of as for one we know. An error string is only ever returned for something
 * true of the request rather than of the address: too many tries, or our own
 * mail failing.
 */
function issue_otp(string $email, string $audience = 'any'): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    if (otp_recent_count($email, $ip) >= OTP_MAX_PER_HOUR) {
        return 'Too many codes requested. Try again in an hour.';
    }

    /* Counted whether or not a code follows: an unknown address that costs
       nothing to try is what makes the form worth walking through a list. */
    db()->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)')
        ->execute(['otp:' . $email, $ip]);

    $known = $audience === 'any' ? roles_for_email($email) !== [] : (bool) otp_owner($email, $audience);

    if (!$known) {
        /* An application still waiting on the office is not an unknown address.
           Saying so on the page would answer the question for anybody typing
           addresses at it, so it is said in an email instead — where only the
           person who reads that mailbox sees it. */
        $waiting = db()->prepare(
            "SELECT COUNT(*) FROM applications WHERE email = ? AND status = 'submitted'"
        );
        $waiting->execute([$email]);

        if ((int) $waiting->fetchColumn() > 0) {
            after_response(static function () use ($email): void {
                send_application_waiting_email($email);
            });
        }

        /* nothing signed, so any code typed at the next step fails */
        unset($_SESSION['otp']);

        return '';
    }

    $code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = time() + OTP_TTL_MINUTES * 60;

    /* The mailbox has an hourly cap and refuses over it — "451 4.7.1 Ratelimit
       exceeded", then "454 4.3.0 Try again later". Saying the code is on its way
       when it is not leaves somebody waiting on an email that will never come, so
       the failure is passed back rather than swallowed. */
    if (!send_otp_email($email, $code)) {
        unset($_SESSION['otp']);

        return 'We could not send the code just now. Please try again in a few minutes, '
             . 'or call +91 97251 54186 and we will help you in.';
    }

    $_SESSION['otp'] = [
        'email'     => $email,
        'expires'   => $expires,
        'signature' => otp_signature($email, $expires, $code),
        'attempts'  => 0,
    ];

    return '';
}

/** Checks a code. Returns an error string, or '' when the code was accepted. */
function verify_otp(string $email, string $code, string $audience = 'any'): string
{
    $issued = $_SESSION['otp'] ?? null;

    /* No signature, a signature for a different address, or one that has run
       out: all the same answer. An address the form said nothing about at step
       one must not be told something about it at step two. */
    if (!is_array($issued)
        || !hash_equals((string) ($issued['email'] ?? ''), $email)
        || (int) ($issued['expires'] ?? 0) < time()) {
        unset($_SESSION['otp']);

        return 'That code is not right, or it has run out. Ask for a new one.';
    }

    if ((int) ($issued['attempts'] ?? 0) >= OTP_MAX_ATTEMPTS) {
        unset($_SESSION['otp']);

        return 'Too many wrong attempts. Ask for a new code.';
    }

    $expected = otp_signature($email, (int) $issued['expires'], $code);

    if (!hash_equals((string) ($issued['signature'] ?? ''), $expected)) {
        $_SESSION['otp']['attempts'] = (int) ($issued['attempts'] ?? 0) + 1;

        return 'That code is not right.';
    }

    /* Looked up only now the code is proven: the address may have been switched
       off since it went out, and a code is not a licence to be a dealer. This is
       the only thing that grants a role. */
    $roles = $audience === 'any'
        ? roles_for_email($email)
        : (otp_owner($email, $audience) ? [$audience] : []);

    if (!$roles) {
        unset($_SESSION['otp']);

        return 'That address is not registered any more. Ask the office to check it.';
    }

    /* used once: the signature goes before the session is rebuilt */
    unset($_SESSION['otp']);

    session_regenerate_id(true);

    /* signing into a portal ends any office session sharing this browser, so an
       admin who handed the machine over does not leave the dashboard reachable */
    unset($_SESSION['admin']);

    /* every role the address holds is opened at once — the person is the same
       either way, and asking them to sign in twice to see both would be theatre */
    foreach ($roles as $role) {
        $owner = otp_owner($email, $role);

        if (!$owner) {
            continue;
        }

        if ($role === 'dealer') {
            $_SESSION['dealer_id'] = (int) $owner['id'];
        } elseif ($role === 'distributor') {
            $_SESSION['distributor_id'] = (int) $owner['id'];
        } else {
            $_SESSION['applicant_email'] = $email;
        }
    }

    $_SESSION['portal_roles'] = $roles;

    return '';
}

function product_name(string $product): string
{
    return product_label($product);
}
