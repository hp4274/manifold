<?php
/**
 * Email bodies. Plain inline-styled HTML — email clients ignore stylesheets.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';

function email_wrap(string $heading, string $inner): string
{
    $year = date('Y');

    return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f6f9fc;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f9fc;padding:28px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #e3ebf2;border-radius:18px;overflow:hidden;font-family:Figtree,Segoe UI,Helvetica,Arial,sans-serif;">'
        . '<tr><td style="background:#061a28;padding:22px 30px;color:#ffffff;font-size:16px;font-weight:700;letter-spacing:.02em;">Manifold Clean Energy</td></tr>'
        . '<tr><td style="padding:32px 30px 8px;"><h1 style="margin:0 0 16px;font-size:24px;line-height:1.35;color:#0f2c4d;">' . $heading . '</h1></td></tr>'
        . '<tr><td style="padding:0 30px 30px;font-size:16px;line-height:1.7;color:#5b7186;">' . $inner . '</td></tr>'
        . '<tr><td style="padding:20px 30px;background:#f6f9fc;border-top:1px solid #e3ebf2;font-size:13px;line-height:1.6;color:#8499ac;">'
        . 'Manifold Clean Energy Pvt. Ltd. · 711, SAFAL Prelude, Corporate Road, Prahlad Nagar, Ahmedabad 380015<br>'
        . '+91 97251 54186 · info@manifoldcleanenergy.com<br>&copy; ' . $year . ' Manifold Clean Energy Pvt. Ltd.'
        . '</td></tr></table></td></tr></table></body></html>';
}

function email_button(string $url, string $label): string
{
    return '<p style="margin:26px 0;"><a href="' . e($url) . '" '
        . 'style="display:inline-block;padding:14px 26px;border-radius:999px;background:#4bb453;color:#ffffff;'
        . 'font-size:15px;font-weight:700;text-decoration:none;">' . e($label) . '</a></p>';
}

/**
 * Sent when an admin confirms an application: QR code, amount reference and
 * the link to upload the receipt.
 */
function send_payment_email(array $app): bool
{
    $product = product_label((string) $app['product']);
    $portal  = base_url() . '/portal/index.php';
    $qrFile  = qr_file();
    $hasQr   = $qrFile !== null;

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($app['full_name']) . ',</p>'
        . '<p style="margin:0 0 14px;">Good news — your application for the <strong style="color:#0f2c4d;">'
        . e($product) . '</strong> has been confirmed.</p>'
        . '<p style="margin:0 0 20px;">Your reference is <strong style="color:#0f2c4d;">' . e($app['reference_code'])
        . '</strong>. Quote it on the payment and in any message to us.</p>'
        . '<p style="margin:0 0 10px;font-weight:700;color:#0f2c4d;">1. Pay with the QR code</p>';

    if ($hasQr) {
        $inner .= '<p style="margin:0 0 8px;text-align:center;">'
            . '<img src="cid:qr" alt="Payment QR code" width="240" height="240" '
            . 'style="width:240px;height:auto;max-width:100%;border:1px solid #e3ebf2;border-radius:14px;background:#ffffff;"></p>'
            . '<p style="margin:0 0 20px;text-align:center;font-size:14px;color:#8499ac;">'
            . 'Not showing? <a href="' . e($portal) . '" style="color:#0e8f96;">Open the QR code on our website</a>.</p>';
    } else {
        $inner .= '<p style="margin:0 0 20px;">We will send the payment QR code separately.</p>';
    }

    $inner .= '<p style="margin:0 0 10px;font-weight:700;color:#0f2c4d;">2. Upload the receipt</p>'
        . '<p style="margin:0 0 4px;">Sign in with this email address and upload a screenshot or PDF of the payment. '
        . 'We verify it and then confirm your installation slot.</p>'
        . email_button($portal, 'Open my application')
        . '<p style="margin:0;font-size:14px;color:#8499ac;">Signing in sends a one-time code to this address — no password to remember.</p>';

    return send_mail(
        $app['email'],
        'Your application is confirmed — payment details inside (' . $app['reference_code'] . ')',
        email_wrap('Your application is confirmed', $inner),
        'payment',
        $hasQr ? $qrFile : null
    );
}

