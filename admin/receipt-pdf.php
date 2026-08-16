<?php
/**
 * Receipt PDFs, written by hand.
 *
 * No Composer here, so this builds a minimal PDF 1.4 document directly: one
 * A4 page, the two base-14 Helvetica faces, lines and filled rectangles. That
 * is everything a receipt needs and keeps the project dependency-free.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

final class SimplePdf
{
    /** A4 in points. */
    private const WIDTH  = 595.28;
    private const HEIGHT = 841.89;

    private string $content = '';

    public function width(): float
    {
        return self::WIDTH;
    }

    /** y is measured from the top of the page, which is easier to reason about. */
    private function y(float $fromTop): float
    {
        return self::HEIGHT - $fromTop;
    }

    public function text(float $x, float $fromTop, string $text, float $size = 11, bool $bold = false, array $rgb = [0.36, 0.44, 0.53]): void
    {
        $this->content .= sprintf(
            "BT /%s %.2f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1',
            $size,
            $rgb[0], $rgb[1], $rgb[2],
            $x,
            $this->y($fromTop),
            $this->escape($text)
        );
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

    /** Right-aligned text, using the Helvetica width table below. */
    public function textRight(float $rightX, float $fromTop, string $text, float $size = 11, bool $bold = false, array $rgb = [0.36, 0.44, 0.53]): void
    {
        $this->text($rightX - $this->widthOf($text, $size), $fromTop, $text, $size, $bold, $rgb);
    }

    /** Good enough for layout: Helvetica averages about 0.52em per character. */
    public function widthOf(string $text, float $size): float
    {
        return strlen($text) * $size * 0.5;
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
        $objects = [
            "<< /Type /Catalog /Pages 2 0 R >>",
            "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>",
                self::WIDTH,
                self::HEIGHT
            ),
            "<< /Length " . strlen($this->content) . " >>\nstream\n" . $this->content . "endstream",
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>",
        ];

        $pdf     = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefAt = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefAt . "\n%%EOF";

        return $pdf;
    }
}

/**
 * A receipt for one verified payment.
 *
 * @return string raw PDF bytes
 */
