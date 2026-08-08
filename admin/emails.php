<?php
/**
 * Email bodies. Plain inline-styled HTML — email clients ignore stylesheets.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/receipt-pdf.php';

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
 * Sent the moment an application is submitted: what it costs, the QR code to
 * pay with, and the link to upload the receipt.
 */
function send_payment_email(array $app): bool
{
    $product = product_label((string) $app['product']);
    $portal  = base_url() . '/portal/index.php';
    $amount  = money((float) ($app['payment_amount'] ?? PAYMENT_AMOUNT));
    $qrFile  = qr_file();
    $hasQr   = $qrFile !== null;

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($app['full_name']) . ',</p>'
        . '<p style="margin:0 0 14px;">We have your application for the <strong style="color:#0f2c4d;">'
        . e($product) . '</strong>. Your reference is <strong style="color:#0f2c4d;">'
        . e($app['reference_code']) . '</strong>.</p>'
        . '<p style="margin:0 0 20px;">To reserve your place, pay the application fee of '
        . '<strong style="color:#0f2c4d;">' . e($amount) . '</strong> and upload the receipt. '
        . 'We check every payment by hand and confirm within two working days.</p>'
        . '<p style="margin:0 0 10px;font-weight:700;color:#0f2c4d;">1. Pay ' . e($amount) . '</p>';

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
        . '<p style="margin:0 0 4px;">Sign in with this email address and upload a screenshot or PDF of the payment.</p>'
        . email_button($portal, 'Sign in and upload')
        . '<p style="margin:0;font-size:14px;color:#8499ac;">Signing in sends a one-time code to this address — no password to remember.</p>';

    return send_mail(
        $app['email'],
        'Application received — pay ' . $amount . ' to reserve your place (' . $app['reference_code'] . ')',
        email_wrap('Application received', $inner),
        'payment',
        $hasQr ? $qrFile : null
    );
}

/** Nudge for an application that is still waiting on payment. */
function send_payment_reminder_email(array $app, ?array $totals = null): bool
{
    $product = product_label((string) $app['product']);
    $portal  = base_url() . '/portal/index.php';
    $totals  = $totals ?? payment_totals($app);
    $amount  = money((float) $totals['balance']);
    $qrFile  = qr_file();

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($app['full_name']) . ',</p>'
        . '<p style="margin:0 0 14px;">Your application for the <strong style="color:#0f2c4d;">' . e($product)
        . '</strong> (' . e($app['reference_code']) . ') still has '
        . '<strong style="color:#0f2c4d;">' . e($amount) . '</strong> outstanding'
        . ($totals['paid'] > 0
            ? ' — you have paid ' . e(money((float) $totals['paid'])) . ' of ' . e(money((float) $totals['due'])) . ' so far'
            : '')
        . '.</p>'
        . '<p style="margin:0 0 8px;">You can pay it in one go or in instalments; upload a receipt for each transfer. '
        . 'If you have already paid, upload the receipt and ignore this note.</p>';

    if ($qrFile !== null) {
        $inner .= '<p style="margin:16px 0 20px;text-align:center;">'
            . '<img src="cid:qr" alt="Payment QR code" width="220" height="220" '
            . 'style="width:220px;height:auto;max-width:100%;border:1px solid #e3ebf2;border-radius:14px;background:#ffffff;"></p>';
    }

    $inner .= email_button($portal, 'Pay and upload the receipt')
        . '<p style="margin:0;font-size:14px;color:#8499ac;">Any trouble? Call +91 97251 54186 and we will help.</p>';

    return send_mail(
        $app['email'],
        'Reminder: ' . $amount . ' still due for ' . $app['reference_code'],
        email_wrap('A quick reminder about your payment', $inner),
        'reminder',
        $qrFile
    );
}

/**
 * A receipt for one verified transfer. An applicant paying in instalments gets
 * one of these per payment, each with its own receipt number and the balance
 * still outstanding.
 */
