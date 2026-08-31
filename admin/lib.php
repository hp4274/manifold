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

if (!defined('DEALER_RATE_DEFAULT')) {
    define('DEALER_RATE_DEFAULT', 15);
}

if (!defined('DISTRIBUTOR_OVERRIDE_RATE_DEFAULT')) {
    define('DISTRIBUTOR_OVERRIDE_RATE_DEFAULT', 5);
}

if (!defined('DISTRIBUTOR_DIRECT_RATE_DEFAULT')) {
    define('DISTRIBUTOR_DIRECT_RATE_DEFAULT', 15);
}

if (!defined('DEALER_LIMIT_DEFAULT')) {
    define('DEALER_LIMIT_DEFAULT', 10);
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

/**
 * Workflow states for product applications, in the order they happen.
 *
 * An application from the website starts at 'submitted' and waits there: the
 * office looks at it first, and approving it is what issues the payment email
 * and opens the portal. Nothing is asked of the applicant until then, which is
 * the point — we do not take a payment for a place we have not agreed to.
 */
const APPLICATION_STATUSES = [
    'submitted', 'booking_pending', 'booking_review', 'delivery_pending', 'delivery_review',
    'complete', 'rejected',
];

/** The stages an applicant sees in the portal timeline (rejected sits outside). */
const APPLICATION_STAGES = [
    'submitted', 'booking_pending', 'booking_review', 'delivery_pending', 'delivery_review', 'complete',
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
        'submitted'        => $audience === 'applicant' ? 'Application received' : 'Waiting for approval',
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
        'submitted'        => 'to approve',
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
        'submitted'        => ['Application received',
                               'We have your application and are looking at it. Nothing to pay yet — we '
                               . 'email you the payment details as soon as it is approved.'],
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

    /* An application nobody has approved yet has no payment stage to be at.
       Without this, anything that recalculates a status would quietly approve
       it — the decision is the office's, not a side effect. */
    if ($app['status'] === 'submitted') {
        return 'submitted';
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

/* ---------- what a sale pays whom ----------
   Commission is a share of what the sale is worth — the booking amount plus the
   delivery amount already frozen on the application — and not a flat figure.

     a dealer sells        dealer 15%, their distributor 5%
     a distributor sells   distributor 15%, no dealer involved

   The override follows the dealer's own distributor rather than to
   the office. Both shares are worked out once, when the application arrives,
   and written onto the row: changing a rate here must never rewrite what a sale
   that has already happened was worth.

   Nothing is earned until the whole application is complete — both payments
   verified. A booking payment on its own earns nobody anything, because the
   commission on a stove is larger than the booking payment that would fund it. */

/** One rate, as a fraction. Stored in `settings` as a whole percentage. */
function commission_rate(string $name, float $default): float
{
    $percent = (float) setting($name, (string) $default);

    /* a rate above 100% is a typo, not a policy */
    return min(1.0, max(0.0, $percent / 100));
}

/** What a dealer keeps of a sale they made. */
function dealer_rate(): float
{
    return commission_rate('dealer_rate', DEALER_RATE_DEFAULT);
}

/** What a distributor takes of a sale one of their dealers made. */
function distributor_override_rate(): float
{
    return commission_rate('distributor_override_rate', DISTRIBUTOR_OVERRIDE_RATE_DEFAULT);
}

/** What a distributor keeps of a sale they made themselves. */
function distributor_direct_rate(): float
{
    return commission_rate('distributor_direct_rate', DISTRIBUTOR_DIRECT_RATE_DEFAULT);
}

/** What one application is worth, whoever ends up being paid out of it. */
function sale_value(array $app): float
{
    return (float) ($app['booking_amount'] ?? 0) + (float) ($app['delivery_amount'] ?? 0);
}

/**
 * The two shares a sale carries, given the code that was quoted.
 *
 * $dealer or $distributor, never both from the form: a dealer's code implies
 * their distributor, and a distributor's own code cuts the dealer out entirely.
 * Returns the amounts to freeze onto the application.
 */
function commission_split(float $saleValue, ?array $dealer, ?array $distributor): array
{
    if ($dealer) {
        return [
            'dealer'      => round($saleValue * dealer_rate(), 2),
            /* the override follows the dealer's distributor, not the form */
            'distributor' => $distributor ? round($saleValue * distributor_override_rate(), 2) : 0.0,
        ];
    }

    if ($distributor) {
        return ['dealer' => 0.0, 'distributor' => round($saleValue * distributor_direct_rate(), 2)];
    }

    return ['dealer' => 0.0, 'distributor' => 0.0];
}

/**
 * Whether an application has earned anyone their commission yet.
 *
 * One definition, used by both totals functions and by every screen that says
 * "earned" — so the dealer portal, the distributor portal and the admin can
 * never disagree about what is owed.
 */
function commission_is_earned(array $app): bool
{
    return ($app['status'] ?? '') === 'complete';
}

/** The SQL half of commission_is_earned(), for the totals queries. */
const COMMISSION_EARNED_SQL = "status = 'complete'";

/**
 * A code nobody can misread down a phone line: no O/0, no I/1.
 *
 * One generator for both kinds, so the two can never drift into different
 * alphabets or lengths. The prefix is what tells them apart when one is quoted:
 * MF a customer, MD a dealer, MX a distributor.
 */
function make_partner_code(string $prefix, string $table, string $column): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $check    = db()->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $column . ' = ?');

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $code = $prefix;

        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $check->execute([$code]);

        if (!$check->fetchColumn()) {
            return $code;
        }
    }

    throw new RuntimeException('Could not allocate a ' . $prefix . ' code.');
}

function make_dealer_code(): string
{
    return make_partner_code('MD', 'dealers', 'dealer_code');
}

function make_distributor_code(): string
{
    return make_partner_code('MX', 'distributors', 'distributor_code');
}

/**
 * The dealer a quoted code belongs to, or null.
 *
 * Switched off is nobody, and so is waiting for approval: a distributor can
 * create a dealer, but until the office says that dealer is real their code
 * books nothing. Enforced here rather than at the form, because this is the one
 * place every route into a sale has to pass through.
 */
function dealer_for_code(string $code): ?array
{
    if ($code === '') {
        return null;
    }

    $stmt = db()->prepare(
        "SELECT * FROM dealers
          WHERE dealer_code = ? AND is_active = 1 AND approval_status = 'approved' LIMIT 1"
    );
    $stmt->execute([$code]);

    return $stmt->fetch() ?: null;
}

/* ---------- a distributor's own dealers ----------
   A distributor signs dealers up, the office decides whether they are real, and
   there is a ceiling on how many one distributor may hold. */

/** How many dealers one distributor may hold. Editable under Settings. */
function dealer_limit(): int
{
    return max(0, (int) setting('dealer_limit', (string) DEALER_LIMIT_DEFAULT));
}

/**
 * How many of that allowance a distributor has used.
 *
 * A dealer waiting for approval counts. They were requested, and letting a
 * distributor queue up fifty pending dealers against a limit of ten would make
 * the limit meaningless.
 */
function distributor_dealer_count(int $distributorId): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM dealers
          WHERE distributor_id = ? AND approval_status <> 'rejected'"
    );
    $stmt->execute([$distributorId]);

    return (int) $stmt->fetchColumn();
}

/** Whether this distributor has room for another dealer. */
function distributor_has_room(int $distributorId): bool
{
    return distributor_dealer_count($distributorId) < dealer_limit();
}

/** Reading name for where a dealer has got to with the office. */
function approval_label(string $status): string
{
    $labels = [
        'pending'  => 'Waiting for approval',
        'approved' => 'Approved',
        'rejected' => 'Turned down',
    ];

    return $labels[$status] ?? ucfirst($status);
}

