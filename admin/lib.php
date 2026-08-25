<?php
/**
 * Manifold Clean Energy — shared admin helpers.
 * Sessions, CSRF, authentication guard, status vocabulary, output escaping.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/* --------------------------------------------------------------------------
 * Defaults for a config.php that predates the code around it.
 *
 * config.php is the one file kept per server, so a deploy that copies
 * everything else leaves the site running new code against an old config. That
 * has taken the forms down once already — an undefined constant is a fatal, and
 * a fatal is a 500 with no explanation. Anything the code needs but a config
 * may not carry gets a sane value here instead of bringing the page down.
 * config.php still wins wherever it defines them.
 * ----------------------------------------------------------------------- */

if (!defined('SITE_PUBLIC_URL')) {
    define('SITE_PUBLIC_URL', 'https://manifoldcleanenergy.co.in');
}

if (!defined('EMAIL_LOGO_URL')) {
    define('EMAIL_LOGO_URL', SITE_PUBLIC_URL . '/assets/images/favicon.png');
}

if (!defined('ERROR_LOG_DIR')) {
    define('ERROR_LOG_DIR', __DIR__ . '/logs');
}

if (!defined('ERROR_LOG_FILE')) {
    define('ERROR_LOG_FILE', ERROR_LOG_DIR . '/php-error.log');
}

if (!defined('PAYMENT_PLAN')) {
    define('PAYMENT_PLAN', [
        'stove'  => ['booking' => 3500.0, 'delivery' => 16500.0],
        'tuktuk' => ['booking' => 6000.0, 'delivery' => 24000.0],
    ]);
}

if (!defined('DEALER_COMMISSION_DEFAULT')) {
    define('DEALER_COMMISSION_DEFAULT', 500);
}

