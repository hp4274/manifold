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
        /* The masthead is the H2 mark as a PNG beside the name set as live text.
           The wordmark file is WebP, which Outlook cannot decode at all and
           which some proxies mangle — one arrived at a reader as coloured
           static — so nothing here depends on an image the client may not
           understand. Text also stays sharp on every screen. */
        . '<tr><td style="padding:24px 30px 18px;background:#ffffff;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td width="52" style="padding-right:14px;vertical-align:middle;">'
        . '<a href="' . e(SITE_PUBLIC_URL) . '" style="text-decoration:none;">'
        . '<img src="' . e(EMAIL_LOGO_URL) . '" alt="" width="46" height="49" '
        . 'style="display:block;border:0;outline:none;width:46px;height:49px;">'
        . '</a></td>'
        . '<td style="vertical-align:middle;font-family:Figtree,Segoe UI,Helvetica,Arial,sans-serif;">'
        . '<a href="' . e(SITE_PUBLIC_URL) . '" style="text-decoration:none;">'
        . '<span style="display:block;font-size:18px;font-weight:700;letter-spacing:.01em;color:#0f2c4d;">'
        . 'Manifold Clean Energy</span>'
        . '<span style="display:block;padding-top:3px;font-size:12px;font-weight:400;color:#8499ac;">'
        . 'Hydrogen on demand. Made in India.</span>'
        . '</a></td>'
        . '</tr></table></td></tr>'
        /* the brand rule from the website, in the only way email can draw it */
        . '<tr><td style="padding:0;font-size:0;line-height:0;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td width="34%" height="4" style="height:4px;line-height:4px;font-size:4px;background:#4bb453;">&nbsp;</td>'
        . '<td width="33%" height="4" style="height:4px;line-height:4px;font-size:4px;background:#2ab98d;">&nbsp;</td>'
        . '<td width="33%" height="4" style="height:4px;line-height:4px;font-size:4px;background:#17b0a6;">&nbsp;</td>'
        . '</tr></table></td></tr>'
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
 * Sent the moment an application is submitted: the booking amount, the QR code
 * to pay it with, and the link to upload the receipt. The delivery payment is
 * named here too, so nobody is surprised by it later.
 */
function send_payment_email(array $app): bool
{
    $product  = product_label((string) $app['product']);
    $portal   = base_url() . '/portal/index.php';
    $amount   = money(stage_amount($app, 'booking'));
    $delivery = money(stage_amount($app, 'delivery'));
    $qrFile   = qr_file();
    $hasQr    = $qrFile !== null;

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($app['full_name']) . ',</p>'
        . '<p style="margin:0 0 14px;">We have your application for the <strong style="color:#0f2c4d;">'
        . e($product) . '</strong>. Your booking number is <strong style="color:#0f2c4d;">'
        . e($app['reference_code']) . '</strong>.</p>'
        . '<p style="margin:0 0 20px;">To reserve your place, pay the booking amount of '
        . '<strong style="color:#0f2c4d;">' . e($amount) . '</strong> and upload the receipt. '
        . 'We check every payment by hand and confirm within two working days.</p>'
        . '<p style="margin:0 0 20px;">There are two payments in all: this booking amount now, and '
        . '<strong style="color:#0f2c4d;">' . e($delivery) . '</strong> when your '
        . e($product) . ' is delivered. Both come off the price and both are confirmed by our team.</p>';

    $referred = ($app['referral_reward_status'] ?? 'none') === 'pending';

    if ($referred) {
        $inner .= '<p style="margin:0 0 20px;padding:12px 18px;border-radius:12px;background:#eefaf4;'
            . 'border:1px solid #cdeee0;font-size:15px;color:#0f2c4d;">'
            . 'Referral code <strong>' . e((string) $app['referred_by_code']) . '</strong> recorded. '
            . 'What you pay is unchanged — the reward goes to whoever gave you the code, '
            . 'once your booking payment is verified.</p>';
    } elseif (!empty($app['referred_by_code'])) {
        $inner .= '<p style="margin:0 0 20px;padding:12px 18px;border-radius:12px;background:#fff6ed;'
            . 'border:1px solid #f4ddc4;font-size:15px;color:#0f2c4d;">'
            . 'We could not match the referral code <strong>' . e((string) $app['referred_by_code'])
            . '</strong>. Reply to this email with the correct one and we will credit the right person.</p>';
    }

    $inner .= '<p style="margin:0 0 10px;font-weight:700;color:#0f2c4d;">1. Pay the booking amount, '
        . e($amount) . '</p>';

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
        'Application received — pay the ' . $amount . ' booking amount to reserve your place ('
            . $app['reference_code'] . ')',
        email_wrap('Application received', $inner),
        'payment',
        $hasQr ? $qrFile : null
    );
}