/** The dealers one distributor has asked the office to approve. */
function dealers_awaiting_approval(?int $distributorId = null): array
{
    $sql = "SELECT d.*, x.full_name AS distributor_name, x.distributor_code
              FROM dealers d
              LEFT JOIN distributors x ON x.id = d.distributor_id
             WHERE d.approval_status = 'pending'"
        . ($distributorId === null ? '' : ' AND d.distributor_id = ?')
        . ' ORDER BY d.created_at';

    $stmt = db()->prepare($sql);
    $stmt->execute($distributorId === null ? [] : [$distributorId]);

    return $stmt->fetchAll();
}

/**
 * Saves one uploaded file into the application upload directory and returns the
 * name it was stored under, or null if there was nothing usable.
 *
 * The name is generated rather than taken from the browser, the type is read
 * from the file itself rather than trusted from the request, and anything over
 * the size limit or of the wrong type is dropped. Silent by design: an
 * unreadable ID copy must not cost somebody their whole application, and the
 * office can always ask for it again.
 */
function store_upload(string $field, string $dir = UPLOAD_DIR): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > UPLOAD_MAX_BYTES) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    if (!isset(UPLOAD_ALLOWED_MIME[$mime])) {
        return null;
    }

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }

    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . UPLOAD_ALLOWED_MIME[$mime];

    return move_uploaded_file($file['tmp_name'], $dir . '/' . $name) ? $name : null;
}

/* ---------- a sale a partner took themselves ----------
   A dealer or distributor can enter a customer they sold to directly, where the
   money went to them rather than to the company. Nothing is owed by that
   customer here, so the application is created settled — but it is marked
   `direct`, because a report that mixed it with a website sale would be
   claiming money the company never received. */

/**
 * Creates one application on a partner's behalf and returns its id.
 *
 * $dealer or $distributor, never both — a dealer's own distributor is looked up
 * the same way the public form does it, so the split is identical whichever
 * route the sale came in by.
 */
function create_direct_sale(array $fields, string $product, ?array $dealer, ?array $distributor): int
{
    $plan  = payment_plan($product);
    $sale  = (float) $plan['booking'] + (float) $plan['delivery'];
    $split = commission_split($sale, $dealer, $distributor);
    $now   = date('Y-m-d H:i:s');

    $columns = [
        'product'                => $product,
        /* the customer has already paid the partner in full, so there is no
           stage left for them to be at */
        'status'                 => 'complete',
        'reference_code'         => 'tmp-' . bin2hex(random_bytes(6)),
        'referral_code'          => make_referral_code(),
        'full_name'              => $fields['full_name'],
        'email'                  => $fields['email'],
        'mobile_number'          => $fields['mobile_number'],
        'city'                   => $fields['city'],
        'state'                  => $fields['state'],
        /* the applications table keeps a street, not a single address line */
        'street'                 => $fields['address'],
        'pin_code'               => $fields['pin_code'],
        'units_required'         => $fields['units_required'],
        'admin_note'             => $fields['note'],
        'id_number'              => $fields['id_number'] ?? null,
        'id_document_path'       => $fields['id_document_path'] ?? null,
        'residence_proof_path'   => $fields['residence_proof_path'] ?? null,
        'booking_amount'         => (float) $plan['booking'],
        'delivery_amount'        => (float) $plan['delivery'],
        'payment_amount'         => (float) $plan['booking'],
        'booking_paid_at'        => $now,
        'delivery_paid_at'       => $now,
        'payment_verified_at'    => $now,
        'completed_at'           => $now,
        'confirmed_at'           => $now,
        'dealer_id'              => $dealer ? (int) $dealer['id'] : null,
        'dealer_commission'      => $split['dealer'],
        'distributor_id'         => $distributor ? (int) $distributor['id'] : null,
        'distributor_commission' => $split['distributor'],
        'sale_channel'           => 'direct',
        'entered_by_dealer'      => $dealer ? (int) $dealer['id'] : null,
        'entered_by_distributor' => $distributor && !$dealer ? (int) $distributor['id'] : null,
        'declaration_accepted'   => $fields['declaration_accepted'] ?? 1,
        'terms_accepted'         => $fields['terms_accepted'] ?? 1,
        'testimonial_consent'    => $fields['testimonial_consent'] ?? 0,
    ];

    $names        = array_keys($columns);
    $placeholders = implode(', ', array_fill(0, count($names), '?'));

    db()->prepare('INSERT INTO applications (`' . implode('`, `', $names) . '`) VALUES ('
        . $placeholders . ')')->execute(array_values($columns));

    $id = (int) db()->lastInsertId();

    db()->prepare('UPDATE applications SET reference_code = ? WHERE id = ?')
        ->execute([make_reference_code($id), $id]);

    return $id;
}

/**
 * The fields a partner fills in for a direct sale, read off a POST.
 *
 * Returns [values, error]. Deliberately short: the office can fill the rest in
 * later, but a sale with no name or no way to reach the customer is not a sale
 * anybody can act on.
 */
function direct_sale_values(array $post): array
{
    $values = [
        'full_name'      => trim((string) ($post['full_name'] ?? '')),
        'email'          => strtolower(trim((string) ($post['email'] ?? ''))),
        'mobile_number'  => trim((string) ($post['mobile_number'] ?? '')),
        'city'           => trim((string) ($post['city'] ?? '')) ?: null,
        'state'          => trim((string) ($post['state'] ?? '')) ?: null,
        'address'        => trim((string) ($post['address'] ?? '')) ?: null,
        'pin_code'       => trim((string) ($post['pin_code'] ?? '')) ?: null,
        'units_required' => max(1, (int) ($post['units_required'] ?? 1)),
        'note'           => trim((string) ($post['note'] ?? '')) ?: null,

        /* the same identification the public form asks for — a sale a partner
           took is still a customer of ours, and the office needs the same
           paperwork on it */
        'id_number'      => trim((string) ($post['id_number'] ?? '')) ?: null,

        /* and the same two things a customer has to agree to. The partner ticks
           them having read them to the customer, which is what the wording on
           the form says they are doing. */
        'declaration_accepted' => empty($post['declaration_accepted']) ? 0 : 1,
        'terms_accepted'       => empty($post['terms_accepted']) ? 0 : 1,
        'testimonial_consent'  => empty($post['testimonial_consent']) ? 0 : 1,
    ];

    if ($values['full_name'] === '') {
        return [$values, 'The customer needs a name.'];
    }

    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        return [$values, 'Enter the customer\'s email address — it is how they reach their portal.'];
    }

    if ($values['mobile_number'] === '') {
        return [$values, 'Enter a mobile number for the customer.'];
    }

    if (!$values['declaration_accepted'] || !$values['terms_accepted']) {
        return [$values, 'The declaration and the terms both have to be agreed to before a sale can be recorded.'];
    }

    /* Taken only once everything else checks out: a rejected form must not
       leave a stray file on disk, and the customer's documents would have to be
       picked again anyway. */
    $values['id_document_path']     = store_upload('id_document_file');
    $values['residence_proof_path'] = store_upload('residence_proof_file');

    return [$values, ''];
}

/** One dealer by id, or null. */
function dealer_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM dealers WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

/**
 * How far along a sale is, as a partner reads it: the stage and how many of the
 * five it is.
 *
 * Never how much is outstanding. A dealer and a distributor both see progress
 * and neither sees money the customer owes, so there is one definition of what
 * "progress" means rather than one per portal.
 */
function partner_progress(string $status): array
{
    if ($status === 'rejected') {
        return ['label' => 'Not proceeding', 'step' => 0, 'of' => count(APPLICATION_STAGES)];
    }

    $step = array_search($status, APPLICATION_STAGES, true);

    return [
        /* The short label, not the applicant's. A pill is white-space:nowrap, so
           "Booking payment submitted — verifying" cannot wrap and runs straight
           over the next column. It is also what the Progress column header shows
           when it is filtering, so the pill and the filter now say the same
           thing about the same row. */
        'label' => status_short($status),
        'step'  => $step === false ? 0 : (int) $step + 1,
        'of'    => count(APPLICATION_STAGES),
    ];
}

