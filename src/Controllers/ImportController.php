<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Http;
use App\PostService;
use App\Providers\Registry;
use App\Workspace;

/**
 * Bulk CSV upload. Columns (header names, any order, case-insensitive):
 * date, time, text, accounts, media, first_comment, tags, status
 */
final class ImportController
{
    public function form(): void
    {
        $preview = $_SESSION['_import'] ?? null;
        view('import/index', [
            'title' => 'Bulk upload',
            'preview' => $preview && (int) $preview['workspace'] === Workspace::id() ? $preview : null,
            'accounts' => PostsController::accounts(),
        ]);
    }

    public function sample(): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mixcreate-sample.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['date', 'time', 'text', 'accounts', 'media', 'first_comment', 'tags', 'status']);
        $d = (new \DateTimeImmutable('tomorrow', new \DateTimeZone(user_tz())))->format('Y-m-d');
        fputcsv($out, [$d, '09:00', 'Our spring sale starts today! 🌷', 'all', '', 'Shop now: https://example.com', 'Spring', 'schedule']);
        fputcsv($out, [$d, '13:30', 'Behind the scenes of our new studio', 'x; bluesky', 'https://example.com/photo.jpg', '', '', 'schedule']);
        fputcsv($out, ['', '', 'An idea to finish later', '', '', '', '', 'draft']);
        fclose($out);
        exit;
    }

    public function upload(): void
    {
        unset($_SESSION['_import']);
        $file = $_FILES['csv'] ?? null;
        if (!$file || !is_uploaded_file($file['tmp_name'] ?? '')) {
            flash('error', 'Choose a CSV file.');
            redirect('/import');
        }
        $fh = fopen($file['tmp_name'], 'r');
        $first = fgets($fh);
        // Strip a UTF-8 BOM and detect ; or , as the separator (Excel in some locales uses ;).
        $first = preg_replace('/^\xEF\xBB\xBF/', '', (string) $first);
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(fn($h) => strtolower(trim($h)), str_getcsv($first, $sep, '"', '\\'));
        if (!in_array('text', $header, true)) {
            flash('error', 'The first row must be a header with at least a “text” column. Download the sample to see the format.');
            redirect('/import');
        }
        $rows = [];
        $line = 1;
        while (($cols = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
            $line++;
            if ($cols === [null] || trim(implode('', $cols)) === '') {
                continue;
            }
            $r = [];
            foreach ($header as $i => $h) {
                $r[$h] = trim((string) ($cols[$i] ?? ''));
            }
            $rows[] = $this->parse($r, $line);
            if (count($rows) >= 500) {
                break;
            }
        }
        fclose($fh);
        if (!$rows) {
            flash('error', 'No rows found.');
            redirect('/import');
        }
        $_SESSION['_import'] = ['workspace' => Workspace::id(), 'rows' => $rows];
        redirect('/import');
    }

    private function parse(array $r, int $line): array
    {
        $errors = [];
        $accounts = PostsController::accounts();
        $status = in_array(strtolower($r['status'] ?? ''), ['draft'], true) ? 'draft' : 'schedule';

        // Accounts: "all", ids, names, @usernames, or platform keys/names ("x", "bluesky", "Instagram").
        $ids = [];
        $spec = $r['accounts'] ?? '';
        if ($spec === '' && $status === 'schedule') {
            $errors[] = 'No accounts.';
        }
        foreach (preg_split('/[;,|]+/', $spec, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $t = strtolower(trim($token));
            $matched = false;
            foreach ($accounts as $a) {
                $pname = strtolower(Registry::class($a['provider'])::name());
                if ($t === 'all' || $t === (string) $a['id'] || $t === strtolower($a['name']) || ltrim($t, '@') === strtolower($a['username'])
                    || $t === $a['provider'] || $t === $pname || str_starts_with($pname, $t . ' ')) {
                    $ids[] = (int) $a['id'];
                    $matched = true;
                }
            }
            if (!$matched) {
                $errors[] = "Unknown account “{$token}”.";
            }
        }

        $scheduled = null;
        if ($status === 'schedule') {
            $date = $r['date'] ?? '';
            $time = $r['time'] ?? '' ?: '09:00';
            $ts = $date !== '' ? strtotime("$date $time") : false;
            if ($ts === false) {
                $errors[] = 'Missing or invalid date/time.';
            } else {
                $scheduled = to_utc(date('Y-m-d H:i', $ts));
                if (strtotime($scheduled . ' UTC') < time()) {
                    $errors[] = 'Date is in the past.';
                }
            }
        }

        $media = [];
        foreach (preg_split('/[\s;|]+/', $r['media'] ?? '', -1, PREG_SPLIT_NO_EMPTY) as $m) {
            if (ctype_digit($m) || preg_match('#^https?://#i', $m)) {
                $media[] = $m;
            } else {
                $errors[] = "Media must be a URL or media ID: “{$m}”.";
            }
        }
        return [
            'line' => $line,
            'text' => $r['text'] ?? '',
            'accounts' => array_values(array_unique($ids)),
            'scheduled_at' => $scheduled,
            'media' => $media,
            'first_comment' => $r['first_comment'] ?? '',
            'tags' => array_values(array_filter(array_map('trim', preg_split('/[;,]+/', $r['tags'] ?? '')))),
            'status' => $status,
            'errors' => $errors,
        ];
    }

    public function confirm(): void
    {
        $data = $_SESSION['_import'] ?? null;
        unset($_SESSION['_import']);
        if (!$data || (int) $data['workspace'] !== Workspace::id()) {
            redirect('/import');
        }
        set_time_limit(600);
        $wsId = Workspace::id();
        $ok = 0;
        $failed = [];
        foreach ($data['rows'] as $row) {
            if ($row['errors']) {
                continue;
            }
            $mediaIds = [];
            foreach ($row['media'] as $m) {
                if (ctype_digit($m)) {
                    $mediaIds[] = (int) $m;
                    continue;
                }
                $res = Http::get($m, ['timeout' => 120, 'headers' => ['Accept' => '*/*']]);
                if (!Http::ok($res)) {
                    $failed[] = "Line {$row['line']}: couldn’t download $m";
                    continue 2;
                }
                $tmp = tempnam(sys_get_temp_dir(), 'imp');
                file_put_contents($tmp, $res['body']);
                $saved = MediaController::storeLocal($tmp, basename((string) parse_url($m, PHP_URL_PATH)) ?: 'import', $wsId, (int) Auth::id());
                @unlink($tmp);
                if (isset($saved['error'])) {
                    $failed[] = "Line {$row['line']}: {$saved['error']}";
                    continue 2;
                }
                $mediaIds[] = $saved['id'];
            }
            $tagIds = [];
            foreach ($row['tags'] as $tag) {
                Db::query('INSERT OR IGNORE INTO tags (workspace_id, name) VALUES (?, ?)', [$wsId, mb_substr($tag, 0, 40)]);
                $tagIds[] = (int) Db::value('SELECT id FROM tags WHERE workspace_id = ? AND name = ?', [$wsId, mb_substr($tag, 0, 40)]);
            }
            $result = PostService::save(null, [
                'content' => $row['text'],
                'accounts' => $row['accounts'],
                'media' => $mediaIds,
                'tags' => $tagIds,
                'first_comment' => $row['first_comment'],
                'scheduled_at' => $row['scheduled_at'],
            ], $row['status'], $wsId, (int) Auth::id(), Workspace::can('admin'));
            if ($result['errors']) {
                $failed[] = "Line {$row['line']}: " . implode(' ', $result['errors']);
            } else {
                $ok++;
            }
        }
        flash($ok ? 'success' : 'error', "Imported $ok post" . ($ok === 1 ? '' : 's') . '.' . ($failed ? ' ' . count($failed) . ' skipped.' : ''));
        foreach (array_slice($failed, 0, 10) as $f) {
            flash('error', $f);
        }
        redirect('/posts');
    }

    public function cancel(): void
    {
        unset($_SESSION['_import']);
        redirect('/import');
    }
}