if (!function_exists('payment_plan')) {
    /** The two amounts for one product, booking first. */
    function payment_plan(string $product): array
    {
        return PAYMENT_PLAN[$product] ?? PAYMENT_PLAN['stove'];
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/** Workflow states for contact enquiries and newsletter signups. */
const STATUSES = ['new', 'accepted', 'contacted', 'rejected'];

/** Workflow states for product applications, in the order they happen. */
const APPLICATION_STATUSES = [
    'booking_pending', 'booking_review', 'delivery_pending', 'delivery_review', 'complete', 'rejected',
];

/** The stages an applicant sees in the portal timeline (rejected sits outside). */
const APPLICATION_STAGES = [
    'booking_pending', 'booking_review', 'delivery_pending', 'delivery_review', 'complete',
];

/** The two transfers every application is made of, in the order they fall due. */
const PAYMENT_STAGES = ['booking', 'delivery'];

/** Which status list applies to a submission type. */
function statuses_for(string $type): array
{
    return type_config($type)['table'] === 'applications' ? APPLICATION_STATUSES : STATUSES;
}

/**
 * Human label for a status. The same state reads differently to the two
 * audiences: staff need to know there is a receipt waiting, the applicant
 * needs to know their payment landed.
 */
function status_label(string $status, string $audience = 'admin'): string
{
    $labels = [
        'booking_pending'  => $audience === 'applicant' ? 'Booking payment due' : 'Booking payment pending',
        'booking_review'   => $audience === 'applicant'
            ? 'Booking payment submitted — verifying'
            : 'Booking receipt — verify',
        'delivery_pending' => $audience === 'applicant' ? 'Delivery payment due' : 'Delivery payment pending',
        'delivery_review'  => $audience === 'applicant'
            ? 'Delivery payment submitted — verifying'
            : 'Delivery receipt — verify',
        'complete'         => $audience === 'applicant' ? 'Complete' : 'Both payments verified',
        'new'              => 'New',
        'accepted'         => 'Accepted',
        'contacted'        => 'Contacted',
        'rejected'         => 'Rejected',
    ];

    return $labels[$status] ?? ucfirst($status);
}

/** Short label used in tables, pills and tiles. */
function status_short(string $status): string
{
    $short = [
        'booking_pending'  => 'booking due',
        'booking_review'   => 'booking receipt',
        'delivery_pending' => 'delivery due',
        'delivery_review'  => 'delivery receipt',
        'complete'         => 'complete',
    ];

    return $short[$status] ?? $status;
}

/** What the applicant is told at each stage. */
function stage_copy(string $status): array
{
    $copy = [
        'booking_pending'  => ['Booking payment due',
                               'Pay the booking amount with the QR code below and upload the receipt. '
                               . 'It reserves your unit and comes off the price.'],
        'booking_review'   => ['Booking payment submitted',
                               'We are checking your booking receipt. Nothing more is needed for now.'],
        'delivery_pending' => ['Delivery payment due',
                               'Your booking is confirmed. Pay the delivery amount and upload that receipt '
                               . 'to complete the purchase.'],
        'delivery_review'  => ['Delivery payment submitted',
                               'We are checking your delivery receipt. Nothing more is needed for now.'],
        'complete'         => ['Complete',
                               'Both payments are verified. Your receipts are on their way and we will call you '
                               . 'to arrange the handover.'],
        'rejected'         => ['Not proceeding',
                               'This application is not moving forward. Contact us if you think that is a mistake.'],
    ];

    return $copy[$status] ?? [status_label($status, 'applicant'), ''];
}

/* ---------- payments ---------- */

/** Reading name for one of the two transfers. */
function payment_stage_label(string $stage): string
{
    return $stage === 'delivery' ? 'Delivery payment' : 'Booking payment';
}

/**
 * What one stage is worth on one application. The figures are frozen onto the
 * row at submit time; the price list is only the fallback for older rows.
 */
function stage_amount(array $app, string $stage): float
{
    $plan = payment_plan((string) ($app['product'] ?? 'stove'));

    if ($stage === 'delivery') {
        $amount = (float) ($app['delivery_amount'] ?? 0);

        return $amount > 0 ? $amount : (float) $plan['delivery'];
    }

    $amount = (float) ($app['booking_amount'] ?? 0);

    if ($amount <= 0) {
        $amount = (float) ($app['payment_amount'] ?? 0);
    }

    return $amount > 0 ? $amount : (float) $plan['booking'];
}

/** Every transfer on one application, oldest first. */
function payments_for(int $applicationId): array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE application_id = ? ORDER BY uploaded_at, id');
    $stmt->execute([$applicationId]);

    return $stmt->fetchAll();
}

/**
 * One stage of one application: what it costs, what has been verified against
 * it, and where it stands.
 *
 *   locked    the booking payment has not been verified yet
 *   due       nothing uploaded, or the last upload was rejected
 *   checking  a receipt is waiting for a decision
 *   paid      verified in full
 */
function payment_stage(array $app, string $stage, ?array $payments = null, ?bool $bookingPaid = null): array
{
    $payments = $payments ?? payments_for((int) $app['id']);
    $rows     = array_values(array_filter(
        $payments,
        static fn (array $p): bool => ($p['stage'] ?? 'booking') === $stage
    ));

    $amount  = stage_amount($app, $stage);
    $paid    = 0.0;
    $waiting = 0.0;

    foreach ($rows as $row) {
        if ($row['status'] === 'verified') {
            $paid += (float) $row['amount'];
        } elseif ($row['status'] === 'pending') {
            $waiting += (float) $row['amount'];
        }
    }

    $settled = $paid + 0.001 >= $amount;

    if ($settled) {
        $state = 'paid';
    } elseif ($waiting > 0) {
        $state = 'checking';
    } elseif ($stage === 'delivery' && !($bookingPaid ?? false)) {
        $state = 'locked';
    } else {
        $state = 'due';
    }

    /* the reason on the most recent rejection, so the applicant is told why */
    $refused = array_values(array_filter($rows, static fn (array $p): bool => $p['status'] === 'rejected'));
    $last    = $refused ? $refused[count($refused) - 1] : null;

    return [
        'stage'         => $stage,
        'label'         => payment_stage_label($stage),
        'amount'        => $amount,
        'paid'          => $paid,
        'waiting'       => $waiting,
        'balance'       => max($amount - $paid, 0),
        'settled'       => $settled,
        'state'         => $state,
        'payments'      => $rows,
        'reject_reason' => $last['reject_reason'] ?? null,
    ];
}

/**
 * Both stages plus the totals across them. `current` is the stage the
 * applicant is being asked for, or null when there is nothing left to pay.
 */
function payment_totals(array $app, ?array $payments = null): array
{
    $payments = $payments ?? payments_for((int) $app['id']);

    $booking  = payment_stage($app, 'booking', $payments);
    $delivery = payment_stage($app, 'delivery', $payments, $booking['settled']);

    $due     = $booking['amount'] + $delivery['amount'];
    $paid    = $booking['paid'] + $delivery['paid'];
    $waiting = $booking['waiting'] + $delivery['waiting'];

    $current = null;

    if (!$booking['settled']) {
        $current = 'booking';
    } elseif (!$delivery['settled']) {
        $current = 'delivery';
    }

    return [
        'due'     => $due,
        'paid'    => $paid,
        'waiting' => $waiting,
        'balance' => max($due - $paid, 0),
        'settled' => $booking['settled'] && $delivery['settled'],
        'percent' => $due > 0 ? min(100, (int) round($paid / $due * 100)) : 100,
        'stages'  => ['booking' => $booking, 'delivery' => $delivery],
        'current' => $current,
    ];
}

/**
 * The status an application should be on, worked out from its payments.
 * A rejected application stays rejected — that is an admin decision.
 */
function status_from_payments(array $app, ?array $payments = null): string
{
    if ($app['status'] === 'rejected') {
        return 'rejected';
    }

    $payments = $payments ?? payments_for((int) $app['id']);
    $totals   = payment_totals($app, $payments);
    $booking  = $totals['stages']['booking'];
    $delivery = $totals['stages']['delivery'];

    if (!$booking['settled']) {
        return $booking['state'] === 'checking' ? 'booking_review' : 'booking_pending';
    }

    if (!$delivery['settled']) {
        return $delivery['state'] === 'checking' ? 'delivery_review' : 'delivery_pending';
    }

    return 'complete';
}

/**
 * Writes the status and the payment timestamps back onto the application after
 * a receipt is uploaded or decided. Returns the status it settled on.
 */
function sync_application_status(int $applicationId): string
{
    $stmt = db()->prepare('SELECT * FROM applications WHERE id = ?');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch();

    if (!$app) {
        return '';
    }

    $payments = payments_for($applicationId);
    $next     = status_from_payments($app, $payments);
    $totals   = payment_totals($app, $payments);
    $now      = date('Y-m-d H:i:s');

    if ($next !== $app['status']) {
        db()->prepare('UPDATE applications SET status = ? WHERE id = ?')->execute([$next, $applicationId]);
        log_status_change('application', $applicationId, (string) $app['status'], $next, null);
    }

    /* a stage that is no longer verified loses its timestamp, so a rejected
       receipt puts the application back where it was */
    db()->prepare(
        'UPDATE applications
            SET booking_paid_at = ?, delivery_paid_at = ?, payment_verified_at = ?, completed_at = ?
          WHERE id = ?'
    )->execute([
        $totals['stages']['booking']['settled'] ? ($app['booking_paid_at'] ?? $now) : null,
        $totals['stages']['delivery']['settled'] ? ($app['delivery_paid_at'] ?? $now) : null,
        $totals['paid'] > 0 ? ($app['payment_verified_at'] ?? $now) : null,
        $next === 'complete' ? ($app['completed_at'] ?? $now) : null,
        $applicationId,
    ]);

    return $next;
}

/**
 * Has the booking payment been verified? That is the moment the referral
 * reward is earned and the application enters the raffle — the delivery
 * payment comes months later and nothing waits for it.
 */
function booking_paid(array $app): bool
{
    return !empty($app['booking_paid_at']);
}

/* ---------- settings ---------- */

/**
 * Every row of `settings`, read once per request. Pass true after a write so
 * the next read sees it.
 */
function settings_all(bool $reload = false): array
{
    static $values = null;

    if ($values === null || $reload) {
        $values = [];

        foreach (db()->query('SELECT name, value FROM settings')->fetchAll() as $row) {
            $values[(string) $row['name']] = (string) $row['value'];
        }
    }

    return $values;
}

/** One admin-editable value, or $default when the row is missing. */
function setting(string $name, string $default = ''): string
{
    $values = settings_all();

    return $values[$name] ?? $default;
}

function save_setting(string $name, string $value): void
{
    db()->prepare(
        'INSERT INTO settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    )->execute([$name, $value]);

    settings_all(true);
}

/* ---------- referrals ----------
   Every application carries a code of its own for life. Quoting somebody
   else's code changes nothing about what this applicant pays — it books a
   reward for the person who referred them, which the office pays by hand. */

/** States a reward goes through. `none` means the application was not referred. */
const REWARD_STATUSES = ['none', 'pending', 'sent', 'cancelled'];

/** What the referrer earns when their code is used. */
function referral_reward(): float
{
    return max(0.0, (float) setting('referral_reward', (string) REFERRAL_REWARD_DEFAULT));
}

function reward_label(string $status): string
{
    $labels = [
        'none'      => 'Not referred',
        'pending'   => 'Payout pending',
        'sent'      => 'Payout sent',
        'cancelled' => 'Payout cancelled',
    ];

    return $labels[$status] ?? ucfirst($status);
}

/** Nothing that can be misread down a phone line: no O/0, no I/1. */
function make_referral_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $check    = db()->prepare('SELECT 1 FROM applications WHERE referral_code = ?');

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $code = 'MF';

        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $check->execute([$code]);

        if (!$check->fetchColumn()) {
            return $code;
        }
    }

    throw new RuntimeException('Could not allocate a referral code.');
}

