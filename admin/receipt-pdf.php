<?php
/**
 * Receipt PDFs, written by hand.
 *
 * No Composer here, so this builds a minimal PDF 1.4 document directly: one
 * A4 page, the two base-14 Helvetica faces, lines, filled rectangles and
 * circles. The logo is drawn rather than embedded — the mark is a ring of
 * dots around H2, which is a handful of bezier circles, so the receipt stays
 * one dependency-free file and the mark is crisp at any zoom.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

final class SimplePdf
{
    /** A4 portrait in points, which is what a receipt is. */
    private const WIDTH  = 595.28;
    private const HEIGHT = 841.89;

    /** The page this document is actually drawn on — A4 either way up. */
    private float $width;
    private float $height;

    /** Pages already finished; the one being drawn is $content. */
    private array $pages = [];

    /** Landscape is the only other page a report ever wants. */
    public function __construct(bool $landscape = false)
    {
        $this->width  = $landscape ? self::HEIGHT : self::WIDTH;
        $this->height = $landscape ? self::WIDTH : self::HEIGHT;
    }

    /** Finishes the page being drawn and starts the next one. */
    public function newPage(): void
    {
        $this->pages[] = $this->content;
        $this->content = '';
    }

    public function height(): float
    {
        return $this->height;
    }

    /** Bezier constant for drawing a circle out of four curves. */
    private const KAPPA = 0.5523;

    private string $content = '';

    /**
     * Helvetica character widths, thousandths of an em, ASCII 32-126. Right
     * alignment and centring are only as good as these numbers, and guessing
     * an average put every right-aligned value a few points out.
     */
    private const W_REGULAR = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    private const W_BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];

    public function width(): float
    {
        return $this->width;
    }

    /** y is measured from the top of the page, which is easier to reason about. */
    private function y(float $fromTop): float
    {
        return $this->height - $fromTop;
    }

    public function text(float $x, float $fromTop, string $text, float $size = 11, bool $bold = false, array $rgb = [0.36, 0.44, 0.53], float $tracking = 0.0): void
    {
        $this->content .= sprintf(
            "BT /%s %.2f Tf %.2f Tc %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1',
            $size,
            $tracking,
            $rgb[0], $rgb[1], $rgb[2],
            $x,
            $this->y($fromTop),
            $this->escape($text)
        );
    }

    /** A small uppercase label. Tracking is what makes these read as labels. */
    public function label(float $x, float $fromTop, string $text, array $rgb, bool $right = false, float $rightX = 0.0): void
    {
        $text = strtoupper($text);

        if ($right) {
            $x = $rightX - $this->widthOf($text, 7.5, true, 1.1);
        }

        $this->text($x, $fromTop, $text, 7.5, true, $rgb, 1.1);
    }

    public function textRight(float $rightX, float $fromTop, string $text, float $size = 11, bool $bold = false, array $rgb = [0.36, 0.44, 0.53]): void
    {
        $this->text($rightX - $this->widthOf($text, $size, $bold), $fromTop, $text, $size, $bold, $rgb);
    }

    public function textCenter(float $centreX, float $fromTop, string $text, float $size, bool $bold, array $rgb): void
    {
        $this->text($centreX - $this->widthOf($text, $size, $bold) / 2, $fromTop, $text, $size, $bold, $rgb);
    }

    public function rect(float $x, float $fromTop, float $w, float $h, array $rgb): void
    {
        $this->content .= sprintf(
            "%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",
            $rgb[0], $rgb[1], $rgb[2],
            $x,
            $this->y($fromTop + $h),
            $w,
            $h
        );
    }

    public function line(float $x1, float $fromTop, float $x2, array $rgb = [0.89, 0.92, 0.95], float $thickness = 0.8): void
    {
        $this->content .= sprintf(
            "%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n",
            $rgb[0], $rgb[1], $rgb[2],
            $thickness,
            $x1, $this->y($fromTop),
            $x2, $this->y($fromTop)
        );
    }

    /** A filled circle, from four bezier curves. */
    public function circle(float $cx, float $fromTop, float $r, array $rgb): void
    {
        $cy = $this->y($fromTop);
        $k  = $r * self::KAPPA;

        $this->content .= sprintf(
            "%.3f %.3f %.3f rg %.2f %.2f m %.2f %.2f %.2f %.2f %.2f %.2f c "
            . "%.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c "
            . "%.2f %.2f %.2f %.2f %.2f %.2f c f\n",
            $rgb[0], $rgb[1], $rgb[2],
            $cx + $r, $cy,
            $cx + $r, $cy + $k, $cx + $k, $cy + $r, $cx, $cy + $r,
            $cx - $k, $cy + $r, $cx - $r, $cy + $k, $cx - $r, $cy,
            $cx - $r, $cy - $k, $cx - $k, $cy - $r, $cx, $cy - $r,
            $cx + $k, $cy - $r, $cx + $r, $cy - $k, $cx + $r, $cy
        );
    }

    /** A pill, for the one badge on the page. */
    public function pill(float $x, float $fromTop, float $w, float $h, array $rgb): void
    {
        $r  = $h / 2;
        $cy = $this->y($fromTop + $h);
        $k  = $r * self::KAPPA;

        $this->content .= sprintf(
            "%.3f %.3f %.3f rg %.2f %.2f m %.2f %.2f l "
            . "%.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c "
            . "%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c f\n",
            $rgb[0], $rgb[1], $rgb[2],
            $x + $r, $cy,
            $x + $w - $r, $cy,
            $x + $w - $r + $k, $cy, $x + $w, $cy + $r - $k, $x + $w, $cy + $r,
            $x + $w, $cy + $r + $k, $x + $w - $r + $k, $cy + $h, $x + $w - $r, $cy + $h,
            $x + $r, $cy + $h,
            $x + $r - $k, $cy + $h, $x, $cy + $r + $k, $x, $cy + $r,
            $x, $cy + $r - $k, $x + $r - $k, $cy, $x + $r, $cy
        );
    }

    /**
     * The mark: a ring of dots that grows and shrinks around the circle,
     * green on the left and blue on the right, with H2 set inside it.
     * `$size` is the diameter of the whole mark.
     */
    public function logo(float $cx, float $fromTop, float $size, float $wash = 0.0, bool $withMark = true): void
    {
        $green = $this->wash([0.29, 0.71, 0.33], $wash);
        $blue  = $this->wash([0.09, 0.41, 0.70], $wash);
        $ink   = [0.09, 0.41, 0.70];

        /* three loose bands of dots, each offset so the ring reads as organic
           rather than as a dotted circle */
        $bands = [
            ['radius' => 0.430, 'count' => 34, 'dot' => 0.038, 'phase' => 0.00],
            ['radius' => 0.345, 'count' => 24, 'dot' => 0.026, 'phase' => 0.55],
            ['radius' => 0.500, 'count' => 22, 'dot' => 0.022, 'phase' => 1.70],
        ];

        foreach ($bands as $band) {
            for ($i = 0; $i < $band['count']; $i++) {
                $angle = 2 * M_PI * $i / $band['count'] + $band['phase'];

                /* dots swell and shrink around the ring, which is what stops
                   it reading as a dotted circle */
                $swell = 0.58 + 0.42 * abs(cos($angle * 1.5 + $band['phase']));
                $r     = $size * $band['dot'] * $swell;

                /* left of the mark is green, right is blue, blended by x */
                $mix = (cos($angle) + 1) / 2;
                $rgb = [
                    $green[0] + ($blue[0] - $green[0]) * $mix,
                    $green[1] + ($blue[1] - $green[1]) * $mix,
                    $green[2] + ($blue[2] - $green[2]) * $mix,
                ];

                $this->circle(
                    $cx + cos($angle) * $size * $band['radius'],
                    $fromTop - sin($angle) * $size * $band['radius'],
                    $r,
                    $rgb
                );
            }
        }

        if (!$withMark) {
            return;
        }

        /* H2 in the middle, the 2 sitting low like a subscript */
        $h = $size * 0.38;
        $this->text($cx - $this->widthOf('H', $h, true) / 2 - $size * 0.045, $fromTop + $h * 0.36, 'H', $h, true, $ink);
        $this->text($cx + $size * 0.13, $fromTop + $h * 0.52, '2', $h * 0.62, true, $ink);
    }

    /** Mixes a colour towards white. 0 leaves it alone, 1 makes it white. */
    private function wash(array $rgb, float $amount): array
    {
        return [
            $rgb[0] + (1 - $rgb[0]) * $amount,
            $rgb[1] + (1 - $rgb[1]) * $amount,
            $rgb[2] + (1 - $rgb[2]) * $amount,
        ];
    }

    /** Width of a string when set, in points. */
    public function widthOf(string $text, float $size, bool $bold = false, float $tracking = 0.0): float
    {
        $table = $bold ? self::W_BOLD : self::W_REGULAR;
        $text  = $this->escape($text);
        $width = 0.0;

        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $code = ord($text[$i]);

            if ($text[$i] === '\\' && $i + 1 < $n) {
                continue;   /* the escape itself takes no space */
            }

            $width += ($table[$code - 32] ?? 556) / 1000 * $size + $tracking;
        }

        return $width;
    }

    private function escape(string $text): string
    {
        /* PDF base-14 fonts are single byte, so fold the few symbols we use */
        $text = str_replace(
            ['₹', '—', '–', '·', '’', '“', '”'],
            ['Rs. ', '-', '-', '-', "'", '"', '"'],
            $text
        );
        $text = @iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /** Assembles the objects into a finished document. */
    public function output(): string
    {
        $streams = array_merge($this->pages, [$this->content]);

        /* 1 catalog, 2 the page tree, 3 and 4 the two faces, then a page object
           and a content stream for each page after them. */
        $first = 5;
        $kids  = [];

        foreach (array_keys($streams) as $i) {
            $kids[] = ($first + $i * 2) . ' 0 R';
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($streams) . ' >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];

        foreach ($streams as $i => $stream) {
            $objects[] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->width,
                $this->height,
                $first + $i * 2 + 1
            );
            $objects[] = '<< /Length ' . strlen($stream) . " >>
stream
" . $stream . 'endstream';
        }

        $pdf     = "%PDF-1.4
";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj
" . $object . "
endobj
";
        }

        $xrefAt = strlen($pdf);
        $pdf .= "xref
0 " . (count($objects) + 1) . "
0000000000 65535 f 
";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n 
", $offset);
        }

        $pdf .= "trailer
