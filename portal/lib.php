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
 * Who a code is being issued to. Both sign-ins prove the same thing — that
 * somebody reads that mailbox — so they share the code table and the throttle.
 * The audience decides which address is recognised and what the accepted code
 * signs you in as, and it is checked again on verify: an applicant's code can
 * never open the dealer portal unless that address is also a live dealer.
 */
const OTP_AUDIENCES = ['applicant', 'dealer'];

/**
 * The row an address belongs to for one audience, or null when it is nobody's.
 */
function otp_owner(string $email, string $audience): ?array
{
    if ($audience === 'dealer') {
        $stmt = db()->prepare('SELECT id, full_name FROM dealers WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);

        return $stmt->fetch() ?: null;
    }

    $stmt = db()->prepare('SELECT id FROM applications WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);

    return $stmt->fetch() ?: null;
}

/**
 * Issues a code and emails it. Returns an error string, or '' on success.
 *
 * An address nobody is registered under is turned away by name, because
 * somebody who mistypes their address should be told so rather than left
 * waiting for a code that will never arrive. The cost of that is that the form
 * will confirm whether a given address is known — see portal/README.md.
 */
function issue_otp(string $email, string $audience = 'applicant'): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $recent = db()->prepare(
        'SELECT COUNT(*) FROM applicant_otps WHERE email = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
    );
    $recent->execute([$email]);

    if ((int) $recent->fetchColumn() >= OTP_MAX_PER_HOUR) {
        return 'Too many codes requested for that address. Try again in an hour.';
    }

    if (!otp_owner($email, $audience)) {
        return $audience === 'dealer'
            ? 'We do not recognise that email address. Use the one the office holds for you, '
              . 'or call them and they will check it.'
            : 'We do not recognise that email address. Use the one you applied with — '
              . 'or apply first, and we will email you as soon as the application is in.';
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
function verify_otp(string $email, string $code, string $audience = 'applicant'): string
{
    /* checked again here, not only when the code went out: the address may have
       been switched off in between, and a code is not a licence to be a dealer */
    $owner = otp_owner($email, $audience);

    if (!$owner) {
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

    if ($audience === 'dealer') {
        $_SESSION['dealer_id'] = (int) $owner['id'];
    } else {
        $_SESSION['applicant_email'] = $email;
    }

    return '';
}

function product_name(string $product): string
{
    return product_label($product);
}
