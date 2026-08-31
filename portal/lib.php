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
        header('Location: index.php');
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
        'applicant'   => '../portal/status.php',
        'dealer'      => '../dealer/index.php',
        'distributor' => '../distributor/index.php',
    ];

    return $homes[$role] ?? '../portal/status.php';
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
        $stmt = db()->prepare('SELECT id, full_name FROM dealers WHERE email = ? AND is_active = 1 LIMIT 1');
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
 * Issues a code and emails it. Returns an error string, or '' on success.
 *
 * An address nobody is registered under is turned away by name, because
 * somebody who mistypes their address should be told so rather than left
 * waiting for a code that will never arrive. The cost of that is that the form
 * will confirm whether a given address is known — see portal/README.md.
 */
function issue_otp(string $email, string $audience = 'any'): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $recent = db()->prepare(
        'SELECT COUNT(*) FROM applicant_otps WHERE email = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
    );
    $recent->execute([$email]);

    if ((int) $recent->fetchColumn() >= OTP_MAX_PER_HOUR) {
        return 'Too many codes requested for that address. Try again in an hour.';
    }

    $known = $audience === 'any' ? roles_for_email($email) !== [] : (bool) otp_owner($email, $audience);

    if (!$known) {
        /* An application still waiting on the office is not an unknown address,
           and telling somebody we have never heard of them when we have their
           application in hand is the wrong answer to the wrong question. */
        $waiting = db()->prepare(
            "SELECT COUNT(*) FROM applications WHERE email = ? AND status = 'submitted'"
        );
        $waiting->execute([$email]);

        if ((int) $waiting->fetchColumn() > 0) {
            return 'Your application is with our team. We email you the payment details as soon as it '
                . 'is approved, and your portal opens at the same time.';
        }

        return 'We do not recognise that email address. Use the one you applied with, or the one the '
            . 'office holds for you — or apply first, and we will email you as soon as it is in.';
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    db()->prepare(
        'INSERT INTO applicant_otps (email, code_hash, expires_at, ip_address)
         VALUES (?, ?, (NOW() + INTERVAL ? MINUTE), ?)'
    )->execute([$email, password_hash($code, PASSWORD_DEFAULT), OTP_TTL_MINUTES, $ip]);

    send_otp_email($email, $code);

    return '';
}

/** Checks a code. Returns an error string, or '' when the code was accepted. */
function verify_otp(string $email, string $code, string $audience = 'any'): string
{
    /* Looked up again here, not only when the code went out: the address may
       have been switched off in between, and a code is not a licence to be a
       dealer. This is the only thing that grants a role. */
    $roles = $audience === 'any'
        ? roles_for_email($email)
        : (otp_owner($email, $audience) ? [$audience] : []);

    if (!$roles) {
        return 'That address is not registered any more. Ask the office to check it.';
    }

    $stmt = db()->prepare(
        'SELECT * FROM applicant_otps
          WHERE email = ? AND used_at IS NULL AND expires_at > NOW()
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$email]);
    $otp = $stmt->fetch();

    if (!$otp) {
        return 'That code has expired. Ask for a new one.';
    }

    if ((int) $otp['attempts'] >= OTP_MAX_ATTEMPTS) {
        return 'Too many wrong attempts. Ask for a new code.';
    }

    if (!password_verify($code, $otp['code_hash'])) {
        db()->prepare('UPDATE applicant_otps SET attempts = attempts + 1 WHERE id = ?')->execute([$otp['id']]);

        return 'That code is not right.';
    }

    db()->prepare('UPDATE applicant_otps SET used_at = NOW() WHERE id = ?')->execute([$otp['id']]);

    session_regenerate_id(true);

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
