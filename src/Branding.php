<?php
declare(strict_types=1);

namespace App;

/** White-label settings for a workspace's reports and share pages. */
final class Branding
{
    public static function for(int $workspaceId): array
    {
        $ws = Db::one('SELECT name, color, branding FROM workspaces WHERE id = ?', [$workspaceId]) ?? ['name' => '', 'color' => '#4f46e5', 'branding' => '{}'];
        $b = json_arr($ws['branding']);
        $logo = null;
        if (!empty($b['logo_media_id'])) {
            $m = Publisher::resolveMedia([(int) $b['logo_media_id']])[0] ?? null;
            if ($m && str_starts_with($m['mime'], 'image/')) {
                $logo = $m;
            }
        }
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string) ($b['color'] ?? '')) ? $b['color'] : ($ws['color'] ?: '#4f46e5');
        return [
            'name' => trim((string) ($b['name'] ?? '')) ?: $ws['name'],
            'color' => $color,
            'logo' => $logo,
            'logo_url' => $logo ? url(substr($logo['path'], strlen(BASE_PATH . '/public/'))) : null,
            'hide_powered_by' => !empty($b['hide_powered_by']),
            'footer' => trim((string) ($b['footer'] ?? '')),
        ];
    }

    /** Readable text color (black or white) for a background. */
    public static function onColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#15181e' : '#ffffff';
    }
}
