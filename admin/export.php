<?php
/**
 * The dealer and distributor MIS reports, as a spreadsheet.
 *
 * One report per partner kind, one row per partner, and a period the office
 * picks — all time, the last seven or thirty days, or two dates of their own.
 * Everything is summed in SQL over the whole table rather than the page on
 * screen, because a report that quietly covers ten rows is worse than none.
 *
 * The figures are the same ones the lists show: sales are applications
 * attributed to them, commission is what `commission_lines` says was earned,
 * and paid is what the payout table says went out. Two definitions of "still
 * owed" is how two screens start quoting different money at the same person.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

require_login();

$kind = (string) ($_GET['kind'] ?? 'dealers');

if (!in_array($kind, ['dealers', 'distributors'], true)) {
    http_response_code(404);
    exit('Unknown report.');
}

/* One partner, from their own drawer, or all of them from the list. */
$only = (int) ($_GET['id'] ?? 0);

$format = (string) ($_GET['format'] ?? 'csv');

if (!in_array($format, ['xlsx', 'csv', 'pdf'], true)) {
    $format = 'xlsx';
}

/* ---------- the period ----------
   Read once, into two dates and a reading name. A date that will not parse is
   not worth a page of its own: it falls back to all time, which is the honest
   answer to a period nobody could understand. */

$range = (string) ($_GET['range'] ?? 'all');

if (!in_array($range, ['all', 'week', 'month', 'custom'], true)) {
    $range = 'all';
}

/** A date typed into the form, as Y-m-d, or null when it was not one. */
function export_date(string $value): ?string
{
    $date = DateTime::createFromFormat('Y-m-d', $value);

    return $date && $date->format('Y-m-d') === $value ? $value : null;
}

$from = null;
$to   = null;

if ($range === 'week') {
    $from = date('Y-m-d', strtotime('-6 days'));
    $to   = date('Y-m-d');
} elseif ($range === 'month') {
    $from = date('Y-m-d', strtotime('-29 days'));
    $to   = date('Y-m-d');
} elseif ($range === 'custom') {
    $from = export_date((string) ($_GET['from'] ?? ''));
    $to   = export_date((string) ($_GET['to'] ?? ''));

    /* one date on its own is a period too: everything since, or everything up
       to. Both missing is all time under another name. */
    if ($from === null && $to === null) {
        $range = 'all';
    } elseif ($from !== null && $to !== null && $from > $to) {
        [$from, $to] = [$to, $from];
    }
}

$periodLabel = 'All time';

if ($range === 'week') {
    $periodLabel = 'Last 7 days (' . format_date($from) . ' to ' . format_date($to) . ')';
} elseif ($range === 'month') {
    $periodLabel = 'Last 30 days (' . format_date($from) . ' to ' . format_date($to) . ')';
} elseif ($range === 'custom') {
    $periodLabel = $from === null
        ? 'Up to ' . format_date($to)
        : ($to === null ? 'From ' . format_date($from) : format_date($from) . ' to ' . format_date($to));
}

/**
 * The period as a SQL condition on one datetime column.
 *
 * The dates are already validated as Y-m-d, so they go in as literals — the
 * correlated subqueries below would otherwise need the same two values bound
 * six times over. A `to` date covers its whole day, not midnight at its start,
 * which is what somebody asking for "up to the 5th" means.
 */
function export_window(string $column): string
{
    global $from, $to;

    $clauses = [];

    if ($from !== null) {
        $clauses[] = $column . " >= '" . $from . " 00:00:00'";
    }

    if ($to !== null) {
        $clauses[] = $column . " <= '" . $to . " 23:59:59'";
    }

    return $clauses ? ' AND ' . implode(' AND ', $clauses) : '';
}

/* ---------- the rows ----------
   One query, one row per partner: the subqueries are correlated, so a partner
   with nothing in the period still comes back at zero rather than dropping out
   of their own report. */

$isDealer = $kind === 'dealers';
$column   = $isDealer ? 'dealer_id' : 'distributor_id';
$party    = db()->quote($isDealer ? 'dealer' : 'distributor');
$payouts  = $isDealer ? 'dealer_payouts' : 'distributor_payouts';

$appWindow  = export_window('a.created_at');
$lineWindow = export_window('cl.earned_at');
$payWindow  = export_window('p.paid_at');

