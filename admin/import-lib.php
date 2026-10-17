<?php
/**
 * Importing clients from a spreadsheet — the shared engine.
 *
 * Three desks import the same thing: the office (admin/import.php), a dealer
 * (dealer/import.php) and a distributor (distributor/import.php). What differs
 * between them is only who the sale is booked to, so that is a parameter and
 * everything else lives here.
 *
 * The shape of an import is deliberately two steps. Reading a file tells you
 * nothing about whether it is the right file, and a hundred applications made by
 * accident are a hundred emails to real customers that cannot be taken back. So
 * import_prepare() reads, checks and reports without writing anything, and only
 * import_commit() inserts — against rows the person has already seen listed.
 *
 * A note on .xlsx: this reads one by hand, the same way admin/xlsx.php writes one
 * by hand, and for the same reason. There is no Composer here and the zip
 * extension is not compiled in on this install (ZipArchive does not exist), so a
 * reader that depended on it would work on the laptop it was written on and fail
 * on the server. An .xlsx is a zip of small XML files; the central directory is
 * walked below and the entries are inflated with gzinflate(), which zlib gives
 * us everywhere.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/emails.php';

/**
 * How many data rows one file may carry.
 *
 * Not a technical ceiling — it is how many people we are willing to email in one
 * go. Each imported client is written to and the office mailbox is rate-limited
 * (the error log carries "451 Ratelimit exceeded" from ordinary traffic), so a
 * file of ten thousand would quietly drop most of its mail and nobody would know
 * which. Split a bigger list and import it in passes.
 */
const IMPORT_MAX_ROWS = 15000;

/** Bigger than any client list of 500 rows, small enough to parse in memory. */
const IMPORT_MAX_BYTES = 4 * 1024 * 1024;

/**
 * The columns a file may carry, what each one is called, and how it is checked.
 *
 * `aliases` is what a heading may say. People export from whatever they already
 * keep their customers in, so "Full name", "Name", "Customer Name" and
 * "full_name" are all the same column; matching is done on letters and digits
 * only, so spacing, case and punctuation in a heading do not matter.
 */
