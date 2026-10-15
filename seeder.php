<?php
/**
 * Test data, for a database nobody minds losing.
 *
 * Open http://localhost/manifold/seeder.php and it builds a network — two
 * principal C&F, four zonal C&F, five distributors, ten dealers under each,
 * and clients arranged so that every route into a sale, every tier of the
 * commission chain, every payout state, every booking shape and every referral
 * outcome is represented at least once. It prints what it made and stops.
 *
 *   ?wipe=1   removes everything a previous run made, then seeds again
 *   ?only=... 'wipe' removes and does not re-seed
 *
 * Nothing here invents its own rules: applications are attributed by the same
 * resolution the public form uses, the chain above the seller is frozen by
 * commission_chain_columns(), statuses are decided by sync_application_status()
 * and commission is written by commission_write_lines() through it. The payout
 * states come from commission_release_due() and commission_transfer(), the
 * pass-down every payouts screen uses; the low-stock hold from the same
 * is_stock_critical() test admin/submit.php applies. If the business rules
 * change, this seeder changes with them rather than quietly disagreeing.
 *
 * Every seeded row is tagged (SEED_TAG below) so ?wipe=1 can find them again
 * and real data is never touched. The placeholder documents and transfer
 * receipts it writes are all named `seed-…`, and the wipe deletes those too.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin/lib.php';
/* the voucher chain fires notifications (held back while seeding, but the
   functions still have to exist) and the raffle block calls raffle-lib */
require_once __DIR__ . '/admin/emails.php';
require_once __DIR__ . '/admin/raffle-lib.php';

/* ---------------------------------------------------------------- guards */

/* A seeder loose on a live site is a bad afternoon, so somewhere between here
   and the first INSERT somebody has to say yes.
 *
 *   local machine          runs straight away — that is what it is for
 *   anywhere else          asks once, on screen, naming the database it is
 *                          about to write to; ?confirm=1 is that answer
 *   SEEDER_ALLOW_REMOTE    defined in admin/config.local.php: never asks,
 *                          for a staging box that is seeded often
 *
 * A test domain is therefore one click, not a file edit. */
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'cli'));
$local = preg_match('/^(localhost|127\.0\.0\.1|::1|.*\.local|.*\.test|.*\.localhost)(:\d+)?$/', $host) === 1;
$confirmed = isset($_GET['confirm']) || (defined('SEEDER_ALLOW_REMOTE') && SEEDER_ALLOW_REMOTE);

/* ?confirm=1 is a click, not a credential: anybody who can reach this page can
   send it. Off the dev machine the seeder therefore writes nothing until an
   office session says who is asking. */
if (!$local && PHP_SAPI !== 'cli') {
    require_login();
}

@set_time_limit(300);

const SEED_TAG = '[seed]';

/* Seeding walks the voucher, referral and status flows through the live code,
   which would fire real email at every step. This flag, checked in send_mail(),
   holds all of it back — the seeder is after the rows, not the notifications. */
define('MANIFOLD_SEEDING', true);

$wipe = isset($_GET['wipe']) || (($_GET['only'] ?? '') === 'wipe');
$onlyWipe = ($_GET['only'] ?? '') === 'wipe';

