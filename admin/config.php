<?php
/**
 * Manifold Clean Energy — admin configuration.
 *
 * Copy the credentials below to match the server. On stock XAMPP the defaults
 * work as-is once schema.sql has been imported.
 */

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'u768511311_test_manifold';
const DB_USER = 'u768511311_manifold';
const DB_PASS = 'Manifold@2210';
const DB_PORT = 3306;
/** Absolute path where uploaded application documents are stored. */
/**
 * The clock the whole application runs on: the office's, in Ahmedabad.
 * PHP and MySQL are both pinned to it below, because a default install has
 * them on different ones and then SQL and PHP disagree about what is due.
 */
const SITE_TIMEZONE = 'Asia/Kolkata';

date_default_timezone_set(SITE_TIMEZONE);

/**
 * Where PHP writes its errors.
 *
 * Shared hosting often hides the server log, so every warning and uncaught
 * exception is also written here and read back from admin/error-log.php. The
 * directory carries an .htaccess that denies web access.
 */
const ERROR_LOG_DIR  = __DIR__ . '/logs';
const ERROR_LOG_FILE = ERROR_LOG_DIR . '/php-error.log';

if (!is_dir(ERROR_LOG_DIR)) {
    @mkdir(ERROR_LOG_DIR, 0775, true);
}

ini_set('log_errors', '1');
ini_set('error_log', ERROR_LOG_FILE);
ini_set('display_errors', '0');

/**
 * The key everything signed by this site is signed with — the sign-in code and
 * the one-click unsubscribe link.
 *
 * Not a constant in this file: it is tracked, so a value written here would be
 * published the moment the repository is. Define APP_SECRET in the untracked
 * config.local.php to set it by hand. Otherwise one is made on first use and
 * kept beside the error log, in a directory the web server already denies.
 *
 * Changing it invalidates every code and unsubscribe link already out — which
 * for a ten-minute code and a link nobody has clicked yet costs nothing.
 */
function app_secret(): string
{
    static $secret = null;

    if ($secret !== null) {
        return $secret;
    }

    if (defined('APP_SECRET') && APP_SECRET !== '') {
        return $secret = (string) APP_SECRET;
    }

    $file = ERROR_LOG_DIR . '/app-secret.key';

    if (is_file($file)) {
        $stored = trim((string) @file_get_contents($file));

        if ($stored !== '') {
            return $secret = $stored;
        }
    }

    $secret = bin2hex(random_bytes(32));
    @file_put_contents($file, $secret, LOCK_EX);
    @chmod($file, 0600);

    return $secret;
}

const UPLOAD_DIR = __DIR__ . '/uploads';

/** Largest accepted upload, in bytes. */
const UPLOAD_MAX_BYTES = 10 * 1024 * 1024;

/** Accepted upload types. */
const UPLOAD_ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];

/** Where the public site lives, relative to /admin. */
const SITE_URL = '..';

/* --------------------------------------------------------------------------
 * Email (SMTP)
 * Fill these in — they are intentionally blank. Nothing is sent until
 * SMTP_HOST and SMTP_USER are set; attempts are recorded in `email_log`.
 * Example for Gmail: smtp.gmail.com / 587 / tls / an app password.
 * ----------------------------------------------------------------------- */
const SMTP_HOST   = 'smtp.hostinger.com';
const SMTP_PORT   = 465;         // 587 for TLS, 465 for SSL
const SMTP_SECURE = 'ssl';       // 'tls', 'ssl' or '' for none
const SMTP_USER   = 'info@manifoldcleanenergy.co.in';
/* The mailbox password lives in the untracked config.local.php beside this
   file, so it never enters the repository. Empty here means "not set":
   mailer.php only sends when SMTP_HOST and SMTP_USER are filled in. */
$local = __DIR__ . '/config.local.php';
if (is_file($local)) { require $local; }
if (!defined('SMTP_PASS')) { define('SMTP_PASS', ''); }
const SMTP_TIMEOUT = 15;

/* Gmail rejects a From that is not the authenticated mailbox or a verified
   alias, so send as the account and route replies to the company inbox. */
const MAIL_FROM      = 'info@manifoldcleanenergy.co.in';
const MAIL_FROM_NAME = 'Manifold Clean Energy';
const MAIL_REPLY_TO  = 'info@manifoldcleanenergy.co.in';

/**
 * Where staff notifications go (a receipt has been uploaded, etc).
 * Comma-separate several. Leave blank to use the email addresses of the
 * active accounts in `admin_users` instead.
 */
const ADMIN_NOTIFY_EMAIL = 'info@manifoldcleanenergy.co.in';