function build_receipt_pdf(array $app, array $payment, array $totals): string
{
    $pdf   = new SimplePdf();
    $ink   = [0.06, 0.17, 0.30];
    $body  = [0.36, 0.44, 0.53];
    $muted = [0.52, 0.60, 0.67];
    $green = [0.29, 0.71, 0.33];

    $left  = 56.0;
    $right = $pdf->width() - 56.0;

    /* header band */
    $pdf->rect(0, 0, $pdf->width(), 96, [0.02, 0.10, 0.16]);
    $pdf->text($left, 44, 'MANIFOLD CLEAN ENERGY', 16, true, [1, 1, 1]);
    $pdf->text($left, 64, 'Hydrogen on demand. Made in India.', 10, false, [0.72, 0.80, 0.86]);
    $pdf->textRight($right, 44, 'PAYMENT RECEIPT', 13, true, [1, 1, 1]);
    $pdf->textRight($right, 64, (string) $payment['receipt_no'], 11, false, [0.55, 0.85, 0.80]);

    /* who and what */
    $top = 140.0;
    $pdf->text($left, $top, 'RECEIVED FROM', 8, true, $muted);
    $pdf->text($left, $top + 18, (string) $app['full_name'], 13, true, $ink);
    $pdf->text($left, $top + 36, (string) $app['email'], 10, false, $body);

    if (!empty($app['mobile_number'])) {
        $pdf->text($left, $top + 52, (string) $app['mobile_number'], 10, false, $body);
    }

    $pdf->textRight($right, $top, 'ISSUED', 8, true, $muted);
    $pdf->textRight($right, $top + 18, format_datetime($payment['decided_at'] ?? date('Y-m-d H:i:s')), 11, false, $ink);
    $pdf->textRight($right, $top + 36, 'Application ' . $app['reference_code'], 10, false, $body);

    /* the amount, in a tinted panel */
    $panel = $top + 84;
    $pdf->rect($left, $panel, $right - $left, 62, [0.96, 0.98, 0.99]);
    $pdf->text($left + 20, $panel + 24, 'AMOUNT RECEIVED', 8, true, $muted);
    $pdf->text($left + 20, $panel + 46, money((float) $payment['amount']), 22, true, $ink);

    $settled = $totals['balance'] <= 0.001;
    $pdf->textRight($right - 20, $panel + 24, $settled ? 'PAID IN FULL' : 'PART PAYMENT', 8, true, $muted);
    $pdf->textRight(
        $right - 20,
        $panel + 46,
        $settled ? 'Nil balance' : money((float) $totals['balance']) . ' outstanding',
        12,
        true,
        $settled ? $green : [0.79, 0.27, 0.36]
    );

    /* the detail table */
    $rows = [
        ['Receipt number',    (string) $payment['receipt_no']],
        ['Application',       (string) $app['reference_code']],
        ['Product',           $app['product'] === 'stove' ? 'Kinetic Hydrogen Cooking Stove' : 'Hydrogen Conversion Kit for TukTuk'],
        ['Payment reference', (string) ($payment['reference'] ?: 'Not supplied')],
        ['Received on',       format_datetime($payment['uploaded_at'])],
        ['Verified on',       format_datetime($payment['decided_at'] ?? date('Y-m-d H:i:s'))],
        [payment_stage_label((string) ($payment['stage'] ?? 'booking')),
                              money((float) stage_amount($app, (string) ($payment['stage'] ?? 'booking')))],
        ['Total price payable', money((float) $totals['due'])],
        ['Paid to date',      money((float) $totals['paid'])],
        ['Balance',           $settled ? 'Nil - paid in full' : money((float) $totals['balance'])],
    ];

    if (!empty($app['referred_by_code'])) {
        array_splice($rows, 2, 0, [
            ['Referred by', (string) $app['referred_by_code']],
        ]);
    }

    $y = $panel + 104;
    $pdf->text($left, $y, 'DETAILS', 8, true, $muted);
    $y += 16;

    foreach ($rows as [$label, $value]) {
        $pdf->line($left, $y - 12, $right);
        $pdf->text($left, $y + 6, $label, 10, false, $muted);
        $pdf->textRight($right, $y + 6, $value, 10, true, $ink);
        $y += 26;
    }

    $pdf->line($left, $y - 12, $right);

    /* a fully paid application earns the right to refer others, so the code
       and the link that carries it are printed on the final receipt */
    if ($settled && !empty($app['referral_code'])) {
        $code  = (string) $app['referral_code'];
        $share = $y + 26;

        $pdf->rect($left, $share, $right - $left, 74, [0.96, 0.98, 0.99]);
        $pdf->text($left + 20, $share + 24, 'YOUR REFERRAL CODE', 8, true, $muted);
        $pdf->text($left + 20, $share + 46, $code, 16, true, $green);
        $pdf->textRight($right - 20, $share + 24,
            'Earn ' . money(referral_reward()) . ' each time it is used', 9, false, $body);
        $pdf->textRight($right - 20, $share + 46, referral_link($code, (string) $app['product']), 8, false, $muted);
    }

    /* footer */
    $foot = 742.0;
    $pdf->line($left, $foot - 16, $right);
    $pdf->text($left, $foot, 'Manifold Clean Energy Pvt. Ltd.', 9, true, $body);
    $pdf->text($left, $foot + 14, '711, SAFAL Prelude, Corporate Road, Prahlad Nagar, Ahmedabad 380015, Gujarat, India', 8, false, $muted);
    $pdf->text($left, $foot + 27, '+91 97251 54186  ·  info@manifoldcleanenergy.com', 8, false, $muted);
    $pdf->textRight($right, $foot + 14, 'Computer generated receipt - no signature required.', 8, false, $muted);

    return $pdf->output();
}

/** File name a receipt should download as. */
function receipt_filename(array $payment): string
{
    return 'receipt-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) $payment['receipt_no']) . '.pdf';
}