$sql = 'SELECT t.*,
        (SELECT COUNT(*) FROM applications a
          WHERE a.' . $column . ' = t.id' . $appWindow . ') AS period_sales,
        (SELECT COUNT(*) FROM applications a
          WHERE a.' . $column . " = t.id AND a.status = 'complete'" . $appWindow . ') AS period_complete,
        (SELECT COALESCE(SUM(cl.amount), 0) FROM commission_lines cl
          WHERE cl.party_type = ' . $party . ' AND cl.party_id = t.id' . $lineWindow . ') AS period_earned,
        (SELECT COALESCE(SUM(p.amount), 0) FROM ' . $payouts . ' p
          WHERE p.' . $column . ' = t.id' . $payWindow . ') AS period_paid,
        (SELECT COALESCE(SUM(cl.amount), 0) FROM commission_lines cl
          WHERE cl.party_type = ' . $party . ' AND cl.party_id = t.id) AS life_earned,
        (SELECT COALESCE(SUM(p.amount), 0) FROM ' . $payouts . ' p
          WHERE p.' . $column . ' = t.id) AS life_paid';

$one = $only > 0 ? ' WHERE t.id = ' . $only : '';

if ($isDealer) {
    $sql .= ', x.full_name AS distributor_name, x.distributor_code
              FROM dealers t
              LEFT JOIN distributors x ON x.id = t.distributor_id' . $one . '
             ORDER BY t.full_name';
} else {
    $sql .= ', (SELECT COUNT(*) FROM dealers d WHERE d.distributor_id = t.id) AS dealer_count
              FROM distributors t' . $one . '
             ORDER BY t.full_name';
}

$rows = db()->query($sql)->fetchAll();

$stem  = ($isDealer ? 'dealers' : 'distributors') . '-mis-' . date('Y-m-d');
$title = ($isDealer ? 'Dealer' : 'Distributor') . ' MIS report';

/* A report about one partner says whose it is, on the page and in the filename —
   three of these in a downloads folder are otherwise the same file. */
if ($only > 0 && $rows) {
    $who   = $rows[0];
    $code  = (string) ($who['dealer_code'] ?? $who['distributor_code'] ?? '');
    $title .= ' — ' . $who['full_name'] . ($code === '' ? '' : ' (' . $code . ')');
    $stem   = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $code !== '' ? $code : (string) $who['full_name']))
        . '-mis-' . date('Y-m-d');
}

if ($format === 'xlsx') {
    require_once __DIR__ . '/xlsx.php';

    $book = build_xlsx(export_sheet($rows, $isDealer, $title, $periodLabel));

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $stem . '.xlsx"');
    header('Cache-Control: no-store, private');

    echo $book;
    exit;
}

if ($format === 'pdf') {
    require_once __DIR__ . '/receipt-pdf.php';

    $pdf = export_pdf($rows, $isDealer, $title, $periodLabel);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $stem . '.pdf"');
    header('Cache-Control: no-store, private');

    echo $pdf;
    exit;
}

/* ---------- the spreadsheet ---------- */

$filename = $stem . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, private');

$out = fopen('php://output', 'w');

/* Excel reads a file with no byte order mark in the local codepage, which
   turns every rupee sign and every accented name into rubbish. */
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [$title]);
fputcsv($out, ['Period', $periodLabel]);
fputcsv($out, ['Generated', format_datetime(date('Y-m-d H:i:s'))]);
fputcsv($out, []);

$heading = $isDealer
    ? ['Code', 'Dealer', 'Company', 'Status', 'Distributor', 'Distributor code']
    : ['Code', 'Distributor', 'Company', 'Status', 'Dealers'];

$heading = array_merge($heading, [
    'City', 'State', 'Mobile', 'Email',
    'Sales in period', 'Completed in period', 'Commission earned in period', 'Paid in period',
    'Commission earned (all time)', 'Paid (all time)', 'Still owed', 'Added on',
]);

fputcsv($out, $heading);

$sum = ['sales' => 0, 'complete' => 0, 'earned' => 0.0, 'paid' => 0.0,
        'life_earned' => 0.0, 'life_paid' => 0.0, 'owed' => 0.0];

