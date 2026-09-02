<?php
/**
 * Email bodies. Plain inline-styled HTML — email clients ignore stylesheets.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/receipt-pdf.php';

function email_wrap(string $heading, string $inner, string $preheader = ''): string
{
    $year = date('Y');

    /* The inbox preview line. Without one every client shows the masthead, so
       twenty different mails all preview as "Manifold Clean Energy". Falls back
       to the opening of the message itself. */
    if ($preheader === '') {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', $inner))));
        $preheader = $text === '' ? $heading : mb_substr($text, 0, 140);
    }

    return '<!DOCTYPE html><html lang="en"><head>'
        . '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light dark">'
        . '<meta name="supported-color-schemes" content="light dark">'
        /* Gmail and Apple Mail invert a white card on their own, and the navy
           body text is exactly the colour that comes out unreadable. Say what
           dark should look like instead of letting them guess. */
        . '<style>@media (prefers-color-scheme:dark){'
        . 'body,.mf-bg{background:#0b1620 !important}'
        . '.mf-card{background:#12212e !important;border-color:#263a4b !important}'
        . '.mf-head,.mf-title{color:#eaf2f8 !important}'
        . '.mf-body{color:#c2d2df !important}'
        . '.mf-foot{background:#0e1b26 !important;border-color:#263a4b !important;color:#93a7b8 !important}'
        . '.mf-sub{color:#93a7b8 !important}'
        . '}</style></head>'
        . '<body style="margin:0;padding:0;background:#f6f9fc;">'
        . '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f6f9fc;">'
        . e($preheader) . '</div>'
        . '<table role="presentation" class="mf-bg" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f9fc;padding:28px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" class="mf-card" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #e3ebf2;border-radius:18px;overflow:hidden;font-family:Figtree,Segoe UI,Helvetica,Arial,sans-serif;">'
        /* The masthead is the H2 mark as a PNG beside the name set as live text.
           The wordmark file is WebP, which Outlook cannot decode at all and
           which some proxies mangle — one arrived at a reader as coloured
           static — so nothing here depends on an image the client may not
           understand. Text also stays sharp on every screen. */
        . '<tr><td class="mf-card" style="padding:24px 30px 18px;background:#ffffff;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td width="52" style="padding-right:14px;vertical-align:middle;">'
        . '<a href="' . e(SITE_PUBLIC_URL !== '' ? SITE_PUBLIC_URL : base_url()) . '" style="text-decoration:none;">'
        . '<img src="' . e(EMAIL_LOGO_URL !== '' ? EMAIL_LOGO_URL : base_url() . '/assets/images/favicon.png') . '" alt="" width="46" height="49" '
        . 'style="display:block;border:0;outline:none;width:46px;height:49px;">'
        . '</a></td>'
        . '<td style="vertical-align:middle;font-family:Figtree,Segoe UI,Helvetica,Arial,sans-serif;">'
        . '<a href="' . e(SITE_PUBLIC_URL !== '' ? SITE_PUBLIC_URL : base_url()) . '" style="text-decoration:none;">'
        . '<span class="mf-head" style="display:block;font-size:18px;font-weight:700;letter-spacing:.01em;color:#0f2c4d;">'
        . 'Manifold Clean Energy</span>'
        . '<span class="mf-sub" style="display:block;padding-top:3px;font-size:12px;font-weight:400;color:#8499ac;">'
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
        . '<tr><td class="mf-card" style="padding:32px 30px 8px;"><h1 class="mf-title" style="margin:0 0 16px;font-size:24px;line-height:1.35;color:#0f2c4d;">' . $heading . '</h1></td></tr>'
        . '<tr><td class="mf-card mf-body" style="padding:0 30px 30px;font-size:16px;line-height:1.7;color:#5b7186;">' . $inner . '</td></tr>'
        . '<tr><td class="mf-foot" style="padding:20px 30px;background:#f6f9fc;border-top:1px solid #e3ebf2;font-size:13px;line-height:1.6;color:#8499ac;">'
        . 'Manifold Clean Energy Pvt. Ltd. · 711, SAFAL Prelude, Corporate Road, Prahlad Nagar, Ahmedabad 380015<br>'
        . '+91 97251 54186 · info@manifoldcleanenergy.co.in<br>&copy; ' . $year . ' Manifold Clean Energy Pvt. Ltd.'
        . '</td></tr></table></td></tr></table></body></html>';
}