/** The product's full name, as it is written to a customer. */
function product_label(string $product): string
{
    return $product === 'stove' ? 'Kinetic Hydrogen Cooking Stove' : 'Hydrogen Conversion Kit for TukTuk';
}

/* ---------- stock ----------
   A partner buys units from the tier above them before they can sell one out of
   their own hand: a distributor buys from the office, a dealer buys from their
   own distributor. Both pay first and upload proof, and the tier above releases
   the stock by approving it.

   Everything is held per product, because a stove and a TukTuk kit are not
   worth the same and one number for both could not say what is in hand. Units
   and value always move together, and value is always at cost — what these
   units cost the partner holding them, never what they will sell for. That way
   the two can never disagree.

   Balances are summed from the ledger rather than stored. One number kept in
   two places is one number waiting to go wrong, and a ledger answers "where did
   that unit go" for free. */

/* What one order may be at most. The second is what decimal(10,2) can hold —
   an order past it cannot be stored, so it is refused with a sentence rather
   than left to the database to reject with an exception. */
const STOCK_ORDER_MAX_UNITS = 1000;
const STOCK_ORDER_MAX_TOTAL = 90000000.0;

/** What one unit costs the given tier, per product. Editable under Settings. */
function stock_price(string $buyerType, string $product): float
{
    $key = 'stock_price_' . ($buyerType === 'dealer' ? 'dealer' : 'distributor') . '_'
        . ($product === 'tuktuk' ? 'tuktuk' : 'stove');

    return max(0.0, (float) setting($key, '0'));
}

/**
 * What one partner holds, per product: units in hand and what they cost.
 *
 * Always returns both products, at zero when nothing has moved, so a caller
 * never has to guess whether an empty result means none or means unknown.
 */
function stock_balance(string $ownerType, int $ownerId): array
{
    $balance = [
        'stove'  => ['units' => 0, 'value' => 0.0],
        'tuktuk' => ['units' => 0, 'value' => 0.0],
    ];

    $stmt = db()->prepare(
        'SELECT product, COALESCE(SUM(units), 0) AS units, COALESCE(SUM(value), 0) AS value
           FROM stock_ledger
          WHERE owner_type = ? AND owner_id = ?
          GROUP BY product'
    );
    $stmt->execute([$ownerType, $ownerId]);

    foreach ($stmt->fetchAll() as $row) {
        if (isset($balance[$row['product']])) {
            $balance[$row['product']] = ['units' => (int) $row['units'], 'value' => (float) $row['value']];
        }
    }

    $balance['units'] = $balance['stove']['units'] + $balance['tuktuk']['units'];
    $balance['value'] = $balance['stove']['value'] + $balance['tuktuk']['value'];

    return $balance;
}

/** How many of one product a partner has left. */
function stock_units(string $ownerType, int $ownerId, string $product): int
{
    return stock_balance($ownerType, $ownerId)[$product]['units'] ?? 0;
}

/**
 * What one unit of this product cost the partner holding it.
 *
 * The average of what they actually paid, so a price change between two orders
 * cannot make a sale deduct more than the unit was bought for. Falls back to
 * today's price when they hold nothing — the caller refuses that sale anyway.
 */
function stock_unit_cost(string $ownerType, int $ownerId, string $product): float
{
    $held = stock_balance($ownerType, $ownerId)[$product] ?? ['units' => 0, 'value' => 0.0];

    if ($held['units'] > 0 && $held['value'] > 0) {
        return round($held['value'] / $held['units'], 2);
    }

    return stock_price($ownerType, $product);
}

/** One movement. Units and value are signed: in is positive, out is negative. */
function stock_move(
    string $ownerType,
    int $ownerId,
    string $product,
    int $units,
    float $value,
    string $reason,
    ?int $orderId = null,
    ?int $applicationId = null,
    ?string $note = null
): void {
    db()->prepare(
        'INSERT INTO stock_ledger
             (owner_type, owner_id, product, units, value, reason, order_id, application_id, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$ownerType, $ownerId, $product, $units, $value, $reason, $orderId, $applicationId, $note]);
}

/** The movements behind one partner's balance, newest first. */
function stock_history(string $ownerType, int $ownerId, int $limit = 50): array
{
    $stmt = db()->prepare(
        'SELECT * FROM stock_ledger
          WHERE owner_type = ? AND owner_id = ?
          ORDER BY created_at DESC, id DESC
          LIMIT ' . max(1, $limit)
    );
    $stmt->execute([$ownerType, $ownerId]);

    return $stmt->fetchAll();
}

/** Reading name for why stock moved. */
function stock_reason_label(string $reason): string
{
    $labels = [
        'purchase'     => 'Bought in',
        'sale'         => 'Sold to a client',
        'transfer_out' => 'Passed to a dealer',
        'adjustment'   => 'Adjusted by the office',
    ];

    return $labels[$reason] ?? ucfirst($reason);
}

/** Reading name for where an order has got to. */
function stock_status_label(string $status): string
{
    $labels = [
        'pending'  => 'Waiting for approval',
        'approved' => 'Approved',
        'rejected' => 'Turned down',
    ];

    return $labels[$status] ?? ucfirst($status);
}

/**
 * Raises an order for stock. The units are not theirs until it is approved.
 *
 * `$wanted` is how many of each product: ['stove' => 4, 'tuktuk' => 2]. One
 * order can carry both, because the partner pays once and uploads one proof —
 * two orders against a single payment would let the tier above approve half of
 * it, which is not a thing anybody could act on. A product asked for in zeroes
 * is simply left out.
 *
 * Returns [orderId, error]. Every price is frozen onto its line at this moment,
 * so a change under Settings tomorrow never rewrites what was asked for today.
 */
function stock_order_create(
    string $buyerType,
    int $buyerId,
    array $wanted,
    ?int $sellerDistributorId,
    array $extra = []
): array {
    if ($buyerType === 'dealer' && !$sellerDistributorId) {
        return [0, 'You are not under a distributor yet, so there is nobody to order from. '
            . 'Ask the office to assign you one.'];
    }

    $lines = [];
    $total = 0.0;
    $units = 0;

    foreach (['stove', 'tuktuk'] as $product) {
        $quantity = (int) ($wanted[$product] ?? 0);

        if ($quantity === 0) {
            continue;
        }

        if ($quantity < 0) {
            return [0, 'A quantity cannot be negative.'];
        }

        $price = stock_price($buyerType, $product);

        if ($price <= 0) {
            return [0, 'No price has been set for the ' . product_label($product)
                . ' yet. Ask the office to set one under Settings.'];
        }

        $lines[$product] = ['quantity' => $quantity, 'price' => $price,
                            'total' => round($price * $quantity, 2)];
        $total += $lines[$product]['total'];
        $units += $quantity;
    }

    if (!$lines) {
        return [0, 'Enter how many you want of at least one product.'];
    }

    /* An order nobody could mean. Without a ceiling the total overflows the
       column it is stored in and the insert throws, which reaches the partner
       as a blank error page rather than an answer. */
    if ($units > STOCK_ORDER_MAX_UNITS) {
        return [0, 'That is more than ' . STOCK_ORDER_MAX_UNITS . ' units altogether. '
            . 'Split it into smaller orders, or ask the office to arrange it.'];
    }

    if ($total >= STOCK_ORDER_MAX_TOTAL) {
        return [0, 'That order comes to more than ' . money(STOCK_ORDER_MAX_TOTAL)
            . '. Split it into smaller ones.'];
    }

    $db = db();
    $db->beginTransaction();

    try {
        $db->prepare(
            'INSERT INTO stock_orders
                 (buyer_type, buyer_id, seller_distributor_id, total_amount, reference, proof_path, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $buyerType,
            $buyerId,
            $sellerDistributorId,
            round($total, 2),
            $extra['reference'] ?? null,
            $extra['proof_path'] ?? null,
            $extra['note'] ?? null,
        ]);

        $orderId = (int) $db->lastInsertId();

        $item = $db->prepare(
            'INSERT INTO stock_order_items (order_id, product, quantity, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($lines as $product => $line) {
            $item->execute([$orderId, $product, $line['quantity'], $line['price'], $line['total']]);
        }

        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();

        return [0, 'That order could not be saved. Try again.'];
    }

    return [$orderId, ''];
}

