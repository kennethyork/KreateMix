<?php
declare(strict_types=1);

namespace App;

/**
 * Small PDF writer: A4 pages, Helvetica / Helvetica-Bold, filled and stroked shapes, text.
 * Coordinates are in points from the top-left corner. Text is converted to Windows-1252.
 */
final class Pdf
{
    public const W = 595.28;
    public const H = 841.89;

    private array $pages = [];
    private string $buf = '';
    private float $size = 10;
    private bool $bold = false;

    private const HELV = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584,
        ',' => 278, '-' => 333, '.' => 278, '/' => 278, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556, '@' => 1015,
        'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556,
        'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667,
        'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556, '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556,
        'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556,
        'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500, '{' => 334, '|' => 260,
        '}' => 334, '~' => 584,
    ];
    private const HELV_B = [
        ' ' => 278, '!' => 333, '"' => 474, '#' => 556, '$' => 556, '%' => 889, '&' => 722, "'" => 238, '(' => 333, ')' => 333, '*' => 389, '+' => 584,
        ',' => 278, '-' => 333, '.' => 278, '/' => 278, ':' => 333, ';' => 333, '<' => 584, '=' => 584, '>' => 584, '?' => 611, '@' => 975,
        'A' => 722, 'B' => 722, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278, 'J' => 556, 'K' => 722, 'L' => 611,
        'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667,
        'Y' => 667, 'Z' => 611, '[' => 333, '\\' => 278, ']' => 333, '^' => 584, '_' => 556, '`' => 333, 'a' => 556, 'b' => 611, 'c' => 556, 'd' => 611,
        'e' => 556, 'f' => 333, 'g' => 611, 'h' => 611, 'i' => 278, 'j' => 278, 'k' => 556, 'l' => 278, 'm' => 889, 'n' => 611, 'o' => 611, 'p' => 611,
        'q' => 611, 'r' => 389, 's' => 556, 't' => 333, 'u' => 611, 'v' => 556, 'w' => 778, 'x' => 556, 'y' => 556, 'z' => 500, '{' => 389, '|' => 280,
        '}' => 389, '~' => 584,
    ];

    public function addPage(): void
    {
        if ($this->buf !== '') {
            $this->pages[] = $this->buf;
        }
        $this->buf = ' ';
    }

    public function font(float $size, bool $bold = false): void
    {
        $this->size = $size;
        $this->bold = $bold;
    }

    public function fill(string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $this->buf .= sprintf("%.3F %.3F %.3F rg\n", $r, $g, $b);
    }

    public function stroke(string $hex, float $width = 1): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $this->buf .= sprintf("%.3F %.3F %.3F RG %.2F w\n", $r, $g, $b, $width);
    }

    public function rect(float $x, float $y, float $w, float $h, bool $fill = true, bool $stroke = false, float $radius = 0): void
    {
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        if ($radius <= 0) {
            $this->buf .= sprintf("%.2F %.2F %.2F %.2F re %s\n", $x, self::H - $y - $h, $w, $h, $op);
            return;
        }
        $r = min($radius, $w / 2, $h / 2);
        $k = 0.5523 * $r;
        $x1 = $x; $y1 = self::H - $y; $x2 = $x + $w; $y2 = self::H - $y - $h;
        $this->buf .= sprintf(
            "%.2F %.2F m %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c h %s\n",
            $x1 + $r, $y1, $x2 - $r, $y1, $x2 - $r + $k, $y1, $x2, $y1 - $r + $k, $x2, $y1 - $r,
            $x2, $y2 + $r, $x2, $y2 + $r - $k, $x2 - $r + $k, $y2, $x2 - $r, $y2,
            $x1 + $r, $y2, $x1 + $r - $k, $y2, $x1, $y2 + $r - $k, $x1, $y2 + $r,
            $x1, $y1 - $r, $x1, $y1 - $r + $k, $x1 + $r - $k, $y1, $x1 + $r, $y1, $op
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->buf .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $x1, self::H - $y1, $x2, self::H - $y2);
    }

    /** @param array<array{0: float, 1: float}> $points */
    public function polyline(array $points, bool $closeFill = false): void
    {
        if (count($points) < 2) {
            return;
        }
        $s = '';
        foreach ($points as $i => [$x, $y]) {
            $s .= sprintf('%.2F %.2F %s ', $x, self::H - $y, $i === 0 ? 'm' : 'l');
        }
        $this->buf .= $s . ($closeFill ? "h f\n" : "S\n");
    }

    /** Text at (x, baseline y). align: L, R, C. */
    public function text(float $x, float $y, string $text, string $align = 'L'): void
    {
        $enc = self::encode($text);
        $w = $this->width($text);
        if ($align === 'R') {
            $x -= $w;
        } elseif ($align === 'C') {
            $x -= $w / 2;
        }
        $this->buf .= sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $this->bold ? 'F2' : 'F1', $this->size, $x, self::H - $y, self::escape($enc));
    }

    /** Text cut with an ellipsis to fit $maxWidth. */
    public function textFit(float $x, float $y, string $text, float $maxWidth, string $align = 'L'): void
    {
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if ($this->width($text) > $maxWidth) {
            while ($text !== '' && $this->width($text . '...') > $maxWidth) {
                $text = mb_substr($text, 0, -1);
            }
            $text = rtrim($text) . '...';
        }
        $this->text($x, $y, $text, $align);
    }

    /** Wraps text into lines that fit $maxWidth. */
    public function wrap(string $text, float $maxWidth): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $para) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($para)) as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($line !== '' && $this->width($try) > $maxWidth) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    public function width(string $text): float
    {
        $table = $this->bold ? self::HELV_B : self::HELV;
        $w = 0;
        foreach (mb_str_split(self::plain($text)) as $ch) {
            $w += $table[$ch] ?? (ctype_digit($ch) ? 556 : 556);
        }
        return $w * $this->size / 1000;
    }

    public function output(): string
    {
        if ($this->buf !== '') {
            $this->pages[] = $this->buf;
            $this->buf = '';
        }
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->pages as $content) {
            $pageId = $n++;
            $contentId = $n++;
            $kids[] = "$pageId 0 R";
            $objs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $contentId);
            $z = gzcompress($content);
            $objs[$contentId] = "<< /Length " . strlen($z) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info << /Producer (MixCreate) >> >>\nstartxref\n$xref\n%%EOF";
        return $out;
    }

    private static function plain(string $s): string
    {
        // Common typographic characters to ASCII-ish so widths are close.
        return strtr($s, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '…' => '...', '·' => '.', '•' => '.']);
    }

    private static function encode(string $s): string
    {
        $s = strtr($s, ['…' => '...']);
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
    }

    private static function escape(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $s);
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }
}