function email_button(string $url, string $label): string
{
    return '<p style="margin:26px 0;">' . email_pill($url, $label) . '</p>';
}

/**
 * The website's two buttons, in the only way email can draw them: an
 * inline-block with its own padding. `$accent` is the green one somebody is
 * meant to press, the other is the white one beside it.
 *
 * No flexbox and no classes — Outlook has neither.
 */
function email_pill(string $url, string $label, bool $accent = true): string
{
    $style = $accent
        ? 'background:#4bb453;border:1px solid #4bb453;color:#ffffff;'
        : 'background:#ffffff;border:1px solid #cfe0d4;color:#0f2c4d;';

    return '<a href="' . e($url) . '" style="display:inline-block;padding:13px 24px;margin:0 8px 8px 0;'
        . 'border-radius:999px;font-size:15px;font-weight:700;line-height:1;text-decoration:none;'
        . $style . '">' . e($label) . '</a>';
}

/**
 * A label/value table, the shape every notice in here uses for its facts.
 *
 * One renderer rather than six copies of the same markup: an email client is
 * unforgiving enough without six chances to get the padding wrong.
 */
function email_rows(array $rows): string
{
    $table = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 8px;">';

    foreach ($rows as $label => $value) {
        $value = (string) $value;

        $table .= '<tr>'
            . '<td style="padding:8px 0;font-size:15px;color:#8499ac;width:38%;">' . e($label) . '</td>'
            . '<td style="padding:8px 0;font-size:15px;color:#0f2c4d;font-weight:600;">'
            . e($value !== '' ? $value : '-') . '</td>'
            . '</tr>';
    }

    return $table . '</table>';
}

/** Who in the office hears about something. Configured, or every live account. */
function office_recipients(): array
{
    $recipients = array_filter(array_map('trim', explode(',', ADMIN_NOTIFY_EMAIL)));

    if (!$recipients) {
        $stmt       = db()->query('SELECT email FROM admin_users WHERE is_active = 1');
        $recipients = array_column($stmt->fetchAll(), 'email');
    }

    return $recipients ?: [MAIL_REPLY_TO];
}

/** The same letter to everybody in the office. True if any of them took it. */
function send_to_office(string $subject, string $heading, string $inner, string $kind): bool
{
    $sent = false;

    foreach (office_recipients() as $to) {
        $sent = send_mail($to, $subject, email_wrap($heading, $inner), $kind) || $sent;
    }

    return $sent;
}

/**
 * Sent when an application arrives, before anybody has looked at it.
 *
 * The applicant has just filled a long form and pressed a button; saying
 * nothing until the office gets to it is how somebody fills it in again.
 */
function send_application_received_email(array $app): bool
{
    $product = product_label((string) $app['product']);

    $inner = '<p style="margin:0 0 16px;">Thank you, ' . e((string) $app['full_name'])
        . '. We have your application for the <strong style="color:#0f2c4d;">' . e($product)
        . '</strong> and it is with our team.</p>'
        . email_rows([
            'Booking number' => (string) $app['reference_code'],
            'Product'        => $product,
            'Applied on'     => format_datetime((string) ($app['created_at'] ?? date('Y-m-d H:i:s'))),
        ])
        . '<p style="margin:16px 0 0;">Somebody reviews it by hand - usually within two working days. '
        . 'When it is approved we email you the payment details and open your portal, where you upload '
        . 'the receipt. Nothing is owed until then.</p>';

    return send_mail(
        (string) $app['email'],
        'We have your application (' . $app['reference_code'] . ')',
        email_wrap('Application received', $inner),
        'application_received'
    );
}

/** And the office hears about it, so nothing waits on somebody refreshing a page. */
function send_new_application_admin(array $app): bool
{
    $product = product_label((string) $app['product']);
    $link    = base_url() . '/admin/list?type=' . rawurlencode((string) $app['product'])
        . '&status=submitted#row-' . (int) $app['id'];

    $inner = '<p style="margin:0 0 16px;">A new application is waiting for a decision.</p>'
        . email_rows([
            'Booking number' => (string) $app['reference_code'],
            'Product'        => $product,
            'Applicant'      => (string) $app['full_name'],
            'Email'          => (string) $app['email'],
            'Phone'          => (string) ($app['mobile_number'] ?? ''),
            'Quoted code'    => (string) ($app['referred_by_code'] ?? ''),
        ])
        . email_button($link, 'Open it in the admin')
        . '<p style="margin:0;font-size:14px;color:#8499ac;">Approving it emails the applicant their '
        . 'payment details and opens their portal.</p>';

    return send_to_office(
        'New application - ' . $app['reference_code'] . ' (' . $product . ')',
        'New application',
        $inner,
        'application_new'
    );
}