if (!$local && !$confirmed) {
    /* the same query the visitor arrived with, plus the answer */
    $go = $_GET;
    $go['confirm'] = '1';

    http_response_code(200);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Seeder — confirm</title>
      <style>
        body{margin:0;padding:60px 20px;background:#f4f7fa;
             font:15px/1.65 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#0f2c4d}
        .card{max-width:620px;margin-inline:auto;background:#fff;border:1px solid #e3ebf2;
              border-radius:14px;padding:30px 32px}
        h1{margin:0 0 10px;font-size:24px;letter-spacing:-.02em}
        dl{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;margin:20px 0;font-size:14px}
        dt{color:#5c7389}
        dd{margin:0;font-weight:600}
        .go,.no{display:inline-flex;align-items:center;justify-content:center;min-height:46px;
                padding:0 24px;border-radius:999px;font-weight:700;font-size:14px;text-decoration:none}
        .go{background:#0c7a74;color:#fff}
        .no{color:#5c7389}
        p.muted{color:#5c7389;font-size:14px}
        code{background:#f4f7fa;border:1px solid #e3ebf2;border-radius:5px;padding:1px 6px;font-size:13px}
      </style>
    </head>
    <body>
      <div class="card">
        <h1>Seed this database?</h1>
        <p class="muted">
          This is not a local host, so it asks first. It writes test partners, clients and payments —
          fine on a test domain, not something to do twice by accident.
        </p>

        <dl>
          <dt>Host</dt><dd><?= e($host) ?></dd>
          <dt>Database</dt><dd><?= e(DB_NAME) ?> on <?= e(DB_HOST) ?></dd>
          <dt>Action</dt><dd><?= $onlyWipe
              ? 'Remove seeded data only'
              : ($wipe ? 'Remove seeded data, then seed again' : 'Add a batch of seeded data') ?></dd>
        </dl>

        <p>
          <a class="go" href="?<?= e(http_build_query($go)) ?>">
            Yes, <?= $onlyWipe ? 'remove it' : 'seed it' ?>
          </a>
          <a class="no" href="<?= e(rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')), '/\\') ?: '/') ?>/">
            No, take me back
          </a>
        </p>

        <p class="muted" style="margin-bottom:0">
          Only rows tagged <code><?= e(SEED_TAG) ?></code> are ever removed. To stop it asking on
          this server, put <code>define('SEEDER_ALLOW_REMOTE', true);</code> in
          <code>admin/config.local.php</code>.
        </p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/* ------------------------------------------------------------ the fixtures */

const FIRST_NAMES = ['Aarav', 'Vivaan', 'Aditya', 'Vihaan', 'Arjun', 'Sai', 'Reyansh', 'Krishna',
    'Ishaan', 'Rudra', 'Ananya', 'Diya', 'Aadhya', 'Kiara', 'Myra', 'Anika', 'Navya', 'Riya',
    'Prisha', 'Meera', 'Rohan', 'Kabir', 'Dhruv', 'Yash', 'Manav', 'Nisha', 'Pooja', 'Sneha'];

const LAST_NAMES = ['Patel', 'Shah', 'Desai', 'Mehta', 'Joshi', 'Trivedi', 'Chauhan', 'Parmar',
    'Solanki', 'Rathod', 'Bhatt', 'Vyas', 'Pandya', 'Gohil', 'Makwana', 'Thakkar'];

const CITIES = [
    ['Ahmedabad', 'Gujarat', '380001'], ['Surat', 'Gujarat', '395003'],
    ['Vadodara', 'Gujarat', '390001'], ['Rajkot', 'Gujarat', '360001'],
    ['Anand', 'Gujarat', '388001'],    ['Bhavnagar', 'Gujarat', '364001'],
    ['Jamnagar', 'Gujarat', '361001'], ['Gandhinagar', 'Gujarat', '382010'],
];

/** A name that reads like a person's. */
function seed_name(): string
{
    return FIRST_NAMES[array_rand(FIRST_NAMES)] . ' ' . LAST_NAMES[array_rand(LAST_NAMES)];
}

/**
 * A mailbox anybody can open, numbered so it can be typed from memory:
 * client1@yopmail.com, dealer1@yopmail.com, distributor1@yopmail.com.
 *
 * The count carries on from whatever is already in the database, so a second
 * run continues at client58 rather than handing two different people the same
 * address — which would make a one-time sign-in code ambiguous.
 */
function seed_email(string $who): string
{
    static $next = [];

    if (!isset($next[$who])) {
        $table = ['client' => 'applications', 'dealer' => 'dealers', 'distributor' => 'distributors',
                  'zonal' => 'zonal_cf', 'principal' => 'principal_cf'][$who] ?? 'applications';

        /* the highest number already handed out for this role */
        $stmt = db()->prepare(
            'SELECT MAX(CAST(SUBSTRING(email, ?, LOCATE(?, email) - ?) AS UNSIGNED))
               FROM ' . $table . ' WHERE email LIKE ?'
        );
        $stmt->execute([
            strlen($who) + 1, '@', strlen($who) + 1, $who . '%@yopmail.com',
        ]);

        $next[$who] = (int) $stmt->fetchColumn();
    }

    return $who . (++$next[$who]) . '@yopmail.com';
}

function seed_mobile(): string
{
    return '+91' . mt_rand(70, 99) . str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
}

function seed_city(): array
{
    return CITIES[array_rand(CITIES)];
}

/** A moment between $daysAgo and today, as a datetime. */
function seed_when(int $daysAgo): string
{
    return date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, max(0, $daysAgo)) . ' days -' . mt_rand(0, 23) . ' hours'));
}

/**
 * A moment inside the last day, and never before the percentage cutoff.
 *
 * A sale made before `commission_percent_from` keeps the older flat amounts, so
 * a fixture that exists to show the 20/15/10/6 chain has to be dated after it.
 * Both kinds are wanted — the historical sales stay where they are, which is
 * what keeps the flat lines on the books too.
 */
function seed_recent(): string
{
    $from = strtotime((string) setting('commission_percent_from', '')) ?: 0;

    return date('Y-m-d H:i:s', max(time() - mt_rand(1, 20) * 3600, $from + 600));
}

/**
 * A placeholder file on disk, so a document link in the drawer or a receipt
 * link on a payout line opens something instead of answering 404.
 *
 * Returns the stored name only, which is what store_upload() returns and what
 * the columns hold. Named `seed-…` so the wipe finds it again.
 */
function seed_file(string $dir, string $label): string
{
    global $made;

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $name = 'seed-' . bin2hex(random_bytes(6)) . '.pdf';

    /* the shortest thing a reader will still open, with the label in a comment
       so anybody who does open it can see which fixture put it there */
    file_put_contents(
        $dir . '/' . $name,
        "%PDF-1.4\n% " . $label . ' ' . SEED_TAG . "\n1 0 obj<</Type/Catalog>>endobj\n"
        . "trailer<</Root 1 0 R>>\n%%EOF\n"
    );

    $made['placeholder files'] = ($made['placeholder files'] ?? 0) + 1;

    return $name;
}

/* ------------------------------------------------------------------- wipe */

$removed = [];

if ($wipe) {
    $db = db();

    /* applications first — the payments, lines and vouchers hang off them */
    $ids = $db->query("SELECT id FROM applications WHERE admin_note LIKE '%" . SEED_TAG . "%'")
        ->fetchAll(PDO::FETCH_COLUMN);

    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach (['commission_lines' => 'application_id', 'payments' => 'application_id',
                  'stock_ledger' => 'application_id', 'status_log' => 'entity_id'] as $table => $column) {
            try {
                $extra = $table === 'status_log' ? " AND entity = 'application'" : '';
                $removed[$table] = (int) $db->exec("DELETE FROM {$table} WHERE {$column} IN ({$in}){$extra}");
            } catch (Throwable $e) {
                /* a table this database does not have is not an error here */
            }
        }
        $removed['applications'] = (int) $db->exec("DELETE FROM applications WHERE id IN ({$in})");
    }

    $dealerIds = $db->query("SELECT id FROM dealers WHERE note LIKE '%" . SEED_TAG . "%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $distIds = $db->query("SELECT id FROM distributors WHERE note LIKE '%" . SEED_TAG . "%'")
        ->fetchAll(PDO::FETCH_COLUMN);

    if ($dealerIds) {
        $in = implode(',', array_map('intval', $dealerIds));
        /* voucher lines and events cascade from the voucher row */
        $removed['dealer vouchers'] = (int) $db->exec(
            "DELETE FROM commission_vouchers WHERE party_type = 'dealer' AND party_id IN ({$in})"
        );
        $removed['dealer_payouts'] = (int) $db->exec("DELETE FROM dealer_payouts WHERE dealer_id IN ({$in})");
        $removed['transfers (dealers)'] = (int) $db->exec(
            "DELETE FROM commission_transfers WHERE to_type = 'dealer' AND to_id IN ({$in})"
        );
        $removed['stock (dealers)'] = (int) $db->exec(
            "DELETE FROM stock_ledger WHERE owner_type = 'dealer' AND owner_id IN ({$in})"
        );
        $removed['dealers'] = (int) $db->exec("DELETE FROM dealers WHERE id IN ({$in})");
    }

    if ($distIds) {
        $in = implode(',', array_map('intval', $distIds));
        $removed['distributor vouchers'] = (int) $db->exec(
            "DELETE FROM commission_vouchers WHERE party_type = 'distributor' AND party_id IN ({$in})"
        );
        $removed['distributor_payouts'] = (int) $db->exec("DELETE FROM distributor_payouts WHERE distributor_id IN ({$in})");
        $removed['transfers (distributors)'] = (int) $db->exec(
            "DELETE FROM commission_transfers WHERE (to_type = 'distributor' AND to_id IN ({$in}))
                OR (from_type = 'distributor' AND from_id IN ({$in}))"
        );
        $removed['stock (distributors)'] = (int) $db->exec(
            "DELETE FROM stock_ledger WHERE owner_type = 'distributor' AND owner_id IN ({$in})"
        );
        $removed['distributors'] = (int) $db->exec("DELETE FROM distributors WHERE id IN ({$in})");
    }

    /* the two C&F tiers last of the partners: a distributor row points up at a
       zonal and a zonal up at a principal, so they go in that order */
    foreach ([['zonal_cf', 'zonal_cf_payouts', 'zonal_cf_id'],
              ['principal_cf', 'principal_cf_payouts', 'principal_cf_id']] as [$table, $payouts, $column]) {
        try {
            $cfIds = $db->query("SELECT id FROM {$table} WHERE note LIKE '%" . SEED_TAG . "%'")
                ->fetchAll(PDO::FETCH_COLUMN);

            if (!$cfIds) {
                continue;
            }

            $in = implode(',', array_map('intval', $cfIds));
            $removed[$payouts] = (int) $db->exec("DELETE FROM {$payouts} WHERE {$column} IN ({$in})");
            $removed['transfers (' . $table . ')'] = (int) $db->exec(
                "DELETE FROM commission_transfers WHERE (to_type = '{$table}' AND to_id IN ({$in}))
                    OR (from_type = '{$table}' AND from_id IN ({$in}))"
            );
            $removed[$table]   = (int) $db->exec("DELETE FROM {$table} WHERE id IN ({$in})");
        } catch (Throwable $e) {
            /* a table this database does not have is not an error here */
        }
    }

    /* the placeholder documents and transfer receipts the rows pointed at */
    $files = 0;

    foreach ([UPLOAD_DIR, PAYMENT_PROOF_DIR] as $dir) {
        foreach (glob($dir . '/seed-*') ?: [] as $file) {
            $files += (int) @unlink($file);
        }
    }

    $removed['placeholder files'] = $files;

    /* the stand-alone tables, each carrying its own seed marker */
    foreach ([
        'blog posts'         => "DELETE FROM blog_posts WHERE slug LIKE 'seed-%'",
        'contact messages'   => "DELETE FROM contact_messages WHERE admin_note LIKE '%" . SEED_TAG . "%'",
        'newsletter signups' => "DELETE FROM newsletter_subscribers WHERE admin_note LIKE '%" . SEED_TAG . "%'",
    ] as $what => $sql) {
        try {
            $removed[$what] = (int) $db->exec($sql);
        } catch (Throwable $e) {
            /* a table this database does not have is not an error here */
        }
    }

    $removed = array_filter($removed);
}

/* --------------------------------------------------------------- the build */

$made = [
    'principal C&F' => 0, 'zonal C&F' => 0, 'distributors' => 0, 'dealers' => 0,
    'applications' => 0, 'payouts' => 0, 'stock rows' => 0,
    'commission lines released' => 0, 'commission transfers' => 0, 'placeholder files' => 0,
    'blog posts' => 0, 'contact messages' => 0,
    'newsletter signups' => 0, 'raffle winners' => 0,
];
$scenarios = [];
$sample = [];

/**
 * One application, attributed exactly the way admin/submit.php attributes one.
 *
 * $quoted is whatever went in the single code box — a customer's MF code, a
 * dealer's MD code, a distributor's MX code, something unknown, or nothing.
 * Returns the finished row.
 */
function seed_application(string $quoted, string $product, array $opts = []): array
{
    global $made;

    $name  = $opts['name'] ?? seed_name();
    $email = $opts['email'] ?? seed_email('client');
    [$city, $state, $pin] = seed_city();

    /* ---- who this sale belongs to, and who is paid out of it ----
       The form has two boxes: $quoted is the referral code and $partner the
       dealer or distributor selling it. The partner box wins where it is
       answered, which is what lets a customer of one dealer send somebody to
       another. Same order as admin/submit.php. */
    $partner  = $opts['partner'] ?? '';
    $referrer = $quoted === '' ? null : referrer_for_code($quoted);

    $dealer = $partner === '' ? null : dealer_for_code($partner);
    $distributor = $dealer
        ? distributor_for_dealer($dealer)
        : ($partner === '' ? null : distributor_for_code($partner));

    if (!$dealer && !$distributor && $referrer) {
        /* a customer's code and no partner named: the partner behind their own
           sale keeps this one — re-checked, never copied blind */
        $dealer = dealer_by_id((int) ($referrer['dealer_id'] ?? 0));

        if ($dealer && ((int) $dealer['is_active'] !== 1 || $dealer['approval_status'] !== 'approved')) {
            $dealer = null;
        }

        if ($dealer) {
            $distributor = distributor_for_dealer($dealer);
        } elseif (empty($referrer['dealer_id'])) {
            $distributor = distributor_by_id((int) ($referrer['distributor_id'] ?? 0));
        }
    } elseif (!$dealer && !$distributor && !$referrer) {
        $dealer = $quoted === '' ? null : dealer_for_code($quoted);
        $distributor = $dealer
            ? distributor_for_dealer($dealer)
            : ($quoted === '' ? null : distributor_for_code($quoted));
    }

    if ($distributor && (int) $distributor['is_active'] !== 1) {
        $distributor = null;
    }

    /* an MZ code straight on the form: no distributor, no dealer, the zonal and
       their principal take the sale between them */
    $zonalDirect = !$dealer && !$distributor && $partner !== '' ? zonal_cf_for_code($partner) : null;

    /* nobody refers themselves */
    $self = $referrer && strcasecmp((string) ($referrer['email'] ?? ''), $email) === 0;

    /* the whole chain above the seller, frozen by the same function the public
       form and the spreadsheet import both call */
    /* booking amount now, or the lot up front — both are offered on step 3 */
    $full = ($opts['plan'] ?? 'booking') === 'full_payment';

    $chain = commission_chain_columns($product, $dealer, $distributor, $zonalDirect, $full);
    $plan  = payment_plan($product);
    $when  = $opts['created_at'] ?? seed_when(120);

    $columns = [
        'product'                => $product,
        'status'                 => 'submitted',
        'reference_code'         => 'seed-' . bin2hex(random_bytes(5)),
        'referral_code'          => make_referral_code(),
        'full_name'              => $name,
        'email'                  => $email,
        'mobile_number'          => seed_mobile(),
        'alt_mobile_number'      => null,
        'date_of_birth'          => date('Y-m-d', strtotime('-' . mt_rand(21, 58) . ' years')),
        'nationality'            => 'Indian',
        'gender'                 => ['Male', 'Female'][mt_rand(0, 1)],
        'occupation'             => ['Teacher', 'Shopkeeper', 'Driver', 'Farmer', 'Engineer'][mt_rand(0, 4)],
        'id_number'              => 'ID' . mt_rand(100000, 999999),
        'house_number'           => (string) mt_rand(1, 240),
        'street'                 => ['Ring Road', 'Station Road', 'MG Road', 'Market Lane'][mt_rand(0, 3)],
        'city'                   => $city,
        'state'                  => $state,
        'country'                => 'India',
        'pin_code'               => $pin,
        'units_required'         => 1,
        'booking_amount'         => (float) $plan['booking'],
        'delivery_amount'        => (float) $plan['delivery'],
        'payment_amount'         => $full
            ? stage_amount(['product' => $product], 'full')
            : (float) $plan['booking'],
        'payment_plan'           => $full ? 'full_payment' : 'booking',
        'referred_by_code'       => $quoted === '' ? null : $quoted,
        'referred_by_id'         => $referrer && !$self ? (int) $referrer['id'] : null,
        'referral_reward'        => $referrer && !$self ? referral_reward() : 0.0,
        'referral_reward_status' => $referrer && !$self ? 'pending' : 'none',
        'sale_channel'           => 'online',
        'declaration_accepted'   => 1,
        'terms_accepted'         => 1,
        'terms_accepted_at'      => $when,
        'testimonial_consent'    => mt_rand(0, 1),
        'admin_note'             => trim(($opts['note'] ?? '') . ' ' . SEED_TAG),
        'created_at'             => $when,
    ];

    /* ---- what step 1 and step 2 of the form now collect ----
       The address is one block with its landmark, and the KYC pair is
       mandatory, so every seeded row carries both files — the drawer's
       document strip is unreadable without them. */
    $columns['delivery_address'] = $columns['house_number'] . ', ' . $columns['street'] . ', '
        . $city . ', ' . $state . ' ' . $pin;
    $columns['landmark']              = ['Opposite the bus stand', 'Behind the water tank',
        'Next to the panchayat office', 'Near the dairy'][mt_rand(0, 3)];
    $columns['aadhaar_number']        = (string) mt_rand(2000, 9999) . mt_rand(1000, 9999) . mt_rand(1000, 9999);
    $columns['aadhaar_doc_path']      = seed_file(UPLOAD_DIR, 'Aadhaar card');
    $columns['electricity_bill_path'] = seed_file(UPLOAD_DIR, 'Electricity bill');

    /* a tuktuk cannot be registered to nobody, so the form demands the vehicle's
       own paperwork on top of the KYC pair */
    if ($product === 'tuktuk') {
        $columns['registration_number'] = 'GJ' . mt_rand(1, 38) . chr(mt_rand(65, 90)) . chr(mt_rand(65, 90))
            . mt_rand(1000, 9999);
        $columns['rc_doc_path']         = seed_file(UPLOAD_DIR, 'RC book');
        $columns['dl_doc_path']         = seed_file(UPLOAD_DIR, 'Driving licence');
    }

    $columns = array_merge($columns, $chain);

    /* the same guard admin/submit.php applies: a sale off a shelf at or below
       the critical threshold is frozen until both desks have signed it */
    $sellerType = $dealer ? 'dealer' : ($distributor ? 'distributor' : '');
    $sellerId   = $dealer ? (int) $dealer['id'] : ($distributor ? (int) $distributor['id'] : 0);

    if ($sellerType !== '' && is_stock_critical($sellerType, $sellerId, $product)) {
        $columns['low_stock_hold'] = 1;
        $columns['admin_note']     = trim($columns['admin_note']
            . ' [STOCK RESTRICTION: Seller unit balance <= ' . stock_critical_units()
            . '. Dual approval from Principal C&F and Office required before fulfillment.]');
    }

    if ($self) {
        $columns['referral_reward_note'] = 'Own code — a customer cannot refer themselves.';
    }

    $names = array_keys($columns);
    db()->prepare('INSERT INTO applications (`' . implode('`, `', $names) . '`) VALUES ('
        . implode(', ', array_fill(0, count($names), '?')) . ')')->execute(array_values($columns));

    $id = (int) db()->lastInsertId();
    db()->prepare('UPDATE applications SET reference_code = ? WHERE id = ?')
        ->execute([make_reference_code($id), $id]);

    $made['applications']++;

    return db()->query('SELECT * FROM applications WHERE id = ' . $id)->fetch();
}

/**
 * The office's decision. Approving starts no clock: the 48 hours belong to the
 * client's cancel window, which opens when their booking payment is verified
 * (sync_application_status stamps it).
 */
function seed_approve(int $id, string $when): void
{
    db()->prepare("UPDATE applications SET status = 'booking_pending', confirmed_at = ? WHERE id = ?")
        ->execute([$when, $id]);
}

/**
 * A payment, in whatever state.
 *
 * 'verified' is money in; 'pending' is a receipt uploaded and waiting; the
 * status machine is then asked where the application stands, which is what
 * writes the commission lines.
 */
function seed_payment(int $id, string $stage, string $state, string $when): void
{
    $app  = db()->query('SELECT product FROM applications WHERE id = ' . $id)->fetch();
    $plan = payment_plan((string) $app['product']);

    db()->prepare(
        'INSERT INTO payments (application_id, stage, amount, reference, status, receipt_no,
                               uploaded_at, decided_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $id, $stage, (float) $plan[$stage], 'UTR' . mt_rand(100000000, 999999999), $state,
        $state === 'verified' ? 'R-' . $id . '-' . strtoupper(substr($stage, 0, 1)) : null,
        $when, $state === 'pending' ? null : $when,
    ]);

    sync_application_status($id);
}

/**
 * Finance's verdict on the paperwork.
 *
 * The same three stamps docs_verify() lands — the KYC moment and the
 * cancellation lock alongside the document one — because the portal hides the
 * cancel button off `cancellation_locked`, and a row with documents verified
 * but no lock is a state the application cannot reach.
 */
function seed_docs_verified(int $id, string $when): void
{
    db()->prepare("UPDATE applications
                      SET docs_verified_at = ?, docs_verified_by = NULL,
                          kyc_verified_at = ?, kyc_verified_by = 'Seeded office',
                          cancellation_locked = 1
                    WHERE id = ?")
        ->execute([$when, $when, $id]);
    sync_application_status($id);
}

/** The customer cancelling inside their window. */
function seed_delivery_choice(int $id, string $choice, string $when): void
{
    db()->prepare('UPDATE applications SET delivery_choice = ?, delivery_choice_at = ? WHERE id = ?')
        ->execute([$choice, $when, $id]);
    sync_application_status($id);
}

/**
 * Walks an application as far along the flow as asked for.
 *
 * 'submitted' · 'booking_pending' · 'booking_review' · 'docs_pending' ·
 * 'delivery_pending' · 'delivery_review' · 'complete' · 'cancelled' · 'rejected'
 */
function seed_advance(array $app, string $to): array
{
    $id = (int) $app['id'];
    $t  = strtotime((string) $app['created_at']);
    /* A sale made months ago took its steps days apart. A sale made this
       morning took the same steps in hours, and none of them can be in the
       future — a paid-at stamp ahead of the clock reads as a bug on every
       screen that shows it. */
    $at = static function (int $days) use ($t): string {
        return date('Y-m-d H:i:s', max($t, min(time() - mt_rand(60, 900), $t + $days * 86400 + mt_rand(3600, 40000))));
    };

    if ($to === 'submitted') {
        return db()->query('SELECT * FROM applications WHERE id = ' . $id)->fetch();
    }

    if ($to === 'rejected') {
        /* a turned-down application is a status and a log line; there is no
           column of its own for the moment it happened */
        db()->prepare("UPDATE applications SET status = 'rejected' WHERE id = ?")->execute([$id]);
        log_status_change('application', $id, 'submitted', 'rejected', null);
        db()->prepare("UPDATE applications SET referral_reward_status = 'cancelled',
                       referral_reward_note = 'Application turned down.'
                       WHERE id = ? AND referral_reward_status = 'pending'")->execute([$id]);

        return db()->query('SELECT * FROM applications WHERE id = ' . $id)->fetch();
    }

    seed_approve($id, $at(2));

    if ($to === 'booking_pending') {
        return db()->query('SELECT * FROM applications WHERE id = ' . $id)->fetch();
    }

    if ($to === 'booking_review') {
        seed_payment($id, 'booking', 'pending', $at(4));

        return db()->query('SELECT * FROM applications WHERE id = ' . $id)->fetch();
    }

    seed_payment($id, 'booking', 'verified', $at(4));

    if ($to === 'docs_pending') {
        return db()->query('SELECT * FROM applications WHERE id = ' . $id)->fetch();
    }

    /* a cancellation happens inside the window, before the documents pass */
    if ($to === 'cancelled') {
        seed_delivery_choice($id, 'cancel', $at(5));

        return db()->query('SELECT * FROM applications WHERE id = ' . $id)->fetch();
    }

    seed_docs_verified($id, $at(7));

    if ($to === 'delivery_pending') {
        return db()->query('SELECT * FROM applications WHERE id = ' . $id)->fetch();
    }

    if ($to === 'delivery_review') {
        seed_payment($id, 'delivery', 'pending', $at(12));

        return db()->query('SELECT * FROM applications WHERE id = ' . $id)->fetch();
    }

    seed_payment($id, 'delivery', 'verified', $at(12));

    return db()->query('SELECT * FROM applications WHERE id = ' . $id)->fetch();
}

/**
 * One C&F partner, on either tier, in whichever state the caller asks for.
 *
 * Principal and zonal carry the same columns bar the code and the parent, so
 * one helper writes both — which is what lets each tier show an approved
 * partner, one still waiting on the office and one switched off without the
 * fixture repeating itself three times over.
 */
function seed_cf(string $tier, array $opts): array
{
    global $made;

    [$city, $state, $pin] = seed_city();
    $name     = seed_name();
    $zonal    = $tier === 'zonal_cf';
    $approval = $opts['approval'] ?? 'approved';
    $terms    = (int) ($opts['terms'] ?? 1);

    $columns = [
        ($zonal ? 'zonal_code' : 'principal_code') => $zonal ? make_zonal_cf_code() : make_principal_cf_code(),
        'approval_status'   => $approval,
        'full_name'         => $name,
        'company'           => $name . ($zonal ? ' Zonal Agencies' : ' Clearing & Forwarding'),
        'email'             => seed_email($zonal ? 'zonal' : 'principal'),
        'mobile_number'     => seed_mobile(),
        'address'           => mt_rand(1, 60) . ', Transport Nagar',
        'city'              => $city,
        'state'             => $state,
        'pin_code'          => $pin,
        'pan_number'        => 'CCCPZ' . mt_rand(1000, 9999) . 'C',
        'gst_number'        => '24AAACZ' . mt_rand(1000, 9999) . 'A1Z5',
        'bank_name'         => 'Axis Bank',
        'bank_account'      => (string) mt_rand(10000000000, 99999999999),
        'bank_ifsc'         => 'UTIB000' . mt_rand(1000, 9999),
        'upi_id'            => strtolower(explode(' ', $name)[0]) . '@okaxis',
        'note'              => trim(($opts['note'] ?? '') . ' ' . SEED_TAG),
        'is_active'         => (int) ($opts['active'] ?? 1),
        /* the terms carry the 1200 TDS limit and the no-cancellation clause, so
           an unsigned partner is one the office still has to chase */
        'terms_accepted'    => $terms,
        'terms_accepted_at' => $terms ? seed_when(200) : null,
        'decided_at'        => $approval === 'pending' ? null : seed_when(190),
        'created_at'        => seed_when(210),
    ];

    if ($zonal) {
        $columns['principal_cf_id'] = (int) $opts['principal_cf_id'];
    }

    $names = array_keys($columns);
    db()->prepare('INSERT INTO ' . $tier . ' (`' . implode('`, `', $names) . '`) VALUES ('
        . implode(', ', array_fill(0, count($names), '?')) . ')')->execute(array_values($columns));

    $made[$zonal ? 'zonal C&F' : 'principal C&F']++;

    return db()->query('SELECT * FROM ' . $tier . ' WHERE id = ' . (int) db()->lastInsertId())->fetch();
}

/* ---------------------------------------------------------- the network */

if (!$onlyWipe) {
    /* ---- the two tiers above the distributors ----
       Manifold → Principal C&F → Zonal C&F → distributor → dealer. The 20 %
       and 15 % tiers are only earned where the chain above the seller
       resolves, so the states that stop it resolving are here too: a principal
       the office has not decided on, a zonal switched off, and one whose terms
       are unsigned. */
    $principals = [
        1 => seed_cf('principal_cf', ['note' => 'Principal C&F 1 — live, terms signed']),
        2 => seed_cf('principal_cf', [
            'approval' => 'pending', 'terms' => 0,
            'note'     => 'Principal C&F 2 — waiting on the office, terms unsigned',
        ]),
    ];

    $zonals = [
        1 => seed_cf('zonal_cf', ['principal_cf_id' => $principals[1]['id'], 'note' => 'Zonal C&F 1 — live']),
        2 => seed_cf('zonal_cf', ['principal_cf_id' => $principals[1]['id'], 'note' => 'Zonal C&F 2 — live']),
        3 => seed_cf('zonal_cf', [
            'principal_cf_id' => $principals[1]['id'], 'active' => 0,
            'note'            => 'Zonal C&F 3 — switched off, its MZ code books nothing',
        ]),
        4 => seed_cf('zonal_cf', [
            'principal_cf_id' => $principals[2]['id'], 'approval' => 'pending', 'terms' => 0,
            'note'            => 'Zonal C&F 4 — waiting on its principal',
        ]),
    ];

    /* Which zonal each distributor answers to. 1, 2 and 4 sit under a live
       chain and so earn all four tiers. 3 answers to a zonal nobody has
       approved and 5 to one that is switched off: commission_chain_columns()
       drops both from the sale, which is what a chain stopping at the
       distributor looks like — no zonal_cf_id frozen on the row at all. */
    $zonalFor = [1 => 1, 2 => 2, 3 => 4, 4 => 2, 5 => 3];

    $scenarios[] = ['Four-tier chain',
        'principal → zonal → distributor → dealer, with a pending principal, '
        . 'a switched-off zonal and an unsigned set of terms on each tier'];

    $distributors = [];

    for ($d = 1; $d <= 5; $d++) {
        [$city, $state, $pin] = seed_city();
        $code = make_distributor_code();
        $name = seed_name();

        /* the fifth is switched off and still undecided, so its code books
           nothing and it also sits in the office's approval queue; the fourth
           has never signed the terms */
        $active   = $d === 5 ? 0 : 1;
        $approval = $d === 5 ? 'pending' : 'approved';
        $terms    = $d === 4 ? 0 : 1;

        db()->prepare(
            'INSERT INTO distributors (distributor_code, zonal_cf_id, approval_status, decided_at,
                                       terms_accepted, terms_accepted_at, full_name, company, email,
                                       mobile_number, address, city, state, pin_code, pan_number,
                                       bank_name, bank_account, bank_ifsc, upi_id, note, is_active,
                                       created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $code, (int) $zonals[$zonalFor[$d]]['id'], $approval,
            $approval === 'pending' ? null : seed_when(180),
            $terms, $terms ? seed_when(180) : null,
            $name, $name . ' Energy Distribution', seed_email('distributor'), seed_mobile(),
            mt_rand(1, 90) . ', Industrial Estate', $city, $state, $pin,
            'AAAPZ' . mt_rand(1000, 9999) . 'A', 'HDFC Bank',
            (string) mt_rand(10000000000, 99999999999), 'HDFC000' . mt_rand(1000, 9999),
            strtolower(explode(' ', $name)[0]) . '@okhdfcbank',
            'Distributor ' . $d . ' ' . SEED_TAG,
            $active,
            seed_when(200),
        ]);

        $distributors[$d] = distributor_by_id((int) db()->lastInsertId());
        $made['distributors']++;

        /* Stock on the shelf, so the ordering screens have something to show —
           and comfortably over the 200-unit warning line, because a shelf
           under 100 makes every sale off it a held one and the ordinary flow
           would never be seen. The low-stock fixtures below name their own
           sellers instead. */
        foreach (['stove' => mt_rand(320, 600), 'tuktuk' => mt_rand(260, 420)] as $product => $units) {
            stock_move('distributor', (int) $distributors[$d]['id'], $product, $units,
                $units * stock_price('distributor', $product), 'purchase', null, null,
                'Opening stock ' . SEED_TAG);
        }

        $made['stock rows'] += 2;
    }

    $scenarios[] = ['Distributor switched off', 'Dis 5 is inactive and undecided — its code books nothing'];
    $scenarios[] = ['Terms not signed', 'Dis 4 and the pending C&F tiers have no terms_accepted_at'];

    /* ---- ten dealers under each ---- */
    $dealers = [];

    for ($d = 1; $d <= 5; $d++) {
        for ($n = 1; $n <= 10; $n++) {
            [$city, $state, $pin] = seed_city();
            $name = seed_name();

            /* nine in ten are live; the tenth of each is either waiting on the
               office or switched off, because both of those have to be
               represented for a code that books nothing to be testable */
            $approval = 'approved';
            $active = 1;

            if ($n === 9) {
                $approval = 'pending';
            } elseif ($n === 10) {
                $active = 0;
            }

            db()->prepare(
                'INSERT INTO dealers (dealer_code, distributor_id, approval_status, requested_by,
                                      decided_at, full_name, company, email, mobile_number, address,
                                      city, state, pin_code, pan_number, bank_name, bank_account,
                                      bank_ifsc, upi_id, note, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                /* a dealer waiting on the office has no code yet */
                $approval === 'pending' ? null : make_dealer_code(),
                (int) $distributors[$d]['id'],
                $approval,
                $approval === 'pending' ? (int) $distributors[$d]['id'] : null,
                $approval === 'pending' ? null : seed_when(150),
                $name, $name . ' Traders', seed_email('dealer'), seed_mobile(),
                mt_rand(1, 240) . ', Main Bazaar', $city, $state, $pin,
                'BBBPZ' . mt_rand(1000, 9999) . 'B', 'ICICI Bank',
                (string) mt_rand(10000000000, 99999999999), 'ICIC000' . mt_rand(1000, 9999),
                strtolower(explode(' ', $name)[0]) . '@okicici',
                'Dealer ' . $d . '-' . $n . ' ' . SEED_TAG,
                $active, seed_when(170),
            ]);

            $dealer = dealer_by_id((int) db()->lastInsertId());
            $dealers[$d][$n] = $dealer;
            $made['dealers']++;

            if ($approval === 'approved' && $active) {
                /* Three bands, so admin/stock.php and the partners' own stock
                   screens each have a tile of every colour. Both of the thin
                   shelves are under distributor 4 — the one selling today, and
                   so the one whose sales earn the percentage chain the
                   low-stock fixtures below are about. */
                $band  = $d === 4 ? [1 => mt_rand(120, 190), 2 => mt_rand(40, 90)][$n] ?? 0 : 0;
                $units = $band ?: mt_rand(240, 480);

                foreach (['stove' => $units, 'tuktuk' => $units] as $product => $count) {
                    stock_move('dealer', (int) $dealer['id'], $product, $count,
                        $count * stock_price('dealer', $product), 'purchase', null, null,
                        'Opening stock ' . SEED_TAG);
                }

                $made['stock rows'] += 2;
            }
        }
    }

    $scenarios[] = ['Dealer waiting on the office', 'the 9th under each distributor — no code, books nothing'];
    $scenarios[] = ['Dealer switched off', 'the 10th under each — code exists, books nothing'];

    /* ------------------------------------------------------- the clients */

    $products = ['stove', 'tuktuk'];
    $statuses = ['submitted', 'booking_pending', 'booking_review', 'docs_pending',
                 'delivery_pending', 'delivery_review', 'complete',
                 'complete', 'complete', 'cancelled', 'rejected'];

    $completed = [];   /* rows whose code can go on to refer somebody */

    /* --- 1. two to four clients for every live dealer, spread across the
             statuses so that every screen has something to show --- */
    $i = 0;

    foreach ($dealers as $d => $group) {
        foreach ($group as $n => $dealer) {
            if ($dealer['approval_status'] !== 'approved' || !$dealer['is_active']) {
                continue;
            }

            foreach (range(1, mt_rand(2, 4)) as $which) {
                /* every live dealer gets at least one sale that completed, so
                   no dealer screen opens on an empty set of figures */
                $status = $which === 1 ? 'complete' : $statuses[$i % count($statuses)];

                /* Distributors 2 and 4 sell today; 1, 3 and 5 sold months ago.
                   The cutoff under Settings decides which of the two
                   commission schemes a sale earns, so dating them apart is the
                   only way to have the four-tier percentage lines and the
                   older flat ones on the books at once. 2 is in the voucher
                   chain and 4 at the payouts desk, so both routes are seen
                   carrying multi-tranche percentage lines. */
                $fresh = $d === 2 || $d === 4;
                $app   = seed_application((string) $dealer['dealer_code'], $products[$i % 2], [
                    'note'       => 'Dealer link',
                    'created_at' => $fresh ? seed_recent() : seed_when(120),
                    /* one in four pays the lot up front */
                    'plan'       => $i % 4 === 0 ? 'full_payment' : 'booking',
                ]);

                /* a sale off a shelf below the red line takes no money until
                   both desks sign it, so it cannot be walked past approval */
                if (low_stock_blocked($app)) {
                    $status = 'booking_pending';
                }

                $app = seed_advance($app, $status);

                if ($status === 'complete') {
                    $completed[] = $app;
                }

                $i++;
            }
        }
    }

    $scenarios[] = ['Dealer code on the website', 'dealer commission + their distributor\'s override'];
    $scenarios[] = ['Every status', implode(', ', array_unique($statuses))];

    /* --- 2. a distributor's own client: the direct rate, no dealer --- */
    foreach ([1, 2, 3, 4, 5] as $d) {
        $app = seed_advance(
            seed_application((string) $distributors[$d]['distributor_code'], $products[$d % 2],
                ['note' => 'Distributor link']),
            'complete'
        );
        $completed[] = $app;
    }

    $scenarios[] = ['Distributor code on the website', 'the direct rate, no dealer involved'];

    /* --- 3. a client refers somebody: same dealer keeps the sale --- */
    shuffle($completed);
    $chainRoots = array_slice($completed, 0, 12);

    foreach ($chainRoots as $root) {
        $second = seed_advance(
            seed_application((string) $root['referral_code'], $products[mt_rand(0, 1)],
                ['note' => 'Referred by a client']),
            'complete'
        );
        $completed[] = $second;

        /* --- 4. and their referral refers on: only the direct one is paid --- */
        $third = seed_advance(
            seed_application((string) $second['referral_code'], $products[mt_rand(0, 1)],
                ['note' => 'Second link in the chain']),
            mt_rand(0, 1) ? 'complete' : 'delivery_pending'
        );

        if ($third['status'] === 'complete') {
            $completed[] = $third;
        }
    }

    /* --- 3b. cross-network: one dealer's customer sends somebody to another,
             which is what the second box on the form is for --- */
    $crossFrom = $completed[0];
    $crossTo   = null;

    foreach ($dealers as $d => $group) {
        foreach ($group as $dealer) {
            if ($dealer['approval_status'] === 'approved' && $dealer['is_active']
                && (int) $dealer['distributor_id'] !== (int) $crossFrom['distributor_id']) {
                $crossTo = $dealer;
                break 2;
            }
        }
    }

    if ($crossTo) {
        seed_advance(
            seed_application((string) $crossFrom['referral_code'], 'stove', [
                'partner' => (string) $crossTo['dealer_code'],
                'note'    => 'Referred across networks',
            ]),
            'complete'
        );

        /* and the same again, still owing the delivery payment */
        seed_advance(
            seed_application((string) $crossFrom['referral_code'], 'tuktuk', [
                'partner' => (string) $crossTo['dealer_code'],
                'note'    => 'Referred across networks, mid-flow',
            ]),
            'delivery_pending'
        );

        $scenarios[] = ['Referred across networks',
            'the closing dealer takes the commission, the referrer keeps the reward, '
            . 'the referrer\'s own dealer earns nothing'];
    }

    /* --- 3c. a dealer's link with a referral code alongside it --- */
    $withBoth = seed_advance(
        seed_application((string) $completed[1]['referral_code'], 'stove', [
            'partner' => (string) $dealers[1][1]['dealer_code'],
            'note'    => 'Both codes quoted',
        ]),
        'complete'
    );
    $completed[] = $withBoth;

    $scenarios[] = ['Both codes on one form', 'the partner box decides the sale, the referral box the reward'];

    $scenarios[] = ['Client refers a client', 'the referrer is paid ₹' . (int) referral_reward()
        . ', the sale stays with their own dealer'];
    $scenarios[] = ['Three deep', 'C1 → C2 → C3: only the direct referrer is paid on each'];

    /* --- 5. somebody quotes their own code --- */
    $selfer = $completed[0];
    seed_advance(
        seed_application((string) $selfer['referral_code'], 'stove',
            ['name' => (string) $selfer['full_name'], 'email' => (string) $selfer['email'],
             'note' => 'Same customer, second product']),
        'docs_pending'
    );

    $scenarios[] = ['Own code quoted', 'no reward — but the dealer keeps the repeat sale'];

    /* --- 6. a code that matches nothing, and no code at all --- */
    seed_advance(seed_application('MFZZZZZZ', 'stove', ['note' => 'Unknown code']), 'booking_pending');
    seed_advance(seed_application('', 'tuktuk', ['note' => 'No code']), 'complete');
    seed_advance(seed_application('', 'stove', ['note' => 'No code, still waiting']), 'submitted');

    $scenarios[] = ['Unknown code', 'accepted, attributed to nobody, earns nobody anything'];
    $scenarios[] = ['No code at all', 'the company keeps the whole sale'];

    /* --- 7. a code belonging to a dealer nobody approved, and to one switched
             off, and to the inactive distributor: all three book nothing --- */
    $sleeping = $dealers[1][10];        /* switched off, but has a code */
    seed_advance(seed_application((string) $sleeping['dealer_code'], 'stove',
        ['note' => 'Switched-off dealer code']), 'docs_pending');

    seed_advance(seed_application((string) $distributors[5]['distributor_code'], 'tuktuk',
        ['note' => 'Switched-off distributor code']), 'booking_pending');

    $scenarios[] = ['Code of a partner who is switched off', 'the sale is accepted and books nothing'];

    /* --- 8. a referral whose reward has been paid, and one cancelled --- */
    $paidRef = $completed[1] ?? $completed[0];
    $rewarded = seed_advance(
        seed_application((string) $paidRef['referral_code'], 'stove', ['note' => 'Reward already sent']),
        'complete'
    );
    db()->prepare("UPDATE applications SET referral_reward_status = 'sent', referral_reward_sent_at = ?,
                   referral_reward_note = 'Transferred by UPI.' WHERE id = ?")
        ->execute([seed_when(20), (int) $rewarded['id']]);

    $refused = seed_advance(
        seed_application((string) $paidRef['referral_code'], 'tuktuk', ['note' => 'Reward cancelled']),
        'complete'
    );
    db()->prepare("UPDATE applications SET referral_reward_status = 'cancelled',
                   referral_reward_note = 'Duplicate of an earlier application.' WHERE id = ?")
        ->execute([(int) $refused['id']]);

    $scenarios[] = ['Referral reward states', 'pending, sent and cancelled all present'];

    /* --- 8a1. a zonal C&F's own code on the form: no distributor, no dealer,
             so only the top two tiers are on the sale --- */
    seed_advance(
        seed_application('', 'stove', [
            'partner'    => (string) $zonals[1]['zonal_code'],
            'created_at' => seed_recent(),
            'note'       => 'Zonal C&F code quoted directly',
        ]),
        'complete'
    );

    seed_advance(
        seed_application('', 'tuktuk', [
            'partner'    => (string) $zonals[3]['zonal_code'],
            'created_at' => seed_recent(),
            'note'       => 'Switched-off zonal code',
        ]),
        'booking_pending'
    );

    $scenarios[] = ['Zonal C&F code on the website',
        'the 15 % and 20 % tiers only; the switched-off zonal\'s code books nothing'];

    /* --- 8a2. the low-stock dual-approval gate ----
             All four off the one dealer sitting below the red line, because
             what the drawer has to show is the same held sale with each half
             of the signature missing, then with both. */
    $redDealer = $dealers[4][2];
    $lowStock  = [];

    foreach ([
        'neither desk has signed'              => [],
        'Manifold Office has signed'           => ['admin'],
        'Principal C&F has signed'             => ['principal_cf'],
        'both have signed and the hold is off' => ['principal_cf', 'admin'],
    ] as $note => $signers) {
        $app = seed_advance(
            seed_application((string) $redDealer['dealer_code'], 'stove', [
                'created_at' => seed_recent(),
                'note'       => 'Low-stock hold — ' . $note,
            ]),
            'booking_pending'
        );

        foreach ($signers as $role) {
            approve_low_stock_sale((int) $app['id'], $role, 1);
        }

        /* with both signatures in, the money can move and the sale runs on */
        $lowStock[] = count($signers) === 2
            ? seed_advance(application((int) $app['id']), 'complete')
            : application((int) $app['id']);
    }

    $scenarios[] = ['Low-stock dual approval',
        'a held sale with neither signature, with each one on its own, and one released by both'];
    $scenarios[] = ['Stock bands',
        'a dealer in the yellow band (≤ ' . stock_warn_units() . ') and one below the red line (≤ '
        . stock_critical_units() . '), the rest clear of both'];

    /* --- 8a3. the 48-hour cancel window, at three points on it ---
             The window opens when the booking payment is verified and the
             documents are still unchecked. The portal draws a live countdown off
             payment_deadline_at, so one booking has most of its window left, one
             is nearly out of it, and one is past it (cancelling is closed). */
    foreach ([
        ['+46 hours', 'Cancel window - most of it left'],
        ['+95 minutes', 'Cancel window - nearly out of time'],
        ['-6 hours', 'Cancel window - already closed'],
    ] as [$offset, $note]) {
        $app = seed_application((string) $dealers[4][3]['dealer_code'], 'stove',
            ['created_at' => seed_recent(), 'note' => $note]);
        seed_approve((int) $app['id'], seed_recent());
        seed_payment((int) $app['id'], 'booking', 'verified', seed_recent());
        db()->prepare('UPDATE applications SET payment_deadline_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', strtotime($offset)), (int) $app['id']]);
    }

    $scenarios[] = ['Cancel window', 'one comfortably inside 48 h, one nearly out, one closed'];

    /* --- 8a4. what finance has and has not looked at ---
             docs_refused() and docs_resent() read three different shapes out of
             the same four columns, and the drawer shows a different panel for
             each, so each shape needs a row of its own. */
    $refused = seed_advance(
        seed_application((string) $dealers[4][4]['dealer_code'], 'tuktuk',
            ['created_at' => seed_recent(), 'note' => 'Documents turned down']),
        'booking_pending'
    );
    docs_reject((int) $refused['id'], 1, 'The electricity bill is older than three months.');

    $resent = seed_advance(
        seed_application((string) $dealers[4][4]['dealer_code'], 'stove',
            ['created_at' => seed_recent(), 'note' => 'Documents turned down, then sent again']),
        'booking_pending'
    );
    docs_reject((int) $resent['id'], 1, 'The Aadhaar scan is unreadable.');
    db()->prepare('UPDATE applications SET docs_resent_at = NOW(),
                   aadhaar_doc_path = ?, electricity_bill_path = ? WHERE id = ?')
        ->execute([
            seed_file(UPLOAD_DIR, 'Aadhaar card, second attempt'),
            seed_file(UPLOAD_DIR, 'Electricity bill, second attempt'),
            (int) $resent['id'],
        ]);

    $scenarios[] = ['Document verification states',
        'verified, turned down, turned down and resent, and not yet looked at'];

    /* --- 8a5. money taken at the counter, not through the gateway ---
             partner_collect_payment() refuses until the KYC pair is verified,
             which is the whole point of the desk, so the verdict is stamped by
             docs_verify() first and the receipt lands on the sale after. */
    foreach ([
        ['dealer', $dealers[4][5], 'booking', 'cash', 'Counter cash'],
        ['distributor', $distributors[4], 'full', 'upi', 'UPI at the depot'],
    ] as [$who, $party, $stage, $mode, $label]) {
        $code = $who === 'dealer' ? (string) $party['dealer_code'] : (string) $party['distributor_code'];
        $app  = seed_advance(
            seed_application($code, 'stove', [
                'created_at' => seed_recent(),
                'plan'       => $stage === 'full' ? 'full_payment' : 'booking',
                'note'       => 'Collected at the counter by a ' . $who,
            ]),
            'booking_pending'
        );

        $amount = $stage === 'full'
            ? stage_amount($app, 'full')
            : (float) $app[$stage . '_amount'];

        docs_verify((int) $app['id'], 1, 'Seeded office');
        partner_collect_payment(
            (int) $app['id'], $who, (int) $party['id'], $stage, $amount, $mode, $label,
            seed_file(PAYMENT_PROOF_DIR, 'Counter receipt')
        );
    }

    $scenarios[] = ['Partner-collected payment',
        'a dealer took the booking in cash and a distributor the lot by UPI — sale_channel direct, '
        . 'with a receipt file against each'];

    /* --- 8b. lifting the 48-hour hold ---
       `release_at` is stamped 48 hours out when a line is written and nothing
       can be done with the line until it passes — neither a claim nor a
       transfer. Waiting two days is not an option, so the hold is backdated on
       three lines in four and lifted by the function the cron calls. The
       untouched quarter is what the 'Held 48 h' chip and the greyed-out tick
       boxes need, and it is taken per tier so no tier is left without one. */
    $pending = db()->prepare(
        "SELECT c.id FROM commission_lines c JOIN applications a ON a.id = c.application_id
          WHERE a.admin_note LIKE '%" . SEED_TAG . "%' AND c.payout_status = 'pending'
            AND c.party_type = ?
          ORDER BY c.id"
    );

    foreach (['principal_cf', 'zonal_cf', 'distributor', 'dealer'] as $tier) {
        $pending->execute([$tier]);
        $held = $pending->fetchAll(PDO::FETCH_COLUMN);
        $due  = array_slice($held, (int) ceil(count($held) / 4));

        if ($due) {
            db()->exec('UPDATE commission_lines SET release_at = DATE_SUB(NOW(), INTERVAL 3 HOUR)
                         WHERE id IN (' . implode(',', array_map('intval', $due)) . ')');
        }
    }

    $made['commission lines released'] = commission_release_due();

    /* (8c, the commission voucher chain, is gone with the vouchers: money now
       passes down the chain, 8d below.) */

    /* --- 8d. commission passing down the chain ---
       Through commission_transfer(), the function every payouts screen posts
       to. Each holder, starting with the office, sends two of every three
       partners it owes and keeps the third, so money sits at every tier, some
       has reached its partner and some is still with the office. Only seeded
       partners are ever paid: the office's pile holds real lines too. */
    $seedHolders = [['office', null]];

    for ($round = 0; $round < 4 && $seedHolders; $round++) {
        $nextHolders = [];

        foreach ($seedHolders as [$hType, $hId]) {
            foreach (array_values(commission_passdown_due($hType, $hId)) as $i => $row) {
                $to = commission_party_row($row['to_type'], (int) $row['to_id']);

                if ($i % 3 === 2 || !$to || strpos((string) ($to['note'] ?? ''), SEED_TAG) === false) {
                    continue;
                }

                [$tid] = commission_transfer($hType, $hId, $row['to_type'], (int) $row['to_id'], $row['amount'],
                    'UTR' . mt_rand(100000000, 999999999), seed_file(PAYMENT_PROOF_DIR, 'NEFT advice'), 'the seed');

                if ($tid) {
                    $made['commission transfers']++;
                    $nextHolders[] = [$row['to_type'], (int) $row['to_id']];
                }
            }
        }

        $seedHolders = $nextHolders;
    }

    $scenarios[] = ['Payout lifecycle',
        'lines still inside the hold, released ones with the office or part-way down the chain, '
        . 'and ones that have reached their partner, each hop with a UTR and an advice'];

    /* --- 9. money going back out: part of what is owed, paid --- */
    foreach ($distributors as $dist) {
        $owed = commission_totals('distributor', (int) $dist['id']);

        if ($owed['remaining'] > 0) {
            $part = round($owed['remaining'] * 0.6, 2);
            db()->prepare('INSERT INTO distributor_payouts (distributor_id, amount, note, paid_at)
                           VALUES (?, ?, ?, ?)')
                ->execute([(int) $dist['id'], $part, 'Part payment ' . SEED_TAG, seed_when(15)]);
            $made['payouts']++;
        }
    }

    foreach ($dealers as $group) {
        foreach ($group as $dealer) {
            $owed = commission_totals('dealer', (int) $dealer['id']);

            if ($owed['remaining'] > 0 && mt_rand(0, 1)) {
                $part = round($owed['remaining'] * (mt_rand(4, 10) / 10), 2);
                db()->prepare('INSERT INTO dealer_payouts (dealer_id, amount, note, paid_at)
                               VALUES (?, ?, ?, ?)')
                    ->execute([(int) $dealer['id'], $part, 'Part payment ' . SEED_TAG, seed_when(15)]);
                $made['payouts']++;
            }
        }
    }

    $scenarios[] = ['Partly paid partners', 'earned, paid and still owed are all non-zero'];

    /* --- 10. the blog: one of every state it can be in --- */
    $blogRows = [
        ['how the hydrogen stove actually works',      'published',   seed_when(40)],
        ['what a tuktuk owner saves in a year',        'published',   seed_when(12)],
        ['becoming a Manifold dealer',                 'scheduled',   date('Y-m-d H:i:s', strtotime('+5 days'))],
        ['behind the build: our Ahmedabad workshop',   'draft',       null],
        ['an announcement we have since pulled',       'unpublished', seed_when(80)],
    ];

    foreach ($blogRows as [$title, $status, $publishAt]) {
        $slug = 'seed-' . bin2hex(random_bytes(3)) . '-'
            . preg_replace('/[^a-z0-9]+/', '-', strtolower($title));

        db()->prepare(
            'INSERT INTO blog_posts (slug, title, subtitle, body, status, publish_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            mb_substr($slug, 0, 160),
            ucfirst($title),
            'A seeded article, here so the blog screens have something to show.',
            '<p>Seeded copy for <strong>' . e(ucfirst($title)) . '</strong>. Nothing here is real — it '
            . 'exists so the published list, the scheduled queue and the draft and unpublished states '
            . 'can all be seen.</p><p>Second paragraph, for the reading view.</p>',
            $status, $publishAt, seed_when(mt_rand(5, 90)),
        ]);
        $made['blog posts']++;
    }

    $scenarios[] = ['Blog states', 'published, scheduled, draft and unpublished posts all present'];

    /* --- 11. contact enquiries and newsletter signups, across every state --- */
    $interests = ['stove', 'tuktuk', 'dealership', 'distribution', 'other'];

    foreach (['new', 'new', 'accepted', 'contacted', 'rejected'] as $i => $state) {
        [$c, $s, $p] = seed_city();
        $interest = $interests[$i % count($interests)];

        db()->prepare(
            'INSERT INTO contact_messages (status, name, company, email, phone, interest, city,
                                           message, consent, admin_note, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
        )->execute([
            $state, seed_name(), null, seed_email('client'), seed_mobile(), $interest, $c,
            'Seeded enquiry — is the ' . $interest . ' available in ' . $c . '?',
            SEED_TAG, '127.0.0.1', seed_when(mt_rand(1, 60)),
        ]);
        $made['contact messages']++;

        db()->prepare(
            'INSERT INTO newsletter_subscribers (status, email, source_page, admin_note, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $state, 'seed-news-' . bin2hex(random_bytes(3)) . '@yopmail.com',
            'index', SEED_TAG, '127.0.0.1', seed_when(mt_rand(1, 90)),
        ]);
        $made['newsletter signups']++;
    }

    $scenarios[] = ['Contact & newsletter', 'both boxes seeded across new, accepted, contacted and rejected'];

    /* --- 12. raffle winners, only when a draw is actually set up and running,
             so this is a no-op on a site that has not turned the raffle on --- */
    if (function_exists('raffle_running') && raffle_running()) {
        raffle_sync();
        $draws = raffle_all_draws(1);
        $draw  = $draws[0] ?? null;

        if ($draw) {
            $eligible = db()->query(
                "SELECT id FROM applications
                  WHERE admin_note LIKE '%" . SEED_TAG . "%'
                    AND booking_paid_at IS NOT NULL AND status <> 'rejected'
                  ORDER BY id LIMIT 3"
            )->fetchAll(PDO::FETCH_COLUMN);

            foreach ($eligible as $appId) {
                if (raffle_add_winner((int) $draw['id'], (int) $appId) === '') {
                    $made['raffle winners']++;
                }
            }
        }

        if ($made['raffle winners'] > 0) {
            $scenarios[] = ['Raffle winners', 'seeded onto the current draw'];
        }
    }

    /* ---- a few sign-ins worth writing down ---- */
    $sample['A principal C&F'] = $principals[1]['email'] . ' — code ' . $principals[1]['principal_code'];
    $sample['A zonal C&F'] = $zonals[1]['email'] . ' — code ' . $zonals[1]['zonal_code'];
    $sample['A distributor'] = $distributors[1]['email'] . ' — code ' . $distributors[1]['distributor_code'];
    $sample['A dealer'] = $dealers[1][1]['email'] . ' — code ' . $dealers[1][1]['dealer_code'];

    $client = db()->query("SELECT full_name, email, reference_code, referral_code FROM applications
                            WHERE admin_note LIKE '%" . SEED_TAG . "%' AND status = 'complete'
                            ORDER BY id LIMIT 1")->fetch();

    if ($client) {
        $sample['A client'] = $client['email'] . ' — ' . $client['reference_code']
            . ', own code ' . $client['referral_code'];
    }
}

/* ------------------------------------------------------------ the report */

$totals = db()->query(
    "SELECT
        (SELECT COUNT(*) FROM principal_cf WHERE note LIKE '%" . SEED_TAG . "%') AS principals,
        (SELECT COUNT(*) FROM zonal_cf WHERE note LIKE '%" . SEED_TAG . "%') AS zonals,
        (SELECT COUNT(*) FROM distributors WHERE note LIKE '%" . SEED_TAG . "%') AS dists,
        (SELECT COUNT(*) FROM dealers WHERE note LIKE '%" . SEED_TAG . "%') AS deals,
        (SELECT COUNT(*) FROM applications WHERE admin_note LIKE '%" . SEED_TAG . "%') AS apps"
)->fetch();

/* the grid the payouts desk is read through: one row per tier per payout state */
$byPayout = db()->query(
    "SELECT c.party_type, c.payout_status, COUNT(*) n, COALESCE(SUM(c.amount), 0) total
       FROM commission_lines c JOIN applications a ON a.id = c.application_id
      WHERE a.admin_note LIKE '%" . SEED_TAG . "%'
      GROUP BY c.party_type, c.payout_status
      ORDER BY FIELD(c.party_type, 'principal_cf', 'zonal_cf', 'distributor', 'dealer'),
               FIELD(c.payout_status, 'pending', 'approved', 'proof_uploaded', 'disbursed')"
)->fetchAll();

$byStatus = db()->query(
    "SELECT status, COUNT(*) n FROM applications WHERE admin_note LIKE '%" . SEED_TAG . "%'
      GROUP BY status ORDER BY n DESC"
)->fetchAll();

$money = db()->query(
    "SELECT
        (SELECT COALESCE(SUM(amount),0) FROM commission_lines cl
          JOIN applications a ON a.id = cl.application_id
         WHERE a.admin_note LIKE '%" . SEED_TAG . "%' AND cl.party_type = 'dealer') AS dealer_earned,
        (SELECT COALESCE(SUM(amount),0) FROM commission_lines cl
          JOIN applications a ON a.id = cl.application_id
         WHERE a.admin_note LIKE '%" . SEED_TAG . "%' AND cl.party_type = 'distributor') AS dist_earned,
        (SELECT COALESCE(SUM(referral_reward),0) FROM applications
         WHERE admin_note LIKE '%" . SEED_TAG . "%' AND referral_reward_status IN ('pending','sent')) AS rewards"
)->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seeder — Manifold Clean Energy</title>
<style>
  :root{color-scheme:light}
  *{box-sizing:border-box}
  body{
    margin:0;padding:48px 20px;background:#f4f7fa;
    font:15px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#0f2c4d;
  }
  .wrap{max-width:820px;margin-inline:auto}
  .card{background:#fff;border:1px solid #e3ebf2;border-radius:14px;padding:28px 30px;margin-bottom:20px}
  h1{margin:0 0 6px;font-size:26px;letter-spacing:-.02em}
  h2{margin:0 0 14px;font-size:15px;text-transform:uppercase;letter-spacing:.1em;color:#5c7389}
  .done{display:inline-flex;align-items:center;gap:9px;background:#0c7a74;color:#fff;
        border-radius:999px;padding:8px 18px;font-weight:700;font-size:14px}
  table{width:100%;border-collapse:collapse;font-size:14px}
  td,th{text-align:left;padding:8px 10px;border-bottom:1px solid #eef3f8;vertical-align:top}
  th{color:#5c7389;font-weight:600}
  td.n{text-align:right;font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap}
  code{background:#f4f7fa;border:1px solid #e3ebf2;border-radius:5px;padding:1px 6px;font-size:13px}
  .muted{color:#5c7389}
  .links a{display:inline-block;margin:0 14px 8px 0;color:#0c7a74;font-weight:600}
  .warn{background:#fff6ed;border-color:#ffd9b0}
</style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <h1>Seeding complete</h1>
    <p class="muted" style="margin:0 0 18px">
      Test data for <strong><?= e(DB_NAME) ?></strong>, built through the application's own rules —
      attribution, statuses and commission all came from the live code, not from this file.
    </p>
    <span class="done">✓ Seeded data now in the database:
      <?= (int) $totals['apps'] ?> applications, <?= (int) $totals['deals'] ?> dealers,
      <?= (int) $totals['dists'] ?> distributors, <?= (int) $totals['zonals'] ?> zonal C&amp;F,
      <?= (int) $totals['principals'] ?> principal C&amp;F</span>
    <p class="muted" style="margin:14px 0 0">
      That is the running total for everything tagged <code><?= e(SEED_TAG) ?></code>. What this
      particular run added is below — run it again and it adds another batch on top, or use
      <code>?wipe=1</code> to start from one.
    </p>
  </div>

  <?php if ($removed): ?>
    <div class="card warn">
      <h2>Removed first</h2>
      <table>
        <?php foreach ($removed as $what => $n): ?>
          <tr><td><?= e($what) ?></td><td class="n"><?= (int) $n ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>

  <?php if (!$onlyWipe): ?>
    <div class="card">
      <h2>What this run made</h2>
      <table>
        <?php foreach ($made as $what => $n): ?>
          <tr><td><?= e(ucfirst($what)) ?></td><td class="n"><?= (int) $n ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div class="card">
      <h2>Applications by status</h2>
      <table>
        <?php foreach ($byStatus as $row): ?>
          <tr>
            <td><?= e(status_label((string) $row['status'], 'admin')) ?>
              <span class="muted">— <?= e((string) $row['status']) ?></span></td>
            <td class="n"><?= (int) $row['n'] ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div class="card">
      <h2>Commission by tier and payout state</h2>
      <table>
        <tr><th>Tier</th><th>State</th><th class="n">Lines</th><th class="n">Amount</th></tr>
        <?php foreach ($byPayout as $row): ?>
          <tr>
            <td><?= e(str_replace('_cf', ' C&F', (string) $row['party_type'])) ?></td>
            <td class="muted"><?= e(payout_status_label((string) $row['payout_status'])) ?></td>
            <td class="n"><?= (int) $row['n'] ?></td>
            <td class="n"><?= e(money((float) $row['total'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div class="card">
      <h2>Money now on the books</h2>
      <table>
        <tr><td>Dealer commission earned</td><td class="n"><?= e(money((float) $money['dealer_earned'])) ?></td></tr>
        <tr><td>Distributor commission earned</td><td class="n"><?= e(money((float) $money['dist_earned'])) ?></td></tr>
        <tr><td>Referral rewards booked</td><td class="n"><?= e(money((float) $money['rewards'])) ?></td></tr>
      </table>
    </div>

    <div class="card">
      <h2>Scenarios covered</h2>
      <table>
        <?php foreach ($scenarios as [$what, $detail]): ?>
          <tr><td><strong><?= e($what) ?></strong></td><td class="muted"><?= e($detail) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>

    <?php if ($sample): ?>
      <div class="card">
        <h2>Sign in as</h2>
        <table>
          <?php foreach ($sample as $who => $detail): ?>
            <tr><td><?= e($who) ?></td><td><code><?= e($detail) ?></code></td></tr>
          <?php endforeach; ?>
        </table>
        <p class="muted" style="margin:14px 0 0">
          Every address is a <strong>yopmail.com</strong> mailbox — open
          <a href="https://yopmail.com" target="_blank" rel="noopener">yopmail.com</a>, paste the
          address, and the one-time sign-in code is there. Partners and clients all sign in at
          <code>/portal</code>.
        </p>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="card">
    <h2>Where to look</h2>
    <p class="links">
      <a href="admin/">Admin dashboard</a>
      <a href="admin/dealers">Dealers</a>
      <a href="admin/distributors">Distributors</a>
      <a href="admin/commissions">Commission payouts</a>
      <a href="admin/referrals">Referrals</a>
      <a href="admin/blog">Blog</a>
      <a href="portal/">Client &amp; partner portal</a>
    </p>
    <p class="muted" style="margin:6px 0 0">
      Run again to add another batch, <code>?wipe=1</code> to clear this seeder's data and rebuild,
      or <code>?only=wipe</code> to clear it and stop. Only rows tagged <code><?= e(SEED_TAG) ?></code>
      are ever removed.
    </p>
  </div>

</div>
</body>
</html>
