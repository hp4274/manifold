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
    define('SITE_PUBLIC_URL', '');
}

if (!defined('EMAIL_LOGO_URL')) {
    define('EMAIL_LOGO_URL', '');
}

if (!defined('ERROR_LOG_DIR')) {
    define('ERROR_LOG_DIR', __DIR__ . '/logs');
}

if (!defined('ERROR_LOG_FILE')) {
    define('ERROR_LOG_FILE', ERROR_LOG_DIR . '/php-error.log');
}

if (!defined('PAYMENT_PLAN')) {
    define('PAYMENT_PLAN', [
        'stove'  => ['booking' => 3500.0, 'delivery' => 16500.0, 'full' => 35000.0],
        'tuktuk' => ['booking' => 6000.0, 'delivery' => 24000.0, 'full' => 60000.0],
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

/* An address copied out of a message often arrives wrapped in whitespace, and
   an address typed on a phone often picks up a trailing space. Nobody ever
   means either. Every posted field whose name mentions an email is stripped of
   spaces and tabs here, once, so a form, a login and the portal all read the
   same address - and " a@b.com " no longer fails to match the account it is. */
foreach ($_POST as $postKey => $postValue) {
    if (is_string($postValue) && stripos((string) $postKey, 'email') !== false) {
        /* An address is case blind, so it is stored the way it is read out:
           lower case, whichever way the caps lock was pointing. */
        $_POST[$postKey] = mb_strtolower((string) preg_replace('/\s+/u', '', $postValue), 'UTF-8');
    }
}

if (!defined('SESSION_LIFETIME')) {
    /* How long an *office* sign-in survives while idle, in seconds. The
       dashboard sees every customer's paperwork and bank details, and the
       machines it is opened on are shared, so it still times out. Enforced in
       current_user() rather than by the session's own lifetime, which is now the
       long one below. config.php can override. */
    define('SESSION_LIFETIME', 8 * 60 * 60);
}

if (!defined('PORTAL_SESSION_LIFETIME')) {
    /* How long a customer's sign-in survives, in seconds.

       Deliberately long, and deliberately not an idle timeout. A client signs in
       with a code emailed to them, comes back a week later to see where their
       order is, and being asked for a fresh code every time — or being dropped
       mid-payment — reads as the site losing their place rather than as safety.
       They see their own application and nothing else, so there is little to
       protect and a great deal of nuisance in protecting it.

       This is the figure the session cookie and the garbage collector both run
       on, so it has to be the longer of the two lifetimes. */
    define('PORTAL_SESSION_LIFETIME', 60 * 60 * 24 * 90);
}

/**
 * Whether the request reached us over TLS — directly, via a proxy that
 * terminated it, or on the HTTPS port. Decides the Secure flag on our cookies:
 * set on a plain-HTTP local install the browser drops the cookie and nobody can
 * sign in, but omitted behind Cloudflare (where the leg to PHP is plain HTTP) a
 * production session would travel in the clear.
 */
function request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', (string) PORTAL_SESSION_LIFETIME);

    /* Kept out of the shared temp directory a co-tenant could sweep on their
       own, shorter gc schedule — there our raised lifetime meant nothing. Falls
       back to the default path when the private one cannot be made writable: a
       broken save path is worse than a shared one. */
    $sessionDir = ERROR_LOG_DIR . '/sessions';
    $sessionDirOurs = (is_dir($sessionDir) || @mkdir($sessionDir, 0700, true)) && is_writable($sessionDir);

    if ($sessionDirOurs) {
        session_save_path($sessionDir);
    }

    /* A passer-by still gets a cookie that dies with the browser: most visitors
       never sign in, and there is no reason to date a cookie that holds nothing.
       A sign-in is what turns it into a dated one, in session_keep_alive(). */
    session_set_cookie_params([
        'lifetime' => 0,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => request_is_https(),
    ]);
    session_start();

    session_keep_alive();

    if ($sessionDirOurs) {
        session_sweep_anonymous($sessionDir);
    }
}

/**
 * Clears out sessions that never became a sign-in.
 *
 * PHP's own collector goes on one lifetime for the whole save path, and ours has
 * to be the long one so a client is not swept while they are still using their
 * session. That leaves every passer-by's session sitting there for three months:
 * the public pages ask portal/session.php who is signed in, so each visitor gets
 * a file, and on a shared host a hundred thousand of them is an inode problem
 * rather than a disk one.
 *
 * So the long lifetime is for people who signed in, and this puts the short one
 * back for everybody else. A file is spared if it holds any of the four things
 * that mean somebody is signed in — checked by reading it, because an anonymous
 * session is not always empty (a form on the page leaves a CSRF token behind).
 *
 * Runs on about one request in five hundred, after the response has gone.
 */
function session_sweep_anonymous(string $dir): void
{
    if (random_int(1, 500) !== 1) {
        return;
    }

    $cutoff  = time() - SESSION_LIFETIME;
    $current = session_id() === '' ? '' : 'sess_' . session_id();

    register_shutdown_function(static function () use ($dir, $cutoff, $current): void {
        foreach (glob($dir . '/sess_*') ?: [] as $file) {
            if (basename($file) === $current || @filemtime($file) > $cutoff) {
                continue;
            }

            /* Big enough to hold a sign-in and then some. Anything past it is
               not a stray, so leave it alone and let a person look. */
            if (@filesize($file) > 8192) {
                continue;
            }

            $body = @file_get_contents($file);

            if ($body === false) {
                continue;
            }

            /* Loose on purpose. Sparing a file we could have deleted costs
               nothing; deleting one somebody is signed in to costs them their
               session, so the match errs towards leaving things alone. */
            if (preg_match('/(applicant_email|dealer_id|distributor_id|admin)\|/', $body)) {
                continue;
            }

            @unlink($file);
        }
    });
}

/**
 * Keeps a signed-in session from ageing out underneath the person using it.
 *
 * Two things had to be answered to stop a client being signed out on their own:
 *
 *   The cookie. PHP dates it once, when the session is created, so a window set
 *   at sign-in would run out mid-use however recently they were here. Re-sending
 *   it moves the date forward, which is what makes the window a rolling one.
 *   Daily is often enough to keep it from expiring under anybody, and rare
 *   enough not to put a Set-Cookie on every response.
 *
 *   The file. PHP rewrites a session file only when something in it changed, and
 *   the collector goes on the file's mtime — so somebody who signs in and then
 *   only ever reads their status page has a session that looks untouched since
 *   the day they got their code, and is swept while they are still using it.
 *   The daily stamp below is itself a change, so the file is rewritten and
 *   re-dated at the same time.
 *
 * Called for anyone signed in, the office included; the office's own timeout is
 * a separate thing and lives in current_user().
 */
function session_keep_alive(): void
{
    /* Nothing to keep alive, and nothing to hand a cookie to. */
    if (!signed_in_somehow()) {
        return;
    }

    if (!empty($_SESSION['cookie_dated_at'])
        && time() - (int) $_SESSION['cookie_dated_at'] < 86400) {
        return;
    }

    /* The gateway's return runs with session cookies switched off on purpose
       (see portal/pg-return.php). Sending one here would put back exactly the
       bug that file exists to avoid: a Set-Cookie on a cross-site POST, landing
       on the applicant's real session id. */
    if (!ini_get('session.use_cookies') || headers_sent() || session_id() === '') {
        return;
    }

    $params = session_get_cookie_params();

    setcookie(session_name(), session_id(), [
        'expires'  => time() + PORTAL_SESSION_LIFETIME,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $params['secure'],
    ]);

    $_SESSION['cookie_dated_at'] = time();
}

/** Whether this session belongs to anybody at all — any portal, or the office. */
function signed_in_somehow(): bool
{
    return !empty($_SESSION['applicant_email'])
        || !empty($_SESSION['dealer_id'])
        || !empty($_SESSION['distributor_id'])
        || !empty($_SESSION['admin']);
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
    'submitted', 'booking_pending', 'booking_review', 'docs_pending',
    'delivery_pending', 'delivery_review', 'complete', 'cancelled', 'refunded', 'rejected',
];

/** The stages an applicant sees in the portal timeline (rejected sits outside). */
const APPLICATION_STAGES = [
    'submitted', 'booking_pending', 'booking_review', 'docs_pending',
    'delivery_pending', 'delivery_review', 'complete',
];

/** A sale paid in one go: no booking window, no finance documents, no loan. */
function is_full_payment(array $app): bool
{
    return ($app['payment_plan'] ?? '') === 'full_payment';
}

/**
 * The stages one applicant's timeline walks through. A full payer reuses the
 * booking statuses for their single payment and skips the paperwork and the
 * delivery tranche entirely.
 */
function application_stages(array $app): array
{
    return is_full_payment($app)
        ? ['submitted', 'booking_pending', 'booking_review', 'complete']
        : APPLICATION_STAGES;
}

/** The transfers an application may carry: booking, delivery, or one-shot full payment. */
const PAYMENT_STAGES = ['booking', 'delivery', 'full'];

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
function status_label(string $status, string $audience = 'admin', string $plan = ''): string
{
    /* a full payer's one payment rides on the booking statuses */
    if ($plan === 'full_payment') {
        $full = [
            'booking_pending' => $audience === 'applicant' ? 'Full payment due' : 'Full payment pending',
            'booking_review'  => $audience === 'applicant'
                ? 'Full payment submitted — verifying'
                : 'Full payment receipt — verify',
            'complete'        => $audience === 'applicant' ? 'Complete' : 'Full payment verified',
        ];

        if (isset($full[$status])) {
            return $full[$status];
        }
    }

    $labels = [
        'submitted'        => $audience === 'applicant' ? 'Application received' : 'Waiting for approval',
        'booking_pending'  => $audience === 'applicant' ? 'Booking payment due' : 'Booking payment pending',
        'booking_review'   => $audience === 'applicant'
            ? 'Booking payment submitted — verifying'
            : 'Booking receipt — verify',
        'docs_pending'     => $audience === 'applicant'
            ? 'Finance documents - verifying'
            : 'Finance documents - verify',
        'delivery_pending' => $audience === 'applicant' ? 'Delivery payment due' : 'Delivery payment pending',
        'delivery_review'  => $audience === 'applicant'
            ? 'Delivery payment submitted — verifying'
            : 'Delivery receipt — verify',
        'complete'         => $audience === 'applicant' ? 'Complete' : 'Both payments verified',
        'cancelled'        => $audience === 'applicant' ? 'Cancelled - refund due' : 'Refund requested',
        'refunded'         => $audience === 'applicant' ? 'Refunded' : 'Refunded - order closed',
        'expired'          => $audience === 'applicant' ? 'Application expired' : 'Expired',
        'new'              => 'New',
        'accepted'         => 'Accepted',
        'contacted'        => 'Contacted',
        'rejected'         => 'Rejected',
    ];

    return $labels[$status] ?? ucfirst($status);
}

/** Short label used in tables, pills and tiles. */
function status_short(string $status, string $plan = ''): string
{
    if ($plan === 'full_payment' && in_array($status, ['booking_pending', 'booking_review'], true)) {
        return $status === 'booking_pending' ? 'full payment due' : 'full receipt';
    }

    $short = [
        'submitted'        => 'to approve',
        'booking_pending'  => 'booking due',
        'booking_review'   => 'booking receipt',
        'docs_pending'     => 'documents',
        'delivery_pending' => 'delivery due',
        'delivery_review'  => 'delivery receipt',
        'complete'         => 'complete',
        'cancelled'        => 'refund requested',
        'refunded'         => 'refunded',
        'expired'          => 'expired',
    ];

    return $short[$status] ?? $status;
}

/** What the applicant is told at each stage. */
function stage_copy(string $status, string $plan = ''): array
{
    if ($plan === 'full_payment') {
        $full = [
            'booking_pending' => ['Full payment due',
                                  'Pay the full price using the payment details below and upload the receipt. '
                                  . 'There are no finance documents to check — once the payment is verified '
                                  . 'your order is complete.'],
            'booking_review'  => ['Full payment submitted',
                                  'Your payment receipt is being checked. Nothing more is needed for now.'],
            'complete'        => ['Complete',
                                  'Your payment is verified. Your receipt is on its way and we will call you '
                                  . 'to arrange the handover.'],
        ];

        if (isset($full[$status])) {
            return $full[$status];
        }
    }

    $copy = [
        'submitted'        => ['Application received',
                               'We have your application and are looking at it. Nothing to pay yet — we '
                               . 'email you the payment details as soon as it is approved.'],
        'booking_pending'  => ['Booking payment due',
                               'Pay the booking amount using the payment details below and upload the '
                               . 'receipt. It reserves your unit and comes off the price.'],
        'booking_review'   => ['Booking payment submitted',
                               'We are checking your booking receipt. Nothing more is needed for now.'],
        'docs_pending'     => ['Finance documents',
                               'Your booking payment is verified. Our finance team is checking the documents '
                               . 'you gave with the application. Until they are verified you can cancel and '
                               . 'get everything back, for ' . payment_deadline_hours() . ' hours after your '
                               . 'booking payment; once they are verified the booking is final. The delivery '
                               . 'payment opens as soon as they pass, and we email you when that happens.'],
        'delivery_pending' => ['Delivery payment due',
                               'Your documents are verified and the booking is final. Pay the delivery '
                               . 'amount and upload that receipt to complete the purchase.'],
        'delivery_review'  => ['Delivery payment submitted',
                               'We are checking your delivery receipt. Nothing more is needed for now.'],
        'complete'         => ['Complete',
                               'Both payments are verified. Your receipts are on their way and we will call you '
                               . 'to arrange the handover.'],
        'cancelled'        => ['Order cancelled',
                               'You asked us not to go ahead. Everything you have paid is refunded - our '
                               . 'team is arranging the transfer and will confirm it by email.'],
        'refunded'         => ['Refunded',
                               'Your refund has been sent back to the account you paid from. It can take '
                               . 'a few working days to show on your statement.'],
        'expired'          => ['Payment window expired',
                               'This application is no longer active. Please contact your dealer or our '
                               . 'office if you would like to renew it.'],
        'rejected'         => ['Not proceeding',
                               'This application is not moving forward. Contact us if you think that is a mistake.'],
    ];

    return $copy[$status] ?? [status_label($status, 'applicant', $plan), ''];
}

/* ---------- payments ---------- */

/** Reading name for one of the transfers. */
function payment_stage_label(string $stage): string
{
    if ($stage === 'full') {
        return 'Full payment';
    }
    return $stage === 'delivery' ? 'Delivery payment' : 'Booking payment';
}

/**
 * What one stage is worth on one application. The figures are frozen onto the
 * row at submit time; the price list is only the fallback for older rows.
 */
function stage_amount(array $app, string $stage): float
{
    $plan = payment_plan((string) ($app['product'] ?? 'stove'));

    /* the whole price, loan portion included. payment_amount only holds it on
       a full-payment row — on a booking row it is the booking figure */
    if ($stage === 'full') {
        $amount = ($app['payment_plan'] ?? '') === 'full_payment' ? (float) ($app['payment_amount'] ?? 0) : 0.0;
        return $amount > 0 ? $amount : (float) ($plan['full'] ?? (float) $plan['booking'] + (float) $plan['delivery']);
    }

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
 * Stages plus the totals across them. Supports booking, delivery, and full payment.
 */
function payment_totals(array $app, ?array $payments = null): array
{
    $payments = $payments ?? payments_for((int) $app['id']);

    $fullPaid = 0.0;
    $fullWaiting = 0.0;
    $fullRows = [];
    foreach ($payments as $p) {
        if (($p['stage'] ?? '') === 'full') {
            $fullRows[] = $p;
            if ($p['status'] === 'verified') {
                $fullPaid += (float) $p['amount'];
            } elseif ($p['status'] === 'pending') {
                $fullWaiting += (float) $p['amount'];
            }
        }
    }

    $fullPlanAmount = stage_amount($app, 'full');
    $isFullSettled  = $fullPaid + 0.001 >= $fullPlanAmount;
    $fullRefused    = array_values(array_filter($fullRows, static fn (array $p): bool => $p['status'] === 'rejected'));

    $booking  = payment_stage($app, 'booking', $payments);
    $delivery = payment_stage(
        $app,
        'delivery',
        $payments,
        ($booking['settled'] || $isFullSettled)
            && (!empty($app['docs_verified_at']) || !empty($app['kyc_verified_at']))
    );

    if ($isFullSettled) {
        $booking['settled']  = true;
        $booking['state']    = 'paid';
        $delivery['settled'] = true;
        $delivery['state']   = 'paid';
    } elseif ($fullWaiting > 0 && !$booking['settled']) {
        $booking['state'] = 'checking';
    }

    /* a full payer owes the whole price; a booking customer owes the two
       stages, the rest being their loan which never passes through us */
    $due     = ($isFullSettled || $fullPaid + $fullWaiting > 0 || ($app['payment_plan'] ?? '') === 'full_payment')
        ? $fullPlanAmount
        : $booking['amount'] + $delivery['amount'];
    $paid    = $booking['paid'] + $delivery['paid'] + $fullPaid;
    $waiting = $booking['waiting'] + $delivery['waiting'] + $fullWaiting;

    $current = null;

    if (is_full_payment($app) && !$isFullSettled) {
        $current = 'full';
    } elseif (!$booking['settled'] && !$isFullSettled) {
        $current = 'booking';
    } elseif (!$delivery['settled'] && !$isFullSettled) {
        $current = 'delivery';
    }

    $settled = $isFullSettled || ($booking['settled'] && $delivery['settled']);

    return [
        'due'     => $due,
        'paid'    => $paid,
        'waiting' => $waiting,
        'balance' => max($due - $paid, 0),
        'settled' => $settled,
        'percent' => $due > 0 ? min(100, (int) round($paid / $due * 100)) : 100,
        'stages'  => [
            'booking'  => $booking,
            'delivery' => $delivery,
            'full'     => [
                'stage'   => 'full',
                'label'   => 'Full payment',
                'amount'  => $fullPlanAmount,
                'paid'    => $fullPaid,
                'waiting' => $fullWaiting,
                'balance' => max($fullPlanAmount - $fullPaid, 0),
                'settled' => $isFullSettled,
                'state'   => $isFullSettled ? 'paid' : ($fullWaiting > 0 ? 'checking' : 'due'),
                'payments'      => $fullRows,
                'reject_reason' => $fullRefused ? ($fullRefused[count($fullRefused) - 1]['reject_reason'] ?? null) : null,
            ],
        ],
        'current' => $current,
    ];
}

/** Whether one stage of a sale has a verified payment against it. */
function payment_stage_settled(int $applicationId, string $stage): bool
{
    /* One 'full' receipt settles what the customer owes us — the booking and
       the delivery. It says nothing about the loan, which is the financier's
       money and arrives on its own: counting it here stamped loan_paid_at on
       every full payment and wrote a commission line of zero for each tier. */
    $coversFull = $stage !== 'loan';

    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM payments
          WHERE application_id = ? AND (stage = ? OR (? = 1 AND stage = 'full'))
            AND status = 'verified'"
    );
    $stmt->execute([$applicationId, $stage, $coversFull ? 1 : 0]);

    return (int) $stmt->fetchColumn() > 0;
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

    if ($app['status'] === 'refunded') {
        return 'refunded';
    }

    if ($app['status'] === 'expired') {
        return 'expired';
    }

    if ($app['status'] === 'submitted') {
        return 'submitted';
    }

    $payments = $payments ?? payments_for((int) $app['id']);
    $totals   = payment_totals($app, $payments);
    $booking  = $totals['stages']['booking'];
    $delivery = $totals['stages']['delivery'];

    /* A full payer: one payment, no finance documents, nothing to confirm. The
       verified payment is the whole sale. */
    if (is_full_payment($app)) {
        if ($totals['settled']) {
            return 'complete';
        }

        return $totals['waiting'] > 0 ? 'booking_review' : 'booking_pending';
    }

    if (!$booking['settled'] && !$totals['settled']) {
        return ($booking['state'] === 'checking' || $totals['waiting'] > 0) ? 'booking_review' : 'booking_pending';
    }

    /* The booking is paid. Until the documents pass the client may still cancel
       (cancel_window_open) - the only decision they are ever asked to make. */
    if (($app['delivery_choice'] ?? 'waiting') === 'cancel') {
        return 'cancelled';
    }

    /* Documents check */
    if (empty($app['docs_verified_at']) && empty($app['kyc_verified_at'])) {
        return 'docs_pending';
    }

    /* Once settled and docs verified, order is complete */
    if ($totals['settled'] || $delivery['settled']) {
        return 'complete';
    }

    return $delivery['state'] === 'checking' ? 'delivery_review' : 'delivery_pending';
}

/**
 * Writes the status and the payment timestamps back onto the application after
 * a receipt is uploaded or decided. Returns the status it settled on.
 */
/**
 * The columns a payment decision reads off an application.
 *
 * An application is 87 columns wide and none of this work looks at the address
 * or the site survey, so it asks for the money and the dates it moves through.
 */
const APPLICATION_MONEY_COLUMNS = 'id, product, status, dealer_id, distributor_id,
        dealer_commission, distributor_commission, booking_amount, delivery_amount,
        loan_amount, payment_plan, payment_amount, booking_paid_at, delivery_paid_at, loan_paid_at,
        created_at, zonal_cf_id, principal_cf_id, zonal_commission, principal_commission,
        docs_verified_at, delivery_choice, delivery_choice_at,
        payment_verified_at, completed_at';

function sync_application_status(int $applicationId): string
{
    $stmt = db()->prepare('SELECT ' . APPLICATION_MONEY_COLUMNS . ' FROM applications WHERE id = ?');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch();

    if (!$app) {
        return '';
    }

    $payments = payments_for($applicationId);
    $next     = status_from_payments($app, $payments);
    $totals   = payment_totals($app, $payments);
    $now      = date('Y-m-d H:i:s');
    $loanPaid = payment_stage_settled($applicationId, 'loan');

    if ($next !== $app['status']) {
        db()->prepare('UPDATE applications SET status = ? WHERE id = ?')->execute([$next, $applicationId]);
        log_status_change('application', $applicationId, (string) $app['status'], $next, null);
    }

    /* The 48 hours are the client's window to cancel, not a deadline to pay: they
       start when the booking payment is verified and the documents are still
       unchecked, and a booking that falls back to unpaid takes them with it. */
    if ($next === 'docs_pending') {
        db()->prepare('UPDATE applications SET payment_deadline_at = COALESCE(payment_deadline_at, ?) WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() + payment_deadline_hours() * 3600), $applicationId]);
    } elseif (in_array($next, ['booking_pending', 'booking_review'], true)) {
        db()->prepare('UPDATE applications SET payment_deadline_at = NULL WHERE id = ?')->execute([$applicationId]);
    }

    /* a stage that is no longer verified loses its timestamp, so a rejected
       receipt puts the application back where it was. The commission lines are
       rebuilt from those timestamps just below, so they follow it. */
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

    /* the loan tranche has no place in the status machine — a sale is still
       booking, then delivery, then complete — but it is paid and earned like
       the other two, so it carries its own stamp */
    db()->prepare('UPDATE applications SET loan_paid_at = ? WHERE id = ?')
        ->execute([$loanPaid ? ($app['loan_paid_at'] ?? $now) : null, $applicationId]);

    /* what each partner has earned on this sale, rebuilt from what is verified */
    commission_write_lines($applicationId);

    /* and the unit itself comes off the shelf of whoever sold it, once the
       customer has taken delivery */
    if ($next === 'complete') {
        stock_take_on_completion($applicationId);
    }

    return $next;
}

/**
 * Takes a completed sale's units off the shelf of the partner who sold it.
 *
 * A dealer's or a distributor's code on the form is what makes the sale theirs,
 * and the unit the customer ends up with came out of their stock — so the
 * ledger follows the sale rather than waiting for somebody to adjust it by
 * hand. A dealer sale comes off the dealer; a distributor's own sale off the
 * distributor; a sale with no partner comes off nobody, because the company
 * shipped it.
 *
 * Safe to call twice: the ledger row carries the application it was for, and
 * every payment verification runs the status machine over the sale again.
 *
 * The movement is written even when the shelf is short, which shows as a
 * negative balance. That is deliberate — the unit demonstrably left, and a
 * balance that quietly stops at zero hides a partner who has sold more than
 * they bought.
 */
function stock_take_on_completion(int $applicationId): void
{
    $stmt = db()->prepare(
        'SELECT id, product, units_required, dealer_id, distributor_id
           FROM applications WHERE id = ?'
    );
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch();

    if (!$app) {
        return;
    }

    $owner   = $app['dealer_id'] ? 'dealer' : ($app['distributor_id'] ? 'distributor' : '');
    $ownerId = (int) ($app['dealer_id'] ?: $app['distributor_id'] ?: 0);

    if ($owner === '' || $ownerId === 0) {
        return;
    }

    $done = db()->prepare(
        "SELECT 1 FROM stock_ledger WHERE application_id = ? AND reason = 'sale' LIMIT 1"
    );
    $done->execute([$applicationId]);

    if ($done->fetchColumn()) {
        return;
    }

    $product = (string) $app['product'];
    $units   = max(1, (int) $app['units_required']);
    $cost    = stock_unit_cost($owner, $ownerId, $product);
    $short   = stock_units($owner, $ownerId, $product) < $units;

    stock_move(
        $owner, $ownerId, $product, -$units, -round($cost * $units, 2), 'sale', null, $applicationId,
        $short ? 'Sold beyond the units held — order to bring the shelf back' : null
    );
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

    /* what a quoted code is used for: who the reward belongs to, and which
       partner made that first sale — never the applicant's paperwork

       The booking number counts as well as the referral code. Both start MF and
       both sit on the same portal page, so a referrer reading theirs down the
       phone hands over whichever they happened to be looking at; matching only
       one of them threw the reward away without a word to anybody. Stored
       references carry a hyphen (MF-00000182) and the quoted code arrives
       stripped of it, so the comparison drops it on both sides. */
    $stmt = db()->prepare(
        "SELECT id, full_name, email, mobile_number, dealer_id, distributor_id, referral_code
           FROM applications
          WHERE (referral_code = ? OR REPLACE(reference_code, '-', '') = ?)
            AND booking_paid_at IS NOT NULL AND status <> ?
          LIMIT 1"
    );
    $stmt->execute([$code, $code, 'rejected']);

    return $stmt->fetch() ?: null;
}

/** An apply-form URL with the code already in the box. */
function referral_link(string $code, string $product = 'stove'): string
{
    $page = $product === 'tuktuk' ? 'apply-tuktuk' : 'apply-stove';

    /* A partner's code names the seller, a customer's names the referrer, and
       the form has a box for each — so a partner's link fills the partner box
       and a customer's fills the referral box. */
    $param = strncasecmp($code, 'MF', 2) === 0 ? 'ref' : 'code';

    return base_url() . '/' . $page . '?' . $param . '=' . rawurlencode($code);
}

/**
 * A customer's share link, carrying their own code and their dealer's.
 *
 * The reward is theirs and the sale stays with the partner who found them, which
 * is what the two parameters say. Where nobody sold that first application —
 * a customer who came in off the website with no code — only their own goes.
 */
function client_referral_link(array $app, string $product = 'stove'): string
{
    $link = referral_link((string) $app['referral_code'], $product);

    $partner = '';

    if (!empty($app['dealer_id'])) {
        $dealer = dealer_by_id((int) $app['dealer_id']);

        if ($dealer && (int) $dealer['is_active'] === 1 && $dealer['approval_status'] === 'approved') {
            $partner = (string) $dealer['dealer_code'];
        }
    } elseif (!empty($app['distributor_id'])) {
        $distributor = distributor_by_id((int) $app['distributor_id']);

        if ($distributor && (int) $distributor['is_active'] === 1) {
            $partner = (string) $distributor['distributor_code'];
        }
    }

    return $partner === '' ? $link : $link . '&code=' . rawurlencode($partner);
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
                                  THEN referral_reward ELSE 0 END), 0) AS pending,
                /* payable is the subset of pending the office can actually send:
                   the referred client has paid their booking and was not turned
                   down. This is what a payout claim may ask for. */
                COALESCE(SUM(CASE WHEN referral_reward_status = 'pending'
                                   AND booking_paid_at IS NOT NULL
                                   AND status <> 'rejected'
                                  THEN referral_reward ELSE 0 END), 0) AS payable,
                /* and how many rows make up that amount, so the office is told
                   '2 referrals due' rather than every booking-paid referral it
                   has already settled */
                COALESCE(SUM(CASE WHEN referral_reward_status = 'pending'
                                   AND booking_paid_at IS NOT NULL
                                   AND status <> 'rejected'
                                  THEN 1 ELSE 0 END), 0) AS payable_count
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
        'payable'   => (float) ($row['payable'] ?? 0),
        'payable_count' => (int) ($row['payable_count'] ?? 0),
    ];
}