/** Sent when the office turns an application down. */
function send_application_rejected_email(array $app, string $reason = ''): bool
{
    $reason = trim($reason);

    $inner = '<p style="margin:0 0 16px;">Thank you for applying for the '
        . e(product_label((string) $app['product'])) . '. After review we are not able to take this '
        . 'application forward.</p>'
        . email_rows([
            'Booking number' => (string) $app['reference_code'],
            'Applicant'      => (string) $app['full_name'],
        ])
        . ($reason !== ''
            ? '<p style="margin:16px 0 0;"><strong style="color:#0f2c4d;">Why:</strong> ' . e($reason) . '</p>'
            : '')
        . '<p style="margin:16px 0 0;">Nothing has been charged. If you think this is a mistake, or your '
        . 'circumstances change, reply to this email or call +91 97251 54186 and we will look again.</p>';

    return send_mail(
        (string) $app['email'],
        'About your application (' . $app['reference_code'] . ')',
        email_wrap('We cannot take this one forward', $inner),
        'application_rejected'
    );
}

/**
 * A dealer is live: their own letter, and one to the distributor who holds them.
 *
 * Sent when the office approves a request, and when the office adds a dealer
 * itself — both are the moment a code starts working.
 */
function send_dealer_added_email(array $dealer, ?array $distributor = null): bool
{
    $portal = base_url() . '/portal/';
    $code   = (string) ($dealer['dealer_code'] ?? '');

    $inner = '<p style="margin:0 0 16px;">You are set up as a Manifold dealer'
        . ($distributor ? ' under ' . e((string) $distributor['full_name']) : '') . '.</p>'
        . email_rows([
            'Your code'   => $code,
            'Name'        => (string) $dealer['full_name'],
            'Distributor' => $distributor ? (string) $distributor['full_name'] : '',
        ])
        . '<p style="margin:16px 0 0;">Every sale made with your code is yours. Sign in with this email '
        . 'address to see your clients, your stock and what you are owed - no password, we send a code.</p>'
        . email_button($portal, 'Open your dashboard');

    $sent = false;

    if (($dealer['email'] ?? '') !== '') {
        $sent = send_mail(
            (string) $dealer['email'],
            'Your dealer code is ' . $code,
            email_wrap('Welcome to Manifold', $inner),
            'dealer_added'
        );
    }

    if ($distributor && ($distributor['email'] ?? '') !== '') {
        $theirs = '<p style="margin:0 0 16px;">' . e((string) $dealer['full_name'])
            . ' is approved and now sells under you.</p>'
            . email_rows([
                'Dealer'      => (string) $dealer['full_name'],
                'Their code'  => $code,
                'Email'       => (string) ($dealer['email'] ?? ''),
                'Phone'       => (string) ($dealer['mobile_number'] ?? ''),
            ])
            . '<p style="margin:16px 0 0;">Every completed sale of theirs earns you the override.</p>'
            . email_button(base_url() . '/distributor/dealers', 'See your dealers');

        $sent = send_mail(
            (string) $distributor['email'],
            $dealer['full_name'] . ' is approved as your dealer',
            email_wrap('A dealer under you', $theirs),
            'dealer_added'
        ) || $sent;
    }

    return $sent;
}

/**
 * Sent when the finance team has checked the paperwork.
 *
 * It is the moment the delivery payment opens — or, on a sale a partner
 * recorded as paid in full, the moment there is nothing left to do.
 */
