<?php
/**
 * Self-check for the dealer commission arithmetic.
 *
 * The one thing here that can quietly go wrong is the money: what a dealer has
 * earned, what has been paid, and what is still owed. This walks a dealer
 * through three sales and a part payment and asserts the totals after each
 * step, then removes everything it made.
 *
 *   php admin/test-dealers.php
 *
 * Command line only. It writes to the live database and cleans up after
 * itself, so do not run it against production while the office is working.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Command line only.\n");
}

require_once __DIR__ . '/lib.php';

$code   = make_dealer_code();

/* what one stove sale pays a dealer under the current rates */
$plan   = payment_plan('stove');
$sale   = (float) $plan['booking'] + (float) $plan['delivery'];
$rate   = round($sale * dealer_rate(), 2);

db()->prepare('INSERT INTO dealers (dealer_code, full_name, email) VALUES (?, ?, ?)')
    ->execute([$code, 'Self-check dealer', 'selfcheck' . $code . '@example.com']);

$dealerId = (int) db()->lastInsertId();

/** One application attributed to the dealer. $done means the sale is complete. */
function seed_sale(int $dealerId, float $rate, bool $done, string $status = 'booking_pending'): int
{
    $status = $done ? 'complete' : $status;

    db()->prepare(
        'INSERT INTO applications
            (product, status, reference_code, referral_code, full_name, email, mobile_number,
             dealer_id, dealer_commission, booking_paid_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        'stove', $status, 'tmp-' . bin2hex(random_bytes(5)), make_referral_code(),
        'Self-check client', 'selfcheck' . bin2hex(random_bytes(4)) . '@example.com', '0000000000',
        $dealerId, $rate, $done ? date('Y-m-d H:i:s') : null,
    ]);

    return (int) db()->lastInsertId();
}

$failures = 0;

/** Assert one figure, and keep going so every mismatch is reported at once. */
function check(string $what, $expected, $actual): void
{
    global $failures;

    if ((string) $expected === (string) $actual) {
        echo "  ok    $what = $actual\n";

        return;
    }

    $failures++;
    echo "  FAIL  $what: expected $expected, got $actual\n";
}

try {
    echo "Dealer $code, " . rtrim(rtrim(number_format(dealer_rate() * 100, 2, ".", ""), "0"), ".")
        . "% of a " . money($sale) . " sale = " . money($rate) . "\n\n";

    /* three sales: two complete, one still in progress */
    $sales = [seed_sale($dealerId, $rate, true), seed_sale($dealerId, $rate, true),
              seed_sale($dealerId, $rate, false)];

    echo "Three sales, two of them complete:\n";
    $t = dealer_totals($dealerId);
    check('sales', 3, $t['sales']);
    check('confirmed', 2, $t['confirmed']);
    check('earned', $rate * 2, $t['earned']);
    check('paid', 0, $t['paid']);
    check('remaining', $rate * 2, $t['remaining']);

    /* pay for one of the two — the other has to stay owed */
    echo "\nPaying for one of the two:\n";
    db()->prepare('INSERT INTO dealer_payouts (dealer_id, amount, note) VALUES (?, ?, ?)')
        ->execute([$dealerId, $rate, 'self-check']);

    $t = dealer_totals($dealerId);
    check('paid', $rate, $t['paid']);
    check('remaining', $rate, $t['remaining']);

    /* the third sale completes: earned goes up, so does what is owed */
    echo "\nThe third sale completes:\n";
    db()->prepare("UPDATE applications SET status = 'complete', booking_paid_at = NOW() WHERE id = ?")
        ->execute([$sales[2]]);

    $t = dealer_totals($dealerId);
    check('confirmed', 3, $t['confirmed']);
    check('earned', $rate * 3, $t['earned']);
    check('remaining', $rate * 2, $t['remaining']);

    /* a rejected sale earns nothing, however far along it got */
    echo "\nOne sale is rejected:\n";
    db()->prepare("UPDATE applications SET status = 'rejected' WHERE id = ?")->execute([$sales[0]]);

    $t = dealer_totals($dealerId);
    check('confirmed', 2, $t['confirmed']);
    check('earned', $rate * 2, $t['earned']);
    check('remaining', $rate, $t['remaining']);

    /* overpaying reads as nothing owed, never a negative figure */
    echo "\nOverpaying:\n";
    db()->prepare('INSERT INTO dealer_payouts (dealer_id, amount, note) VALUES (?, ?, ?)')
        ->execute([$dealerId, $rate * 5, 'self-check overpay']);

    $t = dealer_totals($dealerId);
    check('remaining never negative', 0, $t['remaining']);

    /* a code belongs to one programme or the other, never both */
    echo "\nCode lookup:\n";
    check('dealer_for_code finds it', $dealerId, (int) (dealer_for_code($code)['id'] ?? 0));
    check('referrer_for_code does not', 0, (int) (referrer_for_code($code)['id'] ?? 0));

    /* switching a dealer off has to stop new sales being attributed to them */
    db()->prepare('UPDATE dealers SET is_active = 0 WHERE id = ?')->execute([$dealerId]);
    check('a switched-off dealer takes no code', 0, (int) (dealer_for_code($code)['id'] ?? 0));
} finally {
    db()->prepare('DELETE FROM applications WHERE dealer_id = ?')->execute([$dealerId]);
    db()->prepare('DELETE FROM dealers WHERE id = ?')->execute([$dealerId]);
}

echo "\n" . ($failures === 0 ? "All checks passed.\n" : $failures . " check(s) failed.\n");

exit($failures === 0 ? 0 : 1);
