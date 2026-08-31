<?php
/**
 * Fills the database with a working example of the whole business.
 *
 * Everything it writes is marked `[demo]` in a note or an admin note, so
 * `--clear` can take it all out again without touching real records. Run it
 * from the command line:
 *
 *   C:\xampp\php\php.exe C:\xampp\htdocs\manifold\admin\seed-demo.php
 *   C:\xampp\php\php.exe C:\xampp\htdocs\manifold\admin\seed-demo.php --clear
 *
 * It builds a chain deep enough to exercise every screen: distributors with
 * dealers under them, applications at every status, receipts waiting and
 * verified, stock bought and sold, referrals owed, and commission vouchers at
 * each stage of their journey.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This is a command-line job.\n");
}

require_once __DIR__ . '/lib.php';

const DEMO_MARK = '[demo]';

/* ---------- clearing ---------- */

if (in_array('--clear', $argv, true)) {
    $db = db();

    $dealerIds = $db->query("SELECT id FROM dealers WHERE note LIKE '%" . DEMO_MARK . "%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $distIds   = $db->query("SELECT id FROM distributors WHERE note LIKE '%" . DEMO_MARK . "%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $appIds    = $db->query("SELECT id FROM applications WHERE admin_note LIKE '%" . DEMO_MARK . "%'")
        ->fetchAll(PDO::FETCH_COLUMN);

    $in = static fn (array $ids): string => $ids ? implode(',', array_map('intval', $ids)) : '0';

    $db->exec('DELETE FROM commission_voucher_lines WHERE application_id IN (' . $in($appIds) . ')');
    $db->exec('DELETE FROM payments WHERE application_id IN (' . $in($appIds) . ')');
    $db->exec('DELETE FROM applications WHERE id IN (' . $in($appIds) . ')');

    $db->exec("DELETE FROM commission_vouchers WHERE party_type = 'dealer' AND party_id IN (" . $in($dealerIds) . ')');
    $db->exec("DELETE FROM commission_vouchers WHERE party_type = 'distributor' AND party_id IN (" . $in($distIds) . ')');
    $db->exec('DELETE FROM stock_ledger WHERE (owner_type = \'dealer\' AND owner_id IN (' . $in($dealerIds) . '))
                  OR (owner_type = \'distributor\' AND owner_id IN (' . $in($distIds) . '))');
    $db->exec('DELETE FROM stock_orders WHERE (buyer_type = \'dealer\' AND buyer_id IN (' . $in($dealerIds) . '))
                  OR (buyer_type = \'distributor\' AND buyer_id IN (' . $in($distIds) . '))');
    $db->exec('DELETE FROM dealer_payouts WHERE dealer_id IN (' . $in($dealerIds) . ')');
    $db->exec('DELETE FROM distributor_payouts WHERE distributor_id IN (' . $in($distIds) . ')');
    $db->exec('DELETE FROM dealers WHERE id IN (' . $in($dealerIds) . ')');
    $db->exec('DELETE FROM distributors WHERE id IN (' . $in($distIds) . ')');
    $db->exec("DELETE FROM contact_messages WHERE message LIKE '%" . DEMO_MARK . "%'");
    $db->exec("DELETE FROM newsletter_subscribers WHERE email LIKE '%demo.invalid'");

    echo "Demo data cleared.\n";
    exit;
}

/* ---------- people ---------- */

$db  = db();
$now = new DateTimeImmutable('now');

/** A date this many days ago, as the database wants it. */
$ago = static fn (int $days): string => (new DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d H:i:s');

$distributorSeeds = [
    ['Meera Raval',   'Raval Energy',      'meera@demo.invalid',   'Surat',     'Gujarat'],
    ['Arjun Deshmukh', 'Deshmukh Traders', 'arjun@demo.invalid',   'Pune',      'Maharashtra'],
    ['Fatima Sheikh', 'Sheikh Distributors', 'fatima@demo.invalid', 'Hyderabad', 'Telangana'],
];

$distIds = [];

foreach ($distributorSeeds as $i => [$name, $company, $email, $city, $state]) {
    $code = make_distributor_code();

    $db->prepare(
        'INSERT INTO distributors (distributor_code, full_name, company, email, mobile_number, city, state,
                                   pan_number, bank_name, bank_account, bank_ifsc, upi_id, note, is_active,
                                   created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $code, $name, $company, $email, '98' . str_pad((string) (76000000 + $i), 8, '0'),
        $city, $state, 'ABCDE' . (1234 + $i) . 'F', 'HDFC Bank', '5010' . (10000000 + $i), 'HDFC0001234',
        strtolower(explode(' ', $name)[0]) . '@upi', DEMO_MARK . ' seeded distributor',
        $i === 2 ? 0 : 1,   /* one switched off, so the filters have something to find */
        $ago(120 - $i * 10),
    ]);

    $distIds[] = (int) $db->lastInsertId();
}

$dealerSeeds = [
    [0, 'Rohit Patel',  'Patel Agencies',  'rohit@demo.invalid',  'Surat',   'approved', 1],
    [0, 'Sana Qureshi', 'Qureshi Sales',   'sana@demo.invalid',   'Navsari', 'approved', 1],
    [0, 'Vikram Joshi', '',                'vikram@demo.invalid', 'Valsad',  'pending',  1],
    [1, 'Nisha Kulkarni', 'NK Energy',     'nisha@demo.invalid',  'Pune',    'approved', 1],
    [1, 'Imran Shaikh', '',                'imran@demo.invalid',  'Nashik',  'approved', 0],
    [2, 'Divya Rao',    'Rao Traders',     'divya@demo.invalid',  'Warangal', 'approved', 1],
];

$dealerIds = [];

foreach ($dealerSeeds as $i => [$distIx, $name, $company, $email, $city, $approval, $active]) {
    $code = make_dealer_code();

    $db->prepare(
        'INSERT INTO dealers (dealer_code, distributor_id, approval_status, decided_at, full_name, company,
                              email, mobile_number, city, state, pan_number, bank_name, bank_account,
                              bank_ifsc, upi_id, note, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $code, $distIds[$distIx], $approval, $approval === 'approved' ? $ago(90 - $i) : null,
        $name, $company, $email, '97' . str_pad((string) (76000000 + $i), 8, '0'),
        $city, $distributorSeeds[$distIx][4], 'PQRST' . (2345 + $i) . 'G', 'ICICI Bank',
        '6010' . (20000000 + $i), 'ICIC0004321', strtolower(explode(' ', $name)[0]) . '@upi',
        DEMO_MARK . ' seeded dealer', $active, $ago(95 - $i * 3),
    ]);

    $dealerIds[] = (int) $db->lastInsertId();
}

echo count($distIds), " distributors, ", count($dealerIds), " dealers\n";

/* ---------- stock: bought from the office, passed down, sold ---------- */

foreach ([0, 1] as $distIx) {
    /* both products on one order, which is what a real one looks like */
    [$orderId, $error] = stock_order_create(
        'distributor',
        $distIds[$distIx],
        ['stove' => 12, 'tuktuk' => 8],
        null,
        ['reference' => 'UTR' . random_int(100000, 999999), 'note' => DEMO_MARK]
    );

    if ($error === '') {
        stock_order_approve($orderId, 1);
    }
}

/* one order left waiting, so the office's queue is not empty */
stock_order_create('distributor', $distIds[2], ['tuktuk' => 5], null,
    ['reference' => 'UTR' . random_int(100000, 999999), 'note' => DEMO_MARK]);

/* a dealer buys from their distributor, and one request is left pending */
[$dealerOrder, $error] = stock_order_create('dealer', $dealerIds[0], ['stove' => 4, 'tuktuk' => 2], $distIds[0],
    ['reference' => 'UTR' . random_int(100000, 999999), 'note' => DEMO_MARK]);

if ($error === '') {
    stock_order_approve($dealerOrder);
}

stock_order_create('dealer', $dealerIds[1], ['stove' => 1, 'tuktuk' => 2], $distIds[0],
    ['reference' => 'UTR' . random_int(100000, 999999), 'note' => DEMO_MARK]);

echo "stock ordered and released\n";

/* ---------- applications, at every status ---------- */

/* The fourth column is which dealer sold it, by position in $dealerSeeds, or
   null for a sale the distributor took themselves. Spread deliberately rather
   than by a modulo: every distributor needs completed sales of their own, or
   half the commission screens have nothing to show. */
$clients = [
    ['Anand Mehta',    'anand@demo.invalid',    'stove',  'complete',         0],
    ['Bhavna Shah',    'bhavna@demo.invalid',   'tuktuk', 'complete',         1],
    ['Chirag Solanki', 'chirag@demo.invalid',   'stove',  'complete',         3],
    ['Deepa Nair',     'deepa@demo.invalid',    'tuktuk', 'complete',         5],
    ['Esha Kapoor',    'esha@demo.invalid',     'stove',  'complete',      null],
    ['Farhan Ali',     'farhan@demo.invalid',   'tuktuk', 'delivery_review',  0],
    ['Gita Menon',     'gita@demo.invalid',     'stove',  'delivery_pending', 3],
    ['Hari Vyas',      'hariv@demo.invalid',    'tuktuk', 'booking_review',   1],
    ['Ishita Bose',    'ishita@demo.invalid',   'stove',  'booking_pending',  5],
    ['Jai Thakkar',    'jai@demo.invalid',      'tuktuk', 'submitted',        0],
    ['Kavya Menon',    'kavya@demo.invalid',    'stove',  'submitted',        2],
    ['Laxman Rathod',  'laxman@demo.invalid',   'tuktuk', 'rejected',         1],
];

$appIds = [];

foreach ($clients as $i => [$name, $email, $product, $status, $dealerIx]) {
    $dealerId = $dealerIx === null ? null : $dealerIds[$dealerIx];
    $dealer   = $dealerId === null ? null : dealer_by_id($dealerId);

    /* a pending dealer books nothing, exactly as a real application would */
    $viaPendingDealer = $dealer && $dealer['approval_status'] !== 'approved';

    if ($viaPendingDealer) {
        $dealer = null;
    }

    /* no dealer means the distributor sold it themselves — except where the
       dealer was pending, where the sale earns nobody at all */
    $distributor = $dealer
        ? distributor_by_id((int) $dealer['distributor_id'])
        : ($dealerIx === null ? distributor_by_id($distIds[1]) : null);

    $plan  = payment_plan($product);
    $sale  = (float) $plan['booking'] + (float) $plan['delivery'];
    $split = commission_split($sale, $dealer, $distributor);

    $complete = $status === 'complete';

    $db->prepare(
        'INSERT INTO applications (product, status, reference_code, referral_code, full_name, email,
                                   mobile_number, city, state, street, pin_code, units_required,
                                   dealer_id, dealer_commission, distributor_id, distributor_commission,
                                   booking_amount, delivery_amount, payment_amount,
                                   booking_paid_at, delivery_paid_at, completed_at, confirmed_at,
                                   declaration_accepted, terms_accepted, admin_note, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?)'
    )->execute([
        $product, $status, 'tmp-' . bin2hex(random_bytes(5)), make_referral_code(), $name, $email,
        '96' . str_pad((string) (76000000 + $i), 8, '0'), 'Surat', 'Gujarat', '12 Demo Road', '395001', 1,
        $dealer ? (int) $dealer['id'] : null, $split['dealer'],
        $distributor ? (int) $distributor['id'] : null, $split['distributor'],
        $plan['booking'], $plan['delivery'], $plan['booking'],
        in_array($status, ['delivery_pending', 'delivery_review', 'complete'], true) ? $ago(40 - $i) : null,
        $complete ? $ago(20 - $i) : null,
        $complete ? $ago(20 - $i) : null,
        $status === 'submitted' ? null : $ago(50 - $i),
        DEMO_MARK . ' seeded application', $ago(60 - $i * 4),
    ]);

    $id = (int) $db->lastInsertId();
    $db->prepare('UPDATE applications SET reference_code = ? WHERE id = ?')
        ->execute([make_reference_code($id), $id]);

    $appIds[] = $id;

    /* receipts, so the payment panel has something to show and to decide on */
    if (in_array($status, ['booking_review', 'delivery_review', 'delivery_pending', 'complete'], true)) {
        $db->prepare(
            'INSERT INTO payments (application_id, stage, amount, reference, status, uploaded_at, decided_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, 'booking', $plan['booking'], 'UTR' . random_int(100000, 999999),
            $status === 'booking_review' ? 'pending' : 'verified',
            $ago(42 - $i), $status === 'booking_review' ? null : $ago(41 - $i),
        ]);
    }

    if (in_array($status, ['delivery_review', 'complete'], true)) {
        $db->prepare(
            'INSERT INTO payments (application_id, stage, amount, reference, status, uploaded_at, decided_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, 'delivery', $plan['delivery'], 'UTR' . random_int(100000, 999999),
            $status === 'delivery_review' ? 'pending' : 'verified',
            $ago(22 - $i), $status === 'delivery_review' ? null : $ago(21 - $i),
        ]);
    }
}

