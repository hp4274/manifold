<?php
/**
 * The Friday run: raise everybody's commission voucher, once a week.
 *
 * Every dealer with something to claim gets a voucher to their distributor, and
 * every distributor then bundles whatever they have approved along with their
 * own claim and sends it to C&F.
 *
 * Safe to run twice. Nothing is raised for a partner who already has a claim in
 * flight, so a retry, a restarted machine or a doubled schedule all produce
 * nothing the second time. A Friday that was missed is not lost either — the
 * next run picks up everything that has accrued since.
 *
 * Run it from the command line:
 *
 *   C:\xampp\php\php.exe C:\xampp\htdocs\manifold\admin\cron\voucher-run.php
 *
 * and on Windows, schedule that for 17:00 every Friday:
 *
 *   schtasks /create /tn "Manifold voucher run" /sc weekly /d FRI /st 17:00 ^
 *     /tr "C:\xampp\php\php.exe C:\xampp\htdocs\manifold\admin\cron\voucher-run.php"
 *
 * --cycle=YYYY-MM-DD raises against another date, for catching a missed week up.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This is a command-line job.\n");
}

require_once __DIR__ . '/../lib.php';

$cycle = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--cycle=')) {
        $cycle = substr($arg, 8);
    }
}

if ($cycle !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cycle)) {
    exit("--cycle needs a date like 2026-08-28.\n");
}

$cycle = $cycle ?? date('Y-m-d');
$made  = voucher_run_cycle($cycle);

printf(
    "[%s] cycle %s: %d dealer voucher%s raised, %d bundle%s sent, %d skipped (nothing owed or already open)\n",
    date('Y-m-d H:i:s'),
    $cycle,
    $made['dealers'],
    $made['dealers'] === 1 ? '' : 's',
    $made['bundles'],
    $made['bundles'] === 1 ? '' : 's',
    $made['skipped']
);
