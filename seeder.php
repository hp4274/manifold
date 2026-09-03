<?php
/**
 * Test data, for a database nobody minds losing.
 *
 * Open http://localhost/manifold/seeder.php and it builds a network — five
 * distributors, ten dealers under each, and clients arranged so that every
 * route into a sale, every commission split and every referral outcome is
 * represented at least once. It prints what it made and stops.
 *
 *   ?wipe=1   removes everything a previous run made, then seeds again
 *   ?only=... 'wipe' removes and does not re-seed
 *
 * Nothing here invents its own rules: applications are attributed by the same
 * resolution the public form uses, statuses are decided by
 * sync_application_status() and commission is written by commission_write_lines()
 * through it. If the business rules change, this seeder changes with them
 * rather than quietly disagreeing.
 *
 * Every seeded row is tagged (SEED_TAG below) so ?wipe=1 can find them again
 * and real data is never touched.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin/lib.php';

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

@set_time_limit(300);

const SEED_TAG = '[seed]';

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
        $table = ['client' => 'applications', 'dealer' => 'dealers', 'distributor' => 'distributors'][$who]
            ?? 'applications';

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
        $removed['dealer_payouts'] = (int) $db->exec("DELETE FROM dealer_payouts WHERE dealer_id IN ({$in})");
        $removed['stock (dealers)'] = (int) $db->exec(
            "DELETE FROM stock_ledger WHERE owner_type = 'dealer' AND owner_id IN ({$in})"
        );
        $removed['dealers'] = (int) $db->exec("DELETE FROM dealers WHERE id IN ({$in})");
    }

    if ($distIds) {
        $in = implode(',', array_map('intval', $distIds));
        $removed['distributor_payouts'] = (int) $db->exec("DELETE FROM distributor_payouts WHERE distributor_id IN ({$in})");
        $removed['stock (distributors)'] = (int) $db->exec(
            "DELETE FROM stock_ledger WHERE owner_type = 'distributor' AND owner_id IN ({$in})"
        );
        $removed['distributors'] = (int) $db->exec("DELETE FROM distributors WHERE id IN ({$in})");
    }

    $removed = array_filter($removed);
}

/* --------------------------------------------------------------- the build */

$made = ['distributors' => 0, 'dealers' => 0, 'applications' => 0, 'payouts' => 0, 'stock rows' => 0];
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

    /* nobody refers themselves */
    $self = $referrer && strcasecmp((string) ($referrer['email'] ?? ''), $email) === 0;

    $split = commission_split($product, $dealer, $distributor);
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
        'payment_amount'         => (float) $plan['booking'],
        'dealer_id'              => $dealer ? (int) $dealer['id'] : null,
        'dealer_commission'      => $split['dealer'],
        'distributor_id'         => $distributor ? (int) $distributor['id'] : null,
        'distributor_commission' => $split['distributor'],
        'referred_by_code'       => $quoted === '' ? null : $quoted,
        'referred_by_id'         => $referrer && !$self ? (int) $referrer['id'] : null,
        'referral_reward'        => $referrer && !$self ? referral_reward() : 0.0,
        'referral_reward_status' => $referrer && !$self ? 'pending' : 'none',
        'sale_channel'           => 'online',
        'declaration_accepted'   => 1,
        'terms_accepted'         => 1,
        'testimonial_consent'    => mt_rand(0, 1),
        'admin_note'             => trim(($opts['note'] ?? '') . ' ' . SEED_TAG),
        'created_at'             => $when,
    ];

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

/** The office's decision. */
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

/** Finance's verdict on the paperwork. */
function seed_docs_verified(int $id, string $when): void
{
    db()->prepare('UPDATE applications SET docs_verified_at = ?, docs_verified_by = NULL WHERE id = ?')
        ->execute([$when, $id]);
    sync_application_status($id);
}

/** The customer's answer to "go ahead, or cancel?". */
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
 * 'confirm_pending' · 'delivery_pending' · 'delivery_review' · 'complete' ·
 * 'cancelled' · 'rejected'
 */