/** Whatever they typed, in the shape the codes are stored in. */
function normalise_referral_code(?string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
}

/**
 * The application a code belongs to, or null. The code is handed out once the
 * booking payment has been verified, so an application that has not got that
 * far is not a valid referrer.
 */
function referrer_for_code(string $code): ?array
{
    if ($code === '') {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT * FROM applications
          WHERE referral_code = ? AND booking_paid_at IS NOT NULL AND status <> ?
          LIMIT 1'
    );
    $stmt->execute([$code, 'rejected']);

    return $stmt->fetch() ?: null;
}

/** An apply-form URL with the code already in the box. */
function referral_link(string $code, string $product = 'stove'): string
{
    $page = $product === 'tuktuk' ? 'apply-tuktuk.html' : 'apply-stove.html';

    return base_url() . '/' . $page . '?ref=' . rawurlencode($code);
}

/** Everyone who has applied with one application's code, newest first. */
function referrals_for(int $applicationId): array
{
    $stmt = db()->prepare(
        'SELECT id, reference_code, full_name, product, status, created_at, booking_paid_at,
                referral_reward, referral_reward_status, referral_reward_sent_at
           FROM applications
          WHERE referred_by_id = ?
          ORDER BY created_at DESC'
    );
    $stmt->execute([$applicationId]);

    return $stmt->fetchAll();
}