function send_docs_verified_email(array $app): bool
{
    $settled = ($app['status'] ?? '') === 'complete';
    $portal  = base_url() . '/portal/';

    $inner = '<p style="margin:0 0 14px;">Hello ' . e((string) $app['full_name']) . ',</p>'
        . '<p style="margin:0 0 16px;">Your documents have been checked and verified'
        . ($settled
            ? ', and that is the last step. Everything on your '
                . e(product_label((string) $app['product'])) . ' is done - we will call you to arrange '
                . 'the handover.</p>'
            : '. One question before anything more is owed: tell us in your portal whether to build and '
                . 'deliver your unit, or to cancel the order - if you cancel, everything you have paid is '
                . 'refunded in full.</p>')
        . email_rows([
            'Booking number' => (string) $app['reference_code'],
            'Product'        => product_label((string) $app['product']),
            'Verified on'    => format_datetime((string) ($app['docs_verified_at'] ?? date('Y-m-d H:i:s'))),
        ])
        . email_button($portal, $settled ? 'See your application' : 'Answer in your portal');

    return send_mail(
        (string) $app['email'],
        'Your documents are verified (' . $app['reference_code'] . ')',
        email_wrap($settled ? 'Documents verified' : 'Documents verified - delivery payment open', $inner),
        'docs_verified'
    );
}

/**
 * Sent when the finance team cannot accept the paperwork.
 *
 * The reason is the whole of what the applicant is told, so it goes in the body
 * where they cannot miss it, in the same red panel a refused payment uses. The
 * application itself is not turned down and nothing they have paid is at risk —
 * said plainly, because "your documents were not accepted" reads like the end
 * of the road to somebody who has already transferred money.
 *
 * There is no upload box in the portal for these, so the email asks for them by
 * reply. Whoever reads that mailbox is the person who has the documents.
 */
function send_docs_rejected_email(array $app, string $reason): bool
{
    $inner = '<p style="margin:0 0 14px;">Hello ' . e((string) $app['full_name']) . ',</p>'
        . '<p style="margin:0 0 14px;">Our finance team has looked at the documents on your '
        . '<strong style="color:#0f2c4d;">' . e(product_label((string) $app['product'])) . '</strong> '
        . 'application (' . e((string) $app['reference_code']) . ') and cannot accept them as they are.</p>'
        . '<p style="margin:0 0 16px;padding:14px 18px;border-radius:12px;background:#fdf2f4;color:#a8324a;">'
        . e($reason) . '</p>'
        . '<p style="margin:0 0 16px;">Reply to this email with the corrected documents attached and we '
        . 'will check them again. Your application stands and your booking payment is safe — the delivery '
        . 'payment simply waits until the paperwork is in order.</p>'
        . email_rows([
            'Booking number' => (string) $app['reference_code'],
            'Product'        => product_label((string) $app['product']),
            'Checked on'     => format_datetime((string) ($app['docs_rejected_at'] ?? date('Y-m-d H:i:s'))),
        ])
        . email_button(base_url() . '/portal/', 'See your application')
        . '<p style="margin:16px 0 0;font-size:14px;color:#5c7389;">Not sure what is being asked for? '
        . 'Call +91 97251 54186 and we will talk it through.</p>';

    return send_mail(
        (string) $app['email'],
        'We need your documents again (' . $app['reference_code'] . ')',
        email_wrap('Documents not accepted', $inner),
        'docs_rejected'
    );
}

/**
 * Sent when the client says to go ahead: the delivery payment is open.
 */
function send_delivery_open_email(array $app): bool
{
    $inner = '<p style="margin:0 0 14px;">Hello ' . e((string) $app['full_name']) . ',</p>'
        . '<p style="margin:0 0 16px;">Thank you - we are going ahead with your '
        . e(product_label((string) $app['product'])) . '. The delivery payment is open in your portal '
        . 'now; pay it and upload the receipt, and we will arrange the handover.</p>'
        . email_rows([
            'Booking number'   => (string) $app['reference_code'],
            'Delivery payment' => money(stage_amount($app, 'delivery')),
        ])
        . email_button(base_url() . '/portal/', 'Pay the delivery amount');

    return send_mail(
        (string) $app['email'],
        'Delivery payment open (' . $app['reference_code'] . ')',
        email_wrap('Going ahead with your order', $inner),
        'delivery_open'
    );
}

/**
 * Sent when the client cancels: what they paid comes back.
 */