function seed_advance(array $app, string $to): array
{
    $id = (int) $app['id'];
    $t  = strtotime((string) $app['created_at']);
    $at = static function (int $days) use ($t): string {
        return date('Y-m-d H:i:s', $t + $days * 86400 + mt_rand(3600, 40000));
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

    seed_docs_verified($id, $at(7));

    if ($to === 'confirm_pending') {
        return db()->query('SELECT * FROM applications WHERE id = ' . $id)->fetch();
    }

    if ($to === 'cancelled') {
        seed_delivery_choice($id, 'cancel', $at(9));

        return db()->query('SELECT * FROM applications WHERE id = ' . $id)->fetch();
    }

    seed_delivery_choice($id, 'continue', $at(9));

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

/* ---------------------------------------------------------- the network */

if (!$onlyWipe) {
    $distributors = [];

    for ($d = 1; $d <= 5; $d++) {
        [$city, $state, $pin] = seed_city();
        $code = make_distributor_code();
        $name = seed_name();

        db()->prepare(
            'INSERT INTO distributors (distributor_code, full_name, company, email, mobile_number,
                                       address, city, state, pin_code, pan_number, bank_name,
                                       bank_account, bank_ifsc, upi_id, note, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $code, $name, $name . ' Energy Distribution', seed_email('distributor'), seed_mobile(),
            mt_rand(1, 90) . ', Industrial Estate', $city, $state, $pin,
            'AAAPZ' . mt_rand(1000, 9999) . 'A', 'HDFC Bank',
            (string) mt_rand(10000000000, 99999999999), 'HDFC000' . mt_rand(1000, 9999),
            strtolower(explode(' ', $name)[0]) . '@okhdfcbank',
            'Distributor ' . $d . ' ' . SEED_TAG,
            /* the fifth is switched off, so its code books nothing */
            $d === 5 ? 0 : 1,
            seed_when(200),
        ]);

        $distributors[$d] = distributor_by_id((int) db()->lastInsertId());
        $made['distributors']++;

        /* stock on the shelf, so the ordering screens have something to show */
        stock_move('distributor', (int) $distributors[$d]['id'], 'stove',
            mt_rand(10, 30), mt_rand(10, 30) * 17000.0, 'purchase', null, null, 'Opening stock ' . SEED_TAG);
        stock_move('distributor', (int) $distributors[$d]['id'], 'tuktuk',
            mt_rand(5, 15), mt_rand(5, 15) * 25500.0, 'purchase', null, null, 'Opening stock ' . SEED_TAG);
        $made['stock rows'] += 2;
    }

    $scenarios[] = ['Distributor switched off', 'Dis 5 is inactive — its code books nothing'];

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
                stock_move('dealer', (int) $dealer['id'], 'stove',
                    mt_rand(2, 8), mt_rand(2, 8) * 18500.0, 'purchase', null, null, 'Opening stock ' . SEED_TAG);
                $made['stock rows']++;
            }
        }
    }

    $scenarios[] = ['Dealer waiting on the office', 'the 9th under each distributor — no code, books nothing'];
    $scenarios[] = ['Dealer switched off', 'the 10th under each — code exists, books nothing'];

    /* ------------------------------------------------------- the clients */

    $products = ['stove', 'tuktuk'];
    $statuses = ['submitted', 'booking_pending', 'booking_review', 'docs_pending',
                 'confirm_pending', 'delivery_pending', 'delivery_review', 'complete',
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
                $app = seed_advance(
                    seed_application((string) $dealer['dealer_code'], $products[$i % 2],
                        ['note' => 'Dealer link']),
                    $status
                );

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

    /* ---- a few sign-ins worth writing down ---- */
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
        (SELECT COUNT(*) FROM distributors WHERE note LIKE '%" . SEED_TAG . "%') AS dists,
        (SELECT COUNT(*) FROM dealers WHERE note LIKE '%" . SEED_TAG . "%') AS deals,
        (SELECT COUNT(*) FROM applications WHERE admin_note LIKE '%" . SEED_TAG . "%') AS apps"
)->fetch();

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
      <?= (int) $totals['dists'] ?> distributors</span>
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
      <a href="admin/referrals">Referrals</a>
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