/** Headline numbers for one application's code. */
function referral_stats(int $applicationId): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS total,
                SUM(booking_paid_at IS NOT NULL) AS completed,
                COALESCE(SUM(CASE WHEN referral_reward_status = 'sent'
                                  THEN referral_reward ELSE 0 END), 0) AS paid,
                COALESCE(SUM(CASE WHEN referral_reward_status = 'pending'
                                  THEN referral_reward ELSE 0 END), 0) AS pending
           FROM applications
          WHERE referred_by_id = ?"
    );
    $stmt->execute([$applicationId]);
    $row = $stmt->fetch() ?: [];

    return [
        'total'     => (int) ($row['total'] ?? 0),
        'completed' => (int) ($row['completed'] ?? 0),
        'paid'      => (float) ($row['paid'] ?? 0),
        'pending'   => (float) ($row['pending'] ?? 0),
    ];
}

/**
 * A reward is only worth paying once the person who used the code has had
 * their own booking payment verified. Until then it sits pending and the
 * office waits — the delivery payment months later changes nothing.
 */
function reward_is_payable(array $referral): bool
{
    return $referral['referral_reward_status'] === 'pending'
        && !empty($referral['booking_paid_at'])
        && $referral['status'] !== 'rejected';
}

/* ---------- dealers ----------
   A dealer sells on our behalf. They are not an applicant, so they live in
   their own table, but their code travels in the same ?ref= link the customer
   referral programme uses. The prefix is what keeps the two apart:

     MF……  an existing customer's referral code
     MD……  a dealer's code

   Nothing a dealer earns is tied to a particular application. Commission is
   summed across their sales, payouts are summed against it, and what is left
   is what the office still owes — so paying for 5 of 10 units delivered leaves
   the other 5 standing without anyone ticking rows off. */