echo count($appIds), " applications across every status\n";

/* one completed sale carries a referral reward, so that page has a row */
$db->prepare(
    'UPDATE applications SET referred_by_id = ?, referred_by_code = ?, referral_reward = ?,
                             referral_reward_status = ?
      WHERE id = ?'
)->execute([
    $appIds[0],
    db()->query('SELECT referral_code FROM applications WHERE id = ' . $appIds[0])->fetchColumn(),
    referral_reward(), 'pending', $appIds[2],
]);

/* ---------- enquiries and signups ---------- */

foreach ([['Kiran Bhatt', 'kiran@demo.invalid', 'new'], ['Lata Iyer', 'lata@demo.invalid', 'accepted']] as $i => [$name, $email, $status]) {
    $db->prepare(
        'INSERT INTO contact_messages (name, email, phone, message, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$name, $email, '95' . str_pad((string) (76000000 + $i), 8, '0'),
                DEMO_MARK . ' How soon can you install in my area?', $status, $ago(10 - $i)]);
}

foreach (['mila@demo.invalid', 'nikhil@demo.invalid', 'ojas@demo.invalid'] as $i => $email) {
    $db->prepare('INSERT INTO newsletter_subscribers (email, status, created_at) VALUES (?, ?, ?)')
        ->execute([$email, 'new', $ago(15 - $i)]);
}

