<?php
/**
 * A one-sheet Excel workbook, written by hand.
 *
 * A CSV carries no widths, no number formats and no heading, so a report sent
 * to somebody who opens it in Excel arrives as a wall of `####` in columns four
 * characters wide. This writes a real .xlsx instead: column widths, a heading
 * block, a frozen header row, right-aligned money with thousands separators,
 * and a totals line — the file somebody can forward without tidying it first.
 *
 * No Composer and no zip extension: an .xlsx is a zip of small XML files, and
 * the handful of them this needs are written straight out, stored rather than
 * compressed, which needs nothing but crc32().
 *
 * Give build_xlsx() a sheet:
 *
 *   ['name' => 'Dealers', 'title' => 'Dealer MIS report',
 *    'meta' => [['Period', 'All time'], ...],
 *    'columns' => [['label' => 'Code', 'width' => 14, 'type' => 'text'], ...],
 *    'rows' => [[...values in column order...], ...],
 *    'totals' => [...same shape, drawn under a rule...]]
 *
 * `type` is text, int, money or date. Money and int take numbers, date takes
 * a Y-m-d string; everything else is written as text.
 */

declare(strict_types=1);

/** One file inside the archive. */
function xlsx_entry(string $name, string $body): array
{
    return ['name' => $name, 'body' => $body];
}

/**
 * The entries as a zip, stored rather than deflated.
 *
 * Excel does not care which it is, and storing means no dependency on the zip
 * extension being compiled in — these files are a few kilobytes.
 */
function xlsx_zip(array $entries): string
{
    $local   = '';
    $central = '';

    foreach ($entries as $entry) {
        $name   = $entry['name'];
        $body   = $entry['body'];
        $crc    = crc32($body);
        $length = strlen($body);
        $offset = strlen($local);

        $header = pack('vvvvv', 20, 0, 0, 0, 0)          /* version, flags, stored, time, date */
            . pack('VVV', $crc, $length, $length)
            . pack('vv', strlen($name), 0);

        $local   .= "PK\x03\x04" . $header . $name . $body;
        $central .= "PK\x01\x02" . pack('v', 20) . $header
            . pack('vvvVV', 0, 0, 0, 0, $offset) . $name;
    }

    return $local . $central . "PK\x05\x06" . pack('vv', 0, 0)
        . pack('vv', count($entries), count($entries))
        . pack('VV', strlen($central), strlen($local)) . pack('v', 0);
}