/** What a dealer earns per unit sold. Editable under Settings. */
function dealer_commission(): float
{
    return max(0.0, (float) setting('dealer_commission', (string) DEALER_COMMISSION_DEFAULT));
}

/** Same alphabet as a referral code, so neither can be misread down a phone line. */
function make_dealer_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $check    = db()->prepare('SELECT 1 FROM dealers WHERE dealer_code = ?');

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $code = 'MD';

        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $check->execute([$code]);

        if (!$check->fetchColumn()) {
            return $code;
        }
    }

    throw new RuntimeException('Could not allocate a dealer code.');
}

/** The dealer a quoted code belongs to, or null. A switched-off dealer is not one. */
function dealer_for_code(string $code): ?array
{
    if ($code === '') {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM dealers WHERE dealer_code = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$code]);

    return $stmt->fetch() ?: null;
}

/** One dealer by id, or null. */
function dealer_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM dealers WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

/** Everyone who applied with one dealer's code, newest first. */
function dealer_clients(int $dealerId): array
{
    $stmt = db()->prepare(
        'SELECT id, reference_code, full_name, email, mobile_number, product, status,
                created_at, booking_paid_at, delivery_paid_at, completed_at, dealer_commission
           FROM applications
          WHERE dealer_id = ?
          ORDER BY created_at DESC'
    );
    $stmt->execute([$dealerId]);

    return $stmt->fetchAll();
}

/** Every transfer made to one dealer, newest first. */
function dealer_payouts(int $dealerId): array
{
    $stmt = db()->prepare(
        'SELECT p.*, u.name AS paid_by_name
           FROM dealer_payouts p
           LEFT JOIN admin_users u ON u.id = p.paid_by
          WHERE p.dealer_id = ?
          ORDER BY p.paid_at DESC, p.id DESC'
    );
    $stmt->execute([$dealerId]);

    return $stmt->fetchAll();
}

/**
 * What one dealer has sold, earned, been paid and is still owed.
 *
 * Commission is only counted once the customer's booking payment has been
 * verified — the same bar a referral reward has to clear. A sale that was
 * rejected, or has not paid yet, is in `sales` but not in `earned`.
 */
function dealer_totals(int $dealerId): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS sales,
                COALESCE(SUM(booking_paid_at IS NOT NULL AND status <> 'rejected'), 0) AS confirmed,
                COALESCE(SUM(CASE WHEN booking_paid_at IS NOT NULL AND status <> 'rejected'
                                  THEN dealer_commission ELSE 0 END), 0) AS earned
           FROM applications
          WHERE dealer_id = ?"
    );
    $stmt->execute([$dealerId]);
    $row = $stmt->fetch() ?: [];

    $paidStmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM dealer_payouts WHERE dealer_id = ?');
    $paidStmt->execute([$dealerId]);

    $earned = (float) ($row['earned'] ?? 0);
    $paid   = (float) $paidStmt->fetchColumn();

    return [
        'sales'     => (int) ($row['sales'] ?? 0),
        'confirmed' => (int) ($row['confirmed'] ?? 0),
        'earned'    => $earned,
        'paid'      => $paid,
        /* an overpayment reads as nothing owed rather than a negative figure */
        'remaining' => max(0.0, $earned - $paid),
    ];
}

/** The reading view of one dealer, in the same shape field_groups() returns. */
function dealer_field_groups(): array
{
    return [
        'Dealer' => [
            'Who they are' => ['full_name' => 'Full name', 'company' => 'Company',
                               'email' => 'Email', 'mobile_number' => 'Mobile',
                               'alt_mobile_number' => 'Alternative mobile'],
            'Address' => ['address' => 'Address', 'city' => 'City', 'state' => 'State',
                          'pin_code' => 'Pin code'],
            'Tax' => ['pan_number' => 'PAN', 'gst_number' => 'GST'],
            'Where the money goes' => ['bank_name' => 'Bank', 'bank_account' => 'Account number',
                                       'bank_ifsc' => 'IFSC', 'upi_id' => 'UPI ID'],
            'Tracking' => ['dealer_code' => 'Dealer code', 'note' => 'Note',
                           'created_at' => 'Added on'],
        ],
    ];
}