/* ---------- the partners sell some of their stock ---------- */

foreach ([[0, 'stove', $dealerIds[0], 'dealer'], [1, 'tuktuk', $distIds[0], 'distributor']] as [$ix, $product, $ownerId, $ownerType]) {
    if (stock_units($ownerType, $ownerId, $product) > 0) {
        stock_move($ownerType, $ownerId, $product, -1,
            -stock_unit_cost($ownerType, $ownerId, $product), 'sale', null, $appIds[$ix], DEMO_MARK);
    }
}

/* ---------- commission vouchers at each stage ---------- */

[$vDealer, $error] = voucher_raise('dealer', $dealerIds[0], 'Rohit Patel');

if ($error === '') {
    voucher_approve_dealer($vDealer, $distIds[0], 'Meera Raval');
}

[$bundle, $error] = voucher_bundle($distIds[0], 'Meera Raval');

if ($error === '') {
    voucher_move_bundle($bundle, 'with_admin', 'R&F', ['with_rf'], 'Checked');
}

/* a second dealer's claim, left with their distributor to decide */
voucher_raise('dealer', $dealerIds[1], 'Sana Qureshi');

/* and a third distributor's bundle sitting with R&F */
voucher_bundle($distIds[1], 'Arjun Deshmukh');

echo "vouchers raised at several stages\n";

echo "\nDemo data seeded. Sign in as:\n";
echo "  office        admin@manifold.com\n";
echo "  R&F           r&f@manifold.com / r&f123\n";

foreach ($distIds as $i => $id) {
    $dist = distributor_by_id($id);
    echo '  distributor   ', $dist['email'], '  (', $dist['distributor_code'], ')', "\n";
}

foreach ($dealerIds as $id) {
    $dealer = dealer_by_id($id);
    echo '  dealer        ', $dealer['email'], '  (', $dealer['dealer_code'], ')', "\n";
}

echo "\nPartners sign in at /portal with a one-time code emailed to that address.\n";
echo "Run with --clear to remove everything this wrote.\n";
