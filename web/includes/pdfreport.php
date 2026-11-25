<?php
/**
 * PdfReport - minimal pure-PHP PDF writer for server-side reports.
 *
 * Produces a multi-page A4 PDF (595x842 pt) with Helvetica text, suitable for
 * certificate compilations and audit listings. No external libraries, which
 * keeps deployment trivial on shared hosting (Gigahost).
 */
declare(strict_types=1);

final class PdfReport
{
    private const PAGE_W = 595.28;
    private const PAGE_H = 842.0;
    private const MARGIN = 50.0;

    /** @var array<int,string> */
    private array $pages = [];
    /** @var list<string> */
    private array $ops = [];
    private float $y = 0.0;

    public function __construct()
    {
        $this->startPage();
    }

    private function startPage(): void
    {
        if ($this->pages !== []) {
            $this->pages[array_key_last($this->pages)] = implode("\n", $this->ops);
            $this->ops = [];
        }
        $this->pages[] = '';
        $this->y = self::PAGE_H - self::MARGIN;
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->y - $needed < self::MARGIN) {
            $this->startPage();
        }
    }

    public function header(string $title, string $subtitle = ''): void
    {
        $this->ops[] = sprintf(
            '%.2f %.2f %.2f %.2f re f %.3f %.3f %.3f rg',
            self::MARGIN,
            self::PAGE_H - 76,
            self::PAGE_W - 2 * self::MARGIN,
            30,
            0.07, 0.18, 0.35
        );
        $this->setFillColor(1, 1, 1);
        $this->text($title, self::MARGIN + 10, self::PAGE_H - 56, true, 15);
        if ($subtitle !== '') {
            $this->text($subtitle, self::MARGIN + 10, self::PAGE_H - 72, false, 9);
        }
        $this->y = self::PAGE_H - 90;
        $this->setFillColor(0, 0, 0);
    }

    public function section(string $label): void
    {
        $this->ensureSpace(26);
        $this->ops[] = sprintf(
            '%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f %.1f %.1f %.1f rg',
            0.93, 0.95, 0.98,
            self::MARGIN, self::PAGE_H - $this->y + 16, self::PAGE_W - 2 * self::MARGIN, 16,
            0.1, 0.1, 0.1
        );
        $this->ops[] = sprintf(
            'BT /F2 %.2f Tf %.2f %.2f Td (%s) Tj ET',
            10.5, self::MARGIN + 8, self::PAGE_H - $this->y + 4, $this->esc($label)
        );
        $this->y -= 22;
        $this->setFillColor(0, 0, 0);
    }

    public function field(string $label, string $value): void
    {
        $this->ensureSpace(16);
        $this->setFillColor(0.4, 0.4, 0.4);
        $this->text($label, self::MARGIN, $this->y - 3, false, 9);
        $this->setFillColor(0, 0, 0);
        $this->text($value, self::MARGIN + 170, $this->y - 3, false, 9.5);
        $this->y -= 15;
    }

    public function paragraph(string $text, float $size = 9.5): void
    {
        foreach ($this->wrap($text, (int)round($size)) as $line) {
            $this->ensureSpace($size + 4);
            $this->text($line, self::MARGIN, $this->y - 3, false, $size);
            $this->y -= $size + 4;
        }
    }

    public function tableRow(array $widths, array $cells, bool $head = false): void
    {
        $total = self::PAGE_W - 2 * self::MARGIN;
        $h = $head ? 18.0 : 15.0;
        $this->ensureSpace($h + 2);
        $top = self::PAGE_H - $this->y + $h;
        if ($head) {
            $this->ops[] = sprintf(
                '%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f %.1f %.1f %.1f rg',
                0.85, 0.88, 0.92,
                self::MARGIN, $top, $total, $h,
                0, 0, 0
            );
        }
        $x = self::MARGIN;
        foreach ($widths as $i => $w) {
            $cell = (string)($cells[$i] ?? '');
            if (mb_strlen($cell) > (int)($w / 4.6)) {
                $cell = mb_substr($cell, 0, max(1, (int)($w / 4.6) - 1)) . '…';
            }
            $font = $head ? true : false;
            $this->ops[] = sprintf(
                'BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET',
                $head ? 'F2' : 'F1',
                $head ? 8.5 : 8.5,
                $x + 3,
                $top + 4,
                $this->esc($cell)
            );
            $x += $w;
        }
        $this->ops[] = sprintf(
            '%.3f %.3f %.3f RG %.2f %.2f m %.2f %.2f l S',
            0.8, 0.8, 0.8, self::MARGIN, $top, self::MARGIN + $total, $top
        );
        $this->y -= $h;
    }

    public function spacer(float $points = 8.0): void
    {
        $this->y -= $points;
    }

    public function pageBreak(): void
    {
        $this->startPage();
    }

    private function wrap(string $text, int $size): array
    {
        $perLine = max(8, (int)floor((self::PAGE_W - 2 * self::MARGIN) / (0.5 * $size)));
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            if ($cur === '') {
                $cur = $w;
            } elseif (mb_strlen($cur) + 1 + mb_strlen($w) <= $perLine) {
                $cur .= ' ' . $w;
            } else {
                $lines[] = $cur;
                $cur = $w;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $lines === [] ? [''] : $lines;
    }

    private function esc(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private function setFillColor(float $r, float $g, float $b): void
    {
        $this->ops[] = sprintf('%.3f %.3f %.3f rg', $r, $g, $b);
    }

    private function rect(float $x, float $y, float $w, float $h): void
    {
        $this->ops[] = sprintf('%.2f %.2f %.2f %.2f re f', $x, self::PAGE_H - $y - $h, $w, $h);
    }

    private function text(string $text, float $x, float $yTop, bool $bold, float $size): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->ops[] = sprintf(
            'BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET',
            $font, $size, $x, self::PAGE_H - $yTop, $this->esc($text)
        );
    }

    /** Assemble and return the full PDF byte stream. */
    public function output(): string
    {
        $this->pages[array_key_last($this->pages)] = implode("\n", $this->ops);
        $this->ops = [];

        $catalog = 1;
        $pagesRoot = 2;
        $font1 = 3;
        $font2 = 4;
        $objects = [];
        $pageObjs = [];

        foreach ($this->pages as $i => $content) {
            $contentObj = 5 + $i * 2;
            $pageObj = $contentObj + 1;
            $pageObjs[] = $pageObj;
            $objects[$contentObj] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $objects[$pageObj] = sprintf(
                "<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>",
                $pagesRoot, self::PAGE_W, self::PAGE_H, $font1, $font2, $contentObj
            );
        }
        $objects[$catalog] = "<< /Type /Catalog /Pages $pagesRoot 0 R >>";
        $kids = implode(' ', array_map(fn($p) => "$p 0 R", $pageObjs));
        $objects[$pagesRoot] = "<< /Type /Pages /Kids [ $kids ] /Count " . count($pageObjs) . " >>";
        $objects[$font1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[$font2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        $max = 4 + count($this->pages) * 2;
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        for ($num = 1; $num <= $max; $num++) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "$num 0 obj\n{$objects[$num]}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root $catalog 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