foreach ($rows as $row) {
    $lifeEarned = (float) $row['life_earned'];
    $lifePaid   = (float) $row['life_paid'];
    $owed       = max(0.0, round($lifeEarned - $lifePaid, 2));

    /* a dealer waiting on the office is neither selling nor stopped, and the
       report should say which of the three it is */
    $status = $isDealer && (string) ($row['approval_status'] ?? 'approved') !== 'approved'
        ? approval_label((string) $row['approval_status'])
        : ($row['is_active'] ? 'Active' : 'Stopped');

    $line = $isDealer
        ? [(string) ($row['dealer_code'] ?? ''), (string) $row['full_name'], (string) ($row['company'] ?? ''),
           $status, (string) ($row['distributor_name'] ?? ''), (string) ($row['distributor_code'] ?? '')]
        : [(string) $row['distributor_code'], (string) $row['full_name'], (string) ($row['company'] ?? ''),
           $status, (int) $row['dealer_count']];

    fputcsv($out, array_merge($line, [
        (string) ($row['city'] ?? ''),
        (string) ($row['state'] ?? ''),
        (string) ($row['mobile_number'] ?? ''),
        (string) ($row['email'] ?? ''),
        (int) $row['period_sales'],
        (int) $row['period_complete'],
        number_format((float) $row['period_earned'], 2, '.', ''),
        number_format((float) $row['period_paid'], 2, '.', ''),
        number_format($lifeEarned, 2, '.', ''),
        number_format($lifePaid, 2, '.', ''),
        number_format($owed, 2, '.', ''),
        format_date((string) $row['created_at']),
    ]));

    $sum['sales']       += (int) $row['period_sales'];
    $sum['complete']    += (int) $row['period_complete'];
    $sum['earned']      += (float) $row['period_earned'];
    $sum['paid']        += (float) $row['period_paid'];
    $sum['life_earned'] += $lifeEarned;
    $sum['life_paid']   += $lifePaid;
    $sum['owed']        += $owed;
}

/* the row the report is actually opened for */
fputcsv($out, []);
fputcsv($out, array_merge(
    array_fill(0, $isDealer ? 9 : 8, ''),
    ['Total',
     $sum['sales'], $sum['complete'],
     number_format($sum['earned'], 2, '.', ''),
     number_format($sum['paid'], 2, '.', ''),
     number_format($sum['life_earned'], 2, '.', ''),
     number_format($sum['life_paid'], 2, '.', ''),
     number_format($sum['owed'], 2, '.', ''),
     count($rows) . ' ' . (count($rows) === 1
         ? ($isDealer ? 'dealer' : 'distributor')
         : ($isDealer ? 'dealers' : 'distributors'))]
));

fclose($out);

/**
 * The same report as a printable page.
 *
 * Landscape A4, one line per partner, and the columns cut back to the ones
 * somebody reads down a page: who, how many sales, and the money. The full
 * record — addresses, contact details, every column — is what the spreadsheet
 * is for, and squeezing it onto paper would make both of them worse.
 *
 * Helvetica has no rupee sign, so the money columns say so in their heading
 * once rather than in every cell.
 */