/* ---------- blog ----------
   Four states. `scheduled` is `published` with a date in the future: the post
   goes live on its own when that moment passes, so nobody has to come back and
   press a button. */

const BLOG_STATUSES = ['draft', 'scheduled', 'published', 'unpublished'];

function blog_status_label(string $status): string
{
    $labels = [
        'draft'       => 'Draft',
        'scheduled'   => 'Scheduled',
        'published'   => 'Published',
        'unpublished' => 'Unpublished',
    ];

    return $labels[$status] ?? ucfirst($status);
}

/** Is this row visible to the public right now? */
function blog_is_live(array $post): bool
{
    if ($post['status'] === 'published') {
        return true;
    }

    return $post['status'] === 'scheduled'
        && !empty($post['publish_at'])
        && strtotime((string) $post['publish_at']) <= time();
}

/**
 * What the office should read on the row: a scheduled post that has passed its
 * date is simply live, whatever the column still says.
 */
function blog_state(array $post): string
{
    if ($post['status'] === 'scheduled' && blog_is_live($post)) {
        return 'published';
    }

    return (string) $post['status'];
}

/** Posts the website may show, newest first. */
function blog_live_posts(int $limit = 12): array
{
    $stmt = db()->prepare(
        "SELECT * FROM blog_posts
          WHERE status = 'published'
             OR (status = 'scheduled' AND publish_at IS NOT NULL AND publish_at <= NOW())
          ORDER BY COALESCE(publish_at, created_at) DESC
          LIMIT " . max(1, $limit)
    );
    $stmt->execute();

    return $stmt->fetchAll();
}

/** A URL-safe, unique slug built from the title. */
function blog_slug(string $title, ?int $ignoreId = null): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string) $slug, '-');

    if ($slug === '') {
        $slug = 'post';
    }

    $slug = mb_substr($slug, 0, 140);
    $base = $slug;

    for ($n = 2; $n < 50; $n++) {
        $stmt = db()->prepare('SELECT id FROM blog_posts WHERE slug = ? AND id <> ?');
        $stmt->execute([$slug, (int) $ignoreId]);

        if (!$stmt->fetchColumn()) {
            return $slug;
        }

        $slug = $base . '-' . $n;
    }

    return $base . '-' . bin2hex(random_bytes(3));
}

/** Roughly how long the piece takes to read, in whole minutes. */
function blog_read_minutes(string $body): int
{
    return max(1, (int) round(str_word_count(strip_tags($body)) / 200));
}

/** MF-00000042-R2 — the receipt number for one verified transfer. */
function next_receipt_no(array $app): string
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM payments WHERE application_id = ? AND status = ?');
    $stmt->execute([(int) $app['id'], 'verified']);

    return $app['reference_code'] . '-R' . ((int) $stmt->fetchColumn() + 1);
}

/**
 * MF-00000042 style booking number, unique per application.
 *
 * Two letters, then the application's own id padded to eight digits. The year
 * used to sit in the middle; it was dropped so every booking number is the same
 * length and reads back over the phone without explanation.
 */
function make_reference_code(int $id): string
{
    return 'MF-' . str_pad((string) $id, 8, '0', STR_PAD_LEFT);
}

/** The four submission types shown in the sidebar. */
function submission_types(): array
{
    return [
        'stove' => [
            'label' => 'Stove applications',
            'icon'  => 'bi-fire',
            'table' => 'applications',
            'entity' => 'application',
        ],
        'tuktuk' => [
            'label' => 'TukTuk applications',
            'icon'  => 'bi-truck-front',
            'table' => 'applications',
            'entity' => 'application',
        ],
        'contact' => [
            'label' => 'Contact enquiries',
            'icon'  => 'bi-envelope',
            'table' => 'contact_messages',
            'entity' => 'contact',
        ],
        'newsletter' => [
            'label' => 'Newsletter signups',
            'icon'  => 'bi-send',
            'table' => 'newsletter_subscribers',
            'entity' => 'newsletter',
        ],
    ];
}

