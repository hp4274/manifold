<?php
/**
 * Terms & Conditions as a PDF, drawn with the receipt's writer so the sheet
 * carries the same masthead, brand rule, washed mark and footer.
 *
 * Customer terms live in static apply-*.html pages, which cannot read PHP, so
 * the items are kept here as the exact HTML each page shows. Change a clause in
 * both places, word for word — the water clause stays last in each list.
 * Partner terms come from partner_terms(), the same source the form renders.
 */

declare(strict_types=1);

require_once __DIR__ . '/receipt-pdf.php';

/** Every kind a terms PDF can be asked for. */
const TERMS_PDF_KINDS = ['stove', 'tuktuk', 'principal_cf', 'zonal_cf', 'distributor', 'dealer'];

/**
 * The customer terms exactly as apply-stove.html / apply-tuktuk.html show them:
 * each list item's inner HTML, then the paragraphs under the list.
 *
 * @return array{title: string, items: list<string>, notes: list<string>}
 */
function customer_terms(string $product): array
{
    $tuktuk = $product === 'tuktuk';

    $items = $tuktuk ? [
        '<strong>48-Hour Cancellation Window:</strong> For the Loan option, once your booking amount has been received and verified you may cancel the booking for a full refund within forty-eight (48) hours, provided your documents have not yet been verified. Full-payment applicants have no document verification step: the office approves the application, then the full price is paid.',
        '<strong>Strict No-Cancellation Policy:</strong> Once your documents have been verified, or the 48-hour cancellation window has passed, or on a full-payment order once payment is confirmed, bookings, orders, and kit allocations are strictly final, non-cancellable, and non-refundable.',
        '<strong>Exclusive Jurisdiction:</strong> Any dispute, difference, or claim arising out of or related to this application, booking, kit allocation, or sale shall be subject exclusively to the competent courts of Ahmedabad, Gujarat, India.',
        'Submission of this application does not constitute an automatic grant or immediate delivery of the conversion kit until technical assessment and, for the Loan option, document verification are completed.',
        'The Company reserves the right to accept, reject or defer any application without assigning a reason.',
        'All information provided by the applicant must be accurate and complete.',
        'The applicant authorises the Company to conduct verification, vehicle inspection, and technical suitability checks where required.',
        'All vehicle documents (RC, DL) and personal KYC submitted must be authentic and valid. Any misrepresentation will result in immediate termination of the application without refund.',
        'Installation timelines are estimates and may vary depending on location, product availability and regulatory approvals.',
        'If the financing application is rejected, any amount already paid towards the order is refunded in full.',
        'Product specifications, performance figures and operating requirements are subject to change without prior notice.',
        'The Company shall not be liable for delays caused by force majeure, government restrictions, utility interruptions or circumstances beyond its control.',
        'The applicant consents to the collection and processing of personal information for application review, installation, customer support and warranty administration.',
        'Financing approvals, where applicable, remain subject to the independent review of the financing institution.',
        'Warranty terms shall be governed by the official Warranty Certificate supplied with the kit upon installation.',
    ] : [
        '<strong>48-Hour Cancellation Window:</strong> For the Loan option, once your booking amount has been received and verified you may cancel the booking for a full refund within forty-eight (48) hours, provided your documents have not yet been verified. Full-payment applicants have no document verification step: the office approves the application, then the full price is paid.',
        '<strong>Strict No-Cancellation Policy:</strong> Once your documents have been verified, or the 48-hour cancellation window has passed, or on a full-payment order once payment is confirmed, bookings, orders, and product allocations are strictly final, non-cancellable, and non-refundable.',
        '<strong>Exclusive Jurisdiction:</strong> Any dispute, difference, or claim arising out of or related to this application, booking, or purchase shall be subject exclusively to the competent courts of Ahmedabad, Gujarat, India.',
        'Submission of this application does not constitute an automatic grant or immediate delivery until technical assessment and, for the Loan option, document verification are completed.',
        'The Company reserves the right to accept, reject or defer any application without assigning a reason.',
        'The applicant authorises the Company to conduct verification, technical assessment, and site inspection where required.',
        'All information and documentation submitted by the applicant must be authentic, valid, and accurate. Any misrepresentation will result in immediate termination of the application without refund.',
        'Installation timelines are estimates and may vary depending on location, product availability and regulatory approvals.',
        'If the financing application is rejected, any amount already paid towards the order is refunded in full.',
        'Product specifications, performance figures and operating requirements are subject to change without prior notice.',
        'The Company shall not be liable for delays caused by force majeure, government restrictions, utility interruptions or circumstances beyond its control.',
        'The applicant consents to the collection and processing of personal information for application review, installation, customer support and warranty administration.',
        'Financing approvals, where applicable, remain subject to the independent review of the financing institution.',
        'Warranty terms shall be governed by the official Warranty Certificate supplied with the appliance upon installation.',
    ];

    $items[] = '<strong>Water Quality &amp; TDS Limit:</strong> Safe and warranty-compliant operation requires source water Total Dissolved Solids (TDS) strictly at or below 1,200 ppm (TDS &le; 1200). Supplying water exceeding 1,200 TDS voids equipment warranty and performance guarantees.';

    return [
        'title' => $tuktuk ? 'Hydrogen Conversion Kit for TukTuk' : 'Kinetic Hydrogen Cooking Stove',
        'items' => $items,
        'notes' => [
            '<strong>Processing fee:</strong> An application processing fee may apply. It covers administrative review, technical assessment, verification and site inspection. Payment of it does not guarantee approval, allocation, financing or installation — and if the financing application is rejected, any amount already paid is refunded in full.',
            '<strong>Processing &amp; Booking Terms:</strong> Booking amounts secure priority production allocation and '
                . ($tuktuk ? 'conversion slot scheduling' : 'technical dispatch scheduling')
                . '. Terms are governed under Ahmedabad legal jurisdiction.',
        ],
    ];
}