function send_order_cancelled_email(array $app): bool
{
    $inner = '<p style="margin:0 0 14px;">Hello ' . e((string) $app['full_name']) . ',</p>'
        . '<p style="margin:0 0 16px;">Your order is cancelled, as you asked. Everything you have paid '
        . 'is refunded in full - our team is arranging the transfer to the account you paid from and '
        . 'will confirm it by email.</p>'
        . email_rows([
            'Booking number' => (string) $app['reference_code'],
            'Product'        => product_label((string) $app['product']),
            'To refund'      => money(payment_totals($app)['paid']),
        ])
        . '<p style="margin:16px 0 0;">If this was a mistake, reply to this email or call '
        . '+91 97251 54186 and we will put it back.</p>';

    return send_mail(
        (string) $app['email'],
        'Your order is cancelled (' . $app['reference_code'] . ')',
        email_wrap('Order cancelled - refund on its way', $inner),
        'order_cancelled'
    );
}

/** And the office hears the answer, because a cancellation owes somebody money. */
function send_delivery_choice_admin(array $app, string $choice): bool
{
    $cancelled = $choice === 'cancel';
    $link      = base_url() . '/admin/list?type=' . rawurlencode((string) $app['product'])
        . '#row-' . (int) $app['id'];

    $inner = '<p style="margin:0 0 16px;">'
        . e((string) $app['full_name'])
        . ($cancelled
            ? ' has cancelled their order after the documents were verified. The booking amount has to be '
                . 'refunded.'
            : ' has asked to go ahead. The delivery payment is open to them now.')
        . '</p>'
        . email_rows([
            'Booking number' => (string) $app['reference_code'],
            'Product'        => product_label((string) $app['product']),
            'Answered'       => format_datetime((string) ($app['delivery_choice_at'] ?? date('Y-m-d H:i:s'))),
            $cancelled ? 'To refund' : 'Delivery payment' => $cancelled
                ? money(payment_totals($app)['paid'])
                : money(stage_amount($app, 'delivery')),
        ])
        . email_button($link, 'Open it in the admin');

    return send_to_office(
        ($cancelled ? 'Order cancelled - refund due' : 'Client is going ahead') . ' - ' . $app['reference_code'],
        $cancelled ? 'Order cancelled' : 'Client is going ahead',
        $inner,
        'delivery_choice'
    );
}

/** Sent when a stock order is released: the units are theirs now. */
function send_stock_released_email(array $order, array $buyer, string $summary): bool
{
    if (($buyer['email'] ?? '') === '') {
        return false;
    }

    $isDealer = ($order['buyer_type'] ?? '') === 'dealer';

    $inner = '<p style="margin:0 0 16px;">Your stock order has been released - the units are on your '
        . 'balance now.</p>'
        . email_rows([
            'Order'     => '#' . (int) $order['id'],
            'Units'     => $summary,
            'Paid'      => money((float) $order['total_amount']),
            'Reference' => (string) ($order['reference'] ?? ''),
        ])
        . '<p style="margin:16px 0 0;">Recording a sale takes a unit off this balance, so what is left '
        . 'is always what you can still sell.</p>'
        . email_button(base_url() . ($isDealer ? '/dealer/stock' : '/distributor/stock'), 'See your stock');

    return send_mail(
        (string) $buyer['email'],
        'Stock released - order #' . (int) $order['id'],
        email_wrap('Your stock is released', $inner),
        'stock_released'
    );
}

/**
 * Where a commission claim has got to, told to whoever it is waiting on.
 *
 * One letter for every step, because a claim that moves in silence is a claim
 * somebody chases by phone.
 */
