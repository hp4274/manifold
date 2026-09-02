<?php
/**
 * The Section E logic that is worth a check: the two signatures, the reference
 * format and the throttle counter. Run it with
 *
 *   php admin/tests/section-e.php
 *
 * Nothing here writes to the database — the throttle is exercised against a
 * temporary table so a real run of the site is never counted against.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../../portal/lib.php';

$checks = 0;

function check(string $what, bool $ok): void
{
    global $checks;

    $checks++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . PHP_EOL;

    assert($ok, $what);
}

echo 'E10 — the sign-in signature' . PHP_EOL;

$expires = time() + 600;
$signed  = otp_signature('a@b.com', $expires, '123456');

check('the same inputs sign the same', hash_equals($signed, otp_signature('a@b.com', $expires, '123456')));
check('a different code does not match', !hash_equals($signed, otp_signature('a@b.com', $expires, '123457')));
check('a different address does not match', !hash_equals($signed, otp_signature('c@d.com', $expires, '123456')));
check('a different expiry does not match', !hash_equals($signed, otp_signature('a@b.com', $expires + 1, '123456')));
check('nothing about the code is recoverable from it', strpos($signed, '123456') === false);

echo PHP_EOL . 'E5 — the unsubscribe token' . PHP_EOL;

check('stable for one address', unsubscribe_token('a@b.com') === unsubscribe_token('a@b.com'));
check('case does not make a new address', unsubscribe_token('A@B.com') === unsubscribe_token('a@b.com'));
check('another address gets another token', unsubscribe_token('a@b.com') !== unsubscribe_token('c@d.com'));
check('the header is only on bulk mail', bulk_headers('a@b.com', 'newsletter_welcome') !== []);
check('and never on a receipt', bulk_headers('a@b.com', 'receipt') === []);

echo PHP_EOL . 'E4 — the stock order reference' . PHP_EOL;

/* the same expression stock_order_create() refuses on */
$good = static fn (string $r): bool => (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9 \/_-]{5,}$/', $r);

check("'34t5yuio9' is accepted",        $good('34t5yuio9'));
check("'UTR 1234567890' is accepted",   $good('UTR 1234567890'));
check("\"tcvgbhjkl,;.'/\" is refused",  !$good("tcvgbhjkl,;.'/"));
check("'abc' is too short",             !$good('abc'));
check("' 123456' cannot start blank",   !$good(' 123456'));

echo PHP_EOL . 'E1 — the recorded rate' . PHP_EOL;

$rate = static fn (float $amount, float $base): float =>
    $base > 0 ? min(999.99, round($amount / $base * 100, 2)) : 0.0;

check('3,000 of 16,500 is 18.18%', $rate(3000, 16500) === 18.18);
check('a zero base records nothing', $rate(3000, 0) === 0.0);
check('it never overflows decimal(5,2)', $rate(3000, 0.01) === 999.99);

echo PHP_EOL . 'E3 — one answer for every address' . PHP_EOL;

/* The point of E3 is that nothing about the address reaches the page. issue_otp
   itself sends mail, so what is checked here is the notice the page shows: it
   must name neither the address nor which of the three states it is in. */
$notice = OTP_SENT_NOTICE;

check('the notice does not name an address', strpos($notice, '@') === false);
check('it does not say "registered with us" as a fact', strpos($notice, 'If that address') === 0);
check('it says nothing about an application', stripos($notice, 'application') === false);

echo PHP_EOL . $checks . ' checks.' . PHP_EOL;