function import_columns(): array
{
    static $columns = null;

    if ($columns !== null) {
        return $columns;
    }

    return $columns = [
        'full_name' => [
            'label' => 'Full name', 'required' => true, 'kind' => 'text', 'max' => 160,
            'aliases' => ['name', 'customername', 'clientname', 'applicantname', 'fullname'],
        ],
        'email' => [
            'label' => 'Email', 'required' => true, 'kind' => 'email', 'max' => 190,
            'aliases' => ['emailaddress', 'mail', 'emailid'],
        ],
        'mobile_number' => [
            'label' => 'Mobile', 'required' => false, 'kind' => 'mobile', 'max' => 32,
            'aliases' => ['mobile', 'phone', 'phonenumber', 'contact', 'contactnumber', 'mobileno'],
        ],
        'mobile_code' => [
            'label' => 'Country code', 'required' => false, 'kind' => 'dial', 'max' => 6,
            'aliases' => ['countrycode', 'dialcode', 'isd', 'mobilecountrycode'],
        ],
        'alt_mobile_number' => [
            'label' => 'Alternative mobile', 'required' => false, 'kind' => 'mobile', 'max' => 32,
            'aliases' => ['altmobile', 'alternatemobile', 'alternativemobile', 'secondmobile', 'altphone'],
        ],
        'product' => [
            'label' => 'Product', 'required' => false, 'kind' => 'product', 'max' => 20,
            'aliases' => ['item', 'producttype', 'ordering'],
        ],
        'house_number' => [
            'label' => 'House / flat number', 'required' => true, 'kind' => 'text', 'max' => 120,
            'aliases' => ['house', 'houseno', 'flat', 'flatnumber', 'building', 'addressline1', 'address1'],
        ],
        'street' => [
            'label' => 'Street', 'required' => true, 'kind' => 'text', 'max' => 160,
            'aliases' => ['streetname', 'road', 'area', 'locality', 'addressline2', 'address2'],
        ],
        'city' => [
            'label' => 'City', 'required' => true, 'kind' => 'text', 'max' => 120,
            'aliases' => ['town', 'district'],
        ],
        'state' => [
            'label' => 'State', 'required' => true, 'kind' => 'text', 'max' => 120,
            'aliases' => ['province', 'region'],
        ],
        'country' => [
            'label' => 'Country', 'required' => true, 'kind' => 'text', 'max' => 120,
            'aliases' => [],
        ],
        'pin_code' => [
            'label' => 'PIN code', 'required' => true, 'kind' => 'text', 'max' => 20,
            'aliases' => ['pin', 'pincode', 'postcode', 'postalcode', 'zip', 'zipcode'],
        ],
        'date_of_birth' => [
            'label' => 'Date of birth', 'required' => false, 'kind' => 'date', 'max' => 10,
            'aliases' => ['dob', 'birthdate', 'dateofbirth'],
        ],
        'nationality' => [
            'label' => 'Nationality', 'required' => false, 'kind' => 'text', 'max' => 80,
            'aliases' => [],
        ],
        'gender' => [
            'label' => 'Gender', 'required' => false, 'kind' => 'text', 'max' => 30,
            'aliases' => ['sex'],
        ],
        'occupation' => [
            'label' => 'Occupation', 'required' => false, 'kind' => 'text', 'max' => 120,
            'aliases' => ['profession', 'job'],
        ],
        'id_number' => [
            'label' => 'ID number', 'required' => false, 'kind' => 'text', 'max' => 80,
            'aliases' => ['aadhaar', 'aadhar', 'idno', 'identitynumber', 'pan'],
        ],
        'property_type' => [
            'label' => 'Property type', 'required' => false, 'kind' => 'text', 'max' => 80,
            'aliases' => ['property', 'hometype'],
        ],
        'ownership_status' => [
            'label' => 'Ownership', 'required' => false, 'kind' => 'text', 'max' => 40,
            'aliases' => ['owned', 'ownership', 'ownerrented'],
        ],
        'household_members' => [
            'label' => 'Household members', 'required' => false, 'kind' => 'int', 'max' => 4,
            'aliases' => ['household', 'familysize', 'members', 'people'],
        ],
        'units_required' => [
            'label' => 'Units required', 'required' => false, 'kind' => 'int', 'max' => 4,
            'aliases' => ['units', 'quantity', 'qty'],
        ],
        'existing_fuel' => [
            'label' => 'Existing fuel', 'required' => false, 'kind' => 'text', 'max' => 80,
            'aliases' => ['currentfuel', 'fuel'],
        ],
        'payment_method' => [
            'label' => 'Payment method', 'required' => false, 'kind' => 'text', 'max' => 60,
            'aliases' => ['payment', 'paymentmode'],
        ],
        'referral_source' => [
            'label' => 'Heard about us via', 'required' => false, 'kind' => 'text', 'max' => 80,
            'aliases' => ['source', 'heardfrom', 'leadsource'],
        ],
        'admin_note' => [
            'label' => 'Note for the office', 'required' => false, 'kind' => 'text', 'max' => 2000,
            'aliases' => ['note', 'notes', 'remark', 'remarks', 'comment', 'comments'],
        ],
    ];
}

/** A heading reduced to what actually identifies it: letters and digits. */
function import_header_key(string $heading): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', $heading) ?? '');
}

/**
 * Which column of the file holds which of ours.
 *
 * Returns [canonical => column index]. A heading we do not know is ignored
 * rather than refused — people keep columns of their own in these files, and an
 * import that fell over on a "Follow up date" nobody asked about would be no use.
 */
function import_map_headers(array $heading): array
{
    $map = [];

    foreach (import_columns() as $name => $spec) {
        $keys = array_merge([import_header_key($name), import_header_key($spec['label'])], $spec['aliases']);

        foreach ($heading as $index => $cell) {
            $key = import_header_key((string) $cell);

            if ($key !== '' && in_array($key, $keys, true) && !in_array($index, $map, true)) {
                $map[$name] = $index;
                break;
            }
        }
    }

    return $map;
}

/* ==========================================================================
 * Reading the file
 * ======================================================================= */

/**
 * The rows of a spreadsheet, heading row first, as plain strings.
 *
 * Throws RuntimeException with something a person can act on — this is shown to
 * whoever is doing the import, not written to a log.
 */