function send_receipt_email(array $app, array $payment, array $totals): bool
{
    $product = product_label((string) $app['product']);
    $amount  = money((float) $payment['amount']);
    $paidOn  = format_datetime($payment['decided_at'] ?? date('Y-m-d H:i:s'));
    $settled = $totals['balance'] <= 0.001;

    $rows = [
        'Receipt number'    => (string) $payment['receipt_no'],
        'Application'       => (string) $app['reference_code'],
        'Applicant'         => (string) $app['full_name'],
        'Product'           => $product,
        'Amount received'   => $amount,
        'Payment reference' => (string) ($payment['reference'] ?: '—'),
        'Verified on'       => $paidOn,
        'Paid to date'      => money((float) $totals['paid']) . ' of ' . money((float) $totals['due']),
        'Balance'           => $settled ? 'Nil — paid in full' : money((float) $totals['balance']),
    ];

    $table = '<table role="presentation" cellpadding="0" cellspacing="0" '
        . 'style="width:100%;margin:0 0 20px;border:1px solid #e3ebf2;border-radius:14px;">';

    foreach ($rows as $label => $value) {
        $table .= '<tr>'
            . '<td style="padding:12px 18px;font-size:15px;color:#8499ac;border-bottom:1px solid #eef3f7;">'
            . e($label) . '</td>'
            . '<td style="padding:12px 18px;font-size:15px;color:#0f2c4d;font-weight:600;border-bottom:1px solid #eef3f7;">'
            . e($value) . '</td></tr>';
    }

    $table .= '</table>';

    $lead = $settled
        ? '<p style="margin:0 0 20px;">This payment clears the balance — your application is now paid in full '
            . 'and accepted. Keep this email as your receipt.</p>'
        : '<p style="margin:0 0 20px;">Thank you. We have credited this payment against your application. '
            . '<strong style="color:#0f2c4d;">' . e(money((float) $totals['balance'])) . '</strong> is still outstanding — '
            . 'pay it whenever you are ready and upload the next receipt.</p>';

    $closing = $settled
        ? '<p style="margin:0 0 14px;">Our team will call you to agree an installation date.</p>'
        : '';

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($app['full_name']) . ',</p>'
        . $lead
        . $table
        . $closing
        . email_button(base_url() . '/portal/index.php', $settled ? 'View my application' : 'Pay the balance');

    /* the office keeps a copy of every receipt it issues */
    $copies = array_filter(array_map('trim', explode(',', ADMIN_NOTIFY_EMAIL)));

    /* and the receipt itself travels as a PDF */
    $files = [
        receipt_filename($payment) => [
            'mime' => 'application/pdf',
            'data' => build_receipt_pdf($app, $payment, $totals),
        ],
    ];

    return send_mail(
        $app['email'],
        'Receipt ' . $payment['receipt_no'] . ' — ' . $amount . ' received'
            . ($settled ? ' (paid in full)' : ', ' . money((float) $totals['balance']) . ' to go'),
        email_wrap($settled ? 'Payment complete — your receipt' : 'Payment received — your receipt', $inner),
        'receipt',
        null,
        $copies,
        $files
    );
}

/** Sent when an admin rejects the uploaded proof of payment. */
function send_payment_rejected_email(array $app, string $reason = '', ?array $payment = null, ?array $totals = null): bool
{
    $portal = base_url() . '/portal/index.php';
    $amount = money((float) ($payment['amount'] ?? $app['payment_amount'] ?? PAYMENT_AMOUNT));

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($app['full_name']) . ',</p>'
        . '<p style="margin:0 0 14px;">We could not verify the payment receipt you uploaded for '
        . '<strong style="color:#0f2c4d;">' . e($app['reference_code']) . '</strong>, so the application is back to '
        . '<strong style="color:#0f2c4d;">payment pending</strong>.</p>';

    if ($reason !== '') {
        $inner .= '<p style="margin:0 0 14px;padding:14px 18px;border-radius:12px;background:#fdf2f4;color:#a8324a;">'
            . e($reason) . '</p>';
    }

    $inner .= '<p style="margin:0 0 8px;">Please check the ' . e($amount)
        . ' payment went through and upload a clearer receipt. If the money has left your account, '
        . 'send us the bank reference and we will trace it.</p>';

    if ($totals !== null) {
        $inner .= '<p style="margin:0 0 8px;">Outstanding on this application: <strong style="color:#0f2c4d;">'
            . e(money((float) $totals['balance'])) . '</strong>.</p>';
    }

    $inner .= ''
        . email_button($portal, 'Upload a new receipt')
        . '<p style="margin:0;font-size:14px;color:#8499ac;">Prefer to talk it through? Call +91 97251 54186.</p>';

    return send_mail(
        $app['email'],
        'We could not verify your payment — ' . $app['reference_code'],
        email_wrap('Payment could not be verified', $inner),
        'payment_rejected'
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