/** What one order is for, a line per product. */
function stock_order_items(int $orderId): array
{
    $stmt = db()->prepare(
        "SELECT * FROM stock_order_items WHERE order_id = ? ORDER BY FIELD(product, 'stove', 'tuktuk')"
    );
    $stmt->execute([$orderId]);

    return $stmt->fetchAll();
}

/** An order's products in a line of prose: "4 stoves · 2 TukTuk kits". */
function stock_order_summary(int $orderId): string
{
    $parts = [];

    foreach (stock_order_items($orderId) as $item) {
        $parts[] = (int) $item['quantity'] . ' × ' . product_label((string) $item['product']);
    }

    return $parts ? implode(' · ', $parts) : '—';
}

/** How many units an order is for altogether. */
function stock_order_units(int $orderId): int
{
    $units = 0;

    foreach (stock_order_items($orderId) as $item) {
        $units += (int) $item['quantity'];
    }

    return $units;
}

/** One order by id, or null. */
function stock_order(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM stock_orders WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

/**
 * Releases the stock on an approved order.
 *
 * The buyer gains the units at what they paid. Where a distributor is the
 * seller, the same units leave their own shelf at what *they* paid for them —
 * their margin is the difference, and margin is not stock, so it is not counted
 * here. A seller who does not hold the units cannot release them.
 *
 * Returns an error string, or '' when the stock has moved.
 */
function stock_order_approve(int $orderId, ?int $adminId = null): string
{
    $order = stock_order($orderId);

    if (!$order || $order['status'] !== 'pending') {
        return 'That order has already been decided.';
    }

    $items  = stock_order_items($orderId);
    $seller = $order['seller_distributor_id'] === null ? null : (int) $order['seller_distributor_id'];

    if (!$items) {
        return 'That order has nothing on it.';
    }

    /* every product is checked before any of them moves: releasing the stoves
       and then failing on the kits would leave half an order approved */
    if ($seller !== null) {
        foreach ($items as $item) {
            $product = (string) $item['product'];
            $have    = stock_units('distributor', $seller, $product);

            if ($have < (int) $item['quantity']) {
                return 'You hold ' . $have . ' ' . product_label($product) . ' and this order is for '
                    . (int) $item['quantity'] . '. Order more from the office first.';
            }
        }
    }

    db()->prepare(
        'UPDATE stock_orders SET status = ?, decided_at = NOW(), decided_by_admin = ?, reject_reason = NULL
          WHERE id = ?'
    )->execute(['approved', $adminId, $orderId]);

    foreach ($items as $item) {
        $product  = (string) $item['product'];
        $quantity = (int) $item['quantity'];

        stock_move(
            (string) $order['buyer_type'],
            (int) $order['buyer_id'],
            $product,
            $quantity,
            (float) $item['line_total'],
            'purchase',
            $orderId
        );

        if ($seller !== null) {
            /* off the distributor's shelf at their own cost, not at what they charged */
            $cost = stock_unit_cost('distributor', $seller, $product);

            stock_move(
                'distributor',
                $seller,
                $product,
                -$quantity,
                -round($cost * $quantity, 2),
                'transfer_out',
                $orderId
            );
        }
    }

    return '';
}

/** Turns an order down. Nothing moves; the reason is kept for the buyer to read. */
function stock_order_reject(int $orderId, string $reason = '', ?int $adminId = null): string
{
    $order = stock_order($orderId);

    if (!$order || $order['status'] !== 'pending') {
        return 'That order has already been decided.';
    }

    db()->prepare(
        'UPDATE stock_orders SET status = ?, reject_reason = ?, decided_at = NOW(), decided_by_admin = ?
          WHERE id = ?'
    )->execute(['rejected', $reason !== '' ? mb_substr($reason, 0, 255) : null, $adminId, $orderId]);

    return '';
}

/** One partner's own orders, newest first. */
function stock_orders_for(string $buyerType, int $buyerId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM stock_orders WHERE buyer_type = ? AND buyer_id = ? ORDER BY requested_at DESC, id DESC'
    );
    $stmt->execute([$buyerType, $buyerId]);

    return $stmt->fetchAll();
}

/**
 * The orders one distributor has been asked to release, with the dealer's name.
 * Pass null for the office's own queue: everything a distributor asked for.
 */
function stock_orders_to_decide(?int $sellerDistributorId): array
{
    if ($sellerDistributorId === null) {
        $stmt = db()->prepare(
            'SELECT o.*, d.full_name AS buyer_name, d.distributor_code AS buyer_code
               FROM stock_orders o
               JOIN distributors d ON d.id = o.buyer_id
              WHERE o.buyer_type = ? AND o.seller_distributor_id IS NULL
              ORDER BY FIELD(o.status, ?, ?, ?), o.requested_at DESC'
        );
        $stmt->execute(['distributor', 'pending', 'approved', 'rejected']);

        return $stmt->fetchAll();
    }

    $stmt = db()->prepare(
        'SELECT o.*, d.full_name AS buyer_name, d.dealer_code AS buyer_code
           FROM stock_orders o
           JOIN dealers d ON d.id = o.buyer_id
          WHERE o.buyer_type = ? AND o.seller_distributor_id = ?
          ORDER BY FIELD(o.status, ?, ?, ?), o.requested_at DESC'
    );
    $stmt->execute(['dealer', $sellerDistributorId, 'pending', 'approved', 'rejected']);

    return $stmt->fetchAll();
}

/**
 * Takes the units a direct sale used off the seller's shelf.
 *
 * Called after the sale is written, because the application id belongs on the
 * movement — this is the row that says which unit went where. Returns an error
 * string when there is not enough stock, in which case nothing has moved.
 */
function stock_take_for_sale(string $ownerType, int $ownerId, string $product, int $units, int $applicationId): string
{
    $units = max(1, $units);
    $have  = stock_units($ownerType, $ownerId, $product);

    if ($have < $units) {
        return 'You have ' . $have . ' ' . product_label($product) . ' in stock and this sale is for '
            . $units . '. Order more before recording it.';
    }

    $cost = stock_unit_cost($ownerType, $ownerId, $product);

    stock_move($ownerType, $ownerId, $product, -$units, -round($cost * $units, 2), 'sale', null, $applicationId);

    return '';
}

/* ---------- commission vouchers ----------
   Commission is earned when a sale completes. Getting it into a partner's bank
   is a separate journey: the claim travels up the chain and the money comes
   back down through R&F, the paying agent.

     dealer ──▶ distributor ──▶ R&F ──▶ office
                                          │
     dealer and distributor ◀── paid by R&F

   A voucher is that claim. What stops a sale being paid twice is not a running
   total but the lines: a voucher names the applications it covers, and an
   application that already has a line is never picked up again. Two people
   raising at the same moment cannot both claim the same sale, because the line
   table refuses the second one.

   See CLIENT-FLOW.md §10 for the whole design. */

