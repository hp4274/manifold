<?php
/**
 * The commission chain, end to end, against the live database.
 *
 * Tranches earn as their payments are verified (§9), a voucher claims them, and
 * the money comes back down through R&F (§10). Everything this writes it
 * deletes again — run it any time:
 *
 *   C:\xampp\php\php.exe C:\xampp\htdocs\manifold\admin\tests\voucher-chain.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This is a command-line job.\n");
}

require_once __DIR__ . '/../lib.php';

$fail = [];
$ok   = static function (bool $cond, string $what) use (&$fail): void {
    if (!$cond) { $fail[] = $what; }
};
$near = static fn (float $a, float $b): bool => abs($a - $b) < 0.01;

/* a suffix of its own, so a run that dies before its cleanup cannot block the next */
$tag = substr(bin2hex(random_bytes(3)), 0, 5);

db()->prepare('INSERT INTO distributors (distributor_code, full_name, email, upi_id)
               VALUES (?, ?, ?, ?)')
    ->execute(['MXT' . $tag, 'Chain Dist ' . $tag, 'cd' . $tag . '@test.invalid', 'cd@upi']);
$distId = (int) db()->lastInsertId();

$dealerIds = [];

foreach (['A', 'B'] as $suffix) {
    db()->prepare("INSERT INTO dealers (dealer_code, distributor_id, approval_status, full_name, email, upi_id)
                   VALUES (?, ?, 'approved', ?, ?, ?)")
        ->execute(['MDT' . $tag . $suffix, $distId, 'Chain Dealer ' . $suffix,
                   'cdl' . $suffix . $tag . '@test.invalid', 'cdl@upi']);
    $dealerIds[] = (int) db()->lastInsertId();
}

/** A sale with its booking verified and, optionally, its delivery too. */
$sale = static function (int $dealerId, int $distId, bool $delivered) use ($tag): int {
    /* the figures a real sale freezes onto its row when it arrives */
    db()->prepare(
        "INSERT INTO applications (product, status, reference_code, referral_code, full_name, email,
                                   mobile_number, dealer_id, distributor_id, dealer_commission,
                                   distributor_commission, booking_amount, delivery_amount,
                                   payment_amount)
         VALUES ('tuktuk','booking_pending',?,?,?,?,'9000000000',?,?,?,?,6000,24000,6000)"
    )->execute(['CHN-' . bin2hex(random_bytes(3)), 'MF' . bin2hex(random_bytes(3)),
                'Chain Client', 'cc' . bin2hex(random_bytes(3)) . $tag . '@test.invalid',
                $dealerId, $distId,
                commission_value('dealer', 'tuktuk'), commission_value('override', 'tuktuk')]);

    $id = (int) db()->lastInsertId();

    db()->prepare("INSERT INTO payments (application_id, stage, amount, status, decided_at)
                   VALUES (?, 'booking', 6000, 'verified', NOW())")->execute([$id]);

    if ($delivered) {
        db()->prepare("INSERT INTO payments (application_id, stage, amount, status, decided_at)
                       VALUES (?, 'delivery', 24000, 'verified', NOW())")->execute([$id]);
    }

    sync_application_status($id);

    return $id;
};

$appIds = [
    $sale($dealerIds[0], $distId, true),    /* both tranches earned */
    $sale($dealerIds[0], $distId, false),   /* only the booking tranche */
    $sale($dealerIds[1], $distId, true),
];

/* ---------- what a sale is worth ----------
   A flat amount per sale, per product, earned in full when the delivery
   payment is verified. A booking payment on its own earns nothing. */
$saleShare = commission_value('dealer', 'tuktuk');

$ok($near(commission_earned('dealer', $dealerIds[0]), $saleShare),
    'dealer A has one delivered sale and one still on its booking: '
    . commission_earned('dealer', $dealerIds[0]));

/* ---------- a claim carries the sales that have been delivered ---------- */
$claimable = voucher_claimable('dealer', $dealerIds[0]);
$ok(count($claimable) === 1, 'the delivered sale is claimable, the other is not: ' . count($claimable));

[$vA, $err] = voucher_raise('dealer', $dealerIds[0], 'Dealer A');
$ok($err === '', 'dealer A raises: ' . $err);
$ok($near((float) voucher($vA)['amount'], $saleShare), 'for everything earned');
$ok(count(voucher_claimable('dealer', $dealerIds[0])) === 0, 'nothing claimable twice');

/* the second sale is delivered later, and is claimable on its own */
db()->prepare("INSERT INTO payments (application_id, stage, amount, status, decided_at)
               VALUES (?, 'delivery', 24000, 'verified', NOW())")->execute([$appIds[1]]);
sync_application_status($appIds[1]);

$ok(count(voucher_claimable('dealer', $dealerIds[0])) === 1,
    'the sale that completes later is claimable on its own');

/* ---------- the rest of the journey ---------- */
[$vB, $err] = voucher_raise('dealer', $dealerIds[1], 'Dealer B');
$ok($err === '', 'dealer B raises too');
$ok(voucher_approve_dealer($vA, $distId, 'Dist') === '', 'the distributor approves A');
$ok(voucher_reject($vB, 'Dist', 'Not this week') === '', 'and turns B down');
$ok(count(voucher_claimable('dealer', $dealerIds[1])) === 1, 'B can claim that sale again');

[$bundle, $err] = voucher_bundle($distId, 'Dist');
$ok($err === '' && voucher($bundle)['status'] === 'with_rf', 'the bundle goes to R&F: ' . $err);
$ok(voucher($vA)['status'] === 'with_rf', 'carrying the dealer voucher');

$ok(voucher_move_bundle($bundle, 'with_admin', 'R&F', ['with_rf']) === '', 'R&F forwards it');
$ok(voucher_move_bundle($bundle, 'funded', 'Office', ['with_admin']) === '', 'the office funds it');

$paidBefore = dealer_totals($dealerIds[0])['paid'];
$ok(voucher_pay($bundle, 'R&F', 'UTR-CHAIN') === '', 'R&F pays it');
$ok(dealer_totals($dealerIds[0])['paid'] > $paidBefore, 'the payout lands');
$ok($near(dealer_totals($dealerIds[0])['remaining'], $saleShare),
    'and what is left owed is the sale that completed after the claim');

/* ---------- clean up ---------- */
$in = implode(',', $appIds);
db()->exec('DELETE FROM commission_voucher_lines WHERE application_id IN (' . $in . ')');
db()->exec("DELETE FROM commission_vouchers WHERE (party_type = 'dealer' AND party_id IN ("
    . implode(',', $dealerIds) . ")) OR (party_type = 'distributor' AND party_id = " . $distId . ')');
db()->exec('DELETE FROM commission_lines WHERE application_id IN (' . $in . ')');
db()->exec('DELETE FROM dealer_payouts WHERE dealer_id IN (' . implode(',', $dealerIds) . ')');
db()->exec('DELETE FROM distributor_payouts WHERE distributor_id = ' . $distId);
db()->exec('DELETE FROM payments WHERE application_id IN (' . $in . ')');
db()->exec('DELETE FROM applications WHERE id IN (' . $in . ')');
db()->exec('DELETE FROM dealers WHERE id IN (' . implode(',', $dealerIds) . ')');
db()->exec('DELETE FROM distributors WHERE id = ' . $distId);

echo $fail ? "FAIL:\n- " . implode("\n- ", $fail) . "\n" : "ok\n";
