<?php
declare(strict_types=1);

namespace App;

/** Builds a branded PowerPoint deck from Analytics::build() data. */
final class ReportPptx
{
    private const TEXT = '#15181e';
    private const MUTED = '#6b7280';
    private const SOFT = '#f4f5f8';

    public static function build(array $a, array $brand, string $scope, string $tz): string
    {
        $accent = $brand['color'] ?? '#4f46e5';
        $on = Branding::onColor($accent);
        $range = date('M j', strtotime($a['from'])) . ' – ' . date('M j, Y', strtotime($a['to']));
        $p = new Pptx(($brand['name'] ?? 'Report') . ' social media report');
        $fmt = fn($n) => $n === null ? '—' : ($n >= 10000 ? number_format($n / 1000, 1) . 'k' : number_format((int) $n));
        $footer = function (int $s) use ($p, $brand, $range) {
            $left = $brand['footer'] ?? '';
            if ($left === '') {
                $left = ($brand['name'] ?? '') . (empty($brand['hide_powered_by']) ? ' · made with ' . Config::get('app_name', 'MixCreate') : '');
            }
            $p->text($s, 40, 506, 600, 22, $left, ['size' => 9, 'color' => self::MUTED]);
            $p->text($s, 640, 506, 280, 22, $range, ['size' => 9, 'color' => self::MUTED, 'align' => 'r']);
        };
        $heading = function (int $s, string $title) use ($p, $accent) {
            $p->rect($s, 40, 40, 6, 30, $accent);
            $p->text($s, 56, 34, 860, 44, $title, ['size' => 26, 'bold' => true, 'valign' => 'ctr']);
        };

        // 1. Cover
        $s = $p->addSlide($accent);
        $y = 170;
        if (!empty($brand['logo'])) {
            $logo = MediaTools::jpeg($brand['logo'], null, 600);
            $info = @getimagesize($logo['path']);
            if ($info) {
                $p->rect($s, 60, 90, 96, 96, '#ffffff', true);
                $scale = min(76 / $info[0], 76 / $info[1]);
                $p->image($s, (string) file_get_contents($logo['path']), 'jpeg', 108 - $info[0] * $scale / 2, 138 - $info[1] * $scale / 2, $info[0] * $scale, $info[1] * $scale);
                $y = 220;
            }
        }
        $p->text($s, 56, $y, 840, 90, $brand['name'] ?? 'Report', ['size' => 48, 'bold' => true, 'color' => $on]);
        $p->text($s, 56, $y + 90, 840, 40, 'Social media report', ['size' => 24, 'color' => $on]);
        $p->text($s, 56, $y + 140, 840, 30, $scope . '  ·  ' . $range, ['size' => 16, 'color' => $on]);

        // 2. Overview
        $s = $p->addSlide();
        $heading($s, 'Overview');
        $stats = [
            ['Followers', $fmt($a['followersNow']), $a['followersChange'] === null ? '' : (($a['followersChange'] >= 0 ? '+' : '') . number_format($a['followersChange']))],
            ['Posts', (string) $a['totals']['posts'], ''],
            ['Engagement', $fmt($a['totals']['engagement']), ''],
            ['Impressions', $a['totals']['impressions'] ? $fmt($a['totals']['impressions']) : '—', ''],
            ['Engagement rate', $a['rate'] === null ? '—' : $a['rate'] . '%', ''],
        ];
        $bw = (880 - 4 * 14) / 5;
        foreach ($stats as $i => [$label, $value, $sub]) {
            $x = 40 + $i * ($bw + 14);
            $p->rect($s, $x, 100, $bw, 96, self::SOFT, true);
            $lines = [[$label, 11, false, self::MUTED], [$value, 26, true, self::TEXT]];
            if ($sub !== '') {
                $lines[] = [$sub, 11, true, $a['followersChange'] >= 0 ? '#15803d' : '#dc2626'];
            }
            $p->text($s, $x + 8, 106, $bw - 16, 86, '', ['lines' => $lines]);
        }
        $p->text($s, 40, 214, 430, 24, 'Followers', ['size' => 14, 'bold' => true]);
        $p->image($s, ChartImage::png($a['followers'], 'line', 430, 250, $accent, 1), 'png', 40, 240, 430, 250);
        $p->text($s, 490, 214, 430, 24, 'Engagement per day', ['size' => 14, 'bold' => true]);
        $p->image($s, ChartImage::png($a['engagementSeries'], 'bar', 430, 250, $accent, 1), 'png', 490, 240, 430, 250);
        $footer($s);

        // 3. Networks
        if ($a['perNetwork']) {
            $s = $p->addSlide();
            $heading($s, 'By network');
            $rows = array_map(fn($n) => [Analytics::providerName($n['provider']), $fmt($n['followers']),
                $n['followers_change'] === null ? '—' : (($n['followers_change'] >= 0 ? '+' : '') . number_format($n['followers_change'])),
                (string) $n['posts'], $fmt($n['engagement']), $n['rate'] === null ? '—' : $n['rate'] . '%'], $a['perNetwork']);
            self::table($p, $s, ['Network', 'Followers', 'Change', 'Posts', 'Engagement', 'Eng. rate'], $rows, [0.34, 0.13, 0.13, 0.1, 0.15, 0.15], 100);
            $footer($s);
        }

        // 4. Top posts
        if ($a['top']) {
            $s = $p->addSlide();
            $heading($s, 'Top posts');
            $rows = array_map(fn($t) => [str_limit(preg_replace('/\s+/u', ' ', $t['content'] !== '' ? $t['content'] : '(media)'), 90), Analytics::providerName($t['provider']), local_time($t['published_at'], 'M j', $tz),
                number_format((int) ($t['m']['likes'] ?? $t['m']['reactions'] ?? 0)), number_format((int) ($t['m']['replies'] ?? $t['m']['comments'] ?? 0)), number_format($t['engagement'])], array_slice($a['top'], 0, 8));
            self::table($p, $s, ['Post', 'Network', 'Date', 'Likes', 'Comments', 'Total'], $rows, [0.5, 0.15, 0.08, 0.08, 0.1, 0.09], 100);
            $footer($s);
        }

        // 5. What worked
        $s = $p->addSlide();
        $heading($s, 'What worked');
        $labels = ['text' => 'Text only', 'image' => 'Single image', 'carousel' => 'Multiple images', 'video' => 'Video'];
        $rows = [];
        foreach ($a['types'] as $k => $t) {
            $rows[] = [$labels[$k], (string) $t['posts'], (string) $t['avg']];
        }
        self::table($p, $s, ['Format', 'Posts', 'Avg engagement'], $rows, [0.5, 0.2, 0.3], 100, 420);
        $right = [['Best times to post', 14, true, self::TEXT]];
        foreach ($a['best']['top'] ?: [] as $i => $c) {
            $right[] = [($i + 1) . '.  ' . Analytics::dayName($c['day']) . ', ' . Analytics::hourLabel($c['hour']), 14, false, self::TEXT];
        }
        if (!$a['best']['top']) {
            $right[] = ['Not enough posts yet', 12, false, self::MUTED];
        }
        $right[] = [' ', 8, false, self::TEXT];
        $right[] = ['Top hashtags', 14, true, self::TEXT];
        foreach (array_slice($a['hashtags'], 0, 6) as $h) {
            $right[] = ['#' . $h['tag'] . '  (' . $h['avg'] . ' avg)', 12, false, self::TEXT];
        }
        if (!$a['hashtags']) {
            $right[] = ['No hashtags used', 12, false, self::MUTED];
        }
        $p->text($s, 500, 100, 420, 380, '', ['lines' => $right]);
        $footer($s);

        return $p->output();
    }

    private static function table(Pptx $p, int $s, array $head, array $rows, array $cols, float $y, float $width = 880): void
    {
        $x0 = 40;
        $rowH = 34;
        $p->rect($s, $x0, $y, $width, $rowH, self::SOFT);
        $x = $x0;
        foreach ($head as $i => $h) {
            $w = $cols[$i] * $width;
            $p->text($s, $x + 4, $y, $w - 8, $rowH, $h, ['size' => 11, 'bold' => true, 'color' => self::MUTED, 'valign' => 'ctr', 'align' => $i === 0 ? 'l' : 'r']);
            $x += $w;
        }
        foreach (array_slice($rows, 0, 10) as $r => $row) {
            $ry = $y + $rowH * ($r + 1);
            $x = $x0;
            foreach ($row as $i => $cell) {
                $w = $cols[$i] * $width;
                $p->text($s, $x + 4, $ry, $w - 8, $rowH, (string) $cell, ['size' => 12, 'valign' => 'ctr', 'align' => $i === 0 ? 'l' : 'r']);
                $x += $w;
            }
            $p->rect($s, $x0, $ry + $rowH - 1, $width, 1, '#e5e7eb');
        }
    }
}
