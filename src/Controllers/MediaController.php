<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Config;
use App\Db;
use App\Workspace;

final class MediaController
{
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
    ];

    public function index(): void
    {
        $rows = Db::all('SELECT * FROM media WHERE workspace_id = ? ORDER BY id DESC LIMIT 200', [Workspace::id()]);
        $items = array_map(fn($m) => self::toArray($m), $rows);
        if (wants_json()) {
            json_response(['media' => $items]);
        }
        view('media/index', ['title' => 'Media', 'media' => $items]);
    }

    public function upload(): void
    {
        $file = $_FILES['file'] ?? null;
        $result = self::store($file, Workspace::id(), (int) Auth::id());
        if (isset($result['error'])) {
            if (wants_json()) {
                json_response(['error' => $result['error']], 422);
            }
            flash('error', $result['error']);
            redirect('/media');
        }
        if (wants_json()) {
            json_response(['media' => $result]);
        }
        flash('success', 'Uploaded.');
        redirect('/media');
    }

    /** Validates and saves an uploaded file. Returns the media array or ['error' => message]. */
    public static function store(?array $file, int $workspaceId, int $userId): array
    {
        if (!$file || !is_uploaded_file($file['tmp_name'] ?? '')) {
            $codes = [UPLOAD_ERR_INI_SIZE => 'The file is larger than the server allows (upload_max_filesize).', UPLOAD_ERR_FORM_SIZE => 'The file is too large.'];
            return ['error' => $codes[$file['error'] ?? 0] ?? 'No file was uploaded.'];
        }
        return self::save($file['tmp_name'], (string) $file['name'], $workspaceId, $userId, true);
    }

    /** Saves a file already on disk (e.g. downloaded from Canva). The source file is copied. */
    public static function storeLocal(string $path, string $name, int $workspaceId, int $userId): array
    {
        return self::save($path, $name, $workspaceId, $userId, false);
    }

    private static function save(string $tmp, string $origName, int $workspaceId, int $userId, bool $uploaded): array
    {
        $size = (int) filesize($tmp);
        $maxBytes = (int) Config::get('max_upload_mb', 200) * 1024 * 1024;
        if ($size > $maxBytes) {
            return ['error' => 'The file is larger than ' . Config::get('max_upload_mb', 200) . ' MB.'];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!isset(self::TYPES[$mime])) {
            return ['error' => 'Only JPG, PNG, GIF, WebP, MP4, MOV and WebM files are supported.'];
        }
        $ext = self::TYPES[$mime];
        $dir = 'uploads/' . $workspaceId . '/' . gmdate('Y/m');
        if (!is_dir(BASE_PATH . '/public/' . $dir)) {
            mkdir(BASE_PATH . '/public/' . $dir, 0775, true);
        }
        $rel = $dir . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        $ok = $uploaded ? move_uploaded_file($tmp, BASE_PATH . '/public/' . $rel) : copy($tmp, BASE_PATH . '/public/' . $rel);
        if (!$ok) {
            return ['error' => 'Couldn’t save the file. Check that public/uploads is writable.'];
        }
        $w = $h = null;
        if (str_starts_with($mime, 'image/')) {
            $dims = @getimagesize(BASE_PATH . '/public/' . $rel);
            if ($dims) {
                [$w, $h] = $dims;
            }
        }
        $name = mb_substr(preg_replace('/[^\w.\- ]+/u', '', $origName) ?: 'file.' . $ext, 0, 120);
        $id = Db::insert('media', [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'path' => $rel,
            'name' => $name,
            'mime' => $mime,
            'size' => $size,
            'width' => $w,
            'height' => $h,
            'created_at' => now(),
        ]);
        return self::toArray(Db::one('SELECT * FROM media WHERE id = ?', [$id]));
    }

    public function delete(string $id): void
    {
        $m = Db::one('SELECT * FROM media WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$m) {
            abort(404);
        }
        Db::query('DELETE FROM media WHERE id = ?', [$m['id']]);
        @unlink(BASE_PATH . '/public/' . $m['path']);
        if (wants_json()) {
            json_response(['ok' => true]);
        }
        flash('success', 'Deleted.');
        redirect('/media');
    }

    public static function toArray(array $m): array
    {
        return [
            'id' => (int) $m['id'],
            'url' => url($m['path']),
            'name' => $m['name'],
            'mime' => $m['mime'],
            'size' => (int) $m['size'],
            'width' => $m['width'] ? (int) $m['width'] : null,
            'height' => $m['height'] ? (int) $m['height'] : null,
            'is_video' => str_starts_with($m['mime'], 'video/'),
        ];
    }
}
