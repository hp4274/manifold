<?php
/**
 * Manifold Clean Energy — admin configuration.
 *
 * Copy the credentials below to match the server. On stock XAMPP the defaults
 * work as-is once schema.sql has been imported.
 */

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'manifold';
const DB_USER = 'root';
const DB_PASS = '';
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
const SMTP_PASS   = 'xs3b-5eaz-daac-ijzl';   // Google app password, spaces removed
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
 * Leave blank to have it worked out from the current request.
 */
const PUBLIC_BASE_URL = '';

/**
 * The public site, and the mark the emails show at the top.
 *
 * It must be a PNG or a JPEG: the WebP wordmark does not decode in Outlook and
 * one reader rendered it as coloured static. The company name beside it is live
 * text in the template, not part of the image.
 */
const SITE_PUBLIC_URL = 'https://manifoldcleanenergy.co.in';
const EMAIL_LOGO_URL  = 'https://manifoldcleanenergy.co.in/assets/images/favicon.png';

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
 * Commission, as whole percentages of what a sale is worth.
 *
 *   a dealer sells        dealer 15%, their distributor 5%
 *   a distributor sells   distributor 15%, no dealer involved
 *
 * Starting values only — the live figures live in the `settings` table and are
 * edited from Settings. Nothing is earned until the sale is complete.
 */
const DEALER_RATE_DEFAULT                = 15;
const DISTRIBUTOR_OVERRIDE_RATE_DEFAULT  = 5;
const DISTRIBUTOR_DIRECT_RATE_DEFAULT    = 15;

/**
 * How many dealers one distributor may hold, counting the ones still waiting
 * for the office to approve them. Starting value only — the live figure lives
 * in the `settings` table and is edited from Settings.
 */
const DEALER_LIMIT_DEFAULT = 10;
const PAYMENT_CURRENCY = '₹';

function money(float $amount): string
{
    return PAYMENT_CURRENCY . number_format($amount, 2);
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