/** Where a voucher has got to, in words. */
function voucher_status_label(string $status): string
{
    $labels = [
        'with_distributor' => 'With your distributor',
        'bundled'          => 'In a bundle',
        'with_rf'          => 'With R&F',
        'with_admin'       => 'With the office',
        'funded'           => 'Funded — paying',
        'paid'             => 'Paid',
        'rejected'         => 'Turned down',
        'cancelled'        => 'Cancelled',
    ];

    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/** The pill class a status wears, reusing the ones the rest of the admin uses. */
function voucher_status_pill(string $status): string
{
    if ($status === 'paid') {
        return 'accepted';
    }

    if ($status === 'rejected' || $status === 'cancelled') {
        return 'rejected';
    }

    return $status === 'funded' ? 'delivery_pending' : 'booking_review';
}

/** A voucher still in flight is one nobody has finished with. */
const VOUCHER_OPEN_STATUSES = ['with_distributor', 'bundled', 'with_rf', 'with_admin', 'funded'];

/**
 * The sales one partner has earned commission on and not yet claimed.
 *
 * Completed sales only — the same definition of earned the portals and the
 * admin already share — minus anything already sitting on a line of *theirs*.
 *
 * A line belongs to a party, not just to a sale, because one sale owes two
 * people: the dealer their commission and that dealer's distributor the
 * override. A claim by one must not swallow the other's.
 *
 * A voucher that is turned down deletes its lines, which puts those sales back
 * within reach of the next one.
 */
function voucher_claimable(string $partyType, int $partyId): array
{
    $column     = $partyType === 'distributor' ? 'distributor_id' : 'dealer_id';
    $commission = $partyType === 'distributor' ? 'distributor_commission' : 'dealer_commission';

    $stmt = db()->prepare(
        'SELECT a.id, a.reference_code, a.full_name, a.product, a.completed_at,
                a.' . $commission . ' AS amount
           FROM applications a
          WHERE a.' . $column . ' = ?
            AND ' . COMMISSION_EARNED_SQL . '
            AND a.' . $commission . ' > 0
            AND NOT EXISTS (
                  SELECT 1 FROM commission_voucher_lines l
                   WHERE l.application_id = a.id AND l.party_type = ? AND l.party_id = ?
                )
          ORDER BY a.completed_at, a.id'
    );
    $stmt->execute([$partyId, $partyType, $partyId]);

    return $stmt->fetchAll();
}

/** What those sales come to. */
function voucher_claimable_total(string $partyType, int $partyId): float
{
    $total = 0.0;

    foreach (voucher_claimable($partyType, $partyId) as $row) {
        $total += (float) $row['amount'];
    }

    return round($total, 2);
}

/** Whichever voucher a partner still has in flight, or null. */
function voucher_open_for(string $partyType, int $partyId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM commission_vouchers
          WHERE party_type = ? AND party_id = ?
            AND status IN (' . implode(',', array_fill(0, count(VOUCHER_OPEN_STATUSES), '?')) . ')
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$partyType, $partyId, ...VOUCHER_OPEN_STATUSES]);

    return $stmt->fetch() ?: null;
}

/** One voucher by id. */
function voucher(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM commission_vouchers WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

/** Records a move, so a disputed payment has a history and not just a state. */
function voucher_event(int $voucherId, ?string $from, string $to, string $actor, ?string $note = null): void
{
    db()->prepare(
        'INSERT INTO commission_voucher_events (voucher_id, from_status, to_status, actor, note)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$voucherId, $from, $to, $actor, $note !== '' ? $note : null]);
}

/** Everything that has happened to one voucher, oldest first. */
function voucher_events(int $voucherId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM commission_voucher_events WHERE voucher_id = ? ORDER BY id'
    );
    $stmt->execute([$voucherId]);

    return $stmt->fetchAll();
}

/** The sales one voucher is made of, with who they were for. */
function voucher_lines(int $voucherId): array
{
    $stmt = db()->prepare(
        'SELECT l.*, a.reference_code, a.full_name, a.product, a.completed_at
           FROM commission_voucher_lines l
           JOIN applications a ON a.id = l.application_id
          WHERE l.voucher_id = ?
          ORDER BY a.completed_at, a.id'
    );
    $stmt->execute([$voucherId]);

    return $stmt->fetchAll();
}

/** The party behind a voucher: their name, code, and where to pay them. */
function voucher_party(array $voucher): ?array
{
    $table = $voucher['party_type'] === 'distributor' ? 'distributors' : 'dealers';
    $code  = $voucher['party_type'] === 'distributor' ? 'distributor_code' : 'dealer_code';

    $stmt = db()->prepare(
        'SELECT id, full_name, email, mobile_number, bank_name, bank_account, bank_ifsc, upi_id,
                ' . $code . ' AS code
           FROM ' . $table . ' WHERE id = ?'
    );
    $stmt->execute([(int) $voucher['party_id']]);

    return $stmt->fetch() ?: null;
}

/** Can this voucher actually be paid — is there anywhere to send the money? */
function voucher_has_bank(array $voucher): bool
{
    $party = voucher_party($voucher);

    if (!$party) {
        return false;
    }

    return !empty($party['upi_id'])
        || (!empty($party['bank_account']) && !empty($party['bank_ifsc']));
}

/**
 * Raises a voucher for everything a partner has earned and not claimed.
 *
 * Returns [voucherId, error]. The lines are written inside a transaction with
 * the voucher, so a sale can never end up on two claims: whichever insert loses
 * the race hits the unique line and rolls the whole thing back.
 */
function voucher_raise(string $partyType, int $partyId, string $actor, ?string $cycle = null): array
{
    if (voucher_open_for($partyType, $partyId)) {
        return [0, 'There is already a voucher open. It has to be settled before another is raised.'];
    }

    $claimable = voucher_claimable($partyType, $partyId);

    if (!$claimable) {
        return [0, 'Nothing to claim — no completed sale is waiting to be paid.'];
    }

    $total  = 0.0;
    $status = $partyType === 'dealer' ? 'with_distributor' : 'with_rf';
    $cycle  = $cycle ?? date('Y-m-d');

    foreach ($claimable as $row) {
        $total += (float) $row['amount'];
    }

    $db = db();
    $db->beginTransaction();

    try {
        $db->prepare(
            'INSERT INTO commission_vouchers (party_type, party_id, cycle_date, status, amount, is_bundle)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$partyType, $partyId, $cycle, $status, round($total, 2),
                    $partyType === 'distributor' ? 1 : 0]);

        $voucherId = (int) $db->lastInsertId();

        $line = $db->prepare(
            'INSERT INTO commission_voucher_lines (voucher_id, party_type, party_id, application_id, amount)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($claimable as $row) {
            $line->execute([$voucherId, $partyType, $partyId, (int) $row['id'], (float) $row['amount']]);
        }

        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();

        return [0, 'Something was claimed twice while this was being raised. Try again.'];
    }

    voucher_event($voucherId, null, $status, $actor, 'Raised for ' . money($total));

    return [$voucherId, ''];
}

/** The dealer vouchers one distributor has been asked to approve. */
function voucher_dealer_claims(int $distributorId, array $statuses = ['with_distributor']): array
{
    $marks = implode(',', array_fill(0, count($statuses), '?'));

    $stmt = db()->prepare(
        'SELECT v.*, d.full_name AS party_name, d.dealer_code AS party_code
           FROM commission_vouchers v
           JOIN dealers d ON d.id = v.party_id
          WHERE v.party_type = ? AND d.distributor_id = ? AND v.status IN (' . $marks . ')
          ORDER BY v.raised_at DESC, v.id DESC'
    );
    $stmt->execute(['dealer', $distributorId, ...$statuses]);

    return $stmt->fetchAll();
}

/** Every voucher a party has ever raised, newest first. */
function vouchers_for(string $partyType, int $partyId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM commission_vouchers WHERE party_type = ? AND party_id = ?
          ORDER BY raised_at DESC, id DESC'
    );
    $stmt->execute([$partyType, $partyId]);

    return $stmt->fetchAll();
}