/**
 * Absolute base URL of the site, used for links inside emails.
 *
 * This has to be set. Left blank it is worked out from the request, and the
 * Friday voucher run has no request — from the command line every link in a
 * commission email came out as http://localhost. and went nowhere. A blank
 * value also leaves the address in a live email at the mercy of whatever Host
 * header the request arrived with.
 *
 * On localhost this should be blank so base_url() auto-detects from the
 * request (e.g. http://localhost/manifold). Set the real address in
 * config.local.php for production:
 *
 *   define('PUBLIC_BASE_URL', 'https://manifoldcleanenergy.co.in');
 *   define('SITE_PUBLIC_URL', 'https://manifoldcleanenergy.co.in');
 *   define('EMAIL_LOGO_URL',  'https://manifoldcleanenergy.co.in/assets/images/favicon.png');
 */
if (!defined('PUBLIC_BASE_URL')) { define('PUBLIC_BASE_URL', ''); }

/**
 * The public site, and the mark the emails show at the top.
 *
 * It must be a PNG or a JPEG: the WebP wordmark does not decode in Outlook and
 * one reader rendered it as coloured static. The company name beside it is live
 * text in the template, not part of the image.
 */
if (!defined('SITE_PUBLIC_URL')) { define('SITE_PUBLIC_URL', ''); }
if (!defined('EMAIL_LOGO_URL'))  { define('EMAIL_LOGO_URL',  ''); }

/**
 * Payment QR code, relative to the site root. The first of these that exists
 * is used, so the image can live at the root or with the other images.
 */
const PAYMENT_QR_CANDIDATES = ['qr.jpeg', 'qr.jpg', 'qr.png', 'assets/images/qr.jpeg', 'assets/images/qr.png'];

/** Site-root-relative path of the QR image, or '' when none is present. */
function qr_path(): string
{
    foreach (PAYMENT_QR_CANDIDATES as $candidate) {
        if (is_file(__DIR__ . '/../' . $candidate)) {
            return $candidate;
        }
    }

    return '';
}

/** Absolute filesystem path of the QR image, or null. */
function qr_file(): ?string
{
    $path = qr_path();

    return $path === '' ? null : __DIR__ . '/../' . $path;
}

/**
 * What every application costs, and how it is collected: a booking amount with
 * the application, then the delivery amount when the unit is handed over. The
 * rest of the price is the applicant's own loan and never passes through here.
 *
 * These figures are copied onto the application row when it is submitted, so a
 * later price change never rewrites what an open application owes.
 */
const PAYMENT_PLAN = [
    'stove'  => ['booking' => 3500.0,  'delivery' => 16500.0],
    'tuktuk' => ['booking' => 6000.0,  'delivery' => 24000.0],
];

/** Fallback booking amount for a row that predates the two-stage plan. */
const PAYMENT_AMOUNT   = 3500;

/** The two amounts for one product, booking first. */
function payment_plan(string $product): array
{
    return PAYMENT_PLAN[$product] ?? PAYMENT_PLAN['stove'];
}

/**
 * Paid to a customer each time somebody applies with their referral code. The
 * office transfers it by hand. This is only the starting value — the live
 * figure lives in the `settings` table and is edited from Settings.
 */
const REFERRAL_REWARD_DEFAULT = 500;

/**
 * Commission, in rupees per sale, per product.
 *
 *   a dealer sells        the dealer's figure, and the override to their distributor
 *   a distributor sells   the direct figure, and no dealer is involved
 *
 * A flat amount, not a share: a stove and a kit are different sales and pay
 * different money. Starting values only — the live figures live in the
 * `settings` table and are edited from Settings, by the office or by R&F.
 * The whole amount is earned when the delivery payment is verified.
 */
const COMMISSION_DEFAULTS = [
    'dealer'   => ['stove' => 3000, 'tuktuk' => 4500],
    'override' => ['stove' => 1000, 'tuktuk' => 1500],
    'direct'   => ['stove' => 3000, 'tuktuk' => 4500],
];

/**
 * How many dealers one distributor may hold, counting the ones still waiting
 * for the office to approve them. Starting value only — the live figure lives
 * in the `settings` table and is edited from Settings.
 */
const DEALER_LIMIT_DEFAULT = 10;
/**
 * The earliest day an installation can be booked for.
 *
 * The first units are built for 2027, so a date before this is a promise
 * nobody can keep. The forms carry it as a `min`; the server keeps the same
 * rule for a request that never went through one.
 */
/** The dial code a number is stored with when the form did not say. */
const DEFAULT_DIAL_CODE = '91';

