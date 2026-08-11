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
const SMTP_HOST   = 'smtp.gmail.com';
const SMTP_PORT   = 587;         // 587 for TLS, 465 for SSL
const SMTP_SECURE = 'tls';       // 'tls', 'ssl' or '' for none
const SMTP_USER   = 'harshlpatel.4274@gmail.com';
const SMTP_PASS   = 'ttkbisjsvgahpcem';   // Google app password, spaces removed
const SMTP_TIMEOUT = 15;

/* Gmail rejects a From that is not the authenticated mailbox or a verified
   alias, so send as the account and route replies to the company inbox. */
const MAIL_FROM      = 'harshlpatel.4274@gmail.com';
const MAIL_FROM_NAME = 'Manifold Clean Energy';
const MAIL_REPLY_TO  = 'info@manifoldcleanenergy.com';

/**
 * Where staff notifications go (a receipt has been uploaded, etc).
 * Comma-separate several. Leave blank to use the email addresses of the
 * active accounts in `admin_users` instead.
 */
const ADMIN_NOTIFY_EMAIL = 'harshlpatel.4274@gmail.com';

/**
 * Absolute base URL of the site, used for links inside emails.
 * Leave blank to have it worked out from the current request.
 */
const PUBLIC_BASE_URL = '';

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

/** What every application costs, and how it is written. */
const PAYMENT_AMOUNT   = 3500;

/**
 * Paid to a customer each time somebody applies with their referral code. The
 * office transfers it by hand. This is only the starting value — the live
 * figure lives in the `settings` table and is edited from Settings.
 */
const REFERRAL_REWARD_DEFAULT = 500;
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
    /* /admin/... or /portal/... → the site root one level up */
    $dir     = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    $root    = preg_replace('#/(admin|portal)$#', '', $dir);

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
