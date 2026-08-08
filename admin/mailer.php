<?php
/**
 * Minimal SMTP sender — no Composer, no vendor directory.
 *
 * Supports AUTH LOGIN over TLS/SSL, an HTML body and one inline image
 * (used for the payment QR code). Every attempt is written to `email_log`.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** True when the SMTP credentials in config.php have been filled in. */
function mail_configured(): bool
{
    return SMTP_HOST !== '' && SMTP_USER !== '';
}

/**
 * Sends one HTML email.
 *
 * @param string      $to      recipient address
 * @param string      $subject subject line
 * @param string      $html    HTML body
 * @param string      $kind    label stored in email_log (otp, payment, complete…)
 * @param string|null $inline  absolute path to an image embedded as cid:qr
 */
function send_mail(string $to, string $subject, string $html, string $kind = 'general', ?string $inline = null): bool
{
    $ok    = false;
    $error = null;

    try {
        if (!mail_configured()) {
            throw new RuntimeException('SMTP is not configured — fill in SMTP_HOST and SMTP_USER in admin/config.php.');
        }

        $message = build_message($to, $subject, $html, $inline);
        smtp_send($to, $message);
        $ok = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log('[manifold mail] ' . $error);
    }

    try {
        db()->prepare('INSERT INTO email_log (to_email, subject, kind, ok, error) VALUES (?, ?, ?, ?, ?)')
            ->execute([$to, $subject, $kind, $ok ? 1 : 0, $error]);
    } catch (Throwable $e) {
        error_log('[manifold mail log] ' . $e->getMessage());
    }

    return $ok;
}

/** Builds the raw RFC 5322 message. */
function build_message(string $to, string $subject, string $html, ?string $inline): string
{
    $eol      = "\r\n";
    $boundary = 'mf' . bin2hex(random_bytes(10));

    $headers = [
        'Date: ' . date('r'),
        'From: ' . mail_encode(MAIL_FROM_NAME) . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_REPLY_TO,
        'To: ' . $to,
        'Subject: ' . mail_encode($subject),
        'MIME-Version: 1.0',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@manifoldcleanenergy.com>',
    ];

    $useInline = $inline !== null && is_file($inline);

    if ($useInline) {
        $headers[] = 'Content-Type: multipart/related; boundary="' . $boundary . '"';
    } else {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    }

    $body = '--' . $boundary . $eol
        . 'Content-Type: text/html; charset=UTF-8' . $eol
        . 'Content-Transfer-Encoding: quoted-printable' . $eol . $eol
        . quoted_printable_encode($html) . $eol;

    if ($useInline) {
        $name = basename($inline);
        $mime = 'image/jpeg';

        if (function_exists('finfo_open')) {
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($inline);

            if (is_string($detected) && strpos($detected, 'image/') === 0) {
                $mime = $detected;
            }
        }

        $body .= '--' . $boundary . $eol
            . 'Content-Type: ' . $mime . '; name="' . $name . '"' . $eol
            . 'Content-Transfer-Encoding: base64' . $eol
            . 'Content-ID: <qr>' . $eol
            . 'X-Attachment-Id: qr' . $eol
            . 'Content-Disposition: inline; filename="' . $name . '"' . $eol . $eol
            . chunk_split(base64_encode((string) file_get_contents($inline))) . $eol;
    }

    $body .= '--' . $boundary . '--' . $eol;

    return implode($eol, $headers) . $eol . $eol . $body;
}

function mail_encode(string $text): string
{
    return preg_match('/[\x80-\xFF]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
}

/** Opens the SMTP conversation and hands over the message. */
function smtp_send(string $to, string $message): void
{
    $host = SMTP_SECURE === 'ssl' ? 'ssl://' . SMTP_HOST : SMTP_HOST;
    $fp   = @fsockopen($host, SMTP_PORT, $errno, $errstr, SMTP_TIMEOUT);

    if (!$fp) {
        throw new RuntimeException('Cannot reach ' . SMTP_HOST . ':' . SMTP_PORT . ' — ' . $errstr);
    }

    stream_set_timeout($fp, SMTP_TIMEOUT);
    smtp_expect($fp, 220);

    $ehlo = 'EHLO ' . (parse_url(base_url(), PHP_URL_HOST) ?: 'localhost');
    smtp_cmd($fp, $ehlo, 250);

    if (SMTP_SECURE === 'tls') {
        smtp_cmd($fp, 'STARTTLS', 220);

        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Could not start TLS.');
        }

        smtp_cmd($fp, $ehlo, 250);
    }

    smtp_cmd($fp, 'AUTH LOGIN', 334);
    smtp_cmd($fp, base64_encode(SMTP_USER), 334);
    smtp_cmd($fp, base64_encode(SMTP_PASS), 235);

    smtp_cmd($fp, 'MAIL FROM:<' . MAIL_FROM . '>', 250);
    smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
    smtp_cmd($fp, 'DATA', 354);

    /* dot-stuffing, so a line that is just "." cannot end the message early */
    $data = preg_replace('/^\./m', '..', $message);
    fwrite($fp, $data . "\r\n.\r\n");
    smtp_expect($fp, 250);

    smtp_cmd($fp, 'QUIT', [221, 250]);
    fclose($fp);
}

/**
 * @param int|int[] $expect
 */
function smtp_cmd($fp, string $command, $expect): void
{
    fwrite($fp, $command . "\r\n");
    smtp_expect($fp, $expect);
}

/**
 * @param int|int[] $expect
 */
function smtp_expect($fp, $expect): void
{
    $codes    = (array) $expect;
    $response = '';

    while ($line = fgets($fp, 515)) {
        $response .= $line;

        /* multi-line replies look like "250-…", the last one "250 …" */
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);

    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP said: ' . trim($response));
    }
}