function send_voucher_update_email(string $to, string $heading, string $line, array $rows, string $link): bool
{
    if ($to === '') {
        return false;
    }

    $inner = '<p style="margin:0 0 16px;">' . $line . '</p>'
        . email_rows($rows)
        . email_button($link, 'Open the claim');

    return send_mail($to, $heading, email_wrap($heading, $inner), 'voucher_update');
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
        . '<p style="margin:0 0 14px;">Your application for the <strong style="color:#0f2c4d;">'
        . e($product) . '</strong> has been approved. Your booking number is '
        . '<strong style="color:#0f2c4d;">' . e((string) $app['reference_code']) . '</strong>.</p>'
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
            . 'What you pay is unchanged - the reward goes to whoever gave you the code, '
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
        . '<p style="margin:0;font-size:14px;color:#8499ac;">Signing in sends a one-time code to this address - no password to remember.</p>';

    /* This one now goes out when the office approves an application, not when
       it arrives — the applicant already heard that it arrived — so it says so
       rather than repeating "received". */
    return send_mail(
        $app['email'],
        'Approved - pay the ' . $amount . ' booking amount to reserve your place ('
            . $app['reference_code'] . ')',
        email_wrap('Your application is approved', $inner),
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

    /* nothing outstanding, nothing to chase — see the same guard on
       payment.php's remind action, which says so to the office */
    if ((float) $stage['balance'] <= 0) {
        return false;
    }

    $amount  = money((float) $stage['balance']);
    $qrFile  = qr_file();

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($app['full_name']) . ',</p>'
        . '<p style="margin:0 0 14px;">Your application for the <strong style="color:#0f2c4d;">' . e($product)
        . '</strong> (' . e($app['reference_code']) . ') is waiting on the '
        . '<strong style="color:#0f2c4d;">' . e(strtolower($stage['label'])) . ' of ' . e($amount) . '</strong>'
        . ($totals['paid'] > 0
            ? ' - you have paid ' . e(money((float) $totals['paid'])) . ' of ' . e(money((float) $totals['due'])) . ' so far'
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
        'Payment reference' => (string) ($payment['reference'] ?: '-'),
        'Verified on'       => $paidOn,
        'Balance'           => $settled ? 'Nil - paid in full' : money((float) $totals['balance']),
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
        $lead = '<p style="margin:0 0 20px;">This clears the last of it - both payments on your application '
            . 'are verified. Keep this email as your receipt.</p>';
    } elseif ($stageKey === 'booking') {
        $lead = '<p style="margin:0 0 20px;">Thank you. Your booking payment is verified and your '
            . e($product) . ' is reserved. The '
            . '<strong style="color:#0f2c4d;">' . e(money((float) $totals['stages']['delivery']['amount']))
            . '</strong> delivery payment falls due when the unit is ready - we will email you then, and you '
            . 'can upload that receipt in the portal.</p>';
    } else {
        $lead = '<p style="margin:0 0 20px;">Thank you. We have credited this payment against your application. '
            . '<strong style="color:#0f2c4d;">' . e(money((float) $totals['balance'])) . '</strong> is still '
            . 'outstanding - upload the receipt once it is paid.</p>';
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
            /* the same pair the website shows: the one to press, and the other */
            . '<p style="margin:0;font-size:15px;">'
            . email_pill(referral_link($code, 'stove'), 'Apply for a stove')
            . email_pill(referral_link($code, 'tuktuk'), 'Apply for a TukTuk kit', false)
            . '</p>'
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
        'Receipt ' . $payment['receipt_no'] . ' - ' . $amount . ' received'
            . ($settled ? ' (both payments verified)' : ', ' . money((float) $totals['balance']) . ' to go'),
        email_wrap($settled ? 'Payment complete - your receipt' : 'Payment received - your receipt', $inner),
        'receipt',
        null,
        $copies,
        $files
    );
}

/**
 * Sent when somebody whose application is still with the office asks for a
 * sign-in code.
 *
 * The portal has nothing to open for them yet, and the form cannot say so on
 * the page without answering "is this address one of your customers?" for
 * anybody who types addresses at it. So it is said here, where only the person
 * who reads the mailbox sees it.
 */
function send_application_waiting_email(string $to): bool
{
    $inner = '<p style="margin:0 0 14px;">Hello,</p>'
        . '<p style="margin:0 0 20px;">You asked for a sign-in code, and your application is still with '
        . 'our team — so there is nothing in the portal to show you yet and no code has been sent.</p>'
        . '<p style="margin:0 0 20px;">We email you the payment details as soon as it is approved, and '
        . 'your portal opens at the same moment. Nothing is owed until then.</p>'
        . '<p style="margin:0;font-size:14px;color:#5c7389;">If you were not expecting this, somebody '
        . 'typed your address into our sign-in form. Nothing has been opened and nothing needs doing. '
        . 'Any question at all: +91 97251 54186.</p>';

    return send_mail(
        $to,
        'Your Manifold application is still with our team',
        email_wrap('Nothing to sign in to yet', $inner),
        'otp'
    );
}

/**
 * Told to the referrer when the office has transferred their reward.
 * $referral is the application that quoted their code.
 */
function send_referral_paid_email(array $referrer, array $referral): bool
{
    $reward = (float) $referral['referral_reward'];

    /* "Your referral reward of ₹0.00 is on its way" is worse than silence.
       Nothing was transferred, so there is nothing to announce. */
    if ($reward <= 0) {
        return false;
    }

    $amount = money($reward);
    $code   = (string) $referrer['referral_code'];

    $inner = '<p style="margin:0 0 14px;">Hello ' . e($referrer['full_name']) . ',</p>'
        . '<p style="margin:0 0 20px;">Somebody applied with your referral code '
        . '<strong style="color:#0f2c4d;">' . e($code) . '</strong> and has paid their fee, so we have sent you '
        . '<strong style="color:#0f2c4d;">' . e($amount) . '</strong>'
        . (!empty($referral['referral_reward_note'])
            ? ' - ' . e((string) $referral['referral_reward_note'])
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
        'We could not verify your payment - ' . $app['reference_code'],
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
    $product = product_label((string) $app['product']);
    $link    = base_url() . '/admin/list.php?type=' . rawurlencode((string) $app['product'])
        . '&status=' . rawurlencode((string) $app['status']) . '#row-' . (int) $app['id'];

    $rows = [
        'Booking number' => (string) $app['reference_code'],
        'Product'        => $product,
        'Applicant'      => (string) $app['full_name'],
        'Email'          => (string) $app['email'],
        'Phone'          => (string) ($app['mobile_number'] ?? ''),
        /* the admin's own labels carry a long dash; a letter does not */
        'Payment'        => str_replace('—', '-', status_label((string) $app['status'])),
        'Payment ref'    => (string) ($app['payment_reference'] ?? '-'),
    ];

    $table = email_rows($rows);

    $inner = '<p style="margin:0 0 16px;">An applicant has uploaded proof of payment. '
        . 'The application is now sitting in <strong style="color:#0f2c4d;">Payment received - verify</strong>.</p>'
        . $table
        . email_button($link, 'Open it in the admin')
        . '<p style="margin:0;font-size:14px;color:#8499ac;">Open the row and use the ✅ action to verify the payment and complete the application.</p>';

    return send_to_office(
        'Payment received - ' . $app['reference_code'] . ' (' . $product . ')',
        'Payment received',
        $inner,
        'payment_received'
    );
}

/** One-time sign-in code for the applicant portal. */
function send_otp_email(string $to, string $code): bool
{
    $inner = '<p style="margin:0 0 14px;">Use this code to sign in and track your application:</p>'
        . '<p style="margin:0 0 20px;font-size:34px;font-weight:700;letter-spacing:.22em;color:#0f2c4d;">'
        . e($code) . '</p>'
        . '<p style="margin:0;">It expires in 10 minutes and can be used once. '
        . 'If you did not ask for it, ignore this email.</p>';

    return send_mail($to, 'Your Manifold sign-in code', email_wrap('Your sign-in code', $inner), 'otp');
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
        . 'and a person - not an autoresponder - will read it. We reply within two working days.</p>'
        . '<p style="margin:0 0 10px;font-weight:700;color:#0f2c4d;">What you sent us</p>'
        . $table
        . '<p style="margin:16px 0 6px;font-weight:700;color:#0f2c4d;">Your message</p>'
        . '<p style="margin:0 0 20px;padding:14px 18px;border-radius:12px;background:#f6f9fc;'
        . 'border:1px solid #e3ebf2;font-size:15px;color:#0f2c4d;">'
        . nl2br(e($enquiry['message'])) . '</p>'
        . '<p style="margin:0 0 4px;">In the meantime you can read how the technology works and what we are building.</p>'
        . email_button(base_url() . '/technology', 'See the technology')
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
        . email_button($site . '/technology', 'See how it works')
        . '<p style="margin:0 0 4px;font-size:14px;color:#8499ac;">Already thinking about a unit? '
        . '<a href="' . e($site) . '/apply-stove" style="color:#0e8f96;">Apply for the stove</a> or '
        . '<a href="' . e($site) . '/apply-tuktuk" style="color:#0e8f96;">for the conversion kit</a>.</p>'
        . '<p style="margin:0;font-size:14px;color:#5c7389;">Did not sign up, or changed your mind? '
        . '<a href="' . e(unsubscribe_url($to)) . '" style="color:#0e8f96;">Unsubscribe</a> — one click, '
        . 'nothing to fill in.</p>';

    return send_mail(
        $to,
        'You are on the Manifold Clean Energy list',
        email_wrap('Thank you for subscribing', $inner),
        'newsletter_welcome'
    );
}