/** Sent when the payment has been verified and the application is complete. */
function send_complete_email(array $app): bool
{
    $portal = base_url() . '/portal/index.php';

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($app['full_name']) . ',</p>'
        . '<p style="margin:0 0 14px;">We have verified your payment for reference <strong style="color:#0f2c4d;">'
        . e($app['reference_code']) . '</strong>. Your application is now complete.</p>'
        . '<p style="margin:0 0 14px;">Our team will call you to agree an installation date. '
        . 'Nothing further is needed from you in the meantime.</p>'
        . email_button($portal, 'View my application');

    return send_mail(
        $app['email'],
        'Payment verified — your application is complete (' . $app['reference_code'] . ')',
        email_wrap('Payment verified', $inner),
        'complete'
    );
}

/**
 * Tells the team a receipt has arrived and is waiting to be verified.
 * Goes to every active admin account, or to the reply-to address if there
 * are none. Returns true when at least one copy went out.
 */
function send_payment_received_admin(array $app): bool
{
    $recipients = array_filter(array_map('trim', explode(',', ADMIN_NOTIFY_EMAIL)));

    if (!$recipients) {
        $stmt       = db()->query('SELECT email FROM admin_users WHERE is_active = 1');
        $recipients = array_column($stmt->fetchAll(), 'email');
    }

    if (!$recipients) {
        $recipients = [MAIL_REPLY_TO];
    }

    $product = product_label((string) $app['product']);
    $link    = base_url() . '/admin/list.php?type=' . rawurlencode((string) $app['product'])
        . '&status=payment_pending#row-' . (int) $app['id'];

    $rows = [
        'Reference'  => (string) $app['reference_code'],
        'Product'    => $product,
        'Applicant'  => (string) $app['full_name'],
        'Email'      => (string) $app['email'],
        'Phone'      => (string) ($app['mobile_number'] ?? ''),
        'Payment ref' => (string) ($app['payment_reference'] ?? '—'),
    ];

    $table = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 8px;">';

    foreach ($rows as $label => $value) {
        $table .= '<tr>'
            . '<td style="padding:8px 0;font-size:15px;color:#8499ac;width:38%;">' . e($label) . '</td>'
            . '<td style="padding:8px 0;font-size:15px;color:#0f2c4d;font-weight:600;">' . e($value !== '' ? $value : '—') . '</td>'
            . '</tr>';
    }

    $table .= '</table>';

    $inner = '<p style="margin:0 0 16px;">An applicant has uploaded proof of payment. '
        . 'The application is now sitting in <strong style="color:#0f2c4d;">Payment received — verify</strong>.</p>'
        . $table
        . email_button($link, 'Open it in the admin')
        . '<p style="margin:0;font-size:14px;color:#8499ac;">Open the row and use the ✅ action to verify the payment and complete the application.</p>';

    $sent = false;

    foreach ($recipients as $to) {
        $sent = send_mail(
            $to,
            'Payment received — ' . $app['reference_code'] . ' (' . $product . ')',
            email_wrap('Payment received', $inner),
            'payment_received'
        ) || $sent;
    }

    return $sent;
}

function product_label(string $product): string
{
    return $product === 'stove' ? 'Kinetic Hydrogen Cooking Stove' : 'Hydrogen Conversion Kit for TukTuk';
}

/** One-time sign-in code for the applicant portal. */
function send_otp_email(string $to, string $code): bool
{
    $inner = '<p style="margin:0 0 14px;">Use this code to sign in and track your application:</p>'
        . '<p style="margin:0 0 20px;font-size:34px;font-weight:700;letter-spacing:.22em;color:#0f2c4d;">'
        . e($code) . '</p>'
        . '<p style="margin:0;">It expires in 10 minutes and can be used once. '
        . 'If you did not ask for it, ignore this email.</p>';

    return send_mail($to, 'Your Manifold sign-in code: ' . $code, email_wrap('Your sign-in code', $inner), 'otp');
}