function export_pdf(array $rows, bool $isDealer, string $title, string $periodLabel): string
{
    $pdf = new SimplePdf(true);

    $ink   = [0.05, 0.13, 0.20];
    $muted = [0.36, 0.44, 0.53];
    $rule  = [0.89, 0.92, 0.95];
    $band  = [0.96, 0.975, 0.985];
    $teal  = [0.055, 0.561, 0.588];

    $left  = 40.0;
    $right = $pdf->width() - 40.0;

    /* name, x offset, width, right-aligned */
    $columns = $isDealer
        ? [['Code', 0, 68, false], ['Dealer', 68, 150, false], ['Distributor', 218, 130, false],
           ['Status', 348, 60, false]]
        : [['Code', 0, 68, false], ['Distributor', 68, 170, false], ['Dealers', 238, 50, true],
           ['Status', 298, 110, false]];

    $columns = array_merge($columns, [
        ['Sales', 408, 52, true],
        ['Complete', 460, 62, true],
        ['Earned (Rs)', 522, 88, true],
        ['Paid (Rs)', 610, 88, true],
        ['Owed (Rs)', 698, 88, true],
    ]);

    /** One cell, cut to its column rather than run into the next one. */
    $cell = static function (array $column, float $y, string $text, bool $bold, array $rgb) use ($pdf, $left) {
        [, $offset, $width, $isRight] = $column;

        $text = (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        while ($text !== '' && $pdf->widthOf($text, 8.5, $bold) > $width - 8) {
            $text = rtrim(substr($text, 0, -2)) . '.';
        }

        if ($isRight) {
            $pdf->textRight($left + $offset + $width - 6, $y, $text, 8.5, $bold, $rgb);
        } else {
            $pdf->text($left + $offset, $y, $text, 8.5, $bold, $rgb);
        }
    };

    $y      = 0.0;
    $page   = 0;
    $bottom = $pdf->height() - 48;

    /* Every page carries the whole heading: a page pulled out of the middle of
       a printout still has to say what it is and what period it covers. */
    $startPage = static function () use ($pdf, &$y, &$page, $title, $periodLabel, $left, $right,
                                         $columns, $ink, $muted, $rule, $teal, $cell) {
        if ($page > 0) {
            $pdf->newPage();
        }

        $page++;

        $pdf->logo($left + 11, 46, 22, 0.0, true);
        $pdf->text($left + 32, 40, 'Manifold Clean Energy', 9, false, $muted);
        $pdf->text($left + 32, 53, $title, 15, true, $ink);

        $pdf->textRight($right, 40, 'Period: ' . $periodLabel, 9, true, $teal);
        $pdf->textRight($right, 53, 'Generated ' . format_datetime(date('Y-m-d H:i:s')), 9, false, $muted);

        $pdf->line($left, 74, $right, $rule, 1.0);

        foreach ($columns as $column) {
            $cell($column, 92, strtoupper($column[0]), true, $muted);
        }

        $pdf->line($left, 100, $right, $rule);

        $y = 116.0;
    };

    $startPage();

    $sum = ['sales' => 0, 'complete' => 0, 'earned' => 0.0, 'paid' => 0.0, 'owed' => 0.0];
    $band_i = 0;

    foreach ($rows as $row) {
        if ($y > $bottom - 40) {
            $startPage();
            $band_i = 0;
        }

        $earned = (float) $row['period_earned'];
        $paid   = (float) $row['period_paid'];
        $owed   = max(0.0, round((float) $row['life_earned'] - (float) $row['life_paid'], 2));

        /* a quiet band every other line, so the eye keeps its place across nine
           columns without a grid drawn over the whole page */
        if ($band_i % 2 === 1) {
            $pdf->rect($left - 4, $y - 9, $right - $left + 8, 18, $band);
        }

        $status = $isDealer && (string) ($row['approval_status'] ?? 'approved') !== 'approved'
            ? approval_label((string) $row['approval_status'])
            : ($row['is_active'] ? 'Active' : 'Stopped');

        $line = $isDealer
            ? [(string) ($row['dealer_code'] ?? '—'), (string) $row['full_name'],
               (string) ($row['distributor_name'] ?? '—'), $status]
            : [(string) $row['distributor_code'], (string) $row['full_name'],
               (string) (int) $row['dealer_count'], $status];

        $line = array_merge($line, [
            (string) (int) $row['period_sales'],
            (string) (int) $row['period_complete'],
            number_format($earned, 0),
            number_format($paid, 0),
            number_format($owed, 0),
        ]);

        foreach ($columns as $i => $column) {
            $cell($column, $y, $line[$i], $i === 1, $i === 1 ? $ink : $muted);
        }

        $sum['sales']    += (int) $row['period_sales'];
        $sum['complete'] += (int) $row['period_complete'];
        $sum['earned']   += $earned;
        $sum['paid']     += $paid;
        $sum['owed']     += $owed;

        $y += 18;
        $band_i++;
    }

    if (!$rows) {
        $pdf->text($left, $y, 'Nothing to report for this period.', 10, false, $muted);
        $y += 18;
    }

    /* the line the report is actually opened for */
    $pdf->line($left, $y - 4, $right, $rule, 1.0);

    $totals = [count($rows) . ' ' . ($isDealer ? 'dealers' : 'distributors'), '', '',
               (string) $sum['sales'], (string) $sum['complete'],
               number_format($sum['earned'], 0), number_format($sum['paid'], 0),
               number_format($sum['owed'], 0)];

    $cell($columns[0], $y + 12, 'Total', true, $ink);

    foreach (array_slice($columns, 1) as $i => $column) {
        $cell($column, $y + 12, $totals[$i], true, $i === 0 ? $muted : $ink);
    }

    return $pdf->output();
}

/**
 * The report as a workbook: the same figures the CSV carries, laid out to be
 * read rather than parsed.
 *
 * Widths are set per column because the office opens these in Excel and a
 * default-width column turns every money figure into `####`. Money and counts
 * go in as numbers so they can be summed and sorted; only the identifying
 * columns are text.
 */
function export_sheet(array $rows, bool $isDealer, string $title, string $periodLabel): array
{
    $columns = $isDealer
        ? [['label' => 'Code', 'width' => 13, 'type' => 'text'],
           ['label' => 'Dealer', 'width' => 26, 'type' => 'text'],
           ['label' => 'Company', 'width' => 22, 'type' => 'text'],
           ['label' => 'Status', 'width' => 12, 'type' => 'text'],
           ['label' => 'Distributor', 'width' => 24, 'type' => 'text'],
           ['label' => 'Distributor code', 'width' => 16, 'type' => 'text']]
        : [['label' => 'Code', 'width' => 13, 'type' => 'text'],
           ['label' => 'Distributor', 'width' => 26, 'type' => 'text'],
           ['label' => 'Company', 'width' => 22, 'type' => 'text'],
           ['label' => 'Status', 'width' => 12, 'type' => 'text'],
           ['label' => 'Dealers', 'width' => 10, 'type' => 'int']];

    $columns = array_merge($columns, [
        ['label' => 'City', 'width' => 16, 'type' => 'text'],
        ['label' => 'State', 'width' => 14, 'type' => 'text'],
        ['label' => 'Mobile', 'width' => 15, 'type' => 'text'],
        ['label' => 'Email', 'width' => 28, 'type' => 'text'],
        ['label' => 'Sales in period', 'width' => 13, 'type' => 'int'],
        ['label' => 'Completed in period', 'width' => 14, 'type' => 'int'],
        ['label' => 'Commission earned in period', 'width' => 18, 'type' => 'money'],
        ['label' => 'Paid in period', 'width' => 15, 'type' => 'money'],
        ['label' => 'Commission earned (all time)', 'width' => 18, 'type' => 'money'],
        ['label' => 'Paid (all time)', 'width' => 15, 'type' => 'money'],
        ['label' => 'Still owed', 'width' => 15, 'type' => 'money'],
        ['label' => 'Added on', 'width' => 14, 'type' => 'date'],
    ]);

    $body = [];
    $sum  = ['sales' => 0, 'complete' => 0, 'earned' => 0.0, 'paid' => 0.0,
             'life_earned' => 0.0, 'life_paid' => 0.0, 'owed' => 0.0];

    foreach ($rows as $row) {
        $lifeEarned = (float) $row['life_earned'];
        $lifePaid   = (float) $row['life_paid'];
        $owed       = max(0.0, round($lifeEarned - $lifePaid, 2));

        $status = $isDealer && (string) ($row['approval_status'] ?? 'approved') !== 'approved'
            ? approval_label((string) $row['approval_status'])
            : ($row['is_active'] ? 'Active' : 'Stopped');

        $line = $isDealer
            ? [(string) ($row['dealer_code'] ?? ''), (string) $row['full_name'],
               (string) ($row['company'] ?? ''), $status,
               (string) ($row['distributor_name'] ?? ''), (string) ($row['distributor_code'] ?? '')]
            : [(string) $row['distributor_code'], (string) $row['full_name'],
               (string) ($row['company'] ?? ''), $status, (int) $row['dealer_count']];

        $body[] = array_merge($line, [
            (string) ($row['city'] ?? ''),
            (string) ($row['state'] ?? ''),
            (string) ($row['mobile_number'] ?? ''),
            (string) ($row['email'] ?? ''),
            (int) $row['period_sales'],
            (int) $row['period_complete'],
            round((float) $row['period_earned'], 2),
            round((float) $row['period_paid'], 2),
            round($lifeEarned, 2),
            round($lifePaid, 2),
            $owed,
            (string) $row['created_at'],
        ]);

        $sum['sales']       += (int) $row['period_sales'];
        $sum['complete']    += (int) $row['period_complete'];
        $sum['earned']      += (float) $row['period_earned'];
        $sum['paid']        += (float) $row['period_paid'];
        $sum['life_earned'] += $lifeEarned;
        $sum['life_paid']   += $lifePaid;
        $sum['owed']        += $owed;
    }

    /* the totals line sits under the last row, headed by the count it covers */
    $totals = array_fill(0, count($columns), null);
    $totals[0] = 'Total';
    $totals[1] = count($rows) . ' ' . (count($rows) === 1
        ? ($isDealer ? 'dealer' : 'distributor')
        : ($isDealer ? 'dealers' : 'distributors'));

    $money = count($columns) - 8;   /* sales, complete, then the six money columns */

    $totals[$money]     = $sum['sales'];
    $totals[$money + 1] = $sum['complete'];
    $totals[$money + 2] = round($sum['earned'], 2);
    $totals[$money + 3] = round($sum['paid'], 2);
    $totals[$money + 4] = round($sum['life_earned'], 2);
    $totals[$money + 5] = round($sum['life_paid'], 2);
    $totals[$money + 6] = round($sum['owed'], 2);

    return [
        'name'    => $isDealer ? 'Dealers' : 'Distributors',
        'title'   => $title,
        'meta'    => [
            ['Period', $periodLabel],
            ['Generated', format_datetime(date('Y-m-d H:i:s'))],
            ['Amounts', 'Indian rupees'],
        ],
        'columns' => $columns,
        'rows'    => $body,
        'totals'  => $totals,
    ];
}