/**
 * A distributor approving one of their dealers' vouchers.
 *
 * Approved is not yet claimed money — it waits at `bundled` for the bundle that
 * carries it to R&F, which is raised separately.
 */
function voucher_approve_dealer(int $voucherId, int $distributorId, string $actor): string
{
    $voucher = voucher($voucherId);

    if (!$voucher || $voucher['status'] !== 'with_distributor') {
        return 'That voucher has already been decided.';
    }

    $dealer = dealer_by_id((int) $voucher['party_id']);

    if (!$dealer || (int) $dealer['distributor_id'] !== $distributorId) {
        return 'That voucher is not yours to decide.';
    }

    db()->prepare('UPDATE commission_vouchers SET status = ?, decided_at = NOW() WHERE id = ?')
        ->execute(['bundled', $voucherId]);

    voucher_event($voucherId, 'with_distributor', 'bundled', $actor);

    return '';
}

/**
 * Turning one down. The lines are released with it — the sales go back to
 * claimable, so the next voucher picks them up rather than losing them.
 */
function voucher_reject(int $voucherId, string $actor, string $reason = '', string $status = 'rejected'): string
{
    $voucher = voucher($voucherId);

    if (!$voucher || in_array($voucher['status'], ['paid', 'rejected', 'cancelled'], true)) {
        return 'That voucher has already been settled.';
    }

    db()->prepare(
        'UPDATE commission_vouchers SET status = ?, reject_reason = ?, decided_at = NOW() WHERE id = ?'
    )->execute([$status, $reason !== '' ? mb_substr($reason, 0, 255) : null, $voucherId]);

    /* the claim is over, so the sales it named are claimable again */
    db()->prepare('DELETE FROM commission_voucher_lines WHERE voucher_id = ?')->execute([$voucherId]);

    /* a bundle taking its dealers' vouchers down with it puts them back to
       their distributor rather than killing a claim the dealer still has */
    if ((int) $voucher['is_bundle'] === 1) {
        $children = db()->prepare('SELECT id FROM commission_vouchers WHERE parent_id = ?');
        $children->execute([$voucherId]);

        foreach ($children->fetchAll() as $child) {
            db()->prepare(
                'UPDATE commission_vouchers SET status = ?, parent_id = NULL, reject_reason = ? WHERE id = ?'
            )->execute(['with_distributor', $reason !== '' ? mb_substr($reason, 0, 255) : null, (int) $child['id']]);

            voucher_event((int) $child['id'], 'bundled', 'with_distributor', $actor,
                'The bundle it was in came back');
        }
    }

    voucher_event($voucherId, (string) $voucher['status'], $status, $actor, $reason);

    return '';
}

/**
 * The distributor's bundle: their own claim plus every dealer voucher they have
 * approved, sent to R&F as one document.
 *
 * Returns [voucherId, error].
 */