/**
 * How many digits a national number runs to, by dial code.
 *
 * Ten digits is India, not the world: a British mobile is ten but a landline
 * can be nine, a Singapore number is eight and a Chinese one eleven. Only the
 * codes worth being exact about are listed; anything else is held to the range
 * E.164 allows, which still catches a typed area code or a missing digit.
 *
 * The same table is in `assets/js/apply.js`, which is what the form uses while
 * somebody is typing. Change one and change the other.
 */
const DIAL_DIGITS = [
    '91'  => [10, 10],  '1'   => [10, 10],  '44'  => [9, 10],   '61'  => [9, 9],
    '64'  => [8, 10],   '27'  => [9, 9],    '234' => [10, 10],  '254' => [9, 9],
    '233' => [9, 9],    '255' => [9, 9],    '256' => [9, 9],    '251' => [9, 9],
    '20'  => [10, 10],  '212' => [9, 9],    '260' => [9, 9],    '263' => [9, 9],
    '971' => [9, 9],    '966' => [9, 9],    '974' => [8, 8],    '965' => [8, 8],
    '968' => [8, 8],    '973' => [8, 8],    '962' => [9, 9],    '972' => [9, 9],
    '92'  => [10, 10],  '880' => [10, 10],  '94'  => [9, 9],    '977' => [10, 10],
    '975' => [8, 8],    '960' => [7, 7],    '95'  => [8, 10],   '93'  => [9, 9],
    '86'  => [11, 11],  '81'  => [10, 10],  '82'  => [9, 10],   '65'  => [8, 8],
    '60'  => [9, 10],   '62'  => [9, 12],   '66'  => [9, 9],    '84'  => [9, 10],
    '63'  => [10, 10],  '852' => [8, 8],    '49'  => [10, 11],  '33'  => [9, 9],
    '39'  => [9, 10],   '34'  => [9, 9],    '351' => [9, 9],    '31'  => [9, 9],
    '32'  => [9, 9],    '41'  => [9, 9],    '43'  => [10, 11],  '46'  => [7, 9],
    '47'  => [8, 8],    '45'  => [8, 8],    '358' => [9, 10],   '353' => [9, 9],
    '48'  => [9, 9],    '30'  => [10, 10],  '420' => [9, 9],    '36'  => [9, 9],
    '40'  => [9, 9],    '359' => [9, 9],    '7'   => [10, 10],  '380' => [9, 9],
    '90'  => [10, 10],  '55'  => [10, 11],  '52'  => [10, 10],  '54'  => [10, 10],
    '56'  => [9, 9],    '57'  => [10, 10],  '51'  => [9, 9],
];

/**
 * The digits a number under one dial code may run to, as [min, max].
 *
 * E.164 caps a whole number at 15 digits including the code, and nothing real
 * is shorter than six, so that is what an unlisted code is held to.
 */
function dial_digits(string $dial): array
{
    return DIAL_DIGITS[$dial] ?? [6, 15 - min(4, strlen($dial))];
}

const INSTALL_FROM = '2027-01-01';

const PAYMENT_CURRENCY = '₹';

function money(float $amount): string
{
    return PAYMENT_CURRENCY . number_format($amount, 2);
}

/**
 * The same amount for a headline figure, where the paise are noise: a whole
 * number of rupees loses its ".00" so a crore still fits inside its card.
 * Anything with paise keeps them — a total is never rounded away.
 */
function money_short(float $amount): string
{
    if (abs($amount - round($amount)) < 0.005) {
        return PAYMENT_CURRENCY . number_format($amount, 0);
    }

    return money($amount);
}

/** Where payment proofs are stored. */
const PAYMENT_PROOF_DIR = __DIR__ . '/uploads/payments';

/**
 * Public base URL for links in emails and redirects.
 */
function base_url(): string
{
    if (PUBLIC_BASE_URL !== '') {
        return rtrim(PUBLIC_BASE_URL, '/');
    }

    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    /* /admin/..., /portal/..., /dealer/... or /distributor/... → the site root
       one level up. Every folder that serves pages has to be listed here.
       A folder missing from this list silently builds links inside itself: a
       dealer's share link came out as /dealer/apply-tuktuk.html and 404'd. */
    $dir     = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    $root    = preg_replace('#/(admin|portal|dealer|distributor)$#', '', $dir);

    return $scheme . '://' . $host . $root;
}

/**
 * Shared PDO connection.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            /* NOW() has to mean the same moment as PHP's time() */
            $pdo->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Database connection failed. Check admin/config.php and that schema.sql has been imported.');
        }
    }

    return $pdo;
}
