<?php
declare(strict_types=1);

namespace App;

/** Image conversions some platforms need (JPEG only, size caps). Results are cached in public/uploads/cache. */
final class MediaTools
{
    public static function isVideo(array $m): bool
    {
        return str_starts_with($m['mime'], 'video/');
    }

    /**
     * Returns a JPEG version of an image media item (same array shape), converting and/or shrinking if needed.
     * $maxBytes and $maxSide are optional caps. Videos are returned unchanged.
     */
    public static function jpeg(array $m, ?int $maxBytes = null, ?int $maxSide = null): array
    {
        if (self::isVideo($m)) {
            return $m;
        }
        $fits = $m['mime'] === 'image/jpeg'
            && ($maxBytes === null || $m['size'] <= $maxBytes)
            && ($maxSide === null || max((int) $m['width'], (int) $m['height']) <= $maxSide);
        if ($fits) {
            return $m;
        }
        return self::convert($m, 'jpeg', $maxBytes, $maxSide);
    }

    /** Shrinks an image to fit a byte limit, keeping PNG/WebP if already small enough. */
    public static function fit(array $m, int $maxBytes, ?int $maxSide = null): array
    {
        if (self::isVideo($m) || ($m['size'] <= $maxBytes && ($maxSide === null || max((int) $m['width'], (int) $m['height']) <= $maxSide))) {
            return $m;
        }
        return self::convert($m, 'jpeg', $maxBytes, $maxSide);
    }

    private static function convert(array $m, string $format, ?int $maxBytes, ?int $maxSide): array
    {
        if (!function_exists('imagecreatefromstring')) {
            return $m;
        }
        $key = $m['id'] . '-' . ($maxBytes ?? 0) . '-' . ($maxSide ?? 0) . '.jpg';
        $rel = 'uploads/cache/' . $key;
        $abs = BASE_PATH . '/public/' . $rel;
        if (!is_file($abs)) {
            if (!is_dir(dirname($abs))) {
                mkdir(dirname($abs), 0775, true);
            }
            $src = @imagecreatefromstring((string) file_get_contents($m['path']));
            if (!$src) {
                return $m;
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = $maxSide && max($w, $h) > $maxSide ? $maxSide / max($w, $h) : 1.0;
            $quality = 88;
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $nw = max(1, (int) round($w * $scale));
                $nh = max(1, (int) round($h * $scale));
                $dst = imagecreatetruecolor($nw, $nh);
                // Flatten transparency onto white; JPEG has no alpha.
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                ob_start();
                imagejpeg($dst, null, $quality);
                $data = ob_get_clean();
                imagedestroy($dst);
                if ($maxBytes === null || strlen($data) <= $maxBytes) {
                    break;
                }
                $quality = max(60, $quality - 8);
                $scale *= 0.85;
            }
            imagedestroy($src);
            file_put_contents($abs, $data);
        }
        $size = getimagesize($abs) ?: [null, null];
        return [
            'id' => $m['id'],
            'path' => $abs,
            'url' => abs_url($rel),
            'mime' => 'image/jpeg',
            'size' => filesize($abs),
            'width' => $size[0],
            'height' => $size[1],
            'name' => pathinfo($m['name'], PATHINFO_FILENAME) . '.jpg',
        ];
    }

    /** Platforms that fetch media by URL can't reach localhost or private addresses. */
    public static function assertPublicUrl(string $url, string $platform): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $private = $host === 'localhost'
            || str_ends_with($host, '.local')
            || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
        if ($private) {
            throw new Providers\ProviderException("$platform downloads media from your server, but $host isn’t reachable from the internet. Set app_url in config.php to your public address.");
        }
    }
}