/** Nudge for an application that is still waiting on the payment now due. */
function send_payment_reminder_email(array $app, ?array $totals = null): bool
{
    $product = product_label((string) $app['product']);
    $portal  = base_url() . '/portal/index.php';
    $totals  = $totals ?? payment_totals($app);
    $stage   = $totals['stages'][$totals['current'] ?? 'booking'];
    $amount  = money((float) $stage['balance']);
    $qrFile  = qr_file();

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($app['full_name']) . ',</p>'
        . '<p style="margin:0 0 14px;">Your application for the <strong style="color:#0f2c4d;">' . e($product)
        . '</strong> (' . e($app['reference_code']) . ') is waiting on the '
        . '<strong style="color:#0f2c4d;">' . e(strtolower($stage['label'])) . ' of ' . e($amount) . '</strong>'
        . ($totals['paid'] > 0
            ? ' — you have paid ' . e(money((float) $totals['paid'])) . ' of ' . e(money((float) $totals['due'])) . ' so far'
            : '')
        . '.</p>'
        . '<p style="margin:0 0 8px;">Pay the full amount in one transfer and upload the receipt in the portal. '
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
        'Reminder: the ' . strtolower($stage['label']) . ' of ' . $amount . ' is due for '
            . $app['reference_code'],
        email_wrap('A quick reminder about your payment', $inner),
        'reminder',
        $qrFile
    );
}

/**
 * A receipt for one verified transfer — one for the booking payment, one for
 * the delivery payment, each with its own receipt number.
 */
function send_receipt_email(array $app, array $payment, array $totals): bool
{
    $product   = product_label((string) $app['product']);
    $amount    = money((float) $payment['amount']);
    $paidOn    = format_datetime($payment['decided_at'] ?? date('Y-m-d H:i:s'));
    $settled   = $totals['balance'] <= 0.001;
    $stageKey  = (string) ($payment['stage'] ?? 'booking');
    $stageName = payment_stage_label($stageKey);

    $rows = [
        'Receipt number'    => (string) $payment['receipt_no'],
        'Booking number'    => (string) $app['reference_code'],
        'Applicant'         => (string) $app['full_name'],
        'Product'           => $product,
        'Payment'           => $stageName,
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

    if ($settled) {
        $lead = '<p style="margin:0 0 20px;">This clears the last of it — both payments on your application '
            . 'are verified. Keep this email as your receipt.</p>';
    } elseif ($stageKey === 'booking') {
        $lead = '<p style="margin:0 0 20px;">Thank you. Your booking payment is verified and your '
            . e($product) . ' is reserved. The '
            . '<strong style="color:#0f2c4d;">' . e(money((float) $totals['stages']['delivery']['amount']))
            . '</strong> delivery payment falls due when the unit is ready — we will email you then, and you '
            . 'can upload that receipt in the portal.</p>';
    } else {
        $lead = '<p style="margin:0 0 20px;">Thank you. We have credited this payment against your application. '
            . '<strong style="color:#0f2c4d;">' . e(money((float) $totals['balance'])) . '</strong> is still '
            . 'outstanding — upload the receipt once it is paid.</p>';
    }

    $closing = $settled
        ? '<p style="margin:0 0 14px;">Our team will call you to agree an installation date.</p>'
        : '';

    /* the verified booking payment is when the referral code becomes worth
       something, so that is where it is handed over */
    $handOver = $stageKey === 'booking' || $settled;
    $referral = '';

    if ($handOver && !empty($app['referral_code'])) {
        $code   = (string) $app['referral_code'];
        $earns  = money(referral_reward());

        $referral = '<div style="margin:0 0 22px;padding:18px;border-radius:14px;background:#f6f9fc;'
            . 'border:1px solid #e3ebf2;">'
            . '<p style="margin:0 0 8px;font-weight:700;color:#0f2c4d;">Your referral code</p>'
            . '<p style="margin:0 0 12px;font-size:22px;font-weight:700;letter-spacing:.12em;color:#0e8f96;">'
            . e($code) . '</p>'
            . '<p style="margin:0 0 12px;font-size:15px;color:#5b7186;">Every person who applies with this code '
            . 'and has their booking payment verified earns you <strong style="color:#0f2c4d;">' . e($earns)
            . '</strong>, which we transfer to you once we have checked it. Send them a link with the code '
            . 'already filled in:</p>'
            . '<p style="margin:0 0 6px;font-size:15px;"><a href="' . e(referral_link($code, 'stove'))
            . '" style="color:#0e8f96;">Apply for a stove &rarr;</a></p>'
            . '<p style="margin:0;font-size:15px;"><a href="' . e(referral_link($code, 'tuktuk'))
            . '" style="color:#0e8f96;">Apply for a TukTuk kit &rarr;</a></p>'
            . '</div>';
    }

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($app['full_name']) . ',</p>'
        . $lead
        . $table
        . $referral
        . $closing
        . email_button(
            base_url() . '/portal/index.php',
            $settled ? 'View my application' : 'View my application and payments'
        );

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
            . ($settled ? ' (both payments verified)' : ', ' . money((float) $totals['balance']) . ' to go'),
        email_wrap($settled ? 'Payment complete — your receipt' : 'Payment received — your receipt', $inner),
        'receipt',
        null,
        $copies,
        $files
    );
}