<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>
startxref
" . $xrefAt . "
%%EOF";

        return $pdf;
    }
}

/**
 * A receipt for one verified payment.
 *
 * It states one transfer and nothing else: what was received, against which
 * application, and who paid it. Balances and totals belong in the portal,
 * where they stay current; a receipt is a record of a single moment.
 *
 * @return string raw PDF bytes
 */
function build_receipt_pdf(array $app, array $payment, array $totals = []): string
{
    $pdf   = new SimplePdf();

    $ink   = [0.06, 0.17, 0.30];
    $body  = [0.36, 0.44, 0.53];
    $muted = [0.52, 0.60, 0.67];
    $teal  = [0.05, 0.56, 0.59];
    $line  = [0.89, 0.92, 0.95];

    $left  = 56.0;
    $right = $pdf->width() - 56.0;
    $mid   = $left + ($right - $left) / 2 + 10;

    $stage     = (string) ($payment['stage'] ?? 'booking');
    $stageName = payment_stage_label($stage);
    $verified  = $payment['decided_at'] ?? date('Y-m-d H:i:s');

    /* ---------- masthead ---------- */
    $pdf->logo($left + 21, 60, 46);

    $pdf->text($left + 56, 52, 'Manifold Clean Energy', 15, true, $ink);
    $pdf->text($left + 56, 68, 'Hydrogen on demand. Made in India.', 8.5, false, $muted);

    $pdf->label(0, 46, 'Payment receipt', $muted, true, $right);
    $pdf->textRight($right, 68, (string) $payment['receipt_no'], 13, true, $teal);

    /* the brand rule: green stepping to teal across the page */
    $steps = 46;
    $span  = ($right - $left) / $steps;

    for ($i = 0; $i < $steps; $i++) {
        $t = $i / ($steps - 1);
        $pdf->rect(
            $left + $i * $span,
            96,
            $span + 0.4,
            2.6,
            [0.29 + (0.09 - 0.29) * $t, 0.71 + (0.69 - 0.71) * $t, 0.33 + (0.65 - 0.33) * $t]
        );
    }

    /* the mark again, washed almost to nothing, so the lower half of the sheet
       is not simply blank — it anchors the page without saying anything */
    $pdf->logo($right - 118, 582, 220, 0.955, false);

    /* ---------- the amount, which is the whole point of the page ---------- */
    $pdf->rect($left - 18, 122, ($right - $left) + 36, 356, [0.976, 0.984, 0.992]);
    $pdf->rect($left - 18, 122, 3, 356, [0.05, 0.56, 0.59]);

    $pdf->label($left, 146, 'Amount received', $muted);
    $pdf->text($left, 190, money((float) $payment['amount']), 34, true, $ink);

    /* which of the two transfers this was — a receipt has to say so */
    $pillText  = strtoupper($stageName);
    $pillWidth = $pdf->widthOf($pillText, 7.5, true, 1.1) + 26;

    $pdf->pill($right - $pillWidth, 150, $pillWidth, 21, [0.93, 0.97, 0.97]);
    $pdf->label($right - $pillWidth + 13, 164, $stageName, $teal);

    $pdf->line($left, 214, $right, $line);

    /* ---------- the record ---------- */
    $rows = [
        ['Receipt number', (string) $payment['receipt_no'],
         'Booking number', (string) $app['reference_code']],

        ['Product',        $app['product'] === 'stove'
                             ? 'Kinetic Hydrogen Cooking Stove'
                             : 'Hydrogen Conversion Kit for TukTuk',
         'Payment reference', (string) ($payment['reference'] ?: 'Not supplied')],

        ['Received on',    format_datetime($payment['uploaded_at']),
         'Verified on',    format_datetime($verified)],
    ];

    $y = 250.0;

    foreach ($rows as [$labelA, $valueA, $labelB, $valueB]) {
        $pdf->label($left, $y, $labelA, $muted);
        $pdf->text($left, $y + 19, $valueA, 11.5, true, $ink);

        $pdf->label($mid, $y, $labelB, $muted);
        $pdf->text($mid, $y + 19, $valueB, 11.5, true, $ink);

        $y += 52;
    }

    /* ---------- who paid ---------- */
    $pdf->line($left, $y - 12, $right, $line);

    $pdf->label($left, $y + 18, 'Received from', $muted);
    $pdf->text($left, $y + 40, (string) $app['full_name'], 13, true, $ink);
    $pdf->text($left, $y + 59, (string) $app['email'], 10, false, $body);

    if (!empty($app['mobile_number'])) {
        $pdf->label($mid, $y + 18, 'Mobile', $muted);
        $pdf->text($mid, $y + 40, (string) $app['mobile_number'], 13, true, $ink);
    }

    $pdf->text($left, $y + 92, 'This receipt confirms one verified payment against the application above.', 9, false, $muted);

    /* ---------- footer ---------- */
    $foot = 742.0;
    $pdf->line($left, $foot - 18, $right, $line);
    $pdf->text($left, $foot, 'Manifold Clean Energy Pvt. Ltd.', 9, true, $body);
    $pdf->text($left, $foot + 14, '711, SAFAL Prelude, Corporate Road, Prahlad Nagar, Ahmedabad 380015, Gujarat, India', 8, false, $muted);
    $pdf->text($left, $foot + 27, '+91 97251 54186   ·   info@manifoldcleanenergy.co.in', 8, false, $muted);

    return $pdf->output();
}

/** File name a receipt should download as. */
function receipt_filename(array $payment): string
{
    return 'receipt-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) $payment['receipt_no']) . '.pdf';
}