function type_config(string $type): array
{
    $types = submission_types();

    if (!isset($types[$type])) {
        http_response_code(404);
        exit('Unknown submission type.');
    }

    return $types[$type];
}

/**
 * Admin asset URL with a cache-busting stamp, so a changed stylesheet or
 * script is picked up without a hard refresh.
 */
function asset_url(string $relative): string
{
    $file = __DIR__ . '/' . ltrim($relative, '/');
    $stamp = is_file($file) ? filemtime($file) : time();

    return $relative . '?v=' . $stamp;
}

/** Escape for HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';

    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Session expired. Go back, reload the page and try again.');
    }
}

/* ---------- Authentication ---------- */

function current_user(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function require_login(): array
{
    $user = current_user();

    if (!$user) {
        header('Location: login.php');
        exit;
    }

    return $user;
}

function log_status_change(string $entity, int $id, ?string $old, string $new, ?int $userId): void
{
    $stmt = db()->prepare(
        'INSERT INTO status_log (entity, entity_id, old_status, new_status, changed_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$entity, $id, $old, $new, $userId]);
}

/**
 * The statuses that mean "waiting on us" for a given form, and how to say it.
 * Applications have no `new` — a receipt sitting unverified is the queue, and
 * either of the two payments can be the one waiting.
 */
function attention_status(string $type): array
{
    if (type_config($type)['table'] === 'applications') {
        return [['booking_review', 'delivery_review'], 'waiting on payment verification'];
    }

    return [['new'], 'waiting to be reviewed'];
}

/** Counts per status for one submission type, plus the total. */
function status_counts(string $type): array
{
    $config   = type_config($type);
    $statuses = statuses_for($type);
    $counts   = array_fill_keys($statuses, 0);

    if ($config['table'] === 'applications') {
        $stmt = db()->prepare(
            'SELECT status, COUNT(*) AS n FROM applications WHERE product = ? GROUP BY status'
        );
        $stmt->execute([$type]);
    } else {
        $stmt = db()->query('SELECT status, COUNT(*) AS n FROM ' . $config['table'] . ' GROUP BY status');
    }

    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }

    $counts['total'] = array_sum(array_intersect_key($counts, array_flip($statuses)));

    return $counts;
}

/** Human label for a record, used in list rows and page titles. */
function record_title(string $type, array $row): string
{
    if ($type === 'newsletter') {
        return $row['email'];
    }

    return $row['full_name'] ?? $row['name'] ?? ('#' . $row['id']);
}

/**
 * Fields shown in the Details drawer, as tab => section => [column => label].
 *
 * Related things live under one tab — the site address, what the property is,
 * its water supply and the technical assessment are all "where it goes", so
 * they sit together rather than being five separate tabs.
 */
function field_groups(string $type): array
{
    $config = type_config($type);

    if ($config['table'] === 'applications') {
        return [
            'Applicant' => [
                'Contact details' => ['full_name' => 'Full name', 'date_of_birth' => 'Date of birth',
                                      'nationality' => 'Nationality', 'gender' => 'Gender',
                                      'occupation' => 'Occupation', 'mobile_number' => 'Mobile',
                                      'alt_mobile_number' => 'Alternative mobile', 'email' => 'Email'],
                'Identity &amp; address proofs' => ['id_number' => 'ID / passport number',
                                                   'id_document_path' => 'ID document (proof of identity)',
                                                   'residence_proof_path' => 'Residence proof (proof of address)'],
            ],

            'Site &amp; address' => [
                'Address' => ['house_number' => 'House / unit', 'street' => 'Street', 'city' => 'City',
                              'state' => 'State', 'country' => 'Country', 'pin_code' => 'Pin code'],
                'Property' => ['property_type' => 'Property type', 'property_type_other' => 'Property type (other)',
                               'ownership_status' => 'Ownership', 'household_members' => 'Household members',
                               'existing_fuel' => 'Existing fuel', 'existing_fuel_other' => 'Existing fuel (other)'],
                'Water supply' => ['water_source' => 'Water source', 'water_source_other' => 'Water source (other)',
                                   'continuous_water' => 'Continuous supply', 'water_storage' => 'Storage tank'],
                'Technical assessment' => ['dedicated_kitchen' => 'Dedicated space',
                                           'countertop_space' => 'Level / counter space',
                                           'existing_gas' => 'Existing fuel connection',
                                           'existing_electric' => 'Electrical supply'],
            ],

            'Requirement' => [
                'What they need' => ['units_required' => 'Units required', 'intended_usage' => 'Intended usage',
                                     'expected_daily_usage' => 'Expected daily usage',
                                     'preferred_install_date' => 'Preferred install date'],
                'Current consumption' => ['monthly_gas_consumption' => 'Monthly fuel',
                                          'monthly_electric_consumption' => 'Monthly electricity',
                                          'carbon_interest' => 'Carbon interest'],
            ],

            'Preferences' => [
                /* what they told us on the form — the real payments are their own tab */
                'Payment preference' => ['payment_method' => 'Preferred method',
                                         'financing_option' => 'Financing option', 'bank_name' => 'Bank'],
                'Referral' => ['referral_source' => 'Heard about us via', 'referral_other' => 'Referral (other)'],
                'Dealer' => ['dealer_commission' => 'Dealer commission'],
                'Referral programme' => ['referral_code' => 'Their own code',
                                         'referred_by_code' => 'Code they quoted',
                                         'referral_reward' => 'Reward owed to referrer',
                                         'referral_reward_status' => 'Reward status',
                                         'referral_reward_sent_at' => 'Reward sent on',
                                         'referral_reward_note' => 'Reward note'],
                'Consent' => ['declaration_accepted' => 'Declaration accepted',
                              'testimonial_consent' => 'Testimonial consent', 'terms_accepted' => 'Terms accepted'],
                'Tracking' => ['reference_code' => 'Booking number', 'booking_amount' => 'Booking amount',
                               'delivery_amount' => 'Delivery amount',
                               'completed_at' => 'Completed on'],
            ],
        ];
    }

    if ($type === 'contact') {
        return [
            'Enquiry' => [
                'Who wrote in' => ['name' => 'Name', 'company' => 'Company', 'email' => 'Email',
                                   'phone' => 'Phone', 'city' => 'City'],
                'What they asked' => ['interest' => 'Interest', 'message' => 'Message',
                                      'consent' => 'Contact consent'],
            ],
        ];
    }

    return [
        'Subscriber' => [
            'Signup' => ['email' => 'Email'],
        ],
    ];
}

/** One field value, rendered for reading. */
function render_value(string $key, $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    if (in_array($key, ['declaration_accepted', 'testimonial_consent', 'terms_accepted', 'consent'], true)) {
        return ((int) $value) === 1 ? 'Yes' : 'No';
    }

    if (in_array($key, ['id_document_path', 'residence_proof_path'], true)) {
        return '<a class="link-arrow" href="file.php?path=' . e(rawurlencode((string) $value))
            . '" target="_blank" rel="noopener">Open file <i class="bi bi-box-arrow-up-right"></i></a>';
    }

    if ($key === 'payment_proof_path') {
        return '<a class="link-arrow" href="file.php?path=' . e(rawurlencode((string) $value))
            . '&amp;dir=payments" target="_blank" rel="noopener">Open receipt <i class="bi bi-box-arrow-up-right"></i></a>';
    }

    if ($key === 'referral_reward' || $key === 'dealer_commission') {
        return ((float) $value) > 0 ? e(money((float) $value)) : '—';
    }

    if ($key === 'referral_reward_status') {
        return $value === 'none' ? '—' : e(reward_label((string) $value));
    }

    if (in_array($key, ['payment_uploaded_at', 'payment_verified_at', 'confirmed_at', 'completed_at',
                        'referral_reward_sent_at', 'created_at'], true)) {
        return e(format_datetime((string) $value));
    }

    return nl2br(e((string) $value));
}

function format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }

    return date('j M Y, H:i', strtotime($value));
}