/**
 * Told to the referrer when the office has transferred their reward.
 * $referral is the application that quoted their code.
 */
function send_referral_paid_email(array $referrer, array $referral): bool
{
    $amount = money((float) $referral['referral_reward']);
    $code   = (string) $referrer['referral_code'];

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($referrer['full_name']) . ',</p>'
        . '<p style="margin:0 0 20px;">Somebody applied with your referral code '
        . '<strong style="color:#0f2c4d;">' . e($code) . '</strong> and has paid their fee, so we have sent you '
        . '<strong style="color:#0f2c4d;">' . e($amount) . '</strong>'
        . (!empty($referral['referral_reward_note'])
            ? ' — ' . e((string) $referral['referral_reward_note'])
            : '')
        . '.</p>'
        . '<p style="margin:0 0 20px;">Thank you for the introduction. Your code keeps working, so anyone else '
        . 'you send our way earns you the same again.</p>'
        . email_button(base_url() . '/portal/index.php', 'See my referrals')
        . '<p style="margin:0;font-size:14px;color:#8499ac;">Not received it after a working day? '
        . 'Call +91 97251 54186 and we will chase it.</p>';

    return send_mail(
        $referrer['email'],
        'Your referral reward of ' . $amount . ' is on its way',
        email_wrap('Referral reward sent', $inner),
        'referral'
    );
}

