<?php
declare(strict_types=1);

namespace App;

/** Renders an analytics report (from Analytics::build) as a PDF. */
final class Report
{
    private const M = 40;            // page margin
    private const ACCENT = '#4f46e5';
    private const TEXT = '#15181e';
    private const MUTED = '#6b7280';
    private const LINE = '#e5e7eb';

    private Pdf $pdf;
    private float $y = 0;

    public static function pdf(array $a, string $workspace, string $scope, string $tz): string
    {
        $r = new self();
        return $r->render($a, $workspace, $scope, $tz);
    }

    private function render(array $a, string $workspace, string $scope, string $tz): string
    {
        $p = $this->pdf = new Pdf();
        $p->addPage();
        $w = Pdf::W - 2 * self::M;

        // Header band
        $p->fill(self::ACCENT);
        $p->rect(0, 0, Pdf::W, 92);
        $p->fill('#ffffff');
        $p->font(20, true);
        $p->text(self::M, 44, $workspace);
        $p->font(11);
        $p->text(self::M, 66, 'Social media report · ' . $scope);
        $p->text(Pdf::W - self::M, 44, self::range($a['from'], $a['to']), 'R');
        $p->font(9);
        $p->text(Pdf::W - self::M, 66, 'Generated ' . (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('M j, Y'), 'R');
        $this->y = 116;

        // Stats
        $fmt = fn($n) => $n === null ? '-' : ($n >= 10000 ? number_format($n / 1000, 1) . 'k' : number_format((int) $n));
        $change = $a['followersChange'] === null ? '' : (($a['followersChange'] >= 0 ? '+' : '') . number_format($a['followersChange']));
        $stats = [
            ['Followers', $fmt($a['followersNow']), $change],
            ['Posts', (string) $a['totals']['posts'], ''],
            ['Engagement', $fmt($a['totals']['engagement']), ''],
            ['Impressions', $a['totals']['impressions'] ? $fmt($a['totals']['impressions']) : '-', ''],
            ['Engagement rate', $a['rate'] === null ? '-' : $a['rate'] . '%', ''],
        ];
        $bw = ($w - 4 * 10) / 5;
        foreach ($stats as $i => [$label, $value, $sub]) {
            $x = self::M + $i * ($bw + 10);
            $p->fill('#f4f5f8');
            $p->rect($x, $this->y, $bw, 62, true, false, 6);
            $p->fill(self::MUTED);
            $p->font(8.5);
            $p->text($x + 10, $this->y + 18, $label);
            $p->fill(self::TEXT);
            $p->font(17, true);
            $p->textFit($x + 10, $this->y + 42, $value, $bw - 20);
            if ($sub !== '') {
                $p->fill($a['followersChange'] >= 0 ? '#15803d' : '#dc2626');
                $p->font(8.5, true);
                $p->text($x + 10, $this->y + 55, $sub);
            }
        }
        $this->y += 84;

        // Charts
        $cw = ($w - 16) / 2;
        $this->chart(self::M, $this->y, $cw, 150, 'Followers', $a['followers'], 'line');
        $this->chart(self::M + $cw + 16, $this->y, $cw, 150, 'Engagement per day', $a['engagementSeries'], 'bar');
        $this->y += 172;

        // Networks
        if ($a['perNetwork']) {
            $this->heading('By network');
            $rows = array_map(fn($n) => [
                Analytics::providerName($n['provider']),
                $fmt($n['followers']),
                $n['followers_change'] === null ? '-' : (($n['followers_change'] >= 0 ? '+' : '') . number_format($n['followers_change'])),
                (string) $n['posts'],
                $fmt($n['engagement']),
                $n['rate'] === null ? '-' : $n['rate'] . '%',
            ], $a['perNetwork']);
            $this->table(['Network', 'Followers', 'Change', 'Posts', 'Engagement', 'Eng. rate'], $rows, [0.34, 0.14, 0.12, 0.1, 0.15, 0.15]);
        }

        // Top posts
        if ($a['top']) {
            $this->heading('Top posts');
            $rows = array_map(fn($t) => [
                $t['content'] !== '' ? $t['content'] : '(media)',
                Analytics::providerName($t['provider']),
                local_time($t['published_at'], 'M j', $tz),
                number_format((int) ($t['m']['likes'] ?? $t['m']['reactions'] ?? 0)),
                number_format((int) ($t['m']['replies'] ?? $t['m']['comments'] ?? 0)),
                number_format($t['engagement']),
            ], $a['top']);
            $this->table(['Post', 'Network', 'Date', 'Likes', 'Comments', 'Total'], $rows, [0.46, 0.16, 0.09, 0.09, 0.11, 0.09]);
        }

        // Formats + best times
        $this->heading('What worked');
        $labels = ['text' => 'Text only', 'image' => 'Single image', 'carousel' => 'Multiple images', 'video' => 'Video'];
        $rows = [];
        foreach ($a['types'] as $k => $t) {
            $rows[] = [$labels[$k], (string) $t['posts'], (string) $t['avg']];
        }
        $this->table(['Format', 'Posts', 'Avg engagement'], $rows, [0.5, 0.25, 0.25]);
        if ($a['best']['top']) {
            $this->need(60);
            $p->fill(self::TEXT);
            $p->font(10, true);
            $p->text(self::M, $this->y + 4, 'Best times to post');
            $p->font(10);
            $slots = array_map(fn($c) => Analytics::dayName($c['day']) . ' ' . Analytics::hourLabel($c['hour']), $a['best']['top']);
            $p->fill(self::MUTED);
            $p->text(self::M, $this->y + 20, implode('   ·   ', $slots));
            $this->y += 34;
        }
        if ($a['hashtags']) {
            $this->need(40);
            $p->fill(self::TEXT);
            $p->font(10, true);
            $p->text(self::M, $this->y + 4, 'Top hashtags');
            $p->font(10);
            $p->fill(self::MUTED);
            $tags = array_map(fn($h) => '#' . $h['tag'] . ' (' . $h['avg'] . ')', array_slice($a['hashtags'], 0, 8));
            foreach ($p->wrap(implode('   ', $tags), $w) as $i => $line) {
                $p->text(self::M, $this->y + 20 + $i * 14, $line);
            }
            $this->y += 40;
        }

        $this->footer();
        return $p->output();
    }

    private function heading(string $text): void
    {
        $this->need(60);
        $this->pdf->fill(self::TEXT);
        $this->pdf->font(12, true);
        $this->pdf->text(self::M, $this->y + 6, $text);
        $this->y += 18;
    }

    private function table(array $head, array $rows, array $cols): void
    {
        $p = $this->pdf;
        $w = Pdf::W - 2 * self::M;
        $rowH = 20;
        $drawHead = function () use ($p, $head, $cols, $w) {
            $p->fill('#f4f5f8');
            $p->rect(self::M, $this->y, $w, $rowH = 20);
            $p->fill(self::MUTED);
            $p->font(8.5, true);
            $x = self::M;
            foreach ($head as $i => $h) {
                $cw = $cols[$i] * $w;
                $i === 0 ? $p->text($x + 8, $this->y + 13.5, $h) : $p->text($x + $cw - 8, $this->y + 13.5, $h, 'R');
                $x += $cw;
            }
            $this->y += 20;
        };
        $drawHead();
        foreach ($rows as $row) {
            if ($this->y + $rowH > Pdf::H - 50) {
                $this->footer();
                $p->addPage();
                $this->y = self::M;
                $drawHead();
            }
            $x = self::M;
            $p->font(9);
            foreach ($row as $i => $cell) {
                $cw = $cols[$i] * $w;
                $p->fill($i === 0 ? self::TEXT : '#374151');
                $i === 0 ? $p->textFit($x + 8, $this->y + 13.5, (string) $cell, $cw - 16) : $p->textFit($x + $cw - 8, $this->y + 13.5, (string) $cell, $cw - 12, 'R');
                $x += $cw;
            }
            $p->stroke(self::LINE, 0.6);
            $p->line(self::M, $this->y + $rowH, self::M + $w, $this->y + $rowH);
            $this->y += $rowH;
        }
        $this->y += 18;
    }

    private function chart(float $x, float $y, float $w, float $h, string $title, array $series, string $type): void
    {
        $p = $this->pdf;
        $p->stroke(self::LINE, 0.8);
        $p->fill('#ffffff');
        $p->rect($x, $y, $w, $h, true, true, 6);
        $p->fill(self::TEXT);
        $p->font(10, true);
        $p->text($x + 12, $y + 18, $title);

        $vals = array_values(array_filter(array_column($series, 'value'), fn($v) => $v !== null));
        $px = $x + 36;
        $py = $y + 30;
        $pw = $w - 48;
        $ph = $h - 52;
        if (!$vals || ($type === 'line' && count($vals) < 2) || ($type === 'bar' && max($vals) == 0)) {
            $p->fill(self::MUTED);
            $p->font(9);
            $p->text($x + $w / 2, $y + $h / 2 + 8, 'No data yet', 'C');
            return;
        }
        $min = $type === 'bar' ? 0 : min($vals);
        $max = max($vals);
        if ($max == $min) {
            $max = $min + 1;
        }
        $n = count($series);
        $sx = fn($i) => $px + ($n === 1 ? $pw / 2 : $i * $pw / ($n - 1));
        $sy = fn($v) => $py + $ph - ($v - $min) / ($max - $min) * $ph;

        $p->font(7);
        for ($t = 0; $t <= 2; $t++) {
            $v = $min + ($max - $min) * $t / 2;
            $p->stroke('#eef0f3', 0.6);
            $p->line($px, $sy($v), $px + $pw, $sy($v));
            $p->fill(self::MUTED);
            $p->text($px - 5, $sy($v) + 2.5, $v >= 10000 ? round($v / 1000, 1) . 'k' : (string) round($v), 'R');
        }
        $p->text($px, $y + $h - 8, date('M j', strtotime($series[0]['date'])));
        $p->text($px + $pw, $y + $h - 8, date('M j', strtotime($series[$n - 1]['date'])), 'R');

        if ($type === 'bar') {
            $bw = max(1.2, $pw / $n * 0.65);
            $p->fill(self::ACCENT);
            foreach ($series as $i => $s) {
                $v = (float) ($s['value'] ?? 0);
                if ($v > 0) {
                    $top = $sy($v);
                    $bx = $n === 1 ? $px + $pw / 2 - $bw / 2 : $px + $i * ($pw - $bw) / max(1, $n - 1);
                    $p->rect($bx, $top, $bw, $py + $ph - $top);
                }
            }
        } else {
            $pts = [];
            foreach ($series as $i => $s) {
                if ($s['value'] !== null) {
                    $pts[] = [$sx($i), $sy($s['value'])];
                }
            }
            $area = $pts;
            $area[] = [end($pts)[0], $py + $ph];
            $area[] = [$pts[0][0], $py + $ph];
            $p->fill('#e8e7fc');
            $p->polyline($area, true);
            $p->stroke(self::ACCENT, 1.6);
            $p->polyline($pts);
        }
    }

    private function need(float $space): void
    {
        if ($this->y + $space > Pdf::H - 50) {
            $this->footer();
            $this->pdf->addPage();
            $this->y = self::M;
        }
    }

    private int $page = 0;

    private function footer(): void
    {
        $this->page++;
        $this->pdf->fill(self::MUTED);
        $this->pdf->font(8);
        $this->pdf->text(self::M, Pdf::H - 24, Config::get('app_name', 'MixCreate'));
        $this->pdf->text(Pdf::W - self::M, Pdf::H - 24, 'Page ' . $this->page, 'R');
    }

    private static function range(string $from, string $to): string
    {
        $f = strtotime($from);
        $t = strtotime($to);
        return date('Y', $f) === date('Y', $t) ? date('M j', $f) . ' – ' . date('M j, Y', $t) : date('M j, Y', $f) . ' – ' . date('M j, Y', $t);
    }
}