function voucher_bundle(int $distributorId, string $actor, ?string $cycle = null): array
{
    $approved = voucher_dealer_claims($distributorId, ['bundled']);
    $approved = array_values(array_filter(
        $approved,
        static fn (array $v): bool => $v['parent_id'] === null
    ));

    $own = voucher_open_for('distributor', $distributorId);

    if ($own) {
        return [0, 'You already have a bundle open. It has to be settled before another is raised.'];
    }

    $claimable = voucher_claimable('distributor', $distributorId);

    if (!$approved && !$claimable) {
        return [0, 'Nothing to send — no approved dealer voucher and nothing of your own to claim.'];
    }

    $cycle = $cycle ?? date('Y-m-d');
    $own   = 0.0;

    foreach ($claimable as $row) {
        $own += (float) $row['amount'];
    }

    $db = db();
    $db->beginTransaction();

    try {
        $db->prepare(
            'INSERT INTO commission_vouchers (party_type, party_id, cycle_date, status, amount, is_bundle)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute(['distributor', $distributorId, $cycle, 'with_rf', round($own, 2)]);

        $bundleId = (int) $db->lastInsertId();

        $line = $db->prepare(
            'INSERT INTO commission_voucher_lines (voucher_id, party_type, party_id, application_id, amount)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($claimable as $row) {
            $line->execute([$bundleId, 'distributor', $distributorId, (int) $row['id'], (float) $row['amount']]);
        }

        foreach ($approved as $child) {
            $db->prepare('UPDATE commission_vouchers SET parent_id = ?, status = ? WHERE id = ?')
                ->execute([$bundleId, 'with_rf', (int) $child['id']]);
        }

        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();

        return [0, 'Something was claimed twice while this was being raised. Try again.'];
    }

    voucher_event($bundleId, null, 'with_rf', $actor,
        count($approved) . ' dealer voucher' . (count($approved) === 1 ? '' : 's')
        . ' and ' . money($own) . ' of their own');

    foreach ($approved as $child) {
        voucher_event((int) $child['id'], 'bundled', 'with_rf', $actor, 'Sent to R&F in a bundle');
    }

    return [$bundleId, ''];
}

/** Everything in a bundle: the distributor's own claim and their dealers'. */
function voucher_bundle_children(int $bundleId): array
{
    $stmt = db()->prepare(
        'SELECT v.*, d.full_name AS party_name, d.dealer_code AS party_code
           FROM commission_vouchers v
           JOIN dealers d ON d.id = v.party_id
          WHERE v.parent_id = ?
          ORDER BY d.full_name'
    );
    $stmt->execute([$bundleId]);

    return $stmt->fetchAll();
}

/** What a bundle is worth altogether: the distributor's own plus every dealer's. */
function voucher_bundle_total(int $bundleId): float
{
    $bundle = voucher($bundleId);
    $total  = $bundle ? (float) $bundle['amount'] : 0.0;

    foreach (voucher_bundle_children($bundleId) as $child) {
        $total += (float) $child['amount'];
    }

    return round($total, 2);
}

/** Bundles at one stage of the journey, newest first, with the distributor on them. */
function voucher_bundles(array $statuses): array
{
    $marks = implode(',', array_fill(0, count($statuses), '?'));

    $stmt = db()->prepare(
        'SELECT v.*, x.full_name AS party_name, x.distributor_code AS party_code
           FROM commission_vouchers v
           JOIN distributors x ON x.id = v.party_id
          WHERE v.is_bundle = 1 AND v.status IN (' . $marks . ')
          ORDER BY v.raised_at DESC, v.id DESC'
    );
    $stmt->execute($statuses);

    return $stmt->fetchAll();
}

/**
 * Moves a bundle and everything in it to one status.
 *
 * The children travel with the bundle because they are the same document from
 * every point but the dealer's — one thing for R&F to pay, one thing for the
 * office to look at.
 */
function voucher_move_bundle(int $bundleId, string $to, string $actor, array $from, ?string $note = null): string
{
    $bundle = voucher($bundleId);

    if (!$bundle || (int) $bundle['is_bundle'] !== 1) {
        return 'That is not a bundle.';
    }

    if (!in_array((string) $bundle['status'], $from, true)) {
        return 'That bundle has already moved on.';
    }

    $stamp = $to === 'paid' ? ', paid_at = NOW()' : '';

    db()->prepare('UPDATE commission_vouchers SET status = ?' . $stamp . ' WHERE id = ?')
        ->execute([$to, $bundleId]);

    voucher_event($bundleId, (string) $bundle['status'], $to, $actor, $note);

    foreach (voucher_bundle_children($bundleId) as $child) {
        db()->prepare('UPDATE commission_vouchers SET status = ?' . $stamp . ' WHERE id = ?')
            ->execute([$to, (int) $child['id']]);

        voucher_event((int) $child['id'], (string) $child['status'], $to, $actor, $note);
    }

    return '';
}

/**
 * R&F paying a funded bundle out.
 *
 * Writes a payout row for the distributor and for every dealer in it, which is
 * what makes "still owed" on the existing screens come down. The payout tables
 * stay the record of money actually transferred — this adds to them rather than
 * inventing a second one.
 */
function voucher_pay(int $bundleId, string $actor, string $reference = ''): string
{
    $bundle = voucher($bundleId);

    if (!$bundle || (int) $bundle['is_bundle'] !== 1) {
        return 'That is not a bundle.';
    }

    if ($bundle['status'] !== 'funded') {
        return 'A bundle can only be paid once the office has funded it.';
    }

    $reference = mb_substr(trim($reference), 0, 120);
    $note      = 'Voucher #' . $bundleId . ($reference !== '' ? ' · ' . $reference : '');

    if ((float) $bundle['amount'] > 0) {
        db()->prepare(
            'INSERT INTO distributor_payouts (distributor_id, amount, note, voucher_id) VALUES (?, ?, ?, ?)'
        )->execute([(int) $bundle['party_id'], (float) $bundle['amount'], $note, $bundleId]);
    }

    foreach (voucher_bundle_children($bundleId) as $child) {
        if ((float) $child['amount'] <= 0) {
            continue;
        }

        db()->prepare(
            'INSERT INTO dealer_payouts (dealer_id, amount, note, voucher_id) VALUES (?, ?, ?, ?)'
        )->execute([(int) $child['party_id'], (float) $child['amount'],
                    'Voucher #' . (int) $child['id'] . ($reference !== '' ? ' · ' . $reference : ''), (int) $child['id']]);
    }

    db()->prepare('UPDATE commission_vouchers SET payment_reference = ? WHERE id = ?')
        ->execute([$reference !== '' ? $reference : null, $bundleId]);

    return voucher_move_bundle($bundleId, 'paid', $actor, ['funded'],
        $reference !== '' ? 'Reference ' . $reference : null);
}

/**
 * The Friday run: raise what is owed, for everybody, once per cycle.
 *
 * Idempotent by cycle date — running it twice on the same Friday raises
 * nothing the second time, because everybody who could be raised for already
 * has a voucher open. Returns a count of what it did.
 */
function voucher_run_cycle(?string $cycle = null, string $actor = 'the Friday run'): array
{
    $cycle = $cycle ?? date('Y-m-d');
    $made  = ['dealers' => 0, 'bundles' => 0, 'skipped' => 0];

    /* an approved, active dealer with something to claim */
    $dealers = db()->query(
        "SELECT id FROM dealers WHERE is_active = 1 AND approval_status = 'approved' ORDER BY id"
    )->fetchAll();

    foreach ($dealers as $row) {
        [$id, $error] = voucher_raise('dealer', (int) $row['id'], $actor, $cycle);

        $error === '' ? $made['dealers']++ : $made['skipped']++;
    }

    /* then every distributor bundles whatever is sitting approved, plus their own */
    $distributors = db()->query('SELECT id FROM distributors WHERE is_active = 1 ORDER BY id')->fetchAll();

    foreach ($distributors as $row) {
        [$id, $error] = voucher_bundle((int) $row['id'], $actor, $cycle);

        $error === '' ? $made['bundles']++ : $made['skipped']++;
    }

    return $made;
}

/* ---------- paging ----------
   Every list that can grow past a screenful pages the same way: ten rows, the
   page in ?page=, and partials/pager.php underneath. The arithmetic is here so
   a list cannot invent its own idea of what page 3 means. */

/** Rows on one page. The dashboard shows the same number at a glance. */
const LIST_PER_PAGE = 10;

/**
 * Where one page of a list starts and ends.
 *
 * A page number past the end is clamped to the last page rather than showing an
 * empty table: somebody who deletes the only row on page 4 should land on the
 * rows that are left, not on nothing.
 */
function paged(int $total, $requested, int $perPage = LIST_PER_PAGE): array
{
    $pages  = max(1, (int) ceil($total / $perPage));
    $page   = max(1, min($pages, (int) $requested ?: 1));
    $offset = ($page - 1) * $perPage;

    return [
        'page'    => $page,
        'pages'   => $pages,
        'offset'  => $offset,
        'perPage' => $perPage,
        'total'   => $total,
        'from'    => $total === 0 ? 0 : $offset + 1,
        'to'      => min($offset + $perPage, $total),
    ];
}

/* ---------- the partner form ----------
   A dealer and a distributor are the same record at different levels: the same
   sixteen fields, the same codes in capitals, the same fixed lengths. Reading
   and checking them lives here once, so the two forms cannot drift into
   validating the same PAN differently. */

/** Everything both partner forms carry, in the order they are stored. */
const PARTNER_FIELDS = ['full_name', 'company', 'email', 'mobile_number', 'alt_mobile_number',
                        'address', 'city', 'state', 'pin_code', 'pan_number', 'gst_number',
                        'bank_name', 'bank_account', 'bank_ifsc', 'upi_id', 'note'];

/** The codes that are issued in capitals, and how long each one is. */
const PARTNER_CODE_FIELDS = [
    'pan_number' => ['label' => 'PAN',  'length' => 10],
    'gst_number' => ['label' => 'GST',  'length' => 15],
    'bank_ifsc'  => ['label' => 'IFSC', 'length' => 11],
];

/**
 * One partner form, read off a POST and cleaned.
 *
 * Returns [values, error]. PAN, GST and IFSC are upper-cased here rather than
 * in the browser, because a value that reaches the table has to be right even
 * when the request did not come from our form. Blank is allowed throughout
 * except the name — the paperwork usually arrives after the phone call — but a
 * half-typed code is not, because it would be quoted on a transfer that bounces.
 */
function partner_values(array $post): array
{
    $values = [];

    foreach (PARTNER_FIELDS as $field) {
        $value = trim((string) ($post[$field] ?? ''));

        if (isset(PARTNER_CODE_FIELDS[$field])) {
            $value = mb_strtoupper($value);
        }

        $values[$field] = $value === '' ? null : mb_substr($value, 0, $field === 'note' ? 2000 : 190);
    }

    foreach (PARTNER_CODE_FIELDS as $field => $rule) {
        $value = (string) ($values[$field] ?? '');

        if ($value === '' || preg_match('/^[A-Z0-9]{' . $rule['length'] . '}$/', $value)) {
            continue;
        }

        return [$values, $rule['label'] . ' has to be ' . $rule['length']
            . ' letters and digits, with nothing in between — you gave ' . mb_strlen($value) . '.'];
    }

    if ($values['full_name'] === null) {
        return [$values, 'A name is required.'];
    }

    if ($values['email'] !== null && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        return [$values, 'That email address does not look right.'];
    }

    return [$values, ''];
}

/* ---------- distributors ----------
   A distributor signs dealers up and takes a share of what they sell, as well
   as selling directly. Same shape as a dealer throughout — the code, the
   payouts, the portal — because they are the same job at a different level, and
   two near-identical implementations is how the two start disagreeing. */

/** The distributor a quoted code belongs to, or null. Switched off is nobody. */
function distributor_for_code(string $code): ?array
{
    if ($code === '') {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM distributors WHERE distributor_code = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$code]);

    return $stmt->fetch() ?: null;
}

/** One distributor by id, or null. */
function distributor_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM distributors WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

/** The distributor a dealer answers to, or null when the office signed them up. */
function distributor_for_dealer(?array $dealer): ?array
{
    $id = (int) ($dealer['distributor_id'] ?? 0);

    return $id > 0 ? distributor_by_id($id) : null;
}

/** The dealers one distributor has signed up, newest first. */
function distributor_dealers(int $distributorId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM dealers WHERE distributor_id = ? ORDER BY is_active DESC, full_name'
    );
    $stmt->execute([$distributorId]);

    return $stmt->fetchAll();
}