/** Escape for XML text, and drop the control characters Excel refuses. */
function xlsx_text(string $value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';

    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** A1, B1, … AA1 for a zero-based column index. */
function xlsx_ref(int $column, int $row): string
{
    $name = '';

    for ($n = $column + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $name = chr(65 + ($n - 1) % 26) . $name;
    }

    return $name . $row;
}

/**
 * Excel counts days from 30 December 1899, with the 1900 leap-year bug baked in.
 *
 * Both sides of the subtraction are local time, so the offset cancels and a row
 * written at half past eleven at night keeps its own date.
 */
function xlsx_date(string $value): ?float
{
    $time  = strtotime($value);
    $epoch = strtotime('1899-12-30 00:00:00');

    return $time === false ? null : floor(($time - $epoch) / 86400);
}

/** One cell, or nothing at all when there is nothing to say. */
function xlsx_cell(int $column, int $row, $value, int $style, string $type = 'text'): string
{
    $ref = xlsx_ref($column, $row);

    if ($value === null || $value === '') {
        return '<c r="' . $ref . '" s="' . $style . '"/>';
    }

    if ($type === 'int' || $type === 'money') {
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . (0 + $value) . '</v></c>';
    }

    if ($type === 'date') {
        $serial = xlsx_date((string) $value);

        return $serial === null
            ? '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>'
              . xlsx_text((string) $value) . '</t></is></c>'
            : '<c r="' . $ref . '" s="' . $style . '"><v>' . $serial . '</v></c>';
    }

    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
        . xlsx_text((string) $value) . '</t></is></c>';
}

/**
 * The styles this report uses, by index into cellXfs below.
 *
 * 0 plain · 1 title · 2 meta label · 3 meta value · 4 column header
 * 5 text · 6 whole number · 7 money · 8 date
 * 9 total label · 10 total number · 11 total money
 */
function xlsx_styles(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="3">'
        . '<numFmt numFmtId="164" formatCode="#,##0.00"/>'
        . '<numFmt numFmtId="165" formatCode="#,##0"/>'
        . '<numFmt numFmtId="166" formatCode="dd mmm yyyy"/>'
        . '</numFmts>'
        . '<fonts count="5">'
        . '<font><sz val="11"/><name val="Calibri"/><color rgb="FF0D2233"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF0D2233"/></font>'
        . '<font><b/><sz val="16"/><name val="Calibri"/><color rgb="FF0E8F96"/></font>'
        . '<font><sz val="10"/><name val="Calibri"/><color rgb="FF6B8199"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0E8F96"/>'
        . '<bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="3">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left/><right/><top/>'
        . '<bottom style="thin"><color rgb="FFE3EBF2"/></bottom><diagonal/></border>'
        . '<border><left/><right/><top style="thin"><color rgb="FF0E8F96"/></top>'
        . '<bottom/><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="12">'
        . '<xf xfId="0" numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
        . '<xf xfId="0" numFmtId="0" fontId="2" fillId="0" borderId="0" applyFont="1"/>'
        . '<xf xfId="0" numFmtId="0" fontId="3" fillId="0" borderId="0" applyFont="1"/>'
        . '<xf xfId="0" numFmtId="0" fontId="1" fillId="0" borderId="0" applyFont="1"/>'
        . '<xf xfId="0" numFmtId="0" fontId="4" fillId="2" borderId="0" applyFont="1" applyFill="1"'
        . ' applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf xfId="0" numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"'
        . ' applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf xfId="0" numFmtId="165" fontId="0" fillId="0" borderId="1" applyNumberFormat="1"'
        . ' applyBorder="1"/>'
        . '<xf xfId="0" numFmtId="164" fontId="0" fillId="0" borderId="1" applyNumberFormat="1"'
        . ' applyBorder="1"/>'
        . '<xf xfId="0" numFmtId="166" fontId="0" fillId="0" borderId="1" applyNumberFormat="1"'
        . ' applyBorder="1"/>'
        . '<xf xfId="0" numFmtId="0" fontId="1" fillId="0" borderId="2" applyFont="1" applyBorder="1"/>'
        . '<xf xfId="0" numFmtId="165" fontId="1" fillId="0" borderId="2" applyNumberFormat="1"'
        . ' applyFont="1" applyBorder="1"/>'
        . '<xf xfId="0" numFmtId="164" fontId="1" fillId="0" borderId="2" applyNumberFormat="1"'
        . ' applyFont="1" applyBorder="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/** The workbook, as raw .xlsx bytes. */
function build_xlsx(array $sheet): string
{
    $columns = $sheet['columns'];
    $count   = count($columns);
    $last    = xlsx_ref($count - 1, 1);
    $wide    = substr($last, 0, -1);

    /* the heading block: a title, then a label and its value on a line each */
    $rowNo = 1;
    $body  = '';

    $body .= '<row r="' . $rowNo . '" ht="26" customHeight="1">'
        . xlsx_cell(0, $rowNo, $sheet['title'], 1) . '</row>';
    $rowNo++;

    foreach ($sheet['meta'] as [$label, $value]) {
        $body .= '<row r="' . $rowNo . '">'
            . xlsx_cell(0, $rowNo, $label, 2)
            . xlsx_cell(1, $rowNo, $value, 3)
            . '</row>';
        $rowNo++;
    }

    $rowNo++;   /* a blank line between the heading and the table */
    $headerRow = $rowNo;

    $body .= '<row r="' . $headerRow . '" ht="30" customHeight="1">';

    foreach ($columns as $i => $column) {
        $body .= xlsx_cell($i, $headerRow, $column['label'], 4);
    }

    $body .= '</row>';
    $rowNo++;

    foreach ($sheet['rows'] as $row) {
        $body .= '<row r="' . $rowNo . '" ht="18" customHeight="1">';

        foreach ($columns as $i => $column) {
            $type  = $column['type'] ?? 'text';
            $style = ['text' => 5, 'int' => 6, 'money' => 7, 'date' => 8][$type] ?? 5;

            $body .= xlsx_cell($i, $rowNo, $row[$i] ?? null, $style, $type);
        }

        $body .= '</row>';
        $rowNo++;
    }

    if (!empty($sheet['totals'])) {
        $body .= '<row r="' . $rowNo . '" ht="20" customHeight="1">';

        foreach ($columns as $i => $column) {
            $type  = $column['type'] ?? 'text';
            $style = ['text' => 9, 'int' => 10, 'money' => 11, 'date' => 9][$type] ?? 9;

            $body .= xlsx_cell($i, $rowNo, $sheet['totals'][$i] ?? null, $style, $type);
        }

        $body .= '</row>';
    }

    $cols = '<cols>';

    foreach ($columns as $i => $column) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="'
            . (float) ($column['width'] ?? 14) . '" customWidth="1"/>';
    }

    $cols .= '</cols>';

    /* the header row stays put while somebody scrolls a long list, and the
       filter arrows are what an office expects on a report like this */
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<sheetViews><sheetView showGridLines="0" workbookViewId="0">'
        . '<pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1)
        . '" activePane="bottomLeft" state="frozen"/>'
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . $cols
        . '<sheetData>' . $body . '</sheetData>'
        . '<autoFilter ref="A' . $headerRow . ':' . $wide . ($rowNo - 1) . '"/>'
        . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';

    return xlsx_zip([
        xlsx_entry('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>'),

        xlsx_entry('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Target="xl/workbook.xml"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"/>'
            . '</Relationships>'),

        xlsx_entry('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . xlsx_text($sheet['name']) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>'),

        xlsx_entry('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Target="worksheets/sheet1.xml"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/>'
            . '<Relationship Id="rId2" Target="styles.xml"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"/>'
            . '</Relationships>'),

        xlsx_entry('xl/styles.xml', xlsx_styles()),
        xlsx_entry('xl/worksheets/sheet1.xml', $sheetXml),
    ]);
}