/** HTML clause text to the plain single-byte text the PDF faces can set. */
function terms_pdf_plain(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return str_replace(['≤', '→'], ['<=', '->'], $text);
}

/**
 * The terms for one kind, as raw PDF bytes. Each clause is starred rather than
 * numbered, matching the pages.
 */
function build_terms_pdf(string $kind): string
{
    if (in_array($kind, ['stove', 'tuktuk'], true)) {
        $terms = customer_terms($kind);
        $title = $terms['title'];
        $intro = 'These terms apply to every application for the ' . $title . '.';
        $items = $terms['items'];
        $notes = $terms['notes'];
    } else {
        $terms = partner_terms($kind);
        $title = terms_pdf_plain($terms['label']) . ' partner';
        $intro = $terms['intro'];
        $items = array_map(static fn (array $i): string => '<strong>' . $i[0] . ':</strong> ' . $i[1], $terms['items']);
        $notes = [];
    }

    $pdf   = new SimplePdf();
    $ink   = [0.06, 0.17, 0.30];
    $body  = [0.36, 0.44, 0.53];
    $muted = [0.52, 0.60, 0.67];
    $teal  = [0.05, 0.56, 0.59];
    $line  = [0.89, 0.92, 0.95];
    $left  = 56.0;
    $right = $pdf->width() - 56.0;
    $foot  = 742.0;
    $page  = 1;

    /* masthead, brand rule, watermark and footer: the receipt's page, on every page */
    $frame = static function () use ($pdf, $ink, $muted, $teal, $line, $body, $left, $right, $foot, $title, &$page): void {
        $pdf->logo($right - 118, 582, 220, 0.955, false);

        $mark = 'MANIFOLD CLEAN ENERGY';
        $w    = $pdf->widthOf($mark, 42, true, 3);
        $pdf->textRotated($pdf->width() / 2 - $w / 2 * M_SQRT1_2, 430 + $w / 2 * M_SQRT1_2, $mark, 42, 45, [0.95, 0.965, 0.975], 3);

        $pdf->logo($left + 21, 60, 46);
        $pdf->text($left + 56, 52, 'Manifold Clean Energy', 15, true, $ink);
        $pdf->text($left + 56, 68, 'Hydrogen on demand. Made in India.', 8.5, false, $muted);
        $pdf->label(0, 46, 'Terms & conditions', $muted, true, $right);
        $pdf->textRight($right, 68, $title, 11, true, $teal);

        $steps = 46;
        $span  = ($right - $left) / $steps;
        for ($i = 0; $i < $steps; $i++) {
            $t = $i / ($steps - 1);
            $pdf->rect($left + $i * $span, 96, $span + 0.4, 2.6,
                [0.29 + (0.09 - 0.29) * $t, 0.71 + (0.69 - 0.71) * $t, 0.33 + (0.65 - 0.33) * $t]);
        }

        $pdf->line($left, $foot - 18, $right, $line);
        $pdf->text($left, $foot, 'Manifold Clean Energy Pvt. Ltd.', 9, true, $body);
        $pdf->textRight($right, $foot, 'Page ' . $page, 8, false, $muted);
        $pdf->text($left, $foot + 14, '711 Safal Prelude, Corporate Rd, Prahlad Nagar, Ahmedabad 380015, Gujarat, India', 8, false, $muted);
        $pdf->text($left, $foot + 27, '+91 97251 54186   ·   info@manifoldcleanenergy.co.in', 8, false, $muted);
    };

    $y = 132.0;

    /* starts the next page once the cursor is past $limit */
    $room = static function (float $limit) use ($pdf, $frame, &$y, &$page): void {
        if ($y > $limit) {
            $pdf->newPage();
            $page++;
            $frame();
            $y = 132.0;
        }
    };

    /* words onto lines no wider than the column, starting a page when full */
    $write = static function (string $text, float $x, float $size, bool $bold, array $rgb) use ($pdf, $right, $room, &$y): void {
        $lead  = $size * 1.45;
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $row   = '';

        $flush = static function (string $row) use ($pdf, $x, $size, $bold, $rgb, $lead, $room, &$y): void {
            $room(700);
            $pdf->text($x, $y, $row, $size, $bold, $rgb);
            $y += $lead;
        };

        foreach ($words as $word) {
            $try = $row === '' ? $word : $row . ' ' . $word;
            if ($row !== '' && $pdf->widthOf($try, $size, $bold) > $right - $x) {
                $flush($row);
                $row = $word;
            } else {
                $row = $try;
            }
        }
        if ($row !== '') {
            $flush($row);
        }
    };

    $frame();
    $write($intro, $left, 9.5, false, $muted);
    $y += 8;

    $clause = static function (string $html, bool $star) use ($pdf, $write, $room, $left, $ink, $body, &$y): void {
        $room(670);   /* keep a heading with its first lines */
        $head = null;
        if (preg_match('#^<strong>(.*?):?</strong>\s*(.*)$#s', $html, $m)) {
            [$head, $html] = [rtrim($m[1], ':'), $m[2]];
        }
        $indent = $star ? $left + 14 : $left;

        if ($star) {
            $pdf->text($left + 2, $y, '*', 11, true, [0.05, 0.56, 0.59]);
        }
        if ($head !== null) {
            $write(terms_pdf_plain($head), $indent, 10, true, $ink);
        }
        $write(terms_pdf_plain($html), $indent, 9.5, false, $body);
        $y += 7;
    };

    foreach ($items as $item) {
        $clause($item, true);
    }
    foreach ($notes as $note) {
        $clause($note, false);
    }

    return $pdf->output();
}