function import_read_file(string $path, string $originalName): array
{
    if (!is_readable($path)) {
        throw new RuntimeException('That file could not be read. Please try the upload again.');
    }

    if (filesize($path) > IMPORT_MAX_BYTES) {
        throw new RuntimeException('That file is larger than '
            . round(IMPORT_MAX_BYTES / 1024 / 1024) . ' MB. Save it as CSV, or split it.');
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($extension === 'xlsx') {
        return import_read_xlsx($path);
    }

    if (in_array($extension, ['csv', 'txt', 'tsv'], true)) {
        return import_read_csv($path);
    }

    if ($extension === 'xls') {
        throw new RuntimeException('That is the old Excel format. Open it in Excel and use '
            . '"Save as" → "Excel Workbook (.xlsx)" or "CSV UTF-8", then import that.');
    }

    throw new RuntimeException('Import a .csv or .xlsx file. '
        . ($extension === '' ? 'That file had no extension.' : 'That one was .' . $extension . '.'));
}

/** Text as UTF-8, whatever the spreadsheet decided to save it as. */
function import_to_utf8(string $text): string
{
    /* Excel writes a BOM on "CSV UTF-8" and the first heading comes out as
       "\u{FEFF}Full name", which then matches nothing. */
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;

    if (mb_check_encoding($text, 'UTF-8')) {
        return $text;
    }

    /* Plain "CSV" out of Excel on Windows is the system codepage, which here is
       1252. Converting from that turns a mangled name into the right one; a file
       that was neither is at least left as valid UTF-8 rather than breaking the
       page it is printed on. */
    return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
}

/** CSV, semicolon-separated CSV or tab-separated — whichever it turns out to be. */
function import_read_csv(string $path): array
{
    $body = (string) file_get_contents($path);

    if (trim($body) === '') {
        throw new RuntimeException('That file is empty.');
    }

    $body = import_to_utf8($body);

    /* Which separator: a European Excel writes semicolons, and a file of names
       full of commas would be read as one column if we simply assumed.

       Counted over the first several lines rather than the first one, because a
       list somebody has been keeping by hand often opens with a title line that
       has no separators in it at all — and judging on that line alone would read
       the whole file as a single column. */
    $counts = [',' => 0, ';' => 0, "\t" => 0];
    $looked = 0;

    foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }

        foreach ($counts as $candidate => $_) {
            $counts[$candidate] += substr_count($line, (string) $candidate);
        }

        if (++$looked >= 5) {
            break;
        }
    }

    arsort($counts);
    $delimiter = (string) array_key_first($counts);

    if ($counts[$delimiter] === 0) {
        $delimiter = ',';
    }

    /* Through a stream rather than explode(), so a quoted field holding a comma
       or a line break is read as one value. */
    $handle = fopen('php://temp', 'r+');

    if ($handle === false) {
        throw new RuntimeException('That file could not be read. Please try the upload again.');
    }

    fwrite($handle, $body);
    rewind($handle);

    $rows = [];

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        /* fgetcsv gives [null] for a blank line */
        if ($row === [null]) {
            continue;
        }

        $rows[] = array_map(static fn ($cell): string => trim((string) $cell), $row);
    }

    fclose($handle);

    return $rows;
}

/* ---------- .xlsx, read without the zip extension ---------- */

/**
 * The named entries of a zip archive, uncompressed.
 *
 * Walks the central directory rather than scanning for local headers: a local
 * header may carry zeroes for the sizes and leave the real ones in a descriptor
 * after the data, where they cannot be found without already knowing the length.
 * The central directory always has them.
 *
 * `$wanted` is matched on the whole entry name, so only the handful of files an
 * .xlsx keeps its data in are inflated.
 */
function import_zip_entries(string $path, array $wanted): array
{
    $body = (string) file_get_contents($path);
    $end  = strrpos($body, "PK\x05\x06");

    if ($end === false) {
        throw new RuntimeException('That .xlsx file is damaged, or is not really an Excel file. '
            . 'Open it in Excel, save it again, and import the new copy.');
    }

    $entries   = unpack('vcount', substr($body, $end + 10, 2))['count'] ?? 0;
    $directory = unpack('Voffset', substr($body, $end + 16, 4))['offset'] ?? 0;

    $found  = [];
    $cursor = $directory;

    for ($i = 0; $i < $entries; $i++) {
        if (substr($body, $cursor, 4) !== "PK\x01\x02") {
            break;
        }

        /* Offsets are counted from the start of this substring, which begins at
           the method field — ten bytes into the entry. So the compressed size,
           at byte 20 of the entry, is at 10 here, and the local header offset,
           at byte 42, is at 32. */
        $head = unpack('vmethod/@10/Vcompressed/Vuncompressed/vnameLen/vextraLen/vcommentLen/@32/Vlocal',
            substr($body, $cursor + 10, 36));

        $name   = substr($body, $cursor + 46, (int) $head['nameLen']);
        $cursor += 46 + (int) $head['nameLen'] + (int) $head['extraLen'] + (int) $head['commentLen'];

        if (!in_array($name, $wanted, true)) {
            continue;
        }

        /* The local header repeats the name and extra fields, and its extra
           field is not always the same length as the central one — so the data
           offset is measured from the local header, not guessed from it. */
        $local = (int) $head['local'];

        if (substr($body, $local, 4) !== "PK\x03\x04") {
            continue;
        }

        $localHead = unpack('vnameLen/vextraLen', substr($body, $local + 26, 4));
        $start     = $local + 30 + (int) $localHead['nameLen'] + (int) $localHead['extraLen'];
        $raw       = substr($body, $start, (int) $head['compressed']);

        if ((int) $head['method'] === 0) {
            $found[$name] = $raw;
        } elseif ((int) $head['method'] === 8) {
            $inflated = @gzinflate($raw);
            $found[$name] = $inflated === false ? '' : $inflated;
        }
        /* any other method is something Excel does not write; leave it out */
    }

    return $found;
}

