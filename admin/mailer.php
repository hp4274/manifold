<?php
/**
 * Minimal SMTP sender — no Composer, no vendor directory.
 *
 * AUTH LOGIN over TLS/SSL. Every message carries both a plain-text and an HTML
 * part, and may embed one inline image (the payment QR). Every attempt is
 * written to `email_log`.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** True when the SMTP credentials in config.php have been filled in. */
function mail_configured(): bool
{
    return SMTP_HOST !== '' && SMTP_USER !== '';
}

/**
 * Sends one email.
 *
 * @param string      $to      recipient address
 * @param string      $subject subject line
 * @param string      $html    HTML body
 * @param string      $kind    label stored in email_log (otp, payment, receipt…)
 * @param string|null $inline  absolute path to an image embedded as cid:qr
 * @param string[]    $bcc     silent copies, e.g. the office keeping a record
 * @param array        $files   attachments as ['name.pdf' => ['mime' => …, 'data' => …]]
 */
function send_mail(
    string $to,
    string $subject,
    string $html,
    string $kind = 'general',
    ?string $inline = null,
    array $bcc = [],
    array $files = []
): bool {
    $ok    = false;
    $error = null;

    try {
        if (!mail_configured()) {
            throw new RuntimeException('SMTP is not configured — fill in SMTP_HOST and SMTP_USER in admin/config.php.');
        }

        $message    = build_message($to, $subject, $html, $inline, $files, bulk_headers($to, $kind));
        $recipients = array_values(array_unique(array_filter(array_merge([$to], $bcc))));

        smtp_send($recipients, $message);
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

/**
 * The token that stands for "this address asked to be taken off the list".
 *
 * Signed rather than looked up, so the link in an email carries everything the
 * endpoint needs and nothing anybody can guess: without app_secret() the token
 * for an address cannot be worked out, so nobody can unsubscribe somebody else
 * by typing their address into the URL.
 */
function unsubscribe_token(string $email): string
{
    return substr(hash_hmac('sha256', 'unsubscribe|' . mb_strtolower($email), app_secret()), 0, 32);
}

function unsubscribe_url(string $email): string
{
    return base_url() . '/unsubscribe?e=' . rawurlencode($email) . '&t=' . unsubscribe_token($email);
}

/**
 * One-click unsubscribe, on bulk mail only.
 *
 * Gmail and Yahoo both require it of anybody sending in volume, and a domain
 * without it starts landing in spam — taking the transactional mail sent from
 * the same mailbox down with it. Transactional mail carries no such header:
 * nobody unsubscribes from their own receipt.
 *
 * List-Unsubscribe-Post is what makes it one click rather than a page visit —
 * the mail client POSTs the address itself and never opens a browser.
 */
function bulk_headers(string $to, string $kind): array
{
    if (strpos($kind, 'newsletter') !== 0) {
        return [];
    }

    return [
        'List-Unsubscribe: <' . unsubscribe_url($to) . '>',
        'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
    ];
}

/**
 * Builds the raw RFC 5322 message.
 *
 * Every mail carries a plain-text part alongside the HTML one — HTML-only mail
 * scores badly with spam filters and shows as blank in some clients. When an
 * inline image is attached the structure is multipart/related wrapping the
 * multipart/alternative body.
 */
function build_message(
    string $to,
    string $subject,
    string $html,
    ?string $inline,
    array $files = [],
    array $extraHeaders = []
): string {
    $eol   = "\r\n";
    $altB  = 'alt' . bin2hex(random_bytes(8));
    $relB  = 'rel' . bin2hex(random_bytes(8));
    $mixB  = 'mix' . bin2hex(random_bytes(8));
    $useIn = $inline !== null && is_file($inline);

    $headers = [
        'Date: ' . date('r'),
        'From: ' . mail_encode(MAIL_FROM_NAME) . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_REPLY_TO,
        'To: ' . $to,
        'Subject: ' . mail_encode($subject),
        'MIME-Version: 1.0',
        /* the domain has to follow MAIL_FROM; a mismatch counts against the spam score */
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr(MAIL_FROM, '@'), 1) . '>',
        'X-Mailer: Manifold Clean Energy',
    ];

    foreach ($extraHeaders as $header) {
        /* a header is one line: anything folded into it would be a new one */
        $headers[] = str_replace(["\r", "\n"], '', $header);
    }

    if ($files) {
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixB . '"';
    } else {
        $headers[] = $useIn
            ? 'Content-Type: multipart/related; boundary="' . $relB . '"'
            : 'Content-Type: multipart/alternative; boundary="' . $altB . '"';
    }

    /* the same message, twice: text first, HTML second */
    $alternative = '--' . $altB . $eol
        . 'Content-Type: text/plain; charset=UTF-8' . $eol
        . 'Content-Transfer-Encoding: quoted-printable' . $eol . $eol
        . quoted_printable_encode(html_to_text($html)) . $eol
        . '--' . $altB . $eol
        . 'Content-Type: text/html; charset=UTF-8' . $eol
        . 'Content-Transfer-Encoding: quoted-printable' . $eol . $eol
        . quoted_printable_encode($html) . $eol
        . '--' . $altB . '--' . $eol;

    /* the readable body, with the inline image folded in when there is one */
    $readable = $alternative;
    $readableType = 'multipart/alternative; boundary="' . $altB . '"';

    if ($useIn) {
        $readable = inline_part($relB, $altB, $alternative, $inline);
        $readableType = 'multipart/related; boundary="' . $relB . '"';
    }

    if ($files) {
        $body = '--' . $mixB . $eol
            . 'Content-Type: ' . $readableType . $eol . $eol
            . $readable;

        foreach ($files as $name => $file) {
            $body .= '--' . $mixB . $eol
                . 'Content-Type: ' . $file['mime'] . '; name="' . $name . '"' . $eol
                . 'Content-Transfer-Encoding: base64' . $eol
                . 'Content-Disposition: attachment; filename="' . $name . '"' . $eol . $eol
                . chunk_split(base64_encode($file['data'])) . $eol;
        }

        $body .= '--' . $mixB . '--' . $eol;

        return implode($eol, $headers) . $eol . $eol . $body;
    }

    return implode($eol, $headers) . $eol . $eol . $readable;
}

/** multipart/related: the readable body plus the inline QR image. */
function inline_part(string $relB, string $altB, string $alternative, string $inline): string
{
    $eol  = "\r\n";
    $name = basename($inline);
    $mime = 'image/jpeg';

    if (function_exists('finfo_open')) {
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($inline);

        if (is_string($detected) && strpos($detected, 'image/') === 0) {
            $mime = $detected;
        }
    }

    return '--' . $relB . $eol
        . 'Content-Type: multipart/alternative; boundary="' . $altB . '"' . $eol . $eol
        . $alternative
        . '--' . $relB . $eol
        . 'Content-Type: ' . $mime . '; name="' . $name . '"' . $eol
        . 'Content-Transfer-Encoding: base64' . $eol
        . 'Content-ID: <qr>' . $eol
        . 'X-Attachment-Id: qr' . $eol
        . 'Content-Disposition: inline; filename="' . $name . '"' . $eol . $eol
        . chunk_split(base64_encode((string) file_get_contents($inline))) . $eol
        . '--' . $relB . '--' . $eol;
}

/** A readable plain-text version of an HTML body. */
function html_to_text(string $html): string
{
    $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
    $text = preg_replace('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $text);
    $text = preg_replace('#</(p|div|tr|h1|h2|h3|li)>#i', "\n", $text);
    $text = preg_replace('#<br\s*/?>#i', "\n", $text);
    $text = preg_replace('#</td>\s*<td[^>]*>#i', ': ', $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}

function mail_encode(string $text): string
{
    return preg_match('/[\x80-\xFF]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
}

/**
 * Opens the SMTP conversation and hands over the message.
 *
 * @param string[] $recipients envelope recipients (To plus any Bcc)
 */
function smtp_send(array $recipients, string $message): void
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

    foreach ($recipients as $recipient) {
        smtp_cmd($fp, 'RCPT TO:<' . $recipient . '>', [250, 251]);
    }

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