/**
 * Whether a referrer may ask the office to pay them again.
 *
 * True if they have never asked, or the cooldown since their last ask has run
 * out. Keyed off `referral_payout_requested_at` on their own application row.
 */
function referral_claim_ready(array $app): bool
{
    $last = $app['referral_payout_requested_at'] ?? null;

    if (empty($last)) {
        return true;
    }

    return (strtotime((string) $last) + REFERRAL_CLAIM_COOLDOWN_HOURS * 3600) <= time();
}

/** How long until the referral request button wakes up, said in plain words. */
function referral_claim_wait_label(array $app): string
{
    $ready = strtotime((string) ($app['referral_payout_requested_at'] ?? 'now'))
        + REFERRAL_CLAIM_COOLDOWN_HOURS * 3600;
    $left  = max(0, $ready - time());

    if ($left <= 0) {
        return 'now';
    }

    /* round, not ceil: a few seconds of clock drift between PHP and MySQL would
       otherwise turn a ten-hour wait into "about 11 hours" the instant it is set */
    $hours = max(1, (int) round($left / 3600));

    return $hours === 1 ? 'in about an hour' : 'in about ' . $hours . ' hours';
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
   Commission is a flat amount per sale, set per product — not a share of what
   the sale is worth.

     a dealer sells        the dealer's figure, their distributor the override
     a distributor sells   the direct figure, no dealer involved

   The override follows the dealer's own distributor rather than going to the
   office. Both figures are read once, when the application arrives, and written
   onto the row: changing an amount under Settings must never rewrite what a
   sale that has already happened was worth.

   The whole amount is earned when the delivery payment is verified. A booking
   payment on its own earns nobody anything. */

/**
 * What one sale of one product pays, in rupees.
 *
 * $kind is 'dealer', 'override' or 'direct'. Stored in `settings` as
 * commission_<kind>_<product>, and edited from Settings — by the office or by
 * C&F, who pay it.
 */
function commission_value(string $kind, string $product): float
{
    $product = isset(PAYMENT_PLAN[$product]) ? $product : 'stove';
    $default = COMMISSION_DEFAULTS[$kind][$product] ?? 0;

    return max(0.0, (float) setting('commission_' . $kind . '_' . $product, (string) $default));
}

/** What one application is worth, whoever ends up being paid out of it. */
function sale_value(array $app): float
{
    return (float) ($app['booking_amount'] ?? 0) + (float) ($app['delivery_amount'] ?? 0);
}

/**
 * The two amounts a sale carries, given the code that was quoted.
 *
 * $dealer or $distributor, never both from the form: a dealer's code implies
 * their distributor, and a distributor's own code cuts the dealer out entirely.
 * Returns the figures to freeze onto the application.
 */
function commission_split(string $product, ?array $dealer, ?array $distributor): array
{
    if ($dealer) {
        return [
            'dealer'      => commission_value('dealer', $product),
            /* the override follows the dealer's distributor, not the form */
            'distributor' => $distributor ? commission_value('override', $product) : 0.0,
        ];
    }

    if ($distributor) {
        return ['dealer' => 0.0, 'distributor' => commission_value('direct', $product)];
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

function make_zonal_cf_code(): string
{
    return make_partner_code('MZ', 'zonal_cf', 'zonal_code');
}

function make_principal_cf_code(): string
{
    return make_partner_code('MP', 'principal_cf', 'principal_code');
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

/* ---------- how many direct children one partner may hold ----------
   Every parent row carries max_children: NULL follows the tier default under
   Settings, a number is that partner's own ceiling. 0 means no limit, in both
   places. Who may change a row's number is decided by the page that saves it:
   the office any of them, a principal its zonals' and their distributors', a
   zonal its own distributors' — never anybody their own. */

/** parent kind => [parent table, child table, child's column naming the parent, default setting, plural] */
const CHILD_CAP_RULES = [
    'principal_cf' => ['principal_cf', 'zonal_cf', 'principal_cf_id', 'default_max_zonals', 'Zonal C&Fs'],
    'zonal_cf'     => ['zonal_cf', 'distributors', 'zonal_cf_id', 'default_max_distributors', 'distributors'],
    /* the dealer default predates the other two and keeps its setting name */
    'distributor'  => ['distributors', 'dealers', 'distributor_id', 'dealer_limit', 'dealers'],
];

/** The tier default for a parent kind; 0 = no limit. */
function child_cap_default(string $kind): int
{
    $fallback = $kind === 'distributor' ? (string) DEALER_LIMIT_DEFAULT : '0';

    return max(0, (int) setting(CHILD_CAP_RULES[$kind][3], $fallback));
}

/** What this one parent may hold: their own number, else the default. 0 = no limit. */
function child_cap(string $kind, int $parentId): int
{
    $stmt = db()->prepare('SELECT max_children FROM `' . CHILD_CAP_RULES[$kind][0] . '` WHERE id = ?');
    $stmt->execute([$parentId]);
    $own = $stmt->fetchColumn();

    return $own === false || $own === null ? child_cap_default($kind) : (int) $own;
}

/**
 * How much of the allowance is used. A child still waiting for approval
 * counts — queueing fifty pending requests against a limit of ten would make
 * the limit meaningless — a turned-down one does not. $exceptId leaves out the
 * child being approved or moved, which is already in the count or about to be.
 */
function child_count(string $kind, int $parentId, int $exceptId = 0): int
{
    [, $child, $column] = CHILD_CAP_RULES[$kind];

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM `' . $child . '`
          WHERE `' . $column . "` = ? AND approval_status <> 'rejected' AND id <> ?"
    );
    $stmt->execute([$parentId, $exceptId]);

    return (int) $stmt->fetchColumn();
}

/** '' when there is room for one more under this parent, otherwise why not. */
function child_cap_error(string $kind, int $parentId, int $exceptId = 0): string
{
    $cap = child_cap($kind, $parentId);

    if ($cap === 0) {
        return '';
    }

    $held = child_count($kind, $parentId, $exceptId);

    if ($held < $cap) {
        return '';
    }

    $label = ['principal_cf' => 'Principal C&F', 'zonal_cf' => 'Zonal C&F', 'distributor' => 'distributor'][$kind];

    return 'This ' . $label . ' already has ' . $held . ' of ' . $cap . ' '
        . CHILD_CAP_RULES[$kind][4] . ' allowed.';
}

/** "3 / 10" — or "3 / no limit" — for a list or a profile. */
function child_cap_usage(string $kind, int $parentId): string
{
    $cap = child_cap($kind, $parentId);

    return child_count($kind, $parentId) . ' / ' . ($cap === 0 ? 'no limit' : $cap);
}

/** [value|null, error] for a posted cap: blank = follow the default. */
function child_cap_parse(?string $raw): array
{
    $raw = trim((string) $raw);

    if ($raw === '') {
        return [null, ''];
    }

    if (!ctype_digit($raw) || (int) $raw > 100000) {
        return [null, 'The limit has to be a whole number, 0 for no limit, or blank for the default.'];
    }

    return [(int) $raw, ''];
}

/** How many dealers a distributor holds by default. Editable under Settings. */
function dealer_limit(): int
{
    return child_cap_default('distributor');
}

/** How many of their allowance a distributor has used. */
function distributor_dealer_count(int $distributorId): int
{
    return child_count('distributor', $distributorId);
}

/** Whether this distributor has room for another dealer. */
function distributor_has_room(int $distributorId): bool
{
    return child_cap_error('distributor', $distributorId) === '';
}

/**
 * The office's answer to a dealer a distributor asked for.
 *
 * Both the Dealers page and the distributor's own drawer offer this, so the
 * rules — already decided, distributor full — live here and cannot drift apart.
 *
 * Returns ['error' => …] or ['message' => …].
 */
function dealer_decide(int $dealerId, string $verdict, int $adminId): array
{
    if (!in_array($verdict, ['approved', 'rejected'], true)) {
        return ['error' => 'That is not a decision.'];
    }

    $dealer = dealer_by_id($dealerId);

    if (!$dealer || $dealer['approval_status'] !== 'pending') {
        return ['error' => 'That request has already been decided.'];
    }

    /* the request is already in the count, so it is left out of it here */
    $full = $verdict === 'approved' ? child_cap_error('distributor', (int) $dealer['distributor_id'], $dealerId) : '';

    if ($full !== '') {
        return ['error' => $full . ' Raise their limit first.'];
    }

    /* The code is issued here, not when the request was raised: until the
       office says yes there is nothing to share, and a code handed out early is
       a link already in circulation by the time the answer is no. */
    $code = (string) ($dealer['dealer_code'] ?? '');

    if ($verdict === 'approved' && $code === '') {
        $code = make_dealer_code();

        db()->prepare('UPDATE dealers SET dealer_code = ? WHERE id = ?')->execute([$code, $dealerId]);
    }

    db()->prepare('UPDATE dealers SET approval_status = ?, decided_at = NOW(), decided_by = ? WHERE id = ?')
        ->execute([$verdict, $adminId, $dealerId]);

    if ($verdict === 'approved') {
        /* their code works from this moment, so both they and the distributor
           who asked for them are told */
        $dealer['dealer_code'] = $code;
        $under = distributor_by_id((int) $dealer['distributor_id']);

        after_response(static function () use ($dealer, $under): void {
            mailer();
            send_dealer_added_email($dealer, $under);
        });
    }

    return ['message' => $verdict === 'approved'
        ? $dealer['full_name'] . ' is approved, with code ' . $code . '. It books commission from now on.'
        : $dealer['full_name'] . ' was turned down. No code was issued.'];
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

    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']);
    }

    /* What the file *is*, never what it is called. A name is the uploader's to
       choose, so a type read back off it would let any content in under a .pdf.
       With no sniffer available (or one that cannot tell), the upload is
       refused rather than guessed from the filename. */
    if ($mime === null || $mime === false || !isset(UPLOAD_ALLOWED_MIME[$mime])) {
        return null;
    }

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }

    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . UPLOAD_ALLOWED_MIME[$mime];

    $stored = move_uploaded_file($file['tmp_name'], $dir . '/' . $name);
    if (!$stored && php_sapi_name() === 'cli' && is_file($file['tmp_name'])) {
        $stored = copy($file['tmp_name'], $dir . '/' . $name);
    }

    return $stored ? $name : null;
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
function partner_progress(string $status, string $plan = ''): array
{
    $stages = application_stages(['payment_plan' => $plan]);

    if ($status === 'rejected') {
        return ['label' => 'Not proceeding', 'step' => 0, 'of' => count($stages)];
    }

    $step = array_search($status, $stages, true);

    return [
        /* The short label, not the applicant's. A pill is white-space:nowrap, so
           "Booking payment submitted — verifying" cannot wrap and runs straight
           over the next column. It is also what the Progress column header shows
           when it is filtering, so the pill and the filter now say the same
           thing about the same row. */
        'label' => status_short($status, $plan),
        'step'  => $step === false ? 0 : (int) $step + 1,
        'of'    => count($stages),
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

/* What one order may be at most: ten thousand of each product, and a total
   inside what decimal(14,2) can hold. An order past either cannot be stored, so
   it is refused with a sentence rather than left to the database to reject with
   an exception. */
const STOCK_ORDER_MAX_UNITS = 10000;
const STOCK_ORDER_MAX_TOTAL = 9000000000.0;

/** Every tier that buys stock, top of the chain first. The office sells, never buys. */
const STOCK_TIERS = ['principal_cf', 'zonal_cf', 'distributor', 'dealer'];

/** Who a tier is, in words, for the sentences the stock screens say. */
function stock_tier_label(string $type): string
{
    return [
        'office'       => 'the office',
        'principal_cf' => 'Principal C&F',
        'zonal_cf'     => 'Zonal C&F',
        'distributor'  => 'distributor',
        'dealer'       => 'dealer',
    ][$type] ?? $type;
}

/** One partner row of any stock-holding tier, or null. */
function stock_party(string $type, int $id): ?array
{
    switch ($type) {
        case 'principal_cf': return principal_cf_by_id($id);
        case 'zonal_cf':     return zonal_cf_by_id($id);
        case 'distributor':  return distributor_by_id($id);
        case 'dealer':       return dealer_by_id($id);
    }

    return null;
}

/**
 * Who a buyer orders from: [seller_type, seller_id], seller_id null for the office.
 *
 * Always the tier directly above, read from the hierarchy rather than taken from
 * the request, so nobody can point an order at a shelf of their choosing. When
 * that tier is missing — a distributor the office signed up with no Zonal C&F,
 * or a parent who has been switched off and so cannot sign in to release
 * anything — the order climbs to the nearest ancestor who can, and the office
 * catches whatever falls off the top.
 */
function stock_seller_for(string $buyerType, int $buyerId): array
{
    $live = static fn (?array $row): bool => $row !== null
        && (int) ($row['is_active'] ?? 1) === 1
        && ($row['approval_status'] ?? 'approved') === 'approved';

    $buyer = stock_party($buyerType, $buyerId);

    /* the chain upward from this buyer, nearest first */
    $chain = [];

    if ($buyerType === 'dealer' && $buyer && !empty($buyer['distributor_id'])) {
        $buyerType = 'distributor';
        $buyer     = distributor_by_id((int) $buyer['distributor_id']);
        $chain[]   = ['distributor', $buyer];
    }

    if ($buyerType === 'distributor' && $buyer && !empty($buyer['zonal_cf_id'])) {
        $buyerType = 'zonal_cf';
        $buyer     = zonal_cf_by_id((int) $buyer['zonal_cf_id']);
        $chain[]   = ['zonal_cf', $buyer];
    }

    if ($buyerType === 'zonal_cf' && $buyer && !empty($buyer['principal_cf_id'])) {
        $chain[] = ['principal_cf', principal_cf_by_id((int) $buyer['principal_cf_id'])];
    }

    foreach ($chain as [$type, $row]) {
        if ($live($row)) {
            return [$type, (int) $row['id']];
        }
    }

    return ['office', null];
}

/** What one unit costs the given tier, per product. Editable under Settings. */
function stock_price(string $buyerType, string $product): float
{
    /* an unknown tier reads the distributor's price rather than a key nobody
       ever saved, which would be a free unit */
    $tier = in_array($buyerType, STOCK_TIERS, true) ? $buyerType : 'distributor';
    $key  = 'stock_price_' . $tier . '_' . ($product === 'tuktuk' ? 'tuktuk' : 'stove');

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

/**
 * The shelves an actor may correct: the office any of them; a Principal C&F its
 * Zonal C&Fs; a Zonal C&F its distributors; a distributor its dealers. Read from
 * the hierarchy, never the request, so a correction cannot reach sideways or up.
 * Returns [type, id, name, code] rows.
 */
function stock_adjust_targets(string $actorType, int $actorId = 0): array
{
    $sql = [
        'principal_cf' => "SELECT 'zonal_cf' AS type, id, full_name, zonal_code AS code FROM zonal_cf
                            WHERE principal_cf_id = ?",
        'zonal_cf'     => "SELECT 'distributor' AS type, id, full_name, distributor_code AS code FROM distributors
                            WHERE zonal_cf_id = ?",
        'distributor'  => "SELECT 'dealer' AS type, id, full_name, dealer_code AS code FROM dealers
                            WHERE distributor_id = ?",
    ];

    if ($actorType === 'office') {
        return db()->query(
            "SELECT 'principal_cf' AS type, id, full_name, principal_code AS code FROM principal_cf
             UNION ALL SELECT 'zonal_cf', id, full_name, zonal_code FROM zonal_cf
             UNION ALL SELECT 'distributor', id, full_name, distributor_code FROM distributors
             UNION ALL SELECT 'dealer', id, full_name, dealer_code FROM dealers
             ORDER BY FIELD(type, 'principal_cf', 'zonal_cf', 'distributor', 'dealer'), full_name"
        )->fetchAll();
    }

    if (!isset($sql[$actorType])) {
        return [];
    }

    $stmt = db()->prepare($sql[$actorType] . ' ORDER BY full_name');
    $stmt->execute([$actorId]);

    return $stmt->fetchAll();
}

/**
 * Correct one shelf by a signed number of units (a recount, a breakage). Valued
 * at the holder's average cost so the shelf's value stays in step; never takes
 * a shelf below zero. Returns '' on success, else the reason it was refused.
 */
function stock_adjust(string $actorType, int $actorId, string $targetType, int $targetId, string $product, int $units, string $note): string
{
    $allowed = false;

    foreach (stock_adjust_targets($actorType, $actorId) as $t) {
        if ($t['type'] === $targetType && (int) $t['id'] === $targetId) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed) {
        return 'That shelf is not yours to correct.';
    }

    if (!in_array($product, ['stove', 'tuktuk'], true) || $units === 0) {
        return 'Pick a product and enter a number of units other than zero (use a minus to take stock off).';
    }

    $held = stock_units($targetType, $targetId, $product);

    if ($held + $units < 0) {
        return 'They hold only ' . $held . ' — that would take the shelf below zero.';
    }

    stock_move(
        $targetType,
        $targetId,
        $product,
        $units,
        round($units * stock_unit_cost($targetType, $targetId, $product), 2),
        'adjustment',
        null,
        null,
        mb_substr(trim($note) !== '' ? trim($note) : 'Corrected by ' . stock_tier_label($actorType), 0, 255)
    );

    return '';
}

/** Handle the adjust form's POST for an actor; returns [message, error]. */
function stock_adjust_post(string $actorType, int $actorId): array
{
    [$tt, $tid] = array_pad(explode(':', (string) ($_POST['target'] ?? ''), 2), 2, '0');

    $error = stock_adjust(
        $actorType,
        $actorId,
        $tt,
        (int) $tid,
        (string) ($_POST['product'] ?? ''),
        (int) ($_POST['units'] ?? 0),
        (string) ($_POST['note'] ?? '')
    );

    return $error === '' ? ['Stock corrected. It is in the movement list.', ''] : ['', $error];
}

/** Reading name for why stock moved. */
function stock_reason_label(string $reason): string
{
    $labels = [
        'purchase'     => 'Bought in',
        'sale'         => 'Sold to a client',
        'transfer_out' => 'Released down the chain',
        'adjustment'   => 'Corrected (recount)',
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
 *
 * The seller is not a parameter: it is worked out here from the hierarchy by
 * stock_seller_for(), so the order lands in the queue of whoever is above the
 * buyer on the day it is raised.
 */
function stock_order_create(
    string $buyerType,
    int $buyerId,
    array $wanted,
    array $extra = []
): array {
    if (!in_array($buyerType, STOCK_TIERS, true)) {
        return [0, 'That kind of partner cannot order stock.'];
    }

    [$sellerType, $sellerId] = stock_seller_for($buyerType, $buyerId);

    /* The reference is what ties this order to a line on a bank statement, so
       a keyboard smash in the box makes the order unreconcilable later. Only
       shape is checked — every bank names its reference differently — but six
       characters of letters and digits is the floor. Optional still means
       optional: an order with the proof attached and nothing typed is fine. */
    $reference = trim((string) ($extra['reference'] ?? ''));

    if ($reference !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 \/_-]{5,}$/', $reference)) {
        return [0, 'The payment reference should be the one printed on your transfer — at least six '
            . 'letters or digits, and nothing else but spaces, hyphens, slashes and underscores.'];
    }

    $lines = [];
    $total = 0.0;

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

        /* the ceiling is per product: ten thousand stoves and ten thousand kits
           are two orders' worth of units but one sensible order */
        if ($quantity > STOCK_ORDER_MAX_UNITS) {
            return [0, 'That is more than ' . number_format(STOCK_ORDER_MAX_UNITS) . ' '
                . product_label($product) . ' units in one order. '
                . 'Split it into smaller orders, or ask the office to arrange it.'];
        }

        $lines[$product] = ['quantity' => $quantity, 'price' => $price,
                            'total' => round($price * $quantity, 2)];
        $total += $lines[$product]['total'];
    }

    if (!$lines) {
        return [0, 'Enter how many you want of at least one product.'];
    }

    /* An order nobody could mean. Without a ceiling the total overflows the
       column it is stored in and the insert throws, which reaches the partner
       as a blank error page rather than an answer. */
    if ($total >= STOCK_ORDER_MAX_TOTAL) {
        return [0, 'That order comes to more than ' . money(STOCK_ORDER_MAX_TOTAL)
            . '. Split it into smaller ones.'];
    }

    $db = db();
    $db->beginTransaction();

    try {
        $db->prepare(
            'INSERT INTO stock_orders
                 (buyer_type, buyer_id, seller_type, seller_id, total_amount, reference, proof_path, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $buyerType,
            $buyerId,
            $sellerType,
            $sellerId,
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

/** Whoever the units are for, with their name and email — any of the four tiers. */
function stock_order_buyer(array $order): ?array
{
    return stock_party((string) ($order['buyer_type'] ?? ''), (int) ($order['buyer_id'] ?? 0));
}

/**
 * Releases the stock on an approved order.
 *
 * The buyer gains the units at what they paid. Where a partner is the seller
 * (anyone but the office, whose shelf is bottomless), the same units leave
 * their own shelf at what *they* paid for them — their margin is the
 * difference, and margin is not stock, so it is not counted here. A seller who
 * does not hold the units cannot release them.
 *
 * Returns an error string, or '' when the stock has moved.
 */
function stock_order_approve(int $orderId, ?int $adminId = null): string
{
    $order = stock_order($orderId);

    if (!$order || $order['status'] !== 'pending') {
        return 'That order has already been decided.';
    }

    $items      = stock_order_items($orderId);
    $sellerType = (string) $order['seller_type'];
    $seller     = $sellerType === 'office' || $order['seller_id'] === null ? null : (int) $order['seller_id'];

    if (!$items) {
        return 'That order has nothing on it.';
    }

    /* every product is checked before any of them moves: releasing the stoves
       and then failing on the kits would leave half an order approved */
    if ($seller !== null) {
        [$aboveType] = stock_seller_for($sellerType, $seller);

        foreach ($items as $item) {
            $product = (string) $item['product'];
            $have    = stock_units($sellerType, $seller, $product);

            if ($have < (int) $item['quantity']) {
                return 'You hold ' . $have . ' ' . product_label($product) . ' and this order is for '
                    . (int) $item['quantity'] . '. Order more from '
                    . ($aboveType === 'office' ? 'the office' : 'your ' . stock_tier_label($aboveType))
                    . ' first.';
            }
        }
    }

    /* only a still-pending row flips: two clicks on "Payment received" in two
       tabs would otherwise both pass the check above and move the units twice */
    $flip = db()->prepare(
        'UPDATE stock_orders SET status = ?, decided_at = NOW(), decided_by_admin = ?, reject_reason = NULL
          WHERE id = ? AND status = ?'
    );
    $flip->execute(['approved', $adminId, $orderId, 'pending']);

    if ($flip->rowCount() === 0) {
        return 'That order has already been decided.';
    }

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
            /* off the seller's shelf at their own cost, not at what they charged */
            $cost = stock_unit_cost($sellerType, $seller, $product);

            stock_move(
                $sellerType,
                $seller,
                $product,
                -$quantity,
                -round($cost * $quantity, 2),
                'transfer_out',
                $orderId
            );
        }
    }

    /* the units are theirs from here, which is worth an email rather than a
       balance that changes while nobody is looking */
    $buyer   = stock_order_buyer($order);
    $summary = stock_order_summary($orderId);

    if ($buyer) {
        after_response(static function () use ($order, $buyer, $summary): void {
            mailer();
            send_stock_released_email($order, $buyer, $summary);
        });
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

    $flip = db()->prepare(
        'UPDATE stock_orders SET status = ?, reject_reason = ?, decided_at = NOW(), decided_by_admin = ?
          WHERE id = ? AND status = ?'
    );
    $flip->execute(['rejected', $reason !== '' ? mb_substr($reason, 0, 255) : null, $adminId, $orderId, 'pending']);

    return $flip->rowCount() ? '' : 'That order has already been decided.';
}

/** One partner's own orders, newest first. */
function stock_orders_for(string $buyerType, int $buyerId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM stock_orders WHERE buyer_type = ? AND buyer_id = ?
          ORDER BY FIELD(status, \'pending\', \'approved\', \'rejected\'), requested_at DESC, id DESC'
    );
    $stmt->execute([$buyerType, $buyerId]);

    return $stmt->fetchAll();
}

/**
 * The orders one seller has been asked to release, pending first, with the
 * buyer's name and code. ('office', null) is the office's own queue.
 *
 * The buyer can be any tier — the office mostly hears from principals, but a
 * distributor with no Zonal C&F above it orders from the office too — so each
 * tier's table is joined on its own type and whichever matched names the row.
 */
function stock_orders_to_decide(string $sellerType, ?int $sellerId): array
{
    $stmt = db()->prepare(
        "SELECT o.*,
                COALESCE(pc.full_name, zc.full_name, di.full_name, de.full_name) AS buyer_name,
                COALESCE(pc.principal_code, zc.zonal_code, di.distributor_code, de.dealer_code) AS buyer_code
           FROM stock_orders o
           LEFT JOIN principal_cf pc ON o.buyer_type = 'principal_cf' AND pc.id = o.buyer_id
           LEFT JOIN zonal_cf     zc ON o.buyer_type = 'zonal_cf'     AND zc.id = o.buyer_id
           LEFT JOIN distributors di ON o.buyer_type = 'distributor'  AND di.id = o.buyer_id
           LEFT JOIN dealers      de ON o.buyer_type = 'dealer'       AND de.id = o.buyer_id
          WHERE o.seller_type = ? AND o.seller_id <=> ?
          ORDER BY FIELD(o.status, 'pending', 'approved', 'rejected'), o.requested_at DESC, o.id DESC"
    );
    $stmt->execute([$sellerType, $sellerType === 'office' ? null : $sellerId]);

    return $stmt->fetchAll();
}

/**
 * Streams the payment proof on an order the caller has already checked is
 * theirs to see, or answers 404. The path comes from the order row, never the
 * request, and is held inside the proofs folder all the same.
 */
function stock_proof_send(?array $order): void
{
    $name = basename((string) ($order['proof_path'] ?? ''));
    $full = PAYMENT_PROOF_DIR . '/' . $name;

    if ($name === '' || !is_file($full)
        || strpos(realpath($full) ?: '', realpath(PAYMENT_PROOF_DIR) ?: "\0") !== 0) {
        http_response_code(404);
        exit('File not found.');
    }

    $mime = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
    ][strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($full);
    exit;
}

/** Is this order one this seller was asked to release? The ownership check every queue page makes. */
function stock_order_sold_by(?array $order, string $sellerType, int $sellerId): bool
{
    return $order !== null
        && $order['seller_type'] === $sellerType
        && (int) $order['seller_id'] === $sellerId;
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

/** Checks if a partner is at or below the critical stock threshold (<= 100 units). */
function is_stock_critical(string $ownerType, int $ownerId, string $product = ''): bool
{
    $critical = stock_critical_units();
    if ($product !== '') {
        return stock_units($ownerType, $ownerId, $product) <= $critical;
    }
    $bal = stock_balance($ownerType, $ownerId);
    return ((int) ($bal['units'] ?? 0)) <= $critical;
}

/** Returns the visual CSS class for a stock tile based on units balance. */
function stock_tile_class(int $units): string
{
    if ($units <= stock_critical_units()) {
        return 'tile--stock-critical';
    }
    if ($units <= stock_warn_units()) {
        return 'tile--stock-warn';
    }
    return '';
}

/**
 * Returns a visual badge for a stock level.
 *
 * $short is the stat-tile form: one word, with the full sentence kept in the
 * title. The long form beside a label in a 210px card wrapped onto two lines
 * and pushed the card's own sub-text out of line with its neighbours.
 */
function stock_badge(int $units, bool $short = false): string
{
    if ($short) {
        [$class, $word, $why] = $units <= stock_critical_units()
            ? ['critical', 'Critical', '≤ ' . stock_critical_units() . ' units — sales need approval']
            : ($units <= stock_warn_units()
                ? ['warning', 'Low', '≤ ' . stock_warn_units() . ' units — time to reorder']
                : ['good', 'In stock', 'Above ' . stock_warn_units() . ' units']);

        return '<span class="pill pill--' . $class . '" title="' . e($why) . '">' . $word
            . '<span class="visually-hidden"> — ' . e($why) . '</span></span>';
    }

    if ($units <= stock_critical_units()) {
        return '<span class="pill pill--critical"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> &le; 100 units (Approval Required)</span>';
    }
    if ($units <= stock_warn_units()) {
        return '<span class="pill pill--warning"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i> &le; 200 units (Stock Warning)</span>';
    }
    return '<span class="pill pill--good"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> In Stock</span>';
}

/** One application by ID. */
function application_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM applications WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function application(int $id): ?array
{
    return application_by_id($id);
}

/**
 * Is this sale still frozen behind the low-stock dual approval?
 *
 * A sale made off a shelf at or below the critical threshold is held until both
 * the Principal C&F and the Manifold Office have said yes. Until they have, no
 * money is taken for it — by a partner at the counter or by the customer online.
 */
function low_stock_blocked(array $app): bool
{
    return !empty($app['low_stock_hold']);
}

/** Why a held sale cannot be paid for yet, in the words the payer reads. */
function low_stock_block_reason(): string
{
    return 'This sale is held because the seller is at or below the '
        . stock_critical_units() . '-unit stock threshold. '
        . 'It needs Principal C&F and Manifold Office approval before payment can be taken.';
}

/**
 * Handles dual approval (Principal C&F + Manifold Office) for sales made at or below 100 units.
 */
function approve_low_stock_sale(int $appId, string $actorRole, int $userId): array
{
    $app = application($appId);
    if (!$app) {
        return ['error' => 'Application not found.'];
    }
    if (empty($app['low_stock_hold'])) {
        return ['message' => 'Sale is not held for low stock.'];
    }

    $set = [];
    $isPrincipal = in_array($actorRole, ['principal_cf', 'cf'], true);
    $isOffice    = in_array($actorRole, ['admin', 'office'], true);

    if ($isPrincipal) {
        $set[] = 'low_stock_approved_by_principal = 1';
    } elseif ($isOffice) {
        $set[] = 'low_stock_approved_by_office = 1';
    } else {
        return ['error' => 'Unauthorized role for low-stock sale approval.'];
    }

    $hasPrincipal = $isPrincipal || !empty($app['low_stock_approved_by_principal']);
    $hasOffice    = $isOffice || !empty($app['low_stock_approved_by_office']);

    if ($hasPrincipal && $hasOffice) {
        $set[] = 'low_stock_hold = 0';
    }

    $sql = 'UPDATE applications SET ' . implode(', ', $set) . ' WHERE id = ?';
    db()->prepare($sql)->execute([$appId]);

    $msg = ($hasPrincipal && $hasOffice)
        ? 'Dual approval complete (Principal C&F + Manifold Office). Low-stock hold released!'
        : 'Approval recorded. Awaiting required ' . (!$hasPrincipal ? 'Principal C&F' : 'Manifold Office') . ' approval.';

    return ['message' => $msg];
}

/** All Principal C&F partners. */
function principal_cf_all(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM principal_cf';
    if ($activeOnly) {
        $sql .= " WHERE is_active = 1 AND approval_status = 'approved'";
    }
    $sql .= ' ORDER BY full_name';
    return db()->query($sql)->fetchAll();
}

/** All Zonal C&F partners with mapped Principal C&F name. */
function zonal_cf_all(bool $activeOnly = false): array
{
    $sql = 'SELECT z.*, p.full_name AS principal_name, p.principal_code
              FROM zonal_cf z
              LEFT JOIN principal_cf p ON p.id = z.principal_cf_id';
    if ($activeOnly) {
        $sql .= " WHERE z.is_active = 1 AND z.approval_status = 'approved'";
    }
    $sql .= ' ORDER BY z.full_name';
    return db()->query($sql)->fetchAll();
}

/** All Zonal C&Fs under a given Principal C&F. */
function zonal_cf_for_principal(int $principalId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM zonal_cf WHERE principal_cf_id = ? ORDER BY is_active DESC, full_name'
    );
    $stmt->execute([$principalId]);
    return $stmt->fetchAll();
}

/** All Distributors under a given Zonal C&F. */
function distributors_for_zonal(int $zonalId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM distributors WHERE zonal_cf_id = ? ORDER BY is_active DESC, full_name'
    );
    $stmt->execute([$zonalId]);
    return $stmt->fetchAll();
}

/** Approval decision for Principal C&F. */
function principal_cf_decide(int $id, string $verdict, int $adminId): array
{
    if (!in_array($verdict, ['approved', 'rejected'], true)) {
        return ['error' => 'Invalid verdict.'];
    }
    $p = principal_cf_by_id($id);
    if (!$p) {
        return ['error' => 'Principal C&F not found.'];
    }
    $code = (string) ($p['principal_code'] ?? '');
    if ($verdict === 'approved' && $code === '') {
        $code = make_principal_cf_code();
        db()->prepare('UPDATE principal_cf SET principal_code = ? WHERE id = ?')->execute([$code, $id]);
    }
    db()->prepare('UPDATE principal_cf SET approval_status = ?, decided_at = NOW(), decided_by_label = ? WHERE id = ?')
        ->execute([$verdict, 'Admin #' . $adminId, $id]);
    return ['message' => 'Principal C&F ' . $verdict . ($verdict === 'approved' ? " with code $code." : '.')];
}

/** Approval decision for Zonal C&F. */
function zonal_cf_decide(int $id, string $verdict, int $adminId): array
{
    if (!in_array($verdict, ['approved', 'rejected'], true)) {
        return ['error' => 'Invalid verdict.'];
    }
    $z = zonal_cf_by_id($id);
    if (!$z) {
        return ['error' => 'Zonal C&F not found.'];
    }
    $full = $verdict === 'approved' ? child_cap_error('principal_cf', (int) $z['principal_cf_id'], $id) : '';
    if ($full !== '') {
        return ['error' => $full . ' Raise their limit first.'];
    }
    $code = (string) ($z['zonal_code'] ?? '');
    if ($verdict === 'approved' && $code === '') {
        $code = make_zonal_cf_code();
        db()->prepare('UPDATE zonal_cf SET zonal_code = ? WHERE id = ?')->execute([$code, $id]);
    }
    db()->prepare('UPDATE zonal_cf SET approval_status = ?, decided_at = NOW(), decided_by_label = ? WHERE id = ?')
        ->execute([$verdict, 'Admin #' . $adminId, $id]);
    return ['message' => 'Zonal C&F ' . $verdict . ($verdict === 'approved' ? " with code $code." : '.')];
}

/** Approval decision for Distributor. */
function distributor_decide(int $id, string $verdict, int $adminId): array
{
    if (!in_array($verdict, ['approved', 'rejected'], true)) {
        return ['error' => 'Invalid verdict.'];
    }
    $d = distributor_by_id($id);
    if (!$d) {
        return ['error' => 'Distributor not found.'];
    }
    $full = $verdict === 'approved' && $d['zonal_cf_id'] !== null
        ? child_cap_error('zonal_cf', (int) $d['zonal_cf_id'], $id) : '';
    if ($full !== '') {
        return ['error' => $full . ' Raise their limit first.'];
    }
    $code = (string) ($d['distributor_code'] ?? '');
    if ($verdict === 'approved' && $code === '') {
        $code = make_distributor_code();
        db()->prepare('UPDATE distributors SET distributor_code = ? WHERE id = ?')->execute([$code, $id]);
    }
    db()->prepare('UPDATE distributors SET approval_status = ?, decided_at = NOW(), decided_by_label = ? WHERE id = ?')
        ->execute([$verdict, 'Admin #' . $adminId, $id]);
    return ['message' => 'Distributor ' . $verdict . ($verdict === 'approved' ? " with code $code." : '.')];
}

/* ---------- commission, tranche by tranche ----------
   A sale arrives as up to three payments — the booking amount, whatever a
   financier pays on the client's behalf, and the delivery amount — and each one
   carries its own commission, earned the moment that payment is verified.
   Nobody waits for the whole sale to be paid for the part of it that already is.

   GST comes out of the delivery payment and nothing else:

       booking   →  commission on what was paid
       loan      →  commission on what was paid
       delivery  →  commission on (what was paid − its GST)

   The figures live in `commission_lines`, one row per tranche per party, so
   every screen and every voucher reads the same number. See CLIENT-FLOW.md §9. */

/** The tranches a sale can be made of, in the order they fall due. */
const COMMISSION_STAGES = ['booking', 'loan', 'delivery'];

/* Where a commission line is on its way to the partner's bank. */
const COMMISSION_PAYOUT_STATUSES = ['pending', 'approved', 'disbursed'];

/**
 * Saves the commission amounts, or says why it did not.
 *
 * The office and C&F both set these, from their own Settings, and a figure that
 * was right in one place and wrong in the other would pay two schemes at once —
 * so the reading and the checking live here rather than on either page.
 *
 * Returns ['error' => …] or ['message' => …].
 */
function commission_values_save(array $post): array
{
    $tiers = [
        'principal_cf' => 'The principal C&F rate',
        'zonal_cf'     => 'The zonal C&F rate',
        'distributor'  => 'The distributor rate',
        'dealer'       => 'The dealer rate',
    ];

    $clean = [];
    $total = 0.0;

    foreach ($tiers as $tier => $label) {
        $name  = 'commission_rate_' . $tier;
        $value = str_replace(['%', ','], '', trim((string) ($post[$name] ?? '')));

        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            return ['error' => $label . ' has to be a percentage between 0 and 100.'];
        }

        $total            += (float) $value;
        $clean[$name]      = number_format((float) $value, 2, '.', '');
    }

    /* paying out more than the sale brought in is a typo, not a policy */
    if ($total > 100) {
        return ['error' => 'The four rates come to ' . number_format($total, 2)
            . '% of the sale, which is more than the sale is worth.'];
    }

    $gst = str_replace(['%', ','], '', trim((string) ($post['commission_gst_percent'] ?? '')));

    if (!is_numeric($gst) || (float) $gst < 0 || (float) $gst > 100) {
        return ['error' => 'The GST in the price has to be a percentage between 0 and 100.'];
    }

    $hours = trim((string) ($post['commission_release_hours'] ?? ''));

    if (!ctype_digit($hours) || (int) $hours > 8760) {
        return ['error' => 'The hold has to be a whole number of hours, up to 8760.'];
    }

    $clean['commission_gst_percent']   = number_format((float) $gst, 2, '.', '');
    $clean['commission_release_hours'] = (string) (int) $hours;

    foreach ($clean as $name => $value) {
        save_setting($name, $value);
    }

    $plan = payment_plan('stove');
    $sale = (float) $plan['booking'] + (float) $plan['delivery'];
    $base = (float) $gst > 0 ? $sale / (1 + (float) $gst / 100) : $sale;

    return ['message' => 'Saved. A stove sold by a dealer from now on pays the dealer '
        . money(round($base * (float) $clean['commission_rate_dealer'] / 100, 2))
        . ', and every line is held ' . (int) $hours . ' hour'
        . ((int) $hours === 1 ? '' : 's') . ' before it releases.'];
}

/** What the client owes for one tranche of a sale. */
function tranche_amount(array $app, string $stage): float
{
    /* A full payer has no loan: the one payment is the whole price, booked as
       the booking tranche plus everything else on delivery, so the tranches
       add up to the full amount exactly once. */
    if (($app['payment_plan'] ?? '') === 'full_payment') {
        if ($stage === 'loan') {
            return 0.0;
        }
        if ($stage === 'delivery') {
            return max(0.0, stage_amount($app, 'full') - (float) ($app['booking_amount'] ?? 0));
        }
    }

    if ($stage === 'loan') {
        return (float) ($app['loan_amount'] ?? 0);
    }

    return (float) ($app[$stage . '_amount'] ?? 0);
}

/**
 * What one tranche is commissioned on.
 *
 * Every tranche is commissioned on what was actually paid. The rates under
 * Settings are the whole calculation — nothing is taken out first. The 'gst'
 * key stays at zero for the columns and screens that still carry it, and for
 * the sales made while GST was deducted, whose lines are already written.
 */
function commission_base(array $app, string $stage): array
{
    $paid = tranche_amount($app, $stage);

    if ($paid <= 0) {
        return ['paid' => 0.0, 'gst' => 0.0, 'base' => 0.0];
    }

    /* percentage scheme: the rates apply to the payment with its GST taken out */
    $gst = commission_gst_percent();

    if ($gst > 0 && commission_is_percent_sale($app)) {
        $base = round($paid / (1 + $gst / 100), 2);

        return ['paid' => $paid, 'gst' => round($paid - $base, 2), 'base' => $base];
    }

    return ['paid' => $paid, 'gst' => 0.0, 'base' => $paid];
}

/** GST included in the published prices, in percent. 0 = prices carry no GST to strip. */
function commission_gst_percent(): float
{
    return max(0.0, (float) setting('commission_gst_percent', '0'));
}

/**
 * Whether a sale earns the 20/15/10/6 percentage commission or the older flat amounts.
 *
 * Sales made before the switch keep their flat amounts, because their lines are
 * already written and a line is never rewritten. `commission_percent_from` is
 * stamped by the phase 5 migration; without it every sale is a percentage sale.
 */
function commission_is_percent_sale(array $app): bool
{
    $from = (string) setting('commission_percent_from', '');

    return $from === '' || strtotime((string) ($app['created_at'] ?? 'now')) >= strtotime($from);
}

/**
 * What each tier of the chain earns on a whole sale, for freezing onto the application.
 *
 * @return array{dealer: float, distributor: float, zonal: float, principal: float}
 */
function commission_percent_split(string $product, bool $dealer, bool $distributor, bool $zonal, bool $principal, bool $full = false, array $ids = []): array
{
    $plan  = payment_plan($product);
    /* a full payer pays the whole price, so the chain is paid on all of it */
    $base  = $full ? stage_amount(['product' => $product], 'full') : (float) $plan['booking'] + (float) $plan['delivery'];
    $gst   = commission_gst_percent();
    $base  = $gst > 0 ? $base / (1 + $gst / 100) : $base;
    $share = static fn (bool $on, string $tier): float => $on ? round($base * commission_rate($tier, $ids[$tier] ?? null) / 100, 2) : 0.0;

    return [
        'dealer'      => $share($dealer, 'dealer'),
        'distributor' => $share($distributor, 'distributor'),
        'zonal'       => $share($zonal, 'zonal_cf'),
        'principal'   => $share($principal, 'principal_cf'),
    ];
}

/** Whether a tranche's payment has been verified, and so is earned. */
function tranche_is_paid(array $app, string $stage): bool
{
    if ($stage === 'loan') {
        return !empty($app['loan_paid_at']);
    }

    return !empty($app[$stage . '_paid_at']);
}

/**
 * Who a sale belongs to, and what each tier of the chain is owed on it.
 *
 * One place for it, because a sale arrives either from the website form or from
 * a spreadsheet import and the two have to freeze the same figures. A
 * distributor carries their zonal C&F, who carries their principal, so naming
 * the seller names the whole chain.
 *
 * @return array<string, int|float|null> columns to merge onto the application
 */
function commission_chain_columns(
    string $product,
    ?array $dealer,
    ?array $distributor,
    ?array $zonalDirect = null,
    bool $full = false
): array {
    $split   = commission_split($product, $dealer, $distributor);
    $zonalId = $distributor
        ? (int) ($distributor['zonal_cf_id'] ?? 0)
        : (int) ($zonalDirect['id'] ?? 0);
    $principalId = null;

    if ($zonalId > 0) {
        /* The same test their own code is resolved by: a zonal who has been
           switched off or not yet approved is not on the sale, and so neither is
           the principal above them. Reading the link off the distributor without
           it paid 15 % to a disabled partner. */
        $stmt = db()->prepare(
            "SELECT principal_cf_id FROM zonal_cf
              WHERE id = ? AND is_active = 1 AND approval_status = 'approved'"
        );
        $stmt->execute([$zonalId]);
        $row = $stmt->fetch();

        if ($row === false) {
            $zonalId = 0;
        } else {
            $principalId = (int) $row['principal_cf_id'] ?: null;
        }
    }

    $columns = [
        'dealer_id'              => $dealer ? (int) $dealer['id'] : null,
        'dealer_commission'      => $split['dealer'],
        'distributor_id'         => $distributor ? (int) $distributor['id'] : null,
        'distributor_commission' => $split['distributor'],
        'zonal_cf_id'            => $zonalId ?: null,
        'principal_cf_id'        => $principalId,
    ];

    /* the 20/15/10/6 chain: every tier on the sale frozen at its own percentage
       of the sale excluding GST, and earned tranche by tranche later */
    if (commission_is_percent_sale(['created_at' => date('Y-m-d H:i:s')])) {
        $pct = commission_percent_split(
            $product,
            (bool) $dealer,
            (bool) $distributor,
            $zonalId > 0,
            (bool) $principalId,
            $full,
            [
                'dealer'       => (int) ($dealer['id'] ?? 0),
                'distributor'  => (int) ($distributor['id'] ?? 0),
                'zonal_cf'     => $zonalId,
                'principal_cf' => (int) $principalId,
            ]
        );

        $columns['dealer_commission']      = $pct['dealer'];
        $columns['distributor_commission'] = $pct['distributor'];
        $columns['zonal_commission']       = $pct['zonal'];
        $columns['principal_commission']   = $pct['principal'];
    }

    return $columns;
}

/**
 * Writes the commission lines a sale has earned so far.
 *
 * Called after anything that verifies or un-verifies a payment. It is a
 * reconciliation, not an increment: every verified tranche gets its line and
 * every tranche that is no longer verified loses it, so a rejected receipt
 * takes its commission back with it and running this twice changes nothing.
 *
 * A line is never rewritten once written — the rate and the base it was worked
 * out with stay as they were on the day it was earned.
 */
function commission_write_lines(int $applicationId): void
{
    $stmt = db()->prepare('SELECT ' . APPLICATION_MONEY_COLUMNS . ' FROM applications WHERE id = ?');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch();

    if (!$app) {
        return;
    }

    $percent = commission_is_percent_sale($app);

    /* The figures frozen onto the sale when it arrived, not today's: changing an
       amount under Settings never rewrites a sale already made. */
    $parties = [];

    if (!empty($app['dealer_id'])) {
        $parties[] = [
            'type'   => 'dealer',
            'id'     => (int) $app['dealer_id'],
            'amount' => (float) ($app['dealer_commission'] ?? 0),
        ];
    }

    if (!empty($app['distributor_id'])) {
        /* the override where a dealer sold it, the direct figure where they did —
           whichever it was, it is the one written onto the row */
        $parties[] = [
            'type'   => 'distributor',
            'id'     => (int) $app['distributor_id'],
            'amount' => (float) ($app['distributor_commission'] ?? 0),
        ];
    }

    if ($percent) {
        /* the whole chain, each tier at its own percentage, on every verified tranche */
        $parties = [];

        foreach ([
            'principal_cf' => 'principal_cf_id', 'zonal_cf' => 'zonal_cf_id',
            'distributor' => 'distributor_id', 'dealer' => 'dealer_id',
        ] as $tier => $column) {
            if (!empty($app[$column])) {
                $parties[] = ['type' => $tier, 'id' => (int) $app[$column], 'rate' => commission_rate($tier, (int) $app[$column])];
            }
        }
    }

    $keep   = db()->prepare(
        'SELECT id FROM commission_lines
          WHERE application_id = ? AND party_type = ? AND party_id = ? AND stage = ?'
    );
    $insert = db()->prepare(
        'INSERT INTO commission_lines
             (application_id, party_type, party_id, stage, paid_amount, gst_amount, base_amount, rate, amount, release_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $releaseAt = date('Y-m-d H:i:s', time() + commission_release_hours() * 3600);

    foreach ($parties as $party) {
        foreach (COMMISSION_STAGES as $stage) {
            $keep->execute([$applicationId, $party['type'], $party['id'], $stage]);
            $existing = $keep->fetchColumn();

            /* A flat amount is earned once, at delivery. The other stages are
               still looked at, so a line written under the old percentage
               scheme is taken away if its payment is ever un-verified. */
            $earned = $app['status'] !== 'rejected'
                && tranche_is_paid($app, $stage)
                && ($percent ? $party['rate'] > 0 : ($stage === 'delivery' && $party['amount'] > 0));

            $base = commission_base($app, $stage);

            if (!$earned) {
                /* no longer earned — a rejected receipt takes its line with it,
                   which is what puts the sale back within reach of a later claim */
                if ($existing) {
                    commission_line_remove((int) $existing);
                }

                continue;
            }

            if ($existing) {
                continue;   /* already written, and never rewritten */
            }

            if ($percent) {
                $insert->execute([
                    $applicationId, $party['type'], $party['id'], $stage, $base['paid'], $base['gst'],
                    $base['base'], $party['rate'], round($base['base'] * $party['rate'] / 100, 2), $releaseAt,
                ]);

                continue;
            }

            $insert->execute([
                $applicationId,
                $party['type'],
                $party['id'],
                $stage,
                $base['paid'],
                0,
                $base['base'],
                /* The commission is a flat amount, so no percentage produced it
                   — but the share of the payment it came to is the one part of
                   the calculation that was never written down. Recorded here so
                   a line can be read back without the settings of the day. */
                $base['base'] > 0
                    ? min(999.99, round($party['amount'] / $base['base'] * 100, 2))
                    : 0,
                round($party['amount'], 2),
                $releaseAt,
            ]);
        }
    }
}

/**
 * Takes a line away, unless it has already been claimed.
 *
 * A tranche that is sitting on somebody's voucher cannot simply vanish: the
 * claim would lose a line and the totals would disagree. It stays, and the
 * correction lands on the next voucher instead.
 */
function commission_line_remove(int $lineId): void
{
    $claimed = db()->prepare('SELECT COUNT(*) FROM commission_voucher_lines WHERE commission_line_id = ?');
    $claimed->execute([$lineId]);

    /* a line the office has already paid out stays as the record of that transfer */
    /* nor one whose money has already left the office down the chain */
    $paid = db()->prepare(
        "SELECT COUNT(*) FROM commission_lines
          WHERE id = ? AND (payout_status IN ('proof_uploaded', 'disbursed') OR holder_type IS NOT NULL)"
    );
    $paid->execute([$lineId]);

    if ((int) $claimed->fetchColumn() > 0 || (int) $paid->fetchColumn() > 0) {
        return;
    }

    db()->prepare('DELETE FROM commission_lines WHERE id = ?')->execute([$lineId]);
}

/** Every tranche one party has earned on one sale. */
function commission_lines_for(int $applicationId, ?string $partyType = null, ?int $partyId = null): array
{
    $sql    = 'SELECT * FROM commission_lines WHERE application_id = ?';
    $params = [$applicationId];

    if ($partyType !== null) {
        $sql     .= ' AND party_type = ? AND party_id = ?';
        $params[] = $partyType;
        $params[] = $partyId;
    }

    $stmt = db()->prepare($sql . " ORDER BY FIELD(stage, 'booking', 'loan', 'delivery')");
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/** A percentage as it is read out: 10%, 7.5%, never 10.00%. */
function rate_short(float $rate): string
{
    return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
}

/** Reading name for a tranche. */
function tranche_label(string $stage): string
{
    $labels = ['booking' => 'Booking payment', 'loan' => 'Loan amount', 'delivery' => 'Delivery payment'];

    return $labels[$stage] ?? ucfirst($stage);
}

/** What a partner has earned altogether, from the lines. */
function commission_earned(string $partyType, int $partyId): float
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM commission_lines WHERE party_type = ? AND party_id = ?'
    );
    $stmt->execute([$partyType, $partyId]);

    return round((float) $stmt->fetchColumn(), 2);
}

/**
 * What is riding on tranches not yet paid: worth seeing, not yet owed.
 *
 * Worked out from the sale rather than from lines, because a tranche that has
 * not been paid has no line — that is the point of them.
 */
function commission_pipeline(string $partyType, int $partyId): float
{
    $column = $partyType . '_id';
    $frozen = ['dealer' => 'dealer_commission', 'distributor' => 'distributor_commission',
               'zonal_cf' => 'zonal_commission', 'principal_cf' => 'principal_commission'][$partyType] ?? 'dealer_commission';

    /* Summed in the database rather than by reading every sale into PHP: this
       is called once per row of the dealers and distributors lists, and an
       application is an 87-column row. The whole amount rides on the delivery
       payment, so a sale is either still to come in full or already earned in
       full — which makes it one WHERE rather than a loop. */
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(GREATEST(0, a.{$frozen} - COALESCE((
                    SELECT SUM(l.amount) FROM commission_lines l
                     WHERE l.application_id = a.id AND l.party_type = ? AND l.party_id = ?), 0))), 0)
           FROM applications a
          WHERE a.{$column} = ? AND a.status <> 'rejected' AND a.delivery_paid_at IS NULL"
    );
    /* a percentage sale has already earned its paid tranches, so only the rest is pipeline */
    $stmt->execute([$partyType, $partyId, $partyId]);

    return round((float) $stmt->fetchColumn(), 2);
}

/* ---------- commission vouchers (RETIRED) ----------
   Replaced by the pass-down transfers (commission_transfer() and friends):
   nothing links to a voucher screen any more and nothing raises one. These
   functions and the voucher tables stay as the record of claims settled that
   way; do not build on them.

   Commission is earned when a sale completes. Getting it into a partner's bank
   is a separate journey: the claim travels up the chain and the money comes
   back down through C&F, the paying agent.

     dealer ──▶ distributor ──▶ C&F ──▶ office
                                          │
     dealer and distributor ◀── paid by C&F

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
        'with_rf'          => 'With C&F',
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
 * The tranches one partner has earned and not yet claimed.
 *
 * A tranche is earned when its own payment is verified (§9), so a partner can
 * claim the booking share of a sale whose delivery payment is months away —
 * they are not waiting on the whole sale to be paid for the part of it that
 * already has.
 *
 * A commission line is claimed once. What makes that true is the voucher line
 * pointing at it, so anything already pointed at is skipped here.
 *
 * A voucher that is turned down deletes its lines, which puts those tranches
 * back within reach of the next one.
 */
function voucher_claimable(string $partyType, int $partyId): array
{
    $stmt = db()->prepare(
        "SELECT c.id AS commission_line_id, c.stage, c.amount, c.base_amount, c.gst_amount,
                c.earned_at, a.id, a.reference_code, a.full_name, a.product, a.completed_at
           FROM commission_lines c
           JOIN applications a ON a.id = c.application_id
          WHERE c.party_type = ? AND c.party_id = ? AND c.amount > 0
            /* the same sales commission_release_due() refuses to release: a
               cancelled or refunded sale is not owed to anybody, and the claim
               screen was still offering it */
            AND a.status NOT IN ('rejected', 'cancelled', 'refunded', 'expired')
            AND (c.release_at IS NULL OR c.release_at <= NOW())
            /* There are two ways the money reaches a partner — a voucher through
               C&F, and the office transferring against the payouts desk. A line
               the office has already sent is gone from here, or the same tranche
               would be paid twice. */
            AND c.payout_status NOT IN ('proof_uploaded', 'disbursed')
            AND NOT EXISTS (
                  SELECT 1 FROM commission_voucher_lines l WHERE l.commission_line_id = c.id
                )
          ORDER BY c.earned_at, c.id"
    );
    $stmt->execute([$partyType, $partyId]);

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
        'SELECT l.*, a.reference_code, a.full_name, a.product, a.completed_at,
                c.stage, c.base_amount, c.gst_amount
           FROM commission_voucher_lines l
           JOIN applications a ON a.id = l.application_id
           LEFT JOIN commission_lines c ON c.id = l.commission_line_id
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
            'INSERT INTO commission_voucher_lines
                 (voucher_id, commission_line_id, party_type, party_id, application_id, amount)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($claimable as $row) {
            $line->execute([$voucherId, (int) $row['commission_line_id'], $partyType, $partyId,
                            (int) $row['id'], (float) $row['amount']]);
        }

        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();

        return [0, 'Something was claimed twice while this was being raised. Try again.'];
    }

    voucher_event($voucherId, null, $status, $actor, 'Raised for ' . money($total));

    voucher_notify($voucherId, 'raised');

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

/**
 * Tells whoever the claim is now waiting on that it has moved.
 *
 * Called after every step of the chain (§10). A claim that changes hands in
 * silence is a claim somebody chases by phone, so each move is a letter: to the
 * distributor when a dealer raises one, to the office and C&F as it travels,
 * and to the partner when the money is actually sent.
 */
function voucher_notify(int $voucherId, string $step, string $note = ''): void
{
    $voucher = voucher($voucherId);

    if (!$voucher) {
        return;
    }

    $party = voucher_party($voucher);
    $rows  = [
        'Claim'  => '#' . $voucherId,
        'Whose'  => (string) ($party['full_name'] ?? 'a partner'),
        'Amount' => money((float) $voucher['amount']),
    ];

    if ($note !== '') {
        $rows['Note'] = $note;
    }

    /* who hears about this step, what it says, and where it opens */
    $office = base_url() . '/admin/vouchers';
    $cf     = base_url() . '/cf/';

    $plan = [
        'raised' => $voucher['party_type'] === 'dealer'
            ? ['to' => 'distributor-of-dealer',
               'subject' => 'A dealer has claimed their commission',
               'line' => e((string) ($party['full_name'] ?? 'A dealer'))
                   . ' has a claim filed by the weekly run. Approve it and the run sends it to C&amp;F.',
               'link' => base_url() . '/distributor/payouts']
            : null,
        'bundled' => ['to' => 'cf',
                      'subject' => 'A commission bundle is with you',
                      'line' => 'A distributor has sent their bundle. Check it and send it to the office.',
                      'link' => $cf],
        'with_admin' => ['to' => 'office',
                         'subject' => 'C&F has sent a commission claim over',
                         'line' => 'C&amp;F has checked this bundle. Funding it lets them pay the partners.',
                         'link' => $office],
        'funded' => ['to' => 'cf',
                     'subject' => 'The office has funded a bundle',
                     'line' => 'The office has funded this bundle. It can be paid out now.',
                     'link' => $cf],
        'paid' => ['to' => 'party',
                   'subject' => 'Your commission has been paid',
                   'line' => 'Your commission has been transferred. It shows against you as a payout.',
                   'link' => base_url() . '/portal/'],
        'rejected' => ['to' => 'party',
                       'subject' => 'Your commission claim was sent back',
                       'line' => 'This claim was not taken forward. The sales on it can be claimed again.',
                       'link' => base_url() . '/portal/'],
    ];

    $step = $plan[$step] ?? null;

    if ($step === null) {
        return;
    }

    after_response(static function () use ($step, $voucher, $party, $rows): void {
        mailer();

        if ($step['to'] === 'office') {
            send_to_office($step['subject'], $step['subject'],
                '<p style="margin:0 0 16px;">' . $step['line'] . '</p>' . email_rows($rows),
                'voucher_update');

            return;
        }

        $to = '';

        if ($step['to'] === 'cf') {
            $stmt = db()->query("SELECT email FROM admin_users WHERE role = 'cf' AND is_active = 1 LIMIT 1");
            $to   = (string) ($stmt->fetchColumn() ?: '');
        } elseif ($step['to'] === 'party') {
            $to = (string) ($party['email'] ?? '');
        } elseif ($step['to'] === 'distributor-of-dealer') {
            /* voucher_party() answers "who is owed", not "who they answer to",
               so the dealer's own row is read for their distributor */
            $dealer = dealer_by_id((int) ($party['id'] ?? 0));
            $under  = $dealer ? distributor_by_id((int) $dealer['distributor_id']) : null;
            $to     = (string) ($under['email'] ?? '');
        }

        send_voucher_update_email($to, $step['subject'], $step['line'], $rows, $step['link']);
    });
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
 * carries it to C&F, which is raised separately.
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
function voucher_reject(
    int $voucherId,
    string $actor,
    string $reason = '',
    string $status = 'rejected',
    array $from = [],
    ?int $distributorId = null
): string {
    $voucher = voucher($voucherId);

    if (!$voucher || in_array($voucher['status'], ['paid', 'rejected', 'cancelled'], true)) {
        return 'That voucher has already been settled.';
    }

    /* A desk may only turn down a claim that is actually on it. Without this a
       distributor could reject another distributor's dealer, and C&F could
       reject a claim that had not reached them — voucher_approve_dealer() has
       always checked both and this, its opposite, did not. */
    if ($from !== [] && !in_array($voucher['status'], $from, true)) {
        return 'That voucher is not on your desk.';
    }

    if ($distributorId !== null) {
        $dealer = (string) $voucher['party_type'] === 'dealer'
            ? dealer_by_id((int) $voucher['party_id'])
            : null;

        if (!$dealer || (int) $dealer['distributor_id'] !== $distributorId) {
            return 'That voucher is not yours to decide.';
        }
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

    voucher_notify($voucherId, 'rejected', $reason);

    return '';
}

/**
 * The distributor's bundle: their own claim plus every dealer voucher they have
 * approved, sent to C&F as one document.
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
            'INSERT INTO commission_voucher_lines
                 (voucher_id, commission_line_id, party_type, party_id, application_id, amount)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($claimable as $row) {
            $line->execute([$bundleId, (int) $row['commission_line_id'], 'distributor', $distributorId,
                            (int) $row['id'], (float) $row['amount']]);
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
        voucher_event((int) $child['id'], 'bundled', 'with_rf', $actor, 'Sent to C&F in a bundle');
    }

    voucher_notify($bundleId, 'bundled');

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
 * Every claim at one of the stages before or after the office, whoever raised it.
 *
 * Top-level rows only: a dealer's voucher inside a bundle travels with that
 * bundle and its worth is already counted in the bundle's total, so listing
 * both would show the same money twice. `parent_id IS NULL` is what says a row
 * stands on its own — a dealer's claim waiting on their distributor, one the
 * distributor has approved but not yet sent, or a bundle on its way through
 * C&F. Unlike voucher_bundles() this is not restricted to bundles, which is
 * why the party is looked up in both tables.
 */
function vouchers_standalone(array $statuses): array
{
    $marks = implode(',', array_fill(0, count($statuses), '?'));

    $stmt = db()->prepare(
        "SELECT v.*,
                COALESCE(x.full_name, d.full_name) AS party_name,
                COALESCE(x.distributor_code, d.dealer_code) AS party_code
           FROM commission_vouchers v
           LEFT JOIN distributors x ON x.id = v.party_id AND v.party_type = 'distributor'
           LEFT JOIN dealers d      ON d.id = v.party_id AND v.party_type = 'dealer'
          WHERE v.parent_id IS NULL AND v.status IN (" . $marks . ')
          ORDER BY v.raised_at DESC, v.id DESC'
    );
    $stmt->execute($statuses);

    return $stmt->fetchAll();
}

/**
 * Moves a bundle and everything in it to one status.
 *
 * The children travel with the bundle because they are the same document from
 * every point but the dealer's — one thing for C&F to pay, one thing for the
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

        /* a dealer inside a bundle is told when the money is actually sent —
           the steps before it are between the distributor, C&F and the office */
        if ($to === 'paid') {
            voucher_notify((int) $child['id'], 'paid');
        }
    }

    voucher_notify($bundleId, $to === 'with_admin' || $to === 'funded' || $to === 'paid' ? $to : '');

    return '';
}

/**
 * C&F paying a funded bundle out.
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

    /* The money has gone, so the lines it was raised over are closed. Without
       this a line paid through C&F still read "Released" on every payouts
       screen, as though the office had yet to send it. */
    $ids = [$bundleId];

    foreach (voucher_bundle_children($bundleId) as $child) {
        $ids[] = (int) $child['id'];
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare(
        "UPDATE commission_lines SET payout_status = 'disbursed', disbursed_at = NOW()
          WHERE payout_status <> 'disbursed'
            AND id IN (SELECT commission_line_id FROM commission_voucher_lines
                        WHERE voucher_id IN ($in) AND commission_line_id IS NOT NULL)"
    )->execute($ids);

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

/**
 * What a partner cannot be added without.
 *
 * A dealer or distributor is paid by bank transfer and invoiced against, so the
 * office needs the identity, the address and the account before the first sale,
 * not after it. What is left out is what genuinely may not exist yet: a second
 * mobile, a GST number, the bank's name, and the office's own note.
 */
const PARTNER_REQUIRED = [
    'full_name'     => 'A full name',
    'company'       => 'A company name',
    'email'         => 'An email address',
    'mobile_number' => 'A mobile number',
    'address'       => 'An address',
    'city'          => 'A city',
    'state'         => 'A state',
    'pin_code'      => 'A pin code',
    'pan_number'    => 'A PAN',
    'bank_account'  => 'A bank account number',
    'bank_ifsc'     => 'An IFSC',
    'upi_id'        => 'A UPI id',
];

/**
 * The national digits of a partner's number.
 *
 * A stored number carries the dial code in front of it, so a call can be made
 * from the row; the form shows and sends the national digits on their own. An
 * edit posts back what the row holds, which is why the code comes off again.
 */
function partner_mobile_digits(?string $number): string
{
    $digits = preg_replace('/\D+/', '', (string) $number);
    $code   = DEFAULT_DIAL_CODE;

    return strlen($digits) > dial_digits($code)[1] && str_starts_with($digits, $code)
        ? substr($digits, strlen($code))
        : $digits;
}

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
 * when the request did not come from our form. Everything in PARTNER_REQUIRED
 * has to be there — the browser marks the same fields, and a request that did
 * not come from our form has to meet the same bar.
 */
/**
 * Who already holds an email address — 'dealer', 'distributor', 'client', or
 * null when nobody does.
 *
 * One address, one account. A person is a dealer or a distributor or a
 * customer, never two at once: the portal reads its roles off the session and
 * an address that answers as both would sign one person into two different
 * sets of screens, with two different sets of money attached.
 *
 * $selfRole and $selfId are the record being edited, so saving somebody's own
 * form does not report their own address as taken.
 */
function email_owner(string $email, string $selfRole = '', int $selfId = 0): ?string
{
    $email = mb_strtolower(trim($email));

    if ($email === '') {
        return null;
    }

    foreach ([
        'principal_cf' => 'principal_cf',
        'zonal_cf'     => 'zonal_cf',
        'distributor'  => 'distributors',
        'dealer'       => 'dealers',
    ] as $role => $table) {
        $sql = 'SELECT 1 FROM `' . $table . '` WHERE LOWER(email) = ?';
        $args = [$email];

        if ($role === $selfRole && $selfId > 0) {
            $sql   .= ' AND id <> ?';
            $args[] = $selfId;
        }

        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute($args);

        if ($stmt->fetchColumn()) {
            return $role;
        }
    }

    /* A customer may hold several applications on one address — buying a second
       stove is the same person, not a second account — so an application is
       only ever 'client', never a clash with itself. */
    $stmt = db()->prepare('SELECT 1 FROM applications WHERE LOWER(email) = ? LIMIT 1');
    $stmt->execute([$email]);

    return $stmt->fetchColumn() ? 'client' : null;
}

/** The same fact said to somebody filling a partner form in. */
function email_owner_message(string $owner): string
{
    $labels = [
        'principal_cf' => 'a Principal C&F',
        'zonal_cf'     => 'a Zonal C&F',
        'distributor'  => 'a distributor',
        'dealer'       => 'a dealer',
        'client'       => 'a customer',
    ];

    return 'That email address already belongs to ' . ($labels[$owner] ?? 'somebody else')
        . '. One address, one account — use a different one.';
}

function partner_values(array $post, string $role = '', int $id = 0): array
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

    foreach (PARTNER_REQUIRED as $field => $label) {
        if ($values[$field] === null) {
            return [$values, $label . ' is required.'];
        }
    }

    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        return [$values, 'That email address does not look right.'];
    }

    /* How long a number runs to is the country's business: dial_digits() is the
       same table the apply form is held to. Checked here rather than in each of
       the seven forms that call this, and stored with the code in front. */
    [$dialMin, $dialMax] = dial_digits(DEFAULT_DIAL_CODE);
    $dialSays = $dialMin === $dialMax ? $dialMin . ' digits' : $dialMin . ' to ' . $dialMax . ' digits';

    foreach (['mobile_number' => 'The mobile number',
              'alt_mobile_number' => 'The alternative mobile number'] as $field => $label) {
        if ($values[$field] === null) {
            continue;
        }

        $digits = partner_mobile_digits($values[$field]);

        if (strlen($digits) < $dialMin || strlen($digits) > $dialMax) {
            return [$values, $label . ' has to be ' . $dialSays
                . ' — no country code, no spaces.'];
        }

        $values[$field] = '+' . DEFAULT_DIAL_CODE . $digits;
    }

    /* checked here rather than in each of the seven forms that call this: the
       office adds partners two ways, a distributor adds one more, and both of
       them plus each partner can edit one afterwards */
    $owner = $role === '' ? null : email_owner((string) $values['email'], $role, $id);

    if ($owner !== null) {
        return [$values, email_owner_message($owner)];
    }

    /* Mandatory Terms & Conditions acceptance for partner registration */
    if ($id === 0) {
        if (empty($post['terms_accepted'])) {
            return [$values, 'You must review and accept the '
                . str_replace('&amp;', '&', partner_terms($role)['label']) . ' Terms & Conditions to proceed.'];
        }
        $values['terms_accepted']    = 1;
        $values['terms_accepted_at'] = date('Y-m-d H:i:s');
    }

    return [$values, ''];
}

/* ---------- partner terms ----------
   A customer accepts one set of terms when they apply for an appliance. A partner
   accepts another, and a Principal C&F, a Zonal C&F, a distributor and a dealer
   do different jobs, so each reads the terms for their own.

   Four clauses are the same in every set because the hierarchy is: where disputes
   are heard, the 48-hour payment rule, non-cancellation, and the integrity of a
   partner code. Each is worded for the role it is read by. The rest belong to the
   role alone.

   Items are [heading, html]. The html is written here, never taken from a
   request, so it goes to the page as it is. */

/**
 * The Terms & Conditions one kind of partner agrees to.
 *
 * @return array{label: string, intro: string, summary: string, items: list<array{0: string, 1: string}>}
 */
function partner_terms(string $kind): array
{
    $kind = in_array($kind, ['principal_cf', 'zonal_cf', 'distributor', 'dealer'], true) ? $kind : 'dealer';

    $water = static fn (string $who): array => ['Water Quality &amp; 1,200 TDS Limit',
        'Manifold appliances and clean-fuel kits are engineered for water with Total Dissolved Solids not exceeding '
        . '<strong>1,200 TDS</strong>. ' . $who . ' Running an appliance on water above 1,200 TDS causes scaling, '
        . 'voids the product warranty and releases Manifold from liability for the fault.'];

    $commission = static fn (string $tier, string $on): array => ['Commission, Vouchers &amp; Tax',
        'Commission is paid at the percentage Manifold sets for the ' . $tier . ' tier, on ' . $on . ' excluding GST, '
        . 'after the hold period shown in your portal and by bank transfer against a transfer reference. Manifold may '
        . 'withhold or reverse commission on anything cancelled, refunded or rejected. Income-tax deducted at source '
        . 'is taken as the law requires, and you are responsible for your own PAN, GST and returns.'];

    $conduct = static fn (string $extra): array => ['KYC, Conduct &amp; Suspension',
        'Your identity, PAN, GST and bank details must be true and kept current. ' . $extra . ' You will not misstate '
        . 'a price, a warranty or a performance figure, or take payment outside the portal. Manifold may switch your '
        . 'code off at once for a breach, which stops it booking commission; it may then end the appointment.'];

    /* the four clauses every partner reads, worded for the role */
    $shared = static fn (string $matters, string $payment, string $final, string $chain): array => [
        ['Exclusive Legal Jurisdiction',
            'Any dispute, controversy, claim, or legal proceeding arising under or in connection with partner '
            . 'appointment, territory, orders, commissions, or vouchers' . $matters . ' shall be instituted strictly '
            . 'and exclusively in the competent courts of <strong>Ahmedabad, Gujarat, India</strong>.'],
        ['48-Hour Payment Policy',
            'A loan customer may cancel in the portal for <strong>48 hours</strong> after their booking payment is '
            . 'verified; after that, or once documents are verified, the booking is final. Bookings are not '
            . 'cancelled automatically on a clock, and a full payer has no cancellation window. ' . $payment],
        ['Strict Non-Cancellation Policy',
            'Once applicant KYC and documentation are verified or payments are booked into the system, orders are '
            . 'final and non-cancellable. No refund claims are entertained post-verification. ' . $final],
        ['Hierarchy &amp; Code Integrity',
            'Partner codes are permanent, non-transferable, and bound to the five-tier hierarchy (Manifold Office '
            . '&rarr; Principal C&amp;F &rarr; Zonal C&amp;F &rarr; Distributor &rarr; Dealer). ' . $chain],
    ];

    $customerCancel = 'A loan customer may cancel in the portal for 48 hours after their booking payment is verified, '
        . 'until their documents are verified; you may not promise any other refund.';

    if ($kind === 'principal_cf') {
        $label = 'Principal C&amp;F';
        $own   = [
            ['Appointment &amp; Territory',
                'Manifold appoints you as a Principal C&amp;F, a Tier 2 partner reporting to the Manifold office, for '
                . 'the states or regions agreed in writing. The appointment is non-exclusive unless it says otherwise '
                . 'and does not make you an employee, agent or partner of Manifold.'],
            ['Zonal C&amp;F Oversight',
                'You answer for the Zonal C&amp;Fs you sponsor: the details given for each must be accurate, and a '
                . 'Zonal C&amp;F can operate only once the office approves them. Their conduct, stock and sales '
                . 'are part of your standing with Manifold.'],
            ['Stock Orders &amp; Payment',
                'You buy stock from the Manifold office at the price list in force on the day of the order. An order '
                . 'is paid first, with a payment reference and proof of transfer, and the units are released only '
                . 'when the office approves it. They are yours, and at your risk, from release.'],
            $commission('Principal C&amp;F', 'the value of stock you buy'),
            $water('You will see that every Zonal C&amp;F, distributor and dealer under you tells customers so.'),
            $conduct('Anything you tell a Zonal C&amp;F about price, territory or payout must match these terms.'),
        ];
        $shared = $shared(
            ', including any matter with the Zonal C&amp;Fs sponsored under you,',
            'Payment for a stock order, and the proof of it, is due within 48 hours of the order being raised.',
            'The same is true of your stock orders: once the office approves an order and releases the units, it '
            . 'cannot be cancelled or returned. ' . $customerCancel,
            'You sit at Tier 2: you report to the Manifold Office and Zonal C&amp;Fs report to you.'
        );
    } elseif ($kind === 'zonal_cf') {
        $label = 'Zonal C&amp;F';
        $own   = [
            ['Appointment &amp; Territory',
                'You are appointed as a Zonal C&amp;F, a regional hub sponsored by a Principal C&amp;F and approved by '
                . 'the Manifold office, for the territory agreed in writing. The appointment is non-exclusive unless '
                . 'it says otherwise and does not make you an employee, agent or partner of Manifold.'],
            ['Distributor Network',
                'You manage the distributors assigned to your territory. The details given for each must be accurate, '
                . 'and a distributor can operate only once the office approves them. You may not reassign a '
                . 'distributor to another Zonal C&amp;F yourself.'],
            ['Stock Orders &amp; Payment',
                'You buy stock from the tier above at the price list in force on the day of the order. An order is '
                . 'paid first, with a payment reference and proof of transfer, and the units are released only when '
                . 'it is approved. They are yours, and at your risk, from release.'],
            $commission('Zonal C&amp;F', 'the value of stock you buy'),
            $water('You will see that every distributor and dealer in your territory tells customers so.'),
            $conduct('Anything you tell a distributor about price, territory or payout must match these terms.'),
        ];
        $shared = $shared(
            ', including any matter with the distributors in your territory,',
            'Payment for a stock order, and the proof of it, is due within 48 hours of the order being raised.',
            'The same is true of your stock orders: once an order is approved and the units are released, it '
            . 'cannot be cancelled or returned. ' . $customerCancel,
            'You sit at Tier 3: you report to a Principal C&amp;F and distributors report to you.'
        );
    } elseif ($kind === 'distributor') {
        $label = 'Distributor';
        $own   = [
            ['Appointment &amp; Territory',
                'You are appointed as a distributor, a Tier 4 partner under a Zonal C&amp;F, to sell Manifold '
                . 'appliances and sign up dealers in the area agreed in writing. The appointment is non-exclusive '
                . 'unless it says otherwise and does not make you an employee, agent or partner of Manifold.'],
            ['Dealer Network',
                'You answer for the dealers you sign up: their details must be accurate, a dealer can operate only '
                . 'once the office approves them, and you release stock to them only by approving a paid order.'],
            ['Stock Orders &amp; Payment',
                'You buy stock from the Manifold office at the price list in force on the day of the order. An order '
                . 'is paid first, with a payment reference and proof of transfer, and the units are released only '
                . 'when the office approves it. They are yours, and at your risk, from release.'],
            ['Customer Payments &amp; Proof',
                'On a sale made under your own code the customer pays you and uploads the proof in their portal. You '
                . 'will verify or turn it down, with a reason, promptly and truthfully. The receipt a customer gets '
                . 'is the one the portal issues; you will not issue your own.'],
            $commission('distributor', 'the value of stock you buy'),
            $water('You will see that every dealer under you, and every customer you sell to, is told so.'),
            $conduct('Anything you tell a dealer about price, territory or payout must match these terms.'),
        ];
        $shared = $shared(
            ', including any matter with the dealers you sign up,',
            'A customer proof you receive must be verified or turned down within 48 hours of upload, and payment for '
            . 'a stock order, with its proof, is due within 48 hours of the order being raised.',
            'The same is true of your stock orders: once the office approves an order and releases the units, it '
            . 'cannot be cancelled or returned. ' . $customerCancel,
            'You sit at Tier 4: you report to a Zonal C&amp;F and dealers report to you.'
        );
    } else {
        $label = 'Dealer';
        $own   = [
            ['Appointment &amp; Territory',
                'You are appointed as a dealer, a Tier 5 partner under a distributor, to sell Manifold appliances to '
                . 'customers in the area agreed with them. The appointment is non-exclusive unless it says otherwise '
                . 'and does not make you an employee, agent or partner of Manifold.'],
            ['Stock Orders &amp; Payment',
                'You buy stock from your distributor at the price list in force on the day of the order. An order is '
                . 'paid first, with a payment reference and proof of transfer, and the units are released only when '
                . 'your distributor approves it. They are yours, and at your risk, from release.'],
            ['Customer Payments &amp; Proof',
                'On a sale made under your code the customer pays you and uploads the proof in their portal. You will '
                . 'verify or turn it down, with a reason, promptly and truthfully. The receipt a customer gets is the '
                . 'one the portal issues; you will not issue your own or accept a discount nobody recorded.'],
            $commission('dealer', 'the value of stock you buy'),
            $water('You will tell every customer so before the sale and again at installation.'),
            ['Customer Data &amp; Conduct',
                'A customer\'s details are used only to complete their sale and are never shared or sold. Your '
                . 'identity, PAN, GST and bank details must be true and kept current, and you will not misstate a '
                . 'price, a warranty or a performance figure. Manifold may switch your code off at once for a breach, '
                . 'which stops it booking commission; it may then end the appointment.'],
        ];
        $shared = $shared(
            ', including any matter with your distributor,',
            'A customer proof you receive must be verified or turned down within 48 hours of upload, and payment for '
            . 'a stock order, with its proof, is due within 48 hours of the order being raised.',
            'The same is true of your stock orders: once your distributor approves an order and releases the units, '
            . 'it cannot be cancelled or returned. ' . $customerCancel,
            'You sit at Tier 5: you report to a distributor, and your code is the one customers quote.'
        );
    }

    return [
        'label'   => $label,
        'intro'   => 'These terms are for ' . $label . ' partners only. They are separate from the terms a customer '
            . 'accepts when they apply for an appliance.',
        'summary' => 'the ' . str_replace('&amp;', '&', $label) . ' appointment, stock, commission, '
            . '1,200 TDS water limit, Ahmedabad jurisdiction, 48-hour cancellation window and non-cancellation terms',
        /* the water clause closes every set; usort is stable, so the rest keep their order */
        'items'   => (static function (array $items): array {
            usort($items, static fn (array $a, array $b): int => str_contains($a[0], 'TDS') <=> str_contains($b[0], 'TDS'));
            return $items;
        })([...$own, ...$shared]),
    ];
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

    /* approval_status as well as is_active, exactly as dealer_for_code() and
       zonal_cf_for_code() do: distributor_decide(..., 'rejected') leaves the row
       active, so filtering on is_active alone kept a turned-down distributor's
       code live and still booking them 10 % of every sale that quoted it. */
    $stmt = db()->prepare(
        "SELECT * FROM distributors
          WHERE distributor_code = ? AND is_active = 1 AND approval_status = 'approved' LIMIT 1"
    );
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

/** Principal C&F by code, or null. */
function principal_cf_for_code(string $code): ?array
{
    if ($code === '') {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM principal_cf WHERE principal_code = ? AND is_active = 1 AND approval_status = 'approved' LIMIT 1");
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

/** Principal C&F by id, or null. */
function principal_cf_by_id(int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM principal_cf WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Zonal C&F by code, or null. */
function zonal_cf_for_code(string $code): ?array
{
    if ($code === '') {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM zonal_cf WHERE zonal_code = ? AND is_active = 1 AND approval_status = 'approved' LIMIT 1");
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

/** Zonal C&F by id, or null. */
function zonal_cf_by_id(int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM zonal_cf WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** The Zonal C&F a distributor answers to, or null. */
function zonal_cf_for_distributor(?array $distributor): ?array
{
    $id = (int) ($distributor['zonal_cf_id'] ?? 0);
    return $id > 0 ? zonal_cf_by_id($id) : null;
}

/** The Principal C&F a Zonal C&F answers to, or null. */
function principal_cf_for_zonal(?array $zonal): ?array
{
    $id = (int) ($zonal['principal_cf_id'] ?? 0);
    return $id > 0 ? principal_cf_by_id($id) : null;
}

/**
 * Resolves any partner code (Dealer, Distributor, Zonal C&F, Principal C&F)
 * into its full mapped hierarchy chain.
 */
function partner_hierarchy_for_code(string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }

    $dealer       = null;
    $distributor  = null;
    $zonalCf      = null;
    $principalCf  = null;
    $tier         = null;

    if (str_starts_with($code, 'MD')) {
        $dealer = dealer_for_code($code);
        if (!$dealer) {
            return null;
        }
        $tier = 'dealer';
        $distributor = distributor_for_dealer($dealer);
        if ($distributor) {
            $zonalCf = zonal_cf_for_distributor($distributor);
            if ($zonalCf) {
                $principalCf = principal_cf_for_zonal($zonalCf);
            }
        }
    } elseif (str_starts_with($code, 'MX')) {
        $distributor = distributor_for_code($code);
        if (!$distributor) {
            return null;
        }
        $tier = 'distributor';
        $zonalCf = zonal_cf_for_distributor($distributor);
        if ($zonalCf) {
            $principalCf = principal_cf_for_zonal($zonalCf);
        }
    } elseif (str_starts_with($code, 'MZ')) {
        $zonalCf = zonal_cf_for_code($code);
        if (!$zonalCf) {
            return null;
        }
        $tier = 'zonal_cf';
        $principalCf = principal_cf_for_zonal($zonalCf);
    } elseif (str_starts_with($code, 'MP')) {
        $principalCf = principal_cf_for_code($code);
        if (!$principalCf) {
            return null;
        }
        $tier = 'principal_cf';
    } else {
        return null;
    }

    return [
        'entered_code' => $code,
        'tier'         => $tier,
        'dealer'       => $dealer ? [
            'id'      => (int) $dealer['id'],
            'code'    => $dealer['dealer_code'],
            'name'    => $dealer['full_name'],
            'company' => $dealer['company'] ?? '',
        ] : null,
        'distributor'  => $distributor ? [
            'id'      => (int) $distributor['id'],
            'code'    => $distributor['distributor_code'],
            'name'    => $distributor['full_name'],
            'company' => $distributor['company'] ?? '',
        ] : null,
        'zonal_cf'     => $zonalCf ? [
            'id'      => (int) $zonalCf['id'],
            'code'    => $zonalCf['zonal_code'],
            'name'    => $zonalCf['full_name'],
            'company' => $zonalCf['company'] ?? '',
        ] : null,
        'principal_cf' => $principalCf ? [
            'id'      => (int) $principalCf['id'],
            'code'    => $principalCf['principal_code'],
            'name'    => $principalCf['full_name'],
            'company' => $principalCf['company'] ?? '',
        ] : null,
    ];
}

/**
 * Commission hierarchy percentage for a given tier — or, when a party id is
 * given, that one partner's own rate if their upline (or the office) set one
 * (the `commission_rate` column; NULL means the tier default).
 */
function commission_rate(string $partyType, ?int $partyId = null): float
{
    static $own = [];

    $table = [
        'dealer'       => 'dealers',
        'distributor'  => 'distributors',
        'zonal_cf'     => 'zonal_cf',
        'principal_cf' => 'principal_cf',
    ][$partyType] ?? '';

    if ($table !== '' && $partyId) {
        $key = $partyType . ':' . $partyId;

        if (!array_key_exists($key, $own)) {
            $stmt = db()->prepare("SELECT commission_rate FROM $table WHERE id = ?");
            $stmt->execute([$partyId]);
            $own[$key] = $stmt->fetchColumn();
        }

        if ($own[$key] !== false && $own[$key] !== null) {
            return max(0.0, (float) $own[$key]);
        }
    }

    $default = COMMISSION_RATES_DEFAULT[$partyType] ?? 0.0;
    return max(0.0, (float) setting('commission_rate_' . $partyType, (string) $default));
}

/** Commission auto-release hours from settings. */
function commission_release_hours(): int
{
    return max(1, (int) setting('commission_release_hours', (string) COMMISSION_RELEASE_HOURS_DEFAULT));
}

/** Customer payment deadline hours from settings. */
function payment_deadline_hours(): int
{
    return max(1, (int) setting('payment_deadline_hours', (string) PAYMENT_DEADLINE_HOURS_DEFAULT));
}

/** Stock warning threshold (yellow) in units. */
function stock_warn_units(): int
{
    return max(1, (int) setting('stock_warn_units', (string) STOCK_WARN_UNITS_DEFAULT));
}

/** Stock critical threshold (red & requires approval) in units. */
function stock_critical_units(): int
{
    return max(1, (int) setting('stock_critical_units', (string) STOCK_CRITICAL_UNITS_DEFAULT));
}

/** Everyone who applied with one distributor's own code or through their dealers. */
/**
 * How many rows a drawer's own tables carry.
 *
 * The drawer is fetched whole and its tables page in the browser, so every row
 * is in the HTML. A partner with five hundred sales made that 350KB for a panel
 * nobody scrolls to the end of; the newest fifty answer the question the drawer
 * is opened to answer, and the list pages hold the rest.
 */
const DRAWER_LIST_LIMIT = 50;

/** How many sales a partner has altogether, for when only fifty are shown. */
function partner_client_count(string $who, int $id): int
{
    $column = $who === 'distributor' ? 'distributor_id' : 'dealer_id';

    $stmt = db()->prepare('SELECT COUNT(*) FROM applications WHERE ' . $column . ' = ?');
    $stmt->execute([$id]);

    return (int) $stmt->fetchColumn();
}

function distributor_clients(int $distributorId, int $limit = DRAWER_LIST_LIMIT): array
{
    $stmt = db()->prepare(
        'SELECT a.id, a.reference_code, a.full_name, a.email, a.mobile_number, a.product, a.status,
                a.created_at, a.booking_paid_at, a.delivery_paid_at, a.completed_at,
                a.dealer_commission, a.distributor_commission, a.dealer_id,
                a.kyc_verified_at, a.docs_verified_at, a.payment_deadline_at, a.cancellation_locked, a.payment_plan,
                d.full_name AS dealer_name, d.dealer_code
           FROM applications a
           LEFT JOIN dealers d ON d.id = a.dealer_id
          WHERE a.distributor_id = ?
          ORDER BY a.created_at DESC
          LIMIT ' . max(1, $limit)
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
function dealer_clients(int $dealerId, int $limit = DRAWER_LIST_LIMIT): array
{
    $stmt = db()->prepare(
        'SELECT id, reference_code, full_name, email, mobile_number, product, status,
                created_at, booking_paid_at, delivery_paid_at, completed_at, dealer_commission,
                kyc_verified_at, docs_verified_at, payment_deadline_at, cancellation_locked, payment_plan
           FROM applications
          WHERE dealer_id = ?
          ORDER BY created_at DESC
          LIMIT ' . max(1, $limit)
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
    $column  = $who === 'distributor' ? 'distributor_id' : 'dealer_id';

    $stmt = db()->prepare(
        'SELECT COUNT(*) AS sales,
                COALESCE(SUM(' . COMMISSION_EARNED_SQL . '), 0) AS confirmed
           FROM applications
          WHERE ' . $column . ' = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch() ?: [];

    /* Paid is what has reached them: lines disbursed, whether by a pass-down
       transfer, a voucher C&F settled before those were retired, or the office's
       old direct transfers. The payout tables only ever held the voucher ones. */
    $paidStmt = db()->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM commission_lines
          WHERE party_type = ? AND party_id = ? AND payout_status = 'disbursed'"
    );
    $paidStmt->execute([$who, $id]);

    /* Earned is the sum of the tranches actually earned — one definition, read
       from `commission_lines` by every screen and every voucher alike. */
    $earned = commission_earned($who, $id);
    $paid   = (float) $paidStmt->fetchColumn();

    return [
        'sales'     => (int) ($row['sales'] ?? 0),
        'confirmed' => (int) ($row['confirmed'] ?? 0),
        'earned'    => $earned,
        /* riding on tranches not yet paid: not owed, but worth seeing */
        'pipeline'  => commission_pipeline($who, $id),
        'paid'      => $paid,
        /* an overpayment reads as nothing owed rather than a negative figure */
        'remaining' => max(0.0, round($earned - $paid, 2)),
    ];
}

/** What one distributor has sold, earned, been paid and is still owed. */
function distributor_totals(int $distributorId): array
{
    return commission_totals('distributor', $distributorId);
}

/**
 * commission_totals() for a whole list of ids at once.
 *
 * The dealers and distributors lists call commission_totals() once per row —
 * four queries each, forty-odd serial round-trips to a remote database for one
 * ten-row page. This is the same figures read in four GROUP BY queries no
 * matter how many rows, keyed by id. The per-id function stays for everywhere
 * that wants one; this is only the list's shortcut, and returns byte-identical
 * arrays (verified in tests/section-e.php).
 */
function commission_totals_map(string $who, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));

    if (!$ids) {
        return [];
    }

    $column  = $who === 'distributor' ? 'distributor_id' : 'dealer_id';
    $frozen = $who === 'distributor' ? 'distributor_commission' : 'dealer_commission';
    $in      = implode(', ', array_fill(0, count($ids), '?'));

    $agg = static function (string $sql, array $args) use ($column): array {
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r[$column]] = $r;
        }
        return $out;
    };

    $sales = $agg(
        'SELECT ' . $column . ' AS ' . $column . ', COUNT(*) AS sales,
                COALESCE(SUM(' . COMMISSION_EARNED_SQL . '), 0) AS confirmed
           FROM applications WHERE ' . $column . ' IN (' . $in . ') GROUP BY ' . $column,
        $ids
    );
    /* the same definition of paid as commission_totals(): disbursed lines */
    $paid = $agg(
        'SELECT party_id AS ' . $column . ", COALESCE(SUM(amount), 0) AS paid
           FROM commission_lines WHERE party_type = ? AND payout_status = 'disbursed'
            AND party_id IN (" . $in . ') GROUP BY party_id',
        array_merge([$who], $ids)
    );
    $pipe = $agg(
        'SELECT ' . $column . ' AS ' . $column . ", COALESCE(SUM(" . $frozen . "), 0) AS pipeline
           FROM applications
          WHERE " . $column . ' IN (' . $in . ") AND status <> 'rejected' AND delivery_paid_at IS NULL
          GROUP BY " . $column,
        $ids
    );

    /* earned reads from commission_lines, keyed on party_id — the same source
       and rounding as commission_earned(), one query for the whole page */
    $earnStmt = db()->prepare(
        'SELECT party_id, COALESCE(SUM(amount), 0) AS earned
           FROM commission_lines
          WHERE party_type = ? AND party_id IN (' . $in . ') GROUP BY party_id'
    );
    $earnStmt->execute(array_merge([$who], $ids));
    $earn = [];
    foreach ($earnStmt->fetchAll() as $r) {
        $earn[(int) $r['party_id']] = (float) $r['earned'];
    }

    $map = [];
    foreach ($ids as $id) {
        $earned = round($earn[$id] ?? 0.0, 2);
        $paidId = (float) ($paid[$id]['paid'] ?? 0);
        $map[$id] = [
            'sales'     => (int) ($sales[$id]['sales'] ?? 0),
            'confirmed' => (int) ($sales[$id]['confirmed'] ?? 0),
            'earned'    => $earned,
            'pipeline'  => round((float) ($pipe[$id]['pipeline'] ?? 0), 2),
            'paid'      => $paidId,
            'remaining' => max(0.0, round($earned - $paidId, 2)),
        ];
    }

    return $map;
}

/**
 * distributor_dealers() for a whole list of distributors at once — one query,
 * grouped in PHP, keeping each distributor's own newest-first ordering.
 */
function distributor_dealers_map(array $distributorIds): array
{
    $ids = array_values(array_unique(array_map('intval', $distributorIds)));

    if (!$ids) {
        return [];
    }

    $in   = implode(', ', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        'SELECT * FROM dealers WHERE distributor_id IN (' . $in . ') ORDER BY is_active DESC, full_name'
    );
    $stmt->execute($ids);

    $map = array_fill_keys($ids, []);
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['distributor_id']][] = $row;
    }

    return $map;
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

/**
 * The finance team's verdict on an application's paperwork.
 *
 * It is the one step between the booking payment and the delivery payment.
 * Moving it re-reads the payments, so the status lands wherever the money says
 * it should — delivery due, or complete.
 *
 * Returns ['error' => …] or ['message' => …, 'status' => …].
 */
/**
 * Whether a refusal of the paperwork is still the last word on it.
 *
 * The applicant answers a refusal from their portal by sending corrected
 * documents, which stamps `docs_resent_at` — so a refusal only still stands
 * while nothing newer has arrived. Both sides read this: the portal draws the
 * reason and the correction form from it, the office draws the red stage.
 */
function docs_refused(array $app): bool
{
    return empty($app['docs_verified_at'])
        && !empty($app['docs_rejected_at'])
        && !docs_resent($app);
}

/** The other half: corrected documents are in and finance has not looked yet. */
function docs_resent(array $app): bool
{
    if (!empty($app['docs_verified_at']) || empty($app['docs_resent_at'])) {
        return false;
    }

    return empty($app['docs_rejected_at'])
        || strtotime((string) $app['docs_resent_at']) >= strtotime((string) $app['docs_rejected_at']);
}

function docs_verify(int $applicationId, int $adminId, ?string $verifierLabel = null): array
{
    $stmt = db()->prepare('SELECT id, status, docs_verified_at, kyc_verified_at, payment_deadline_at, payment_plan FROM applications WHERE id = ?');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch();

    if (!$app) {
        return ['error' => 'That application no longer exists.'];
    }

    if ($app['status'] === 'rejected') {
        return ['error' => 'That application was turned down.'];
    }

    if (!empty($app['docs_verified_at']) || !empty($app['kyc_verified_at'])) {
        return ['error' => 'The documents were already verified.'];
    }

    $verifier = $verifierLabel ?: 'Staff #' . $adminId;
    $fullPayer = is_full_payment($app);

    /* Verifying clears an earlier refusal and locks cancellation for good: the
       window to cancel ends here, whatever time was left on it. */
    db()->prepare(
        'UPDATE applications
            SET docs_verified_at = NOW(), docs_verified_by = ?,
                kyc_verified_at = NOW(), kyc_verified_by = ?,
                cancellation_locked = 1,
                docs_rejected_at = NULL, docs_rejected_by = NULL, docs_reject_reason = NULL,
                kyc_reject_reason = NULL,
                docs_resent_at = NULL
          WHERE id = ?'
    )->execute([$adminId, $verifier, $applicationId]);

    $status = sync_application_status($applicationId);

    return [
        'message' => $status === 'complete'
            ? 'Documents verified. Everything on this sale is done.'
            : 'Documents verified. Cancellation is locked' . ($fullPayer ? '.' : ' and the delivery payment is open.'),
        'status'  => $status,
    ];
}

/**
 * The same verdict, the other way: the paperwork does not pass.
 */
function docs_reject(int $applicationId, int $adminId, string $reason): array
{
    $reason = trim($reason);

    if ($reason === '') {
        return ['error' => 'Say what is wrong with the documents — the applicant is emailed the reason.'];
    }

    $stmt = db()->prepare('SELECT id, status, docs_verified_at FROM applications WHERE id = ?');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch();

    if (!$app) {
        return ['error' => 'That application no longer exists.'];
    }

    if ($app['status'] === 'rejected') {
        return ['error' => 'That application was turned down.'];
    }

    $reason = mb_substr($reason, 0, 255);

    db()->prepare(
        'UPDATE applications
            SET docs_rejected_at = NOW(), docs_rejected_by = ?, docs_reject_reason = ?,
                kyc_reject_reason = ?,
                docs_verified_at = NULL, docs_verified_by = NULL,
                kyc_verified_at = NULL, kyc_verified_by = NULL
          WHERE id = ?'
    )->execute([$adminId, $reason, $reason, $applicationId]);

    return [
        'message' => 'Documents turned down. The applicant has been emailed the reason.',
        'reason'  => $reason,
    ];
}

/** An application the office has let lapse. Nothing expires on a clock any more. */
function is_application_expired(array $app): bool
{
    return ($app['status'] ?? '') === 'expired';
}

/**
 * Whether the client can still cancel and take their money back.
 *
 * Only a loan customer, only between a verified booking payment and verified
 * documents, and only for the 48 hours (payment_deadline_at, stamped when the
 * booking was verified) after it. A full payer has no such window.
 */
function cancel_window_open(array $app): bool
{
    return !is_full_payment($app)
        && ($app['status'] ?? '') === 'docs_pending'
        && empty($app['cancellation_locked'])
        && empty($app['docs_verified_at']) && empty($app['kyc_verified_at'])
        && !empty($app['payment_deadline_at'])
        && strtotime((string) $app['payment_deadline_at']) > time();
}

/**
 * Partner direct payment collection with document verification precondition.
 *
 * Enforces:
 * 1. Application exists and is not rejected/expired.
 * 2. For a loan customer, KYC documents (Aadhaar & Electricity Bill) MUST be verified before payment
 *    can be collected; a full payer has no finance documents and is exempt.
 * 3. Records verified payment in payments table with receipt number.
 * 4. Locks cancellation (cancellation_locked = 1).
 * 5. Advances application status.
 */
function partner_collect_payment(
    int $applicationId,
    string $collectorType,
    int $collectorId,
    string $stage,
    float $amount,
    string $paymentMode,
    ?string $reference,
    ?string $proofPath = null
): array {
    $stmt = db()->prepare('SELECT * FROM applications WHERE id = ?');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch();

    if (!$app) {
        return ['error' => 'Application not found.'];
    }

    if (in_array($app['status'], ['rejected', 'cancelled', 'refunded'], true)) {
        return ['error' => 'Cannot collect payment on a closed or rejected application.'];
    }

    if (is_application_expired($app)) {
        return ['error' => 'This booking has expired.'];
    }

    // MANDATORY PRECONDITION: KYC documents must be verified — a loan customer only;
    // a full payer has no finance documents to verify
    if (!is_full_payment($app) && empty($app['kyc_verified_at']) && empty($app['docs_verified_at'])) {
        return ['error' => 'Customer KYC documents (Aadhaar Card and Electricity Bill) must be verified before payment can be collected.'];
    }

    // MANDATORY PRECONDITION: a low-stock sale needs both approvals first
    if (low_stock_blocked($app)) {
        return ['error' => low_stock_block_reason()];
    }

    if (!in_array($stage, ['booking', 'delivery', 'full'], true)) {
        return ['error' => 'Invalid payment stage.'];
    }

    if ($amount <= 0) {
        return ['error' => 'Payment amount must be greater than zero.'];
    }

    $receipt = next_receipt_no($app);
    $refText = trim(($reference ?: 'Direct-' . strtoupper($paymentMode)) . " [Collected by {$collectorType} #{$collectorId}]");

    db()->prepare(
        'INSERT INTO payments (application_id, stage, amount, reference, proof_path, status, receipt_no, decided_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    )->execute([
        $applicationId,
        $stage,
        $amount,
        $refText,
        $proofPath,
        'verified',
        $receipt,
    ]);

    $updateSql = 'UPDATE applications SET cancellation_locked = 1, sale_channel = \'direct\'';
    $params = [];

    if ($stage === 'full') {
        /* freeze the whole price on the row, as submit does for a full payer */
        $updateSql .= ', payment_plan = \'full_payment\', payment_amount = ?, loan_amount = 0, booking_paid_at = NOW(), delivery_paid_at = NOW()';
        $params[] = stage_amount($app, 'full');
    } elseif ($stage === 'booking') {
        $updateSql .= ', booking_paid_at = NOW()';
    } elseif ($stage === 'delivery') {
        $updateSql .= ', delivery_paid_at = NOW()';
    }

    if ($collectorType === 'dealer') {
        $updateSql .= ', entered_by_dealer = ?';
        $params[] = $collectorId;
    } elseif ($collectorType === 'distributor') {
        $updateSql .= ', entered_by_distributor = ?';
        $params[] = $collectorId;
    }

    $updateSql .= ' WHERE id = ?';
    $params[] = $applicationId;

    db()->prepare($updateSql)->execute($params);

    $newStatus = sync_application_status($applicationId);

    /* Every field the receipt email and the PDF read. They were short of
       'reference' and 'uploaded_at' before, which logged a warning on each
       collection and left those lines blank on the customer's copy. */
    $paymentRecord = [
        'stage'       => $stage,
        'amount'      => $amount,
        'receipt_no'  => $receipt,
        'status'      => 'verified',
        'reference'   => $refText,
        'proof_path'  => $proofPath,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'decided_at'  => date('Y-m-d H:i:s'),
    ];

    $totals = payment_totals($app);

    after_response(static function () use ($app, $paymentRecord, $totals): void {
        send_receipt_email($app, $paymentRecord, $totals);
    });

    return [
        'ok'         => true,
        'success'    => true,
        'message'    => "Payment of " . money($amount) . " collected and verified. Receipt {$receipt} issued.",
        'receipt'    => $receipt,
        'receipt_no' => $receipt,
        'status'     => $newStatus,
    ];
}

/**
 * Whether this partner is the seller a customer pays on this sale.
 *
 * A dealer decides their own sales. A distributor decides only their direct
 * sales — a sale through one of their dealers is paid to, and decided by, that
 * dealer, even though the distributor's id is on the row for the override.
 */
function partner_sold(array $app, string $type, int $partnerId): bool
{
    if ($partnerId < 1) {
        return false;
    }

    if ($type === 'dealer') {
        return (int) ($app['dealer_id'] ?? 0) === $partnerId;
    }

    if ($type === 'distributor') {
        return empty($app['dealer_id']) && (int) ($app['distributor_id'] ?? 0) === $partnerId;
    }

    return false;
}

/**
 * Who the customer pays on a sale made with a partner's code: the dealer if
 * there is one, else the distributor. Null means they pay the company.
 */
function application_payee(array $app): ?array
{
    if (!empty($app['dealer_id'])) {
        $row  = dealer_by_id((int) $app['dealer_id']);
        $kind = 'dealer';
        $code = $row['dealer_code'] ?? '';
    } elseif (!empty($app['distributor_id'])) {
        $row  = distributor_by_id((int) $app['distributor_id']);
        $kind = 'distributor';
        $code = $row['distributor_code'] ?? '';
    } else {
        return null;
    }

    if (!$row) {
        return null;
    }

    $out = ['kind' => ucfirst($kind), 'type' => $kind, 'id' => (int) $row['id'], 'code' => (string) $code];

    foreach (['full_name', 'mobile_number', 'alt_mobile_number', 'email', 'upi_id',
              'bank_name', 'bank_account', 'bank_ifsc'] as $column) {
        $out[$column] = $row[$column] ?? null;
    }

    $out['full_name'] = (string) $out['full_name'];

    return $out;
}

/** Customer-uploaded receipts waiting on this partner's decision, oldest first. */
function partner_pending_proofs(string $type, int $partnerId): array
{
    $where = [
        'dealer'      => 'a.dealer_id = ?',
        'distributor' => 'a.distributor_id = ? AND a.dealer_id IS NULL',
    ][$type] ?? null;

    if ($where === null) {
        return [];
    }

    $stmt = db()->prepare(
        "SELECT p.id, p.stage, p.amount, p.reference, p.proof_path, p.uploaded_at,
                a.id AS application_id, a.reference_code, a.full_name, a.product, a.payment_plan
           FROM payments p
           JOIN applications a ON a.id = p.application_id
          WHERE p.status = 'pending' AND $where
          ORDER BY p.uploaded_at, p.id"
    );
    $stmt->execute([$partnerId]);

    return $stmt->fetchAll();
}

/**
 * Verify or turn down one uploaded receipt.
 *
 * One path for the office and for the partner a customer paid directly, so the
 * status, receipt number, emails and commission that follow are identical
 * whoever decides.
 *
 * $actor = ['type' => 'admin'|'dealer'|'distributor', 'id' => int, 'label' => ?string].
 * A partner may only decide a payment on a sale partner_sold() gives them.
 *
 * Returns ['error' => …, 'code' => http status] or
 *         ['ok' => true, 'status' => …, 'payment' => …, 'receipt' => ?string].
 */
function payment_decide(int $paymentId, string $decision, array $actor, string $reason = ''): array
{
    $type    = (string) ($actor['type'] ?? '');
    $actorId = (int) ($actor['id'] ?? 0);

    if (!in_array($decision, ['verify', 'reject'], true)) {
        return ['error' => 'Unknown decision.', 'code' => 422];
    }

    $stmt = db()->prepare('SELECT * FROM payments WHERE id = ?');
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
    $app     = $payment ? application_by_id((int) $payment['application_id']) : null;

    if (!$payment || !$app) {
        return ['error' => 'Payment not found.', 'code' => 404];
    }

    if (!($type === 'admin' && $actorId > 0) && !partner_sold($app, $type, $actorId)) {
        return ['error' => 'That payment is not yours to decide.', 'code' => 403];
    }

    if ($payment['status'] !== 'pending') {
        return ['error' => 'That payment has already been decided.', 'code' => 409];
    }

    $reason = trim($reason);

    /* the applicant is emailed this, and it is the whole of what they are told */
    if ($decision === 'reject' && $reason === '') {
        return ['error' => 'Say why the payment is being turned down - the applicant is emailed the reason.', 'code' => 422];
    }

    /* decided_by is an office user's key; a partner is not one, so their name
       goes on the reference instead, the way partner_collect_payment() does */
    $adminId   = $type === 'admin' ? $actorId : null;
    $reference = $payment['reference'];

    if ($type !== 'admin') {
        $who       = trim((string) ($actor['label'] ?? '')) ?: $type . ' #' . $actorId;
        $reference = mb_substr(trim((string) $reference . ' [' . ($decision === 'verify' ? 'Verified' : 'Turned down')
            . ' by ' . $who . ']'), 0, 120);
    }

    $applicationId = (int) $app['id'];
    mailer();

    if ($decision === 'verify') {
        $receipt = next_receipt_no($app);

        $upd = db()->prepare(
            "UPDATE payments
                SET status = 'verified', receipt_no = ?, decided_at = NOW(), decided_by = ?,
                    reject_reason = NULL, reference = ?
              WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([$receipt, $adminId, $reference, $paymentId]);

        /* two people deciding at once: only the first one counts */
        if ($upd->rowCount() !== 1) {
            return ['error' => 'That payment has already been decided.', 'code' => 409];
        }

        $status = sync_application_status($applicationId);

        $payment['status']     = 'verified';
        $payment['receipt_no'] = $receipt;
        $payment['reference']  = $reference;
        $payment['decided_at'] = date('Y-m-d H:i:s');

        $totals = payment_totals($app);

        /* the receipt carries a generated PDF and takes seconds to send */
        after_response(static function () use ($app, $payment, $totals): void {
            send_receipt_email($app, $payment, $totals);
        });

        return ['ok' => true, 'status' => $status, 'payment' => $payment, 'receipt' => $receipt];
    }

    $upd = db()->prepare(
        "UPDATE payments
            SET status = 'rejected', reject_reason = ?, decided_at = NOW(), decided_by = ?, reference = ?
          WHERE id = ? AND status = 'pending'"
    );
    $upd->execute([mb_substr($reason, 0, 255), $adminId, $reference, $paymentId]);

    if ($upd->rowCount() !== 1) {
        return ['error' => 'That payment has already been decided.', 'code' => 409];
    }

    $status = sync_application_status($applicationId);

    /* the proof is no longer evidence of anything — take it off disk */
    if (!empty($payment['proof_path'])) {
        $path = PAYMENT_PROOF_DIR . '/' . basename((string) $payment['proof_path']);

        if (is_file($path)) {
            unlink($path);
        }
    }

    $payment['status'] = 'rejected';
    $rejectTotals      = payment_totals($app);

    after_response(static function () use ($app, $reason, $payment, $rejectTotals): void {
        send_payment_rejected_email($app, $reason, $payment, $rejectTotals);
    });

    return ['ok' => true, 'status' => $status, 'payment' => $payment, 'receipt' => null];
}

/**
 * A partner dashboard's Approve / Turn down on a customer's payment proof.
 * Returns the error to show, or redirects back with a flash on success.
 */
function partner_proofs_handle(string $type, int $partnerId, string $label, string $flashKey): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST'
        || !in_array($_POST['action'] ?? '', ['proof_verify', 'proof_reject'], true)) {
        return '';
    }

    csrf_check();

    $decision = $_POST['action'] === 'proof_verify' ? 'verify' : 'reject';
    $done     = payment_decide(
        (int) ($_POST['payment_id'] ?? 0),
        $decision,
        ['type' => $type, 'id' => $partnerId, 'label' => $label],
        (string) ($_POST['reason'] ?? '')
    );

    if (isset($done['error'])) {
        return $done['error'];
    }

    $_SESSION[$flashKey] = $decision === 'verify'
        ? 'Payment verified. Receipt ' . $done['receipt'] . ' is on its way to the customer.'
        : 'Payment turned down. The customer has been emailed your reason.';

    header('Location: ./#proofs');
    exit;
}

/**
 * Streams the proof file of one payment to the partner it was paid to, or 404.
 * The file comes from the payments row, never from the request.
 */
function serve_partner_payment_proof(string $type, int $partnerId, int $paymentId): void
{
    $stmt = db()->prepare('SELECT application_id, proof_path FROM payments WHERE id = ?');
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
    $app     = $payment ? application_by_id((int) $payment['application_id']) : null;

    $name = $payment ? basename((string) $payment['proof_path']) : '';
    $full = PAYMENT_PROOF_DIR . '/' . $name;

    if (!$app || $name === '' || !partner_sold($app, $type, $partnerId) || !is_file($full)
        || strpos(realpath($full) ?: '', realpath(PAYMENT_PROOF_DIR) ?: "\0") !== 0) {
        http_response_code(404);
        exit('File not found.');
    }

    $mime = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
    ][strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($full);
    exit;
}

/**
 * Where a sale came from, for a column that answers "who brought this in".
 *
 * Four answers: a partner filled the form themselves, somebody used a partner's
 * link, a customer's referral code was quoted, or it came in off the website
 * with nothing attached.
 */
function sale_source(array $app): array
{
    /* A row from a list carries ids, not codes. Each one is a primary-key read
       and the answers are kept for the request, so ten rows of the same dealer
       ask once. */
    static $codes = [];

    $code = static function (string $kind, $id) use (&$codes): string {
        $id = (int) $id;

        if ($id < 1) {
            return '';
        }

        $key = $kind . ':' . $id;

        if (!array_key_exists($key, $codes)) {
            $column = $kind === 'distributor' ? 'distributor_code' : 'dealer_code';
            $table  = $kind === 'distributor' ? 'distributors' : 'dealers';

            $stmt = db()->prepare('SELECT ' . $column . ' FROM ' . $table . ' WHERE id = ?');
            $stmt->execute([$id]);

            $codes[$key] = (string) ($stmt->fetchColumn() ?: '');
        }

        return $codes[$key];
    };

    $dealer      = (string) ($app['dealer_code'] ?? '') ?: $code('dealer', $app['dealer_id'] ?? 0);
    $distributor = (string) ($app['distributor_code'] ?? '')
        ?: $code('distributor', $app['distributor_id'] ?? 0);

    if (($app['sale_channel'] ?? 'online') === 'direct') {
        return !empty($app['entered_by_distributor'])
            ? ['label' => 'Distributor form', 'code' => $distributor]
            : ['label' => 'Dealer form', 'code' => $dealer];
    }

    /* Typed in from a spreadsheet rather than filled in by the customer. The
       office still collects, so this is an ordinary online sale and falls past
       the 'direct' branch above — but it did not come off the website, and a row
       that said "Website" would send somebody looking for a form submission that
       never happened. */
    if (!empty($app['entered_by_dealer'])) {
        return ['label' => 'Dealer import', 'code' => $dealer];
    }

    if (!empty($app['entered_by_distributor'])) {
        return ['label' => 'Distributor import', 'code' => $distributor];
    }

    /* One box on the form takes any code, so what was typed decides what this
       says: MF is another customer's, MD a dealer's, MX a distributor's. */
    $quoted = strtoupper((string) ($app['referred_by_code'] ?? ''));

    if ($quoted !== '') {
        $prefix = substr($quoted, 0, 2);

        $label = $prefix === 'MD'
            ? 'Dealer link'
            : ($prefix === 'MX' ? 'Distributor link' : 'Referral');

        return ['label' => $label, 'code' => $quoted];
    }

    if ($dealer !== '') {
        return ['label' => 'Dealer link', 'code' => $dealer];
    }

    if ($distributor !== '') {
        return ['label' => 'Distributor link', 'code' => $distributor];
    }

    return ['label' => 'Website', 'code' => ''];
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
/**
 * The dashboard's latest activity: one row per record, newest activity first.
 *
 * "Activity" is either a record arriving or its status moving — every
 * application status change is written to status_log by sync_application_status,
 * so the most recent of a record's creation and its logged changes is when it
 * last did something. Records are returned whole and current (the live row, not
 * a snapshot), so the dashboard renders them with the same cells, status pill
 * and row actions the form lists use. Each item is ['type' => stove|tuktuk|
 * contact|newsletter, 'row' => the record].
 */
function recent_activity(int $limit = 15): array
{
    $limit = max(1, $limit);

    /* one line per record, timestamped by its latest activity */
    $index = db()->query(
        "SELECT type, id, MAX(at) AS at FROM (
            SELECT product AS type, id, created_at AS at FROM applications
            UNION ALL
            SELECT a.product, a.id, sl.changed_at
              FROM status_log sl JOIN applications a ON a.id = sl.entity_id
             WHERE sl.entity = 'application'
            UNION ALL
            SELECT product, id, docs_resent_at FROM applications WHERE docs_resent_at IS NOT NULL
            UNION ALL
            SELECT 'contact', id, created_at FROM contact_messages
            UNION ALL
            SELECT 'contact', entity_id, changed_at FROM status_log WHERE entity = 'contact'
            UNION ALL
            SELECT 'newsletter', id, created_at FROM newsletter_subscribers
            UNION ALL
            SELECT 'newsletter', entity_id, changed_at FROM status_log WHERE entity = 'newsletter'
         ) e
         GROUP BY type, id
         ORDER BY at DESC
         LIMIT {$limit}"
    )->fetchAll();

    if (!$index) {
        return [];
    }

    $appIds = $contactIds = $newsIds = [];

    foreach ($index as $r) {
        if ($r['type'] === 'contact') {
            $contactIds[] = (int) $r['id'];
        } elseif ($r['type'] === 'newsletter') {
            $newsIds[] = (int) $r['id'];
        } else {
            $appIds[] = (int) $r['id'];
        }
    }

    /* the live rows, one batched read per table */
    $fetch = static function (string $table, array $ids): array {
        if (!$ids) {
            return [];
        }

        $in  = implode(',', array_map('intval', $ids));
        $out = [];

        foreach (db()->query("SELECT * FROM {$table} WHERE id IN ({$in})")->fetchAll() as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    };

    $apps    = $fetch('applications', $appIds);
    $contact = $fetch('contact_messages', $contactIds);
    $news    = $fetch('newsletter_subscribers', $newsIds);

    $rows = [];

    foreach ($index as $r) {
        $type = (string) $r['type'];
        $row  = $type === 'contact'
            ? ($contact[(int) $r['id']] ?? null)
            : ($type === 'newsletter' ? ($news[(int) $r['id']] ?? null) : ($apps[(int) $r['id']] ?? null));

        if ($row) {
            $rows[] = ['type' => $type, 'row' => $row];
        }
    }

    return $rows;
}

function submission_types(): array
{
    /* Built once per request: type_config() reaches for this on nearly every
       admin page, sometimes several times a page, and the shape never changes
       within a request. */
    static $types = null;

    if ($types !== null) {
        return $types;
    }

    /* `list` is what a row of the table needs to draw itself and its actions,
       and nothing else. An application carries 87 columns; the list shows nine
       of them, and the drawer asks for the rest only when somebody opens one
       (admin/drawer.php). */
    return $types = [
        'stove' => [
            'label' => 'Stove applications',
            'icon'  => 'bi-fire',
            'table' => 'applications',
            'entity' => 'application',
            'list'  => 'id, reference_code, full_name, email, mobile_number, product,
                        status, created_at, reminder_count, reminded_at,
                        sale_channel, referred_by_code, entered_by_dealer, entered_by_distributor,
                        dealer_id, distributor_id',
        ],
        'tuktuk' => [
            'label' => 'TukTuk applications',
            'icon'  => 'bi-truck-front',
            'table' => 'applications',
            'entity' => 'application',
            'list'  => 'id, reference_code, full_name, email, mobile_number, product,
                        status, created_at, reminder_count, reminded_at,
                        sale_channel, referred_by_code, entered_by_dealer, entered_by_distributor,
                        dealer_id, distributor_id',
        ],
        'contact' => [
            'label' => 'Contact enquiries',
            'icon'  => 'bi-envelope',
            'table' => 'contact_messages',
            'entity' => 'contact',
            'list'  => 'id, name, email, phone, status, created_at',
        ],
        'newsletter' => [
            'label' => 'Newsletter signups',
            'icon'  => 'bi-send',
            'table' => 'newsletter_subscribers',
            'entity' => 'newsletter',
            'list'  => 'id, email, status, created_at',
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

/* ---------- work that should not keep anybody waiting ----------
   An email costs about two and a half seconds: opening a TLS connection to the
   mail host, the handshake, the conversation, the send. Doing that inside the
   request means the office waits two and a half seconds to approve an
   application, and a client waits it to upload a receipt — for something
   neither of them is watching.

   So the answer goes out first and the slow work happens after. The browser has
   its redirect and has moved on; this process stays alive to finish the job.

   Whether it worked is recorded in `email_log` either way, which is where a
   failure is looked for — the screen can no longer report it, because the
   screen is already gone by the time we know. */
/**
 * Loads the letters.
 *
 * `emails.php` requires this file, so this file cannot require it back at the
 * top — the include guard would leave half of it defined. Anything in here that
 * sends asks for it at the moment it sends, by which time everything is loaded.
 */
function mailer(): void
{
    require_once __DIR__ . '/emails.php';
}

function after_response(callable $work): void
{
    $done = static function () use ($work): void {
        try {
            $work();
        } catch (Throwable $e) {
            error_log('after_response: ' . $e->getMessage());
        }
    };

    /* php-fpm can hand the response back properly */
    if (function_exists('fastcgi_finish_request')) {
        register_shutdown_function(static function () use ($done): void {
            fastcgi_finish_request();
            $done();
        });

        return;
    }

    /* mod_php cannot, so the response is closed by hand: tell the browser
       exactly how much to expect and that nothing follows, then flush. It stops
       waiting, and this process carries on. */
    register_shutdown_function(static function () use ($done): void {
        ignore_user_abort(true);

        if (!headers_sent()) {
            header('Connection: close');
            header('Content-Length: ' . ob_get_length());
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        flush();

        /* the session lock would hold up the next request otherwise */
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $done();
    });

    if (ob_get_level() === 0) {
        ob_start();
    }
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
        /* 419 is Laravel's, not an HTTP one: Apache does not know it and sends
           500 instead, so every stale form post read as a server fault in the
           logs. 403 says the same thing in a code the server can return. */
        http_response_code(403);
        exit('Session expired. Go back, reload the page and try again.');
    }
}

/* ---------- Authentication ---------- */

/**
 * The office account signed in at this browser, or null.
 *
 * The idle timeout the whole application used to run on was the session's own,
 * which is now long so that a client is not signed out on their own. The office
 * is the part that still wants one — the dashboard carries every customer's
 * paperwork and bank details, and it is opened on machines other people use — so
 * it is applied here instead, to the office and to nobody else. A portal session
 * sharing the same browser is untouched by it.
 */
function current_user(): ?array
{
    $user = $_SESSION['admin'] ?? null;

    if (!$user) {
        return null;
    }

    $seen = (int) ($_SESSION['admin_seen'] ?? 0);

    if ($seen > 0 && time() - $seen > SESSION_LIFETIME) {
        unset($_SESSION['admin'], $_SESSION['admin_seen']);

        return null;
    }

    /* Written once a minute rather than on every hit: the point is the gap since
       they were last here, and that is not measured any finer. */
    if (time() - $seen >= 60) {
        $_SESSION['admin_seen'] = time();
    }

    return $user;
}

/**
 * The office's own pages.
 *
 * C&F signs in at the same door and is a different job entirely — they see
 * vouchers and nothing else — so landing on an office page sends them to their
 * own. One sign-in, two destinations.
 */
/**
 * A cookie set the moment somebody signs in and cleared when they sign out —
 * separate from the session cookie and outliving it, so a guard can tell a
 * session that has quietly expired (marker still here) from a browser that has
 * never signed in (no marker). That is what turns a silent bounce to the login
 * form into an "your session ended" page.
 */
const AUTH_MARKER = 'mf_auth';

function mark_authenticated(): void
{
    setcookie(AUTH_MARKER, '1', [
        'expires'  => time() + 60 * 60 * 24 * 30,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => request_is_https(),
    ]);
    $_COOKIE[AUTH_MARKER] = '1';
}

function clear_authenticated(): void
{
    setcookie(AUTH_MARKER, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => request_is_https(),
    ]);
    unset($_COOKIE[AUTH_MARKER]);
}

function auth_marker_present(): bool
{
    return !empty($_COOKIE[AUTH_MARKER]);
}

/**
 * Sends an unauthenticated request back to a sign-in page, flagging it as an
 * expiry (rather than a first visit) when the marker above says this browser
 * had signed in.
 */
function guard_redirect(string $to): void
{
    if (auth_marker_present()) {
        $to .= (strpos($to, '?') === false ? '?' : '&') . 'expired=1';
    }

    header('Location: ' . $to);
    exit;
}

function require_login(): array
{
    $user = current_user();

    if (!$user) {
        guard_redirect('login');
    }

    if (($user['role'] ?? 'admin') === 'cf') {
        header('Location: ' . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/../cf/');
        exit;
    }

    return $user;
}

/** The C&F pages, the other way round. */
function require_rf(): array
{
    $user = current_user();

    if (!$user) {
        guard_redirect('../admin/login');
    }

    if (($user['role'] ?? 'admin') !== 'cf') {
        header('Location: ../admin/');
        exit;
    }

    return $user;
}

/** Where a signed-in account belongs. */
function role_landing(string $role): string
{
    return $role === 'cf' ? '../cf/' : './';
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
        /* One GROUP BY product, status for the whole request, cached: the
           dashboard asks this for both stove and tuktuk, and that is two
           round-trips to a remote database for figures one query already holds. */
        static $byProduct = null;

        if ($byProduct === null) {
            $byProduct = [];

            foreach (db()->query(
                'SELECT product, status, COUNT(*) AS n FROM applications GROUP BY product, status'
            )->fetchAll() as $row) {
                $byProduct[$row['product']][$row['status']] = (int) $row['n'];
            }
        }

        foreach ($byProduct[$type] ?? [] as $status => $n) {
            $counts[$status] = $n;
        }
    } else {
        $stmt = db()->query('SELECT status, COUNT(*) AS n FROM ' . $config['table'] . ' GROUP BY status');

        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
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
 * What a status change reads as afterwards, in the past tense.
 *
 * The confirmation line used to say "Submission #554 updated", which names
 * neither who it was nor what was done to them — after approving four in a row
 * it is impossible to tell whether the right one went through. This is the verb
 * half of "Harsh Patel approved."
 *
 * `booking_pending` is the one that does not read as its own label: for an
 * application it is the moment the office says yes, and "approved" is what
 * everybody calls it.
 */
function status_done(string $status): string
{
    $done = [
        'booking_pending' => 'approved',
        'refunded'        => 'marked refunded',
        'rejected'        => 'rejected',
        'accepted'        => 'accepted',
        'contacted'       => 'marked as contacted',
        'new'             => 'put back to new',
    ];

    return $done[$status] ?? ('moved to ' . mb_strtolower(status_label($status)));
}

/**
 * The whole confirmation line: who, and what was done to them.
 *
 * `$did` is everything that changed, in the order it happened — an empty one
 * means the form was saved without changing anything, and says so rather than
 * claiming an update that did not occur.
 */
function saved_note(string $type, array $row, array $did): string
{
    $who = record_title($type, $row);

    return $did
        ? $who . ' ' . implode(', ', $did) . '.'
        : $who . ' — nothing to change.';
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
                'Identity &amp; address proofs' => ['aadhaar_number' => 'Aadhaar number',
                                                   'aadhaar_doc_path' => 'Aadhaar card',
                                                   'electricity_bill_path' => 'Electricity bill',
                                                   'registration_number' => 'Vehicle registration number',
                                                   'rc_doc_path' => 'Vehicle RC',
                                                   'dl_doc_path' => 'Driving licence',
                                                   'id_number' => 'ID / passport number',
                                                   'id_document_path' => 'ID document (proof of identity)',
                                                   'residence_proof_path' => 'Residence proof (proof of address)'],
            ],

            'Site &amp; address' => [
                'Residential address' => ['house_number' => 'House / unit', 'street' => 'Street', 'city' => 'City',
                              'state' => 'State', 'country' => 'Country', 'pin_code' => 'Pin code'],
                'Delivery address' => ['delivery_address' => 'Delivery address', 'landmark' => 'Landmark'],
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

    $documents = [
        'aadhaar_doc_path'      => 'Aadhaar card',
        'electricity_bill_path' => 'Electricity bill',
        'rc_doc_path'           => 'Vehicle RC',
        'dl_doc_path'           => 'Driving licence',
        'id_document_path'      => 'ID document',
        'residence_proof_path'  => 'Proof of residence',
    ];

    if (isset($documents[$key])) {
        $label = $documents[$key];

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

/** mailto: link for an email cell; enquiries get a subject and their reference. */
function email_mailto(string $type, array $row): string
{
    $href = 'mailto:' . rawurlencode((string) $row['email']);

    if ($type === 'contact') {
        $href .= '?subject=' . rawurlencode('Re: Your enquiry to Manifold Clean Energy (ENQ-' . (int) $row['id'] . ')')
            . '&body=' . rawurlencode('Hello ' . (string) ($row['name'] ?? '') . ",

");
    }

    return $href;
}

/** Gmail web compose for an enquiry reply — opens in a new tab, no mail app needed. */
function enquiry_reply_url(array $row): string
{
    return 'https://mail.google.com/mail/?' . http_build_query([
        'view' => 'cm',
        'fs'   => 1,
        'to'   => (string) $row['email'],
        'su'   => 'Re: Your enquiry to Manifold Clean Energy (ENQ-' . (int) $row['id'] . ')',
        'body' => 'Hello ' . (string) ($row['name'] ?? '') . ",\n\n",
    ], '', '&', PHP_QUERY_RFC3986);
}

/* ---------- commission release and pass-down ----------
   pending -> approved (released commission_release_hours after the tranche was
   verified; the money is now travelling) -> disbursed (it has reached its own
   party). proof_uploaded belonged to the retired "office pays everybody" step
   and is no longer written.

   Released money does not go from the office to each partner. It goes back down
   the road the sale came up — office -> Principal C&F -> Zonal C&F ->
   Distributor -> Dealer — one hop at a time, each hop a commission_transfers
   row with the sender's UTR and receipt. A tier that is not on the sale is
   skipped, so a distributor with no zonal is paid by the office directly.

   commission_lines.holder_* says who has a line's money right now (NULL = the
   office). The route is read from the sale's own chain columns, not today's
   hierarchy: a distributor who moves to another zonal does not drag the money
   of their earlier sales with them — the zonal who was on those sales holds it
   and passes it on. */

/** Moves every line whose release time has passed from pending to approved. Idempotent. Returns lines moved. */
function commission_release_due(): int
{
    $stmt = db()->prepare(
        "UPDATE commission_lines c
           JOIN applications a ON a.id = c.application_id
            SET c.payout_status = 'approved'
          WHERE c.payout_status = 'pending' AND c.release_at IS NOT NULL AND c.release_at <= NOW()
            AND a.status NOT IN ('rejected', 'cancelled', 'refunded', 'expired')"
    );
    $stmt->execute();

    return $stmt->rowCount();
}

/** Reading names for the tiers money passes through. */
const COMMISSION_TIER_LABELS = [
    'office'       => 'Manifold office',
    'principal_cf' => 'Principal C&F',
    'zonal_cf'     => 'Zonal C&F',
    'distributor'  => 'Distributor',
    'dealer'       => 'Dealer',
];

/**
 * The hops a line's money takes, top first, ending with its own party:
 * [[tier, id], ...]. Only tiers above the party that are on the sale.
 */
function commission_line_route(array $line): array
{
    $route = [];

    foreach (['principal_cf' => 'principal_cf_id', 'zonal_cf' => 'zonal_cf_id', 'distributor' => 'distributor_id'] as $tier => $column) {
        if ($tier === $line['party_type']) {
            break;
        }

        if (!empty($line[$column])) {
            $route[] = [$tier, (int) $line[$column]];
        }
    }

    $route[] = [(string) $line['party_type'], (int) $line['party_id']];

    return $route;
}

/** Where a held line goes next: [tier, id], or null if its holder is not on its road. */
function commission_next_hop(array $line): ?array
{
    $route = commission_line_route($line);

    if ($line['holder_type'] === null) {
        return $route[0];
    }

    foreach ($route as $i => [$tier, $id]) {
        if ($tier === $line['holder_type'] && $id === (int) $line['holder_id']) {
            return $route[$i + 1] ?? null;
        }
    }

    return null;
}

/**
 * Everything one holder has to pass on, grouped by who it goes to next:
 * ['tier:id' => ['to_type', 'to_id', 'amount', 'lines' => [...]]].
 *
 * $holderType 'office' = lines nobody has been sent yet. Only the office's
 * money is held back for a sale that has since been cancelled or refunded:
 * money already sent down is with somebody, and only they can pass it on.
 * $lock reads FOR UPDATE, for commission_transfer()'s transaction.
 */
function commission_passdown_due(string $holderType, ?int $holderId, bool $lock = false): array
{
    commission_release_due();   /* a hold that ran out counts now, not at the next cron */

    $sql = "SELECT c.id, c.application_id, c.party_type, c.party_id, c.stage, c.amount,
                   c.holder_type, c.holder_id, a.reference_code,
                   a.principal_cf_id, a.zonal_cf_id, a.distributor_id
              FROM commission_lines c JOIN applications a ON a.id = c.application_id
             WHERE c.payout_status = 'approved' AND ";

    if ($holderType === 'office') {
        $sql   .= "c.holder_type IS NULL AND a.status NOT IN ('rejected', 'cancelled', 'refunded', 'expired')";
        $params = [];
    } else {
        $sql   .= 'c.holder_type = ? AND c.holder_id = ?';
        $params = [$holderType, (int) $holderId];
    }

    $stmt = db()->prepare($sql . ' ORDER BY c.id' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute($params);

    $due = [];

    foreach ($stmt->fetchAll() as $line) {
        $hop = commission_next_hop($line);

        if ($hop === null) {
            continue;
        }

        $key = $hop[0] . ':' . $hop[1];
        $due[$key] ??= ['to_type' => $hop[0], 'to_id' => $hop[1], 'amount' => 0.0, 'lines' => []];
        $due[$key]['amount']  = round($due[$key]['amount'] + (float) $line['amount'], 2);
        $due[$key]['lines'][] = $line;
    }

    return $due;
}

/** A partner's row by tier, or null. */
function commission_party_row(string $type, int $id): ?array
{
    $fn = ['principal_cf' => 'principal_cf_by_id', 'zonal_cf' => 'zonal_cf_by_id',
           'distributor' => 'distributor_by_id', 'dealer' => 'dealer_by_id'][$type] ?? null;

    return $fn ? $fn($id) : null;
}

/** Who a transfer end is, in words. */
function commission_party_name(string $type, ?int $id): string
{
    if ($type === 'office') {
        return COMMISSION_TIER_LABELS['office'];
    }

    $row = commission_party_row($type, (int) $id);

    return $row ? (string) $row['full_name'] : (COMMISSION_TIER_LABELS[$type] ?? $type) . ' #' . (int) $id;
}

/**
 * Records one hop: $fromType has sent $toType/$toId everything it holds that is
 * on its way to them. Returns [transferId, ''] or [0, error].
 *
 * The amount is never the sender's to choose — it is what they hold for that
 * recipient, read under lock. $expect is what the page showed: if more money
 * was released or another tab already sent it, the sum no longer matches and
 * the transfer is refused rather than recording a UTR against a figure that
 * was not the one actually paid. A recipient who is not the next hop of any
 * line the sender holds has nothing due, which is what makes "only your own
 * direct children" hold without a separate check.
 */
function commission_transfer(
    string $fromType,
    ?int $fromId,
    string $toType,
    int $toId,
    float $expect,
    string $utr,
    string $proofPath,
    string $actor
): array {
    $utr = mb_substr(trim($utr), 0, 120);

    if ($utr === '' || $proofPath === '') {
        return [0, 'A UTR / reference number and the transfer receipt are both required.'];
    }

    /* a sale can still name a partner whose row has since been deleted: nobody
       could sign in to receive or pass that money on, so the office sorts it out */
    if (commission_party_row($toType, $toId) === null) {
        return [0, 'That partner no longer exists, so nothing can be sent to them.'];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $due = commission_passdown_due($fromType, $fromId, true)[$toType . ':' . $toId] ?? null;

        if ($due === null || $due['amount'] <= 0) {
            $pdo->rollBack();

            return [0, 'Nothing you hold is due to that partner — it may already have been sent.'];
        }

        if (abs($due['amount'] - $expect) >= 0.005) {
            $pdo->rollBack();

            return [0, 'What is due to ' . commission_party_name($toType, $toId) . ' is now '
                . money($due['amount']) . ', not ' . money($expect) . '. Check the figure and send it again.'];
        }

        $pdo->prepare(
            'INSERT INTO commission_transfers (from_type, from_id, to_type, to_id, amount, utr, proof_path, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$fromType, $fromType === 'office' ? null : $fromId, $toType, $toId,
                    $due['amount'], $utr, $proofPath, mb_substr($actor, 0, 160)]);
        $transferId = (int) $pdo->lastInsertId();

        $ids = array_map(static fn (array $l): int => (int) $l['id'], $due['lines']);
        $in  = implode(',', array_fill(0, count($ids), '?'));

        $pdo->prepare("UPDATE commission_lines SET holder_type = ?, holder_id = ? WHERE id IN ($in)")
            ->execute(array_merge([$toType, $toId], $ids));

        /* the recipient's own lines have arrived: this hop's receipt is theirs */
        $pdo->prepare(
            "UPDATE commission_lines
                SET payout_status = 'disbursed', disbursed_at = NOW(), transfer_id = ?,
                    proof_path = ?, payout_utr = ?, proof_by = ?, proof_at = NOW()
              WHERE id IN ($in) AND party_type = ? AND party_id = ?"
        )->execute(array_merge([$transferId, $proofPath, $utr, mb_substr($actor, 0, 160)], $ids, [$toType, $toId]));

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    commission_transfer_notify($transferId);

    return [$transferId, ''];
}

/**
 * The POST side of every pass-down form (office, C&F desk, distributor):
 * stores the receipt, then commission_transfer(). Returns [flash, error].
 * A refused transfer takes its uploaded receipt with it.
 */
function commission_transfer_post(string $fromType, ?int $fromId, string $actor): array
{
    $toType = (string) ($_POST['to_type'] ?? '');

    if (!in_array($toType, ['principal_cf', 'zonal_cf', 'distributor', 'dealer'], true)) {
        return ['', 'Pick who the money is going to.'];
    }

    $proof = store_upload('proof_file', PAYMENT_PROOF_DIR);

    if ($proof === null) {
        return ['', 'Attach the transfer receipt (JPG, PNG, WebP or PDF, up to 10 MB).'];
    }

    $toId = (int) ($_POST['to_id'] ?? 0);
    [$id, $error] = commission_transfer($fromType, $fromId, $toType, $toId,
        (float) ($_POST['expect'] ?? 0), (string) ($_POST['utr'] ?? ''), $proof, $actor);

    if ($id === 0) {
        @unlink(PAYMENT_PROOF_DIR . '/' . $proof);

        return ['', $error];
    }

    return ['Recorded the transfer to ' . commission_party_name($toType, $toId) . '.', ''];
}

/**
 * Transfers one party sent ($side 'from') or received ($side 'to'), newest
 * first, with both ends' names.
 */
function commission_transfers_for(string $side, string $type, ?int $id, int $limit = 200): array
{
    $sql = $side === 'from'
        ? ($type === 'office' ? "SELECT * FROM commission_transfers WHERE from_type = 'office'"
                              : 'SELECT * FROM commission_transfers WHERE from_type = ? AND from_id = ?')
        : 'SELECT * FROM commission_transfers WHERE to_type = ? AND to_id = ?';
    $stmt = db()->prepare($sql . ' ORDER BY id DESC LIMIT ' . max(1, $limit));
    $stmt->execute($side === 'from' && $type === 'office' ? [] : [$type, (int) $id]);

    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['from_name'] = commission_party_name((string) $row['from_type'], $row['from_id'] === null ? null : (int) $row['from_id']);
        $row['to_name']   = commission_party_name((string) $row['to_type'], (int) $row['to_id']);
    }

    return $rows;
}

/** Emails the recipient of a transfer that it has been sent. Best effort. */
function commission_transfer_notify(int $transferId): void
{
    $stmt = db()->prepare('SELECT * FROM commission_transfers WHERE id = ?');
    $stmt->execute([$transferId]);
    $t   = $stmt->fetch();
    $row = $t ? commission_party_row((string) $t['to_type'], (int) $t['to_id']) : null;

    if (!$row || empty($row['email'])) {
        return;
    }

    require_once __DIR__ . '/emails.php';

    $page = ['principal_cf' => '/cfdesk/payouts?as=principal_cf', 'zonal_cf' => '/cfdesk/payouts?as=zonal_cf',
             'distributor' => '/distributor/payouts', 'dealer' => '/dealer/payouts'][$t['to_type']];

    send_commission_transfer_email(
        (string) $row['email'],
        (string) $row['full_name'],
        (float) $t['amount'],
        (string) $t['utr'],
        commission_party_name((string) $t['from_type'], $t['from_id'] === null ? null : (int) $t['from_id']),
        base_url() . $page
    );
}

/**
 * Streams the receipt of one transfer to either end of it, or answers 404.
 * The file comes from the transfer row; the caller has to be its sender or recipient.
 */
function commission_transfer_receipt_send(int $transferId, string $partyType, int $partyId): void
{
    $stmt = db()->prepare(
        'SELECT proof_path FROM commission_transfers
          WHERE id = ? AND ((from_type = ? AND from_id = ?) OR (to_type = ? AND to_id = ?))'
    );
    $stmt->execute([$transferId, $partyType, $partyId, $partyType, $partyId]);

    commission_proof_stream((string) ($stmt->fetchColumn() ?: ''));
}

/** Reading name for a payout status. */
function payout_status_label(string $status): string
{
    return ['pending' => 'Held ' . commission_release_hours() . ' h', 'approved' => 'Released',
            'proof_uploaded' => 'Proof uploaded', 'disbursed' => 'Disbursed'][$status] ?? ucfirst($status);
}

/**
 * A partner's commission lines with their payout status, newest first.
 * $partyId null = every party of those types (the C&F desk, which sees the whole tier).
 */
function commission_payout_lines(array $partyTypes, ?int $partyId = null, int $limit = 200): array
{
    commission_release_due();   /* a line whose hold has run out shows as released without waiting for cron */

    $in     = implode(',', array_fill(0, count($partyTypes), '?'));
    $params = $partyTypes;
    $sql    = "SELECT c.*, a.reference_code
                 FROM commission_lines c JOIN applications a ON a.id = c.application_id
                WHERE c.party_type IN ($in)";

    if ($partyId !== null) {
        $sql     .= ' AND c.party_id = ?';
        $params[] = $partyId;
    }

    $stmt = db()->prepare($sql . ' ORDER BY c.id DESC LIMIT ' . max(1, $limit));
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Streams the transfer receipt on one commission line, or answers 404.
 * The file is found from the line, and the line has to belong to the caller —
 * nothing here takes a path from the request.
 */
function commission_receipt_send(int $lineId, array $partyTypes, ?int $partyId = null): void
{
    $lines = array_filter(
        commission_payout_lines($partyTypes, $partyId, 100000),
        static fn (array $l): bool => (int) $l['id'] === $lineId && !empty($l['proof_path'])
    );
    commission_proof_stream($lines ? (string) reset($lines)['proof_path'] : '');
}

/** Streams one file out of PAYMENT_PROOF_DIR, or 404. The name is always read off a row, never the request. */
function commission_proof_stream(string $name): void
{
    $name = basename($name);
    $full = PAYMENT_PROOF_DIR . '/' . $name;

    if ($name === '' || !is_file($full) || strpos(realpath($full) ?: '', realpath(PAYMENT_PROOF_DIR) ?: '') !== 0) {
        http_response_code(404);
        exit('File not found.');
    }

    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
             'pdf' => 'application/pdf'][strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($full);
    exit;
}