/** A1 → 0, B1 → 1, AA1 → 26. */
function import_cell_column(string $reference): int
{
    $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $reference) ?? '');
    $index   = 0;

    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }

    return max(0, $index - 1);
}

/**
 * An Excel date serial as Y-m-d.
 *
 * Serial 1 is 1900-01-01 and 25569 is the Unix epoch. Excel also believes 1900
 * was a leap year, so everything from 1900-03-01 on is one day out unless the
 * offset absorbs it — which it does, because 25569 was measured against Excel's
 * calendar and not the real one.
 */
function import_excel_date(float $serial): string
{
    return gmdate('Y-m-d', (int) round(($serial - 25569) * 86400));
}

/** The first worksheet of an .xlsx, as rows of strings. */
function import_read_xlsx(string $path): array
{
    $files = import_zip_entries($path, [
        'xl/sharedStrings.xml',
        'xl/worksheets/sheet1.xml',
    ]);

    if (!isset($files['xl/worksheets/sheet1.xml'])) {
        throw new RuntimeException('That .xlsx has no first worksheet to read. '
            . 'Put the clients on the first sheet, or save the sheet as CSV.');
    }

    /* The strings of a workbook are kept once in a table and referred to by
       number from the cells, so the table is read first. */
    $shared = [];

    if (isset($files['xl/sharedStrings.xml'])) {
        $xml = @simplexml_load_string($files['xl/sharedStrings.xml']);

        if ($xml !== false) {
            foreach ($xml->si as $item) {
                /* A cell whose text is partly formatted is split into runs, each
                   with its own <t>; joined they are the string as typed. */
                $text = '';

                foreach ($item->t as $plain) {
                    $text .= (string) $plain;
                }

                foreach ($item->r as $run) {
                    $text .= (string) $run->t;
                }

                $shared[] = $text;
            }
        }
    }

    $xml = @simplexml_load_string($files['xl/worksheets/sheet1.xml']);

    if ($xml === false) {
        throw new RuntimeException('That .xlsx could not be read. Open it in Excel, save it as '
            . '"CSV UTF-8", and import that instead.');
    }

    $rows = [];

    foreach ($xml->sheetData->row as $row) {
        $cells = [];

        foreach ($row->c as $cell) {
            /* Placed by its own reference rather than by its position among the
               cells present: Excel leaves an empty cell out of the file
               altogether, and counting would shift every column after a gap. */
            $at   = import_cell_column((string) $cell['r']);
            $type = (string) $cell['t'];
            $text = '';

            if ($type === 's') {
                $text = $shared[(int) $cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $text = (string) $cell->is->t;

                foreach ($cell->is->r as $run) {
                    $text .= (string) $run->t;
                }
            } else {
                $text = (string) $cell->v;
            }

            $cells[$at] = trim($text);
        }

        if ($cells === []) {
            $rows[] = [];
            continue;
        }

        /* back to a plain list, gaps filled */
        $width = max(array_keys($cells)) + 1;
        $line  = [];

        for ($i = 0; $i < $width; $i++) {
            $line[$i] = $cells[$i] ?? '';
        }

        $rows[] = $line;
    }

    return $rows;
}

/* ==========================================================================
 * Checking what was read
 * ======================================================================= */

/** Whether a row is entirely blank — spreadsheets are full of these. */
function import_row_is_blank(array $row): bool
{
    foreach ($row as $cell) {
        if (trim((string) $cell) !== '') {
            return false;
        }
    }

    return true;
}

/**
 * Reads a file and says what would happen, without writing anything.
 *
 * Returns:
 *   ready     rows that would be imported, each as columns ready for insert
 *   problems  rows that would not be, each with the line number and why
 *   skipped   rows whose email already has an application, with its number
 *   mapped    which of our columns were found in the file
 *   ignored   headings in the file we do not use, so a mis-spelled one is seen
 */
function import_prepare(string $path, string $originalName): array
{
    $rows = import_read_file($path, $originalName);

    /* A spreadsheet people have been keeping by hand often opens with a title
       line or a blank one, so the heading is the first row that names at least
       two columns we know rather than simply the first row. */
    $heading = null;
    $offset  = 0;

    foreach ($rows as $index => $row) {
        if (import_row_is_blank($row)) {
            continue;
        }

        if (count(import_map_headers($row)) >= 2) {
            $heading = $row;
            $offset  = $index + 1;
            break;
        }

        /* two non-blank rows in and still nothing recognisable: stop looking,
           so the error names the real problem rather than a row deep in the file */
        if ($index > 4) {
            break;
        }
    }

    if ($heading === null) {
        throw new RuntimeException('No headings were recognised in that file. The first row has to '
            . 'name the columns — at least Full name and Email. Download the template below and '
            . 'fill that in if it is easier.');
    }

    $map = import_map_headers($heading);

    $missing = [];

    foreach (import_columns() as $name => $spec) {
        if ($spec['required'] && !isset($map[$name])) {
            $missing[] = $spec['label'];
        }
    }

    if ($missing !== []) {
        throw new RuntimeException('That file has no column for '
            . import_list_words($missing) . '. Every client needs '
            . (count($missing) === 1 ? 'that' : 'those') . ', so nothing was imported. '
            . 'Check the heading row, or start from the template below.');
    }

    $ignored = [];

    foreach ($heading as $index => $cell) {
        $cell = trim((string) $cell);

        if ($cell !== '' && !in_array($index, $map, true)) {
            $ignored[] = $cell;
        }
    }

    $ready    = [];
    $problems = [];
    $skipped  = [];
    $seen     = [];
    /* A duplicate is the same person, not the same mailbox. A household shares
       one address between them, and a second order from the same person is still
       a second order — so all three of name, number and email have to match
       before a row is held back. Any one of them different and it goes in. */
    $existing = db()->prepare(
        'SELECT reference_code FROM applications
          WHERE email = ?
            AND LOWER(TRIM(full_name)) = ?
            AND COALESCE(mobile_number, \'\') = ?
          LIMIT 1'
    );

    foreach (array_slice($rows, $offset) as $index => $row) {
        $line = $offset + $index + 1;      /* what the spreadsheet calls this row */

        if (import_row_is_blank($row)) {
            continue;
        }

        if (count($ready) + count($problems) + count($skipped) >= IMPORT_MAX_ROWS) {
            $problems[] = ['line' => $line, 'name' => '', 'reason' =>
                'Not read — a file may carry ' . IMPORT_MAX_ROWS . ' clients at a time. '
                . 'Import the rest as a second file.'];
            break;
        }

        [$columns, $reason] = import_row_values($row, $map);

        /* Read straight from the row rather than from the checked columns: a row
           that failed has no checked columns, and a refusal that cannot say whose
           row it was leaves somebody counting lines in a spreadsheet. */
        $name = trim((string) ($row[$map['full_name'] ?? -1] ?? ''));

        if ($reason !== '') {
            $problems[] = ['line' => $line, 'name' => $name, 'reason' => $reason];
            continue;
        }

        /* The three things together are what identify a person. */
        $key = strtolower((string) $columns['email'])
            . '|' . strtolower(trim((string) $columns['full_name']))
            . '|' . (string) ($columns['mobile_number'] ?? '');

        /* Twice in the one file. Caught here rather than by the database, which
           holds none of these unique and would take both. */
        if (isset($seen[$key])) {
            $skipped[] = ['line' => $line, 'name' => $name, 'email' => $columns['email'],
                          'reason' => 'Same name, number and email as line ' . $seen[$key] . '.'];
            continue;
        }

        $existing->execute([
            $columns['email'],
            strtolower(trim((string) $columns['full_name'])),
            (string) ($columns['mobile_number'] ?? ''),
        ]);
        $already = $existing->fetchColumn();

        if ($already !== false) {
            $skipped[] = ['line' => $line, 'name' => $name, 'email' => $columns['email'],
                          'reason' => 'Already applied — ' . $already . '.'];
            continue;
        }

        $seen[$key] = $line;
        $ready[]      = ['line' => $line, 'columns' => $columns];
    }

    return [
        'ready'    => $ready,
        'problems' => $problems,
        'skipped'  => $skipped,
        'mapped'   => array_keys($map),
        'ignored'  => $ignored,
    ];
}

/** "a, b and c" — for a sentence, not a list. */
function import_list_words(array $words): string
{
    if (count($words) === 1) {
        return (string) $words[0];
    }

    $last = array_pop($words);

    return implode(', ', $words) . ' and ' . $last;
}

/**
 * One row turned into columns, or a reason it cannot be.
 *
 * Held to the same bar as the website form: admin/submit.php refuses an
 * application without a name, an address or a usable email, and an import is not
 * a way around that.
 */
function import_row_values(array $row, array $map): array
{
    $value = static function (string $name) use ($row, $map): string {
        $index = $map[$name] ?? null;

        return $index === null ? '' : trim((string) ($row[$index] ?? ''));
    };

    $columns = [];

    foreach (import_columns() as $name => $spec) {
        $raw = $value($name);

        if ($raw === '') {
            if ($spec['required']) {
                return [[], $spec['label'] . ' is empty.'];
            }

            continue;
        }

        switch ($spec['kind']) {
            case 'email':
                if (!filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    return [[], '"' . $raw . '" is not an email address we can write to.'];
                }

                $columns[$name] = mb_substr($raw, 0, $spec['max']);
                break;

            case 'int':
                if (!is_numeric($raw)) {
                    return [[], $spec['label'] . ' should be a number, not "' . $raw . '".'];
                }

                $columns[$name] = (int) $raw;
                break;

            case 'product':
                $product = strtolower($raw);
                $product = str_contains($product, 'tuk') || str_contains($product, 'kit')
                    || str_contains($product, 'rickshaw') ? 'tuktuk' : 'stove';
                $columns[$name] = $product;
                break;

            case 'date':
                $date = import_read_date($raw);

                if ($date === null) {
                    return [[], $spec['label'] . ' "' . $raw . '" is not a date we can read. '
                        . 'Use YYYY-MM-DD, or a real date cell.'];
                }

                $columns[$name] = $date;
                break;

            case 'dial':
            case 'mobile':
                /* checked together, below, because neither means anything alone */
                $columns[$name] = $raw;
                break;

            default:
                $columns[$name] = mb_substr($raw, 0, $spec['max']);
        }
    }

    $columns['product'] = $columns['product'] ?? 'stove';

    /* ---------- the number and its code ---------- */

    $digits = static fn (string $text): string => preg_replace('/\D/', '', $text) ?? '';

    $dial = $digits((string) ($columns['mobile_code'] ?? ''));

    if ($dial === '' || strlen($dial) > 4) {
        $dial = DEFAULT_DIAL_CODE;
    }

    unset($columns['mobile_code']);

    [$min, $max] = dial_digits($dial);
    $says = $min === $max ? $min . ' digits' : $min . ' to ' . $max . ' digits';

    foreach (['mobile_number' => 'The mobile number',
              'alt_mobile_number' => 'The alternative mobile number'] as $field => $label) {
        if (!isset($columns[$field])) {
            continue;
        }

        $national = $digits((string) $columns[$field]);

        /* Somebody who keeps numbers as +91 98… or 0091 98… has the code in the
           column already; taking it off is kinder than refusing the row. */
        if (strlen($national) > $max && str_starts_with($national, $dial)) {
            $national = substr($national, strlen($dial));
        }

        if ($national === '') {
            unset($columns[$field]);
            continue;
        }

        if (strlen($national) < $min || strlen($national) > $max) {
            return [[], $label . ' "' . $columns[$field] . '" is not ' . $says . ' for +' . $dial . '.'];
        }

        $columns[$field] = '+' . $dial . $national;
    }

    return [$columns, ''];
}

/**
 * A date cell as Y-m-d, or null.
 *
 * Takes what spreadsheets actually hold: a real date cell (a serial number), or
 * text in any of the orders people write dates in. Day-first where it is
 * ambiguous, because this business is run from India and 03/04 is the third of
 * April to everybody typing it.
 */
function import_read_date(string $raw): ?string
{
    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    if (is_numeric($raw) && (float) $raw > 60 && (float) $raw < 80000) {
        return import_excel_date((float) $raw);
    }

    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j M Y', 'j F Y', 'Y/m/d', 'd/m/y', 'd-m-y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $raw);

        if ($date !== false && $date->format($format) === $raw) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

/* ==========================================================================
 * Writing
 * ======================================================================= */

/**
 * Creates the applications, and says what was created.
 *
 * `$context` decides who the sale belongs to:
 *   dealer       the dealer row it is booked to, or null
 *   distributor  likewise
 *   entered_by   ['dealer'|'distributor'|'office', id]
 *
 * Every row lands at 'submitted', the same place a website application lands:
 * the office looks at it before anything is asked of the customer. Nothing here
 * approves anything.
 */
function import_commit(array $ready, array $context): array
{
    $dealer      = $context['dealer'] ?? null;
    $distributor = $context['distributor'] ?? null;
    $created     = [];

    foreach ($ready as $row) {
        $columns = $row['columns'];

        /* the price list is frozen onto the row, exactly as the website form
           freezes it, so a later change never rewrites what this sale owes */
        $plan = payment_plan((string) $columns['product']);

        $columns['booking_amount']  = (float) $plan['booking'];
        $columns['delivery_amount'] = (float) $plan['delivery'];
        $columns['payment_amount']  = (float) $plan['booking'];

        /* who it belongs to and what every tier of the chain is owed on it */
        $columns = array_merge(
            $columns,
            commission_chain_columns((string) $columns['product'], $dealer, $distributor,
                $context['zonal_direct'] ?? null) /* import has no payment_plan: always booking, so $full stays false */
        );

        /* The office still collects: an imported client goes through the portal
           and pays booking then delivery like any other. What the two columns
           below record is only who typed them in. */
        $columns['sale_channel']           = 'online';
        $columns['entered_by_dealer']      = $context['entered_by_dealer'] ?? null;
        $columns['entered_by_distributor'] = $context['entered_by_distributor'] ?? null;

        /* The person importing has ticked to say each of these customers agreed;
           that is what the tick on the import screen is for. */
        $columns['declaration_accepted'] = 1;
        $columns['terms_accepted']       = 1;

        $columns['reference_code'] = 'tmp-' . bin2hex(random_bytes(6));
        $columns['ip_address']     = $_SERVER['REMOTE_ADDR'] ?? null;

        $names        = array_keys($columns);
        $placeholders = implode(', ', array_fill(0, count($names), '?'));

        db()->prepare('INSERT INTO applications (`' . implode('`, `', $names) . '`) VALUES ('
            . $placeholders . ')')->execute(array_values($columns));

        $id  = (int) db()->lastInsertId();
        $ref = make_reference_code($id);

        db()->prepare('UPDATE applications SET reference_code = ? WHERE id = ?')->execute([$ref, $id]);

        $columns['id']             = $id;
        $columns['reference_code'] = $ref;
        $columns['created_at']     = date('Y-m-d H:i:s');

        $created[] = $columns;
    }

    return $created;
}

/**
 * Tells everybody, once the response has gone.
 *
 * Each customer hears that their application arrived — the same note the website
 * sends — and the office gets one summary rather than one email per row, which
 * for a file of five hundred would be five hundred copies of the same news.
 */
function import_notify(array $created, array $context): void
{
    if ($created === []) {
        return;
    }

    after_response(static function () use ($created, $context): void {
        foreach ($created as $app) {
            /* A refusal is already written to email_log by the mailer, with the
               address and what the server said, so a rate-limited run can be read
               back afterwards rather than being lost. */
            send_application_received_email($app);
        }

        send_import_summary_admin($created, $context);
    });
}

/** Who an import was done by, in words, for the summary email and the log. */
function import_actor_label(array $context): string
{
    if (!empty($context['dealer'])) {
        return 'dealer ' . ($context['dealer']['full_name'] ?? '')
            . ' (' . ($context['dealer']['dealer_code'] ?? '') . ')';
    }

    if (!empty($context['distributor'])) {
        return 'distributor ' . ($context['distributor']['full_name'] ?? '')
            . ' (' . ($context['distributor']['distributor_code'] ?? '') . ')';
    }

    return 'the office';
}

/** Who these sales earn for, in words. */
function import_commission_label(array $context): string
{
    $dealer      = $context['dealer'] ?? null;
    $distributor = $context['distributor'] ?? null;

    if ($dealer) {
        return (string) $dealer['full_name'] . ' (' . (string) $dealer['dealer_code'] . ')'
            . ($distributor ? ', override to ' . (string) $distributor['full_name'] : '');
    }

    if ($distributor) {
        return (string) $distributor['full_name'] . ' (' . (string) $distributor['distributor_code'] . ')';
    }

    return 'Nobody — booked to the company';
}

/* ==========================================================================
 * The template
 * ======================================================================= */

/**
 * A starter file with the headings in it and one example row.
 *
 * Written with a BOM so Excel opens it as UTF-8 and a name with an accent in it
 * survives the round trip.
 */
function import_template_csv(): string
{
    $labels  = [];
    $example = [];

    $sample = [
        'full_name' => 'Priya Sharma',
        'email' => 'priya.sharma@example.com',
        'mobile_number' => '9876543210',
        'mobile_code' => '91',
        'alt_mobile_number' => '',
        'product' => 'stove',
        'house_number' => 'B-204',
        'street' => 'Satellite Road',
        'city' => 'Ahmedabad',
        'state' => 'Gujarat',
        'country' => 'India',
        'pin_code' => '380015',
        'date_of_birth' => '1988-04-17',
        'household_members' => '4',
        'units_required' => '1',
    ];

    foreach (import_columns() as $name => $spec) {
        $labels[]  = $spec['label'] . ($spec['required'] ? ' *' : '');
        $example[] = $sample[$name] ?? '';
    }

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $labels);
    fputcsv($handle, $example);
    rewind($handle);
    $body = (string) stream_get_contents($handle);
    fclose($handle);

    return "\xEF\xBB\xBF" . $body;
}

/** Sends the template as a download and stops. */
function import_send_template(): void
{
    $body = import_template_csv();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="manifold-client-import-template.csv"');
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');

    echo $body;
    exit;
}

/* ==========================================================================
 * The upload itself
 * ======================================================================= */

/* ==========================================================================
 * The two steps, driven from one page
 * ======================================================================= */

/**
 * Runs an import screen and says what it should show.
 *
 * The same three states on all three desks: choose a file, look at what is in
 * it, say yes. What differs is only `$context` — who the sale is booked to — and
 * that is decided by the page that calls this, from its own session, never from
 * anything posted. A dealer cannot import clients onto another dealer by editing
 * a form field, because no form field says which dealer it is.
 *
 * Between the two steps the read rows sit in the session. They are not written
 * anywhere until somebody has seen the count and pressed the button.
 */
function import_run(array $context, string $sessionKey): array
{
    $state = ['stage' => 'upload', 'error' => '', 'preview' => null, 'result' => null];

    if (isset($_GET['template'])) {
        import_send_template();
    }

    $action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';

    if ($action === 'cancel') {
        unset($_SESSION[$sessionKey]);

        return $state;
    }

    if ($action === 'upload') {
        csrf_check();

        $file  = $_FILES['sheet'] ?? null;
        $error = $file === null ? 'No file was chosen.' : import_upload_error((int) $file['error']);

        if ($error !== '') {
            $state['error'] = $error;

            return $state;
        }

        if (!is_uploaded_file((string) $file['tmp_name'])) {
            $state['error'] = 'That upload did not arrive properly. Please try again.';

            return $state;
        }

        try {
            $preview = import_prepare((string) $file['tmp_name'], (string) $file['name']);
        } catch (RuntimeException $e) {
            $state['error'] = $e->getMessage();

            return $state;
        }

        $preview['file'] = (string) $file['name'];

        $_SESSION[$sessionKey] = $preview;

        $state['stage']   = 'preview';
        $state['preview'] = $preview;

        return $state;
    }

    if ($action === 'confirm') {
        csrf_check();

        $preview = $_SESSION[$sessionKey] ?? null;

        if (!is_array($preview) || ($preview['ready'] ?? []) === []) {
            $state['error'] = 'That import is no longer open — nothing was created. '
                . 'Choose the file again.';
            unset($_SESSION[$sessionKey]);

            return $state;
        }

        if (empty($_POST['agreed'])) {
            $state['stage']   = 'preview';
            $state['preview'] = $preview;
            $state['error']   = 'Tick the box to confirm these customers agreed to the '
                . 'declaration and terms, and nothing will be created without it.';

            return $state;
        }

        $created = import_commit($preview['ready'], $context);

        import_notify($created, $context);

        unset($_SESSION[$sessionKey]);

        $state['stage']  = 'done';
        $state['result'] = [
            'created'  => $created,
            'skipped'  => $preview['skipped'] ?? [],
            'problems' => $preview['problems'] ?? [],
        ];

        return $state;
    }

    return $state;
}

/** What PHP's own upload errors mean to somebody who just chose a file. */
function import_upload_error(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_OK:
            return '';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was chosen.';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'That file is too large to upload. Save it as CSV, or split it in two.';
        case UPLOAD_ERR_PARTIAL:
            return 'The upload was cut off. Please try again.';
        default:
            return 'That file could not be uploaded. Please try again.';
    }
}
