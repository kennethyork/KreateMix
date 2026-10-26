<?php
declare(strict_types=1);

namespace App;

/** Renders simple line/bar charts to PNG with GD (for PowerPoint exports). */
final class ChartImage
{
    /** @param array<array{date: string, value: ?float}> $series */
    public static function png(array $series, string $type, int $w, int $h, string $color, int $scale = 2): string
    {
        $W = $w * $scale;
        $H = $h * $scale;
        $im = imagecreatetruecolor($W, $H);
        imageantialias($im, true);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefill($im, 0, 0, $white);
        [$r, $g, $b] = sscanf(ltrim($color, '#'), '%02x%02x%02x');
        $c = imagecolorallocate($im, $r, $g, $b);
        $cSoft = imagecolorallocatealpha($im, $r, $g, $b, 105);
        $grid = imagecolorallocate($im, 232, 234, 238);
        $muted = imagecolorallocate($im, 120, 128, 140);

        $vals = array_values(array_filter(array_column($series, 'value'), fn($v) => $v !== null));
        $font = 3;
        $padL = 46 * $scale;
        $padB = 22 * $scale;
        $padT = 10 * $scale;
        $padR = 10 * $scale;
        if (!$vals || ($type === 'line' && count($vals) < 2) || ($type === 'bar' && max($vals) == 0)) {
            imagestring($im, 5, (int) ($W / 2 - 40), (int) ($H / 2 - 8), 'No data yet', $muted);
            return self::out($im);
        }
        $min = $type === 'bar' ? 0 : min($vals);
        $max = max($vals);
        if ($max == $min) {
            $max = $min + 1;
        }
        $n = count($series);
        $px = fn($i) => $padL + ($n === 1 ? ($W - $padL - $padR) / 2 : $i * ($W - $padL - $padR) / ($n - 1));
        $py = fn($v) => $padT + ($H - $padT - $padB) * (1 - ($v - $min) / ($max - $min));

        imagesetthickness($im, max(1, $scale));
        for ($t = 0; $t <= 4; $t++) {
            $v = $min + ($max - $min) * $t / 4;
            $y = (int) $py($v);
            imageline($im, $padL, $y, $W - $padR, $y, $grid);
            $label = $v >= 10000 ? round($v / 1000, 1) . 'k' : (string) round($v, $max - $min < 5 ? 1 : 0);
            imagestring($im, $font, (int) ($padL - 8 - strlen($label) * 7), $y - 6, $label, $muted);
        }
        $every = max(1, (int) ceil($n / 6));
        foreach ($series as $i => $s) {
            if ($i % $every === 0 || $i === $n - 1) {
                $label = date('M j', strtotime($s['date']));
                imagestring($im, $font, (int) min($W - strlen($label) * 7 - 2, max(0, $px($i) - strlen($label) * 3.5)), $H - $padB + 6, $label, $muted);
            }
        }
        if ($type === 'bar') {
            $bw = max(2, (int) (($W - $padL - $padR) / $n * 0.6));
            foreach ($series as $i => $s) {
                $v = (float) ($s['value'] ?? 0);
                if ($v > 0) {
                    $x = (int) ($n === 1 ? $px($i) : $padL + $i * (($W - $padL - $padR - $bw) / max(1, $n - 1)));
                    imagefilledrectangle($im, $x, (int) $py($v), $x + $bw, (int) $py($min), $c);
                }
            }
        } else {
            $pts = [];
            foreach ($series as $i => $s) {
                if ($s['value'] !== null) {
                    $pts[] = [(int) $px($i), (int) $py((float) $s['value'])];
                }
            }
            $poly = [];
            foreach ($pts as [$x, $y]) {
                $poly[] = $x;
                $poly[] = $y;
            }
            array_push($poly, end($pts)[0], (int) $py($min), $pts[0][0], (int) $py($min));
            imagefilledpolygon($im, $poly, $cSoft);
            imagesetthickness($im, 3 * $scale);
            for ($i = 1; $i < count($pts); $i++) {
                imageline($im, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1], $c);
            }
        }
        return self::out($im);
    }

    private static function out(\GdImage $im): string
    {
        ob_start();
        imagepng($im);
        imagedestroy($im);
        return (string) ob_get_clean();
    }
}