/** Everyone who applied with one distributor's own code or through their dealers. */
function distributor_clients(int $distributorId): array
{
    $stmt = db()->prepare(
        'SELECT a.id, a.reference_code, a.full_name, a.email, a.mobile_number, a.product, a.status,
                a.created_at, a.booking_paid_at, a.delivery_paid_at, a.completed_at,
                a.dealer_commission, a.distributor_commission, a.dealer_id,
                d.full_name AS dealer_name, d.dealer_code
           FROM applications a
           LEFT JOIN dealers d ON d.id = a.dealer_id
          WHERE a.distributor_id = ?
          ORDER BY a.created_at DESC'
    );
    $stmt->execute([$distributorId]);

    return $stmt->fetchAll();
}

/** Every transfer made to one distributor, newest first. */
function distributor_payouts(int $distributorId): array
{
    $stmt = db()->prepare(
        'SELECT p.*, u.name AS paid_by_name
           FROM distributor_payouts p
           LEFT JOIN admin_users u ON u.id = p.paid_by
          WHERE p.distributor_id = ?
          ORDER BY p.paid_at DESC, p.id DESC'
    );
    $stmt->execute([$distributorId]);

    return $stmt->fetchAll();
}

/**
 * What one dealer has earned their distributor, and what is still riding on
 * their sales in progress.
 *
 * Scoped to the pair, not to the dealer: a dealer moved from one distributor to
 * another keeps the override on their old sales with the old distributor, which
 * is exactly what the frozen figures on those applications already say.
 */
function distributor_override_from_dealer(int $distributorId, int $dealerId): array
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(CASE WHEN ' . COMMISSION_EARNED_SQL . '
                                  THEN distributor_commission ELSE 0 END), 0) AS earned,
                COALESCE(SUM(CASE WHEN ' . COMMISSION_EARNED_SQL . " OR status = 'rejected'
                                  THEN 0 ELSE distributor_commission END), 0) AS pipeline
           FROM applications
          WHERE distributor_id = ? AND dealer_id = ?"
    );
    $stmt->execute([$distributorId, $dealerId]);
    $row = $stmt->fetch() ?: [];

    return [
        'earned'   => (float) ($row['earned'] ?? 0),
        'pipeline' => (float) ($row['pipeline'] ?? 0),
    ];
}

/** The reading view of one distributor, in the shape field_groups() returns. */
function distributor_field_groups(): array
{
    $groups = dealer_field_groups();
    $groups['Distributor'] = $groups['Dealer'];
    unset($groups['Dealer']);

    $groups['Distributor']['Tracking'] = ['distributor_code' => 'Distributor code', 'note' => 'Note',
                                          'created_at' => 'Added on'];

    return $groups;
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
    return commission_totals('dealer', $dealerId);
}

/**
 * What one dealer or distributor has sold, earned, been paid and is still owed.
 *
 * The two are the same sum over different columns, so they are the same
 * function: two definitions of "still owed" is how a portal and an admin start
 * quoting different figures at the same person.
 *
 * `sales` counts everything attributed to them, `confirmed` only the complete
 * ones — the ones that have actually earned anything.
 */
function commission_totals(string $who, int $id): array
{
    $column     = $who === 'distributor' ? 'distributor_id' : 'dealer_id';
    $commission = $who === 'distributor' ? 'distributor_commission' : 'dealer_commission';
    $payouts    = $who === 'distributor' ? 'distributor_payouts' : 'dealer_payouts';

    $stmt = db()->prepare(
        'SELECT COUNT(*) AS sales,
                COALESCE(SUM(' . COMMISSION_EARNED_SQL . '), 0) AS confirmed,
                COALESCE(SUM(CASE WHEN ' . COMMISSION_EARNED_SQL . '
                                  THEN ' . $commission . ' ELSE 0 END), 0) AS earned,
                COALESCE(SUM(CASE WHEN ' . COMMISSION_EARNED_SQL . " OR status = 'rejected'
                                  THEN 0 ELSE " . $commission . ' END), 0) AS pipeline
           FROM applications
          WHERE ' . $column . ' = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch() ?: [];

    $paidStmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM ' . $payouts . ' WHERE ' . $column . ' = ?');
    $paidStmt->execute([$id]);

    $earned = (float) ($row['earned'] ?? 0);
    $paid   = (float) $paidStmt->fetchColumn();

    return [
        'sales'     => (int) ($row['sales'] ?? 0),
        'confirmed' => (int) ($row['confirmed'] ?? 0),
        'earned'    => $earned,
        /* riding on sales still in progress: not owed, but worth seeing */
        'pipeline'  => (float) ($row['pipeline'] ?? 0),
        'paid'      => $paid,
        /* an overpayment reads as nothing owed rather than a negative figure */
        'remaining' => max(0.0, $earned - $paid),
    ];
}

/** What one distributor has sold, earned, been paid and is still owed. */
function distributor_totals(int $distributorId): array
{
    return commission_totals('distributor', $distributorId);
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

/* ---------- what an action leaves behind ----------
   An action redirects back to the list it came from, and the list has to be
   able to say what happened. Hanging that off the query string means every
   confirmation rewrites the address bar and stays there — reload it and the
   same "sent" message comes back for something that happened once. The session
   carries it instead: the URL never moves and the message is read exactly once.

   Keys in use: saved (id to flag), deleted (id), mail (sent|failed) and
   pay (receipt|rejected|reminded|mailfail). */

/** Remember what to tell the next page. */
function admin_flash(array $values): void
{
    $_SESSION['admin_flash'] = $values;
}

/** Read it, once — a second look at the same page says nothing. */
function admin_flash_take(): array
{
    $flash = $_SESSION['admin_flash'] ?? [];
    unset($_SESSION['admin_flash']);

    return is_array($flash) ? $flash : [];
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

/**
 * The office's own pages.
 *
 * R&F signs in at the same door and is a different job entirely — they see
 * vouchers and nothing else — so landing on an office page sends them to their
 * own. One sign-in, two destinations.
 */
function require_login(): array
{
    $user = current_user();

    if (!$user) {
        header('Location: login.php');
        exit;
    }

    if (($user['role'] ?? 'admin') === 'rf') {
        header('Location: ' . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/../rf/index.php');
        exit;
    }

    return $user;
}

/** The R&F pages, the other way round. */
function require_rf(): array
{
    $user = current_user();

    if (!$user) {
        header('Location: ../admin/login.php');
        exit;
    }

    if (($user['role'] ?? 'admin') !== 'rf') {
        header('Location: ../admin/index.php');
        exit;
    }

    return $user;
}

/** Where a signed-in account belongs. */
function role_landing(string $role): string
{
    return $role === 'rf' ? '../rf/index.php' : 'index.php';
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

    /* the dashboard's list is a UNION of three tables, so the name arrives
       under the alias they share — without it every row there was a '#id' */
    return $row['full_name'] ?? $row['name'] ?? $row['title'] ?? ('#' . $row['id']);
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
        $label = $key === 'id_document_path' ? 'ID document' : 'Proof of residence';

        return '<a class="link-arrow" data-viewer="' . $label . '" href="file.php?path='
            . e(rawurlencode((string) $value)) . '">Open file <i class="bi bi-box-arrow-up-right"></i></a>';
    }

    if ($key === 'payment_proof_path') {
        return '<a class="link-arrow" data-viewer="Payment receipt" href="file.php?path='
            . e(rawurlencode((string) $value))
            . '&amp;dir=payments">Open receipt <i class="bi bi-box-arrow-up-right"></i></a>';
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

/** The same, without the time — for a date that never had one. */
function format_date(?string $value): string
{
    if (!$value) {
        return '—';
    }

    return date('j M Y', strtotime($value));
}