/** Sent when an admin rejects the uploaded proof of payment. */
function send_payment_rejected_email(array $app, string $reason = '', ?array $payment = null, ?array $totals = null): bool
{
    $portal    = base_url() . '/portal/index.php';
    $stageKey  = (string) ($payment['stage'] ?? 'booking');
    $stageName = payment_stage_label($stageKey);
    $amount    = money((float) ($payment['amount'] ?? stage_amount($app, $stageKey)));

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($app['full_name']) . ',</p>'
        . '<p style="margin:0 0 14px;">We could not verify the receipt you uploaded for the '
        . '<strong style="color:#0f2c4d;">' . e(strtolower($stageName)) . '</strong> on '
        . '<strong style="color:#0f2c4d;">' . e($app['reference_code']) . '</strong>, so that payment is '
        . 'showing as due again.</p>';

    if ($reason !== '') {
        $inner .= '<p style="margin:0 0 14px;padding:14px 18px;border-radius:12px;background:#fdf2f4;color:#a8324a;">'
            . e($reason) . '</p>';
    }

    $inner .= '<p style="margin:0 0 8px;">Please check the ' . e($amount)
        . ' payment went through and upload a clearer receipt. If the money has left your account, '
        . 'send us the bank reference and we will trace it.</p>';

    if ($totals !== null) {
        $inner .= '<p style="margin:0 0 8px;">Still to pay on this application: <strong style="color:#0f2c4d;">'
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
        . '&status=' . rawurlencode((string) $app['status']) . '#row-' . (int) $app['id'];

    $rows = [
        'Booking number' => (string) $app['reference_code'],
        'Product'        => $product,
        'Applicant'      => (string) $app['full_name'],
        'Email'          => (string) $app['email'],
        'Phone'          => (string) ($app['mobile_number'] ?? ''),
        'Payment'        => status_label((string) $app['status']),
        'Payment ref'    => (string) ($app['payment_reference'] ?? '—'),
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

/** Human label for the "I am writing about" choice on the contact form. */
function contact_interest_label(?string $interest): string
{
    $labels = [
        'stove'        => 'Kinetic Hydrogen Cooking Stove',
        'tuktuk'       => 'Hydrogen Conversion Kit for TukTuk',
        'fleet'        => 'A fleet or institutional pilot',
        'distribution' => 'Distribution or dealership',
        'partnership'  => 'Partnership or investment',
        'other'        => 'Something else',
    ];

    return $labels[(string) $interest] ?? '';
}

/**
 * Sent the moment a contact enquiry is submitted: a thank you, the enquiry
 * quoted back so the sender knows what reached us, and how to reach the office
 * in the meantime.
 */
function send_contact_thanks_email(array $enquiry): bool
{
    $interest = contact_interest_label($enquiry['interest'] ?? null);

    $rows = [
        'Name'    => (string) $enquiry['name'],
        'Company' => (string) ($enquiry['company'] ?? ''),
        'Email'   => (string) $enquiry['email'],
        'Phone'   => (string) $enquiry['phone'],
        'About'   => $interest,
        'City'    => (string) ($enquiry['city'] ?? ''),
    ];

    $table = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 8px;">';

    foreach ($rows as $label => $value) {
        if (trim($value) === '') {
            continue;
        }

        $table .= '<tr>'
            . '<td style="padding:8px 0;font-size:15px;color:#8499ac;width:38%;">' . e($label) . '</td>'
            . '<td style="padding:8px 0;font-size:15px;color:#0f2c4d;font-weight:600;">' . e($value) . '</td>'
            . '</tr>';
    }

    $table .= '</table>';

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($enquiry['name']) . ',</p>'
        . '<p style="margin:0 0 20px;">Thank you for writing to us. Your enquiry has reached the Ahmedabad team '
        . 'and a person — not an autoresponder — will read it. We reply within two working days.</p>'
        . '<p style="margin:0 0 10px;font-weight:700;color:#0f2c4d;">What you sent us</p>'
        . $table
        . '<p style="margin:16px 0 6px;font-weight:700;color:#0f2c4d;">Your message</p>'
        . '<p style="margin:0 0 20px;padding:14px 18px;border-radius:12px;background:#f6f9fc;'
        . 'border:1px solid #e3ebf2;font-size:15px;color:#0f2c4d;">'
        . nl2br(e($enquiry['message'])) . '</p>'
        . '<p style="margin:0 0 4px;">In the meantime you can read how the technology works and what we are building.</p>'
        . email_button(base_url() . '/technology.html', 'See the technology')
        . '<p style="margin:0;font-size:14px;color:#8499ac;">Need us sooner? Call +91 97251 54186, '
        . 'or reply to this email and it lands with the same team.</p>';

    return send_mail(
        (string) $enquiry['email'],
        'Thank you for contacting Manifold Clean Energy',
        email_wrap('Thank you for getting in touch', $inner),
        'contact_thanks'
    );
}

/**
 * Sent when someone new joins the mailing list. Re-subscribing an address that
 * is already on the list sends nothing — see submit.php.
 */
function send_newsletter_welcome_email(string $to): bool
{
    $site = base_url();

    $inner = '<p style="margin:0 0 14px;">Hello,</p>'
        . '<p style="margin:0 0 20px;">You are on the list. From now on you will hear from us when there is '
        . 'something worth telling: how the hydrogen stove and the TukTuk conversion kit are coming along, '
        . 'where the pilots are running, and when either opens up in a new city.</p>'
        . '<p style="margin:0 0 20px;">We write rarely and we never pass your address on.</p>'
        . email_button($site . '/technology.html', 'See how it works')
        . '<p style="margin:0 0 4px;font-size:14px;color:#8499ac;">Already thinking about a unit? '
        . '<a href="' . e($site) . '/apply-stove.html" style="color:#0e8f96;">Apply for the stove</a> or '
        . '<a href="' . e($site) . '/apply-tuktuk.html" style="color:#0e8f96;">for the conversion kit</a>.</p>'
        . '<p style="margin:0;font-size:14px;color:#8499ac;">Did not sign up, or changed your mind? '
        . 'Reply with "unsubscribe" and you are off the list.</p>';

    return send_mail(
        $to,
        'You are on the Manifold Clean Energy list',
        email_wrap('Thank you for subscribing', $inner),
        'newsletter_welcome'
    );
}
