<?php
/**
 * Builds KreateMix Cloud (the hosted, paid edition) from the self-hosted index.php.
 *
 *   php cloud/build.php [output]     (default: dist/cloud/index.php)
 *
 * Cloud is the self-hosted file plus the edits in overlay.php: the real Cloud class instead of
 * the free one, CloudController, the signup/billing views, a migration and some routes.
 * Each edit is pinned to text that has to appear exactly once in index.php. If a change to
 * index.php moves or removes that text, the build stops and says which edit to fix.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$lf = fn(string $s): string => str_replace("\r\n", "\n", $s);
$root = dirname(__DIR__);
$output = $argv[1] ?? "$root/dist/cloud/index.php";

$src = $lf((string) file_get_contents("$root/index.php"));
$edits = require __DIR__ . '/overlay.php';

$splices = [];
foreach ($edits as $i => $edit) {
    $mode = isset($edit['replace']) ? 'replace' : (isset($edit['before']) ? 'before' : 'after');
    $anchor = $lf($edit[$mode]);
    $text = isset($edit['file']) ? $lf((string) file_get_contents(__DIR__ . '/parts/' . $edit['file'])) : $lf($edit['text']);
    $count = substr_count($src, $anchor);
    if ($count !== 1) {
        $first = strtok(trim($anchor), "\n");
        fwrite(STDERR, "Edit #$i ($mode): expected the text to appear once in index.php, found it $count times:\n    $first\n");
        exit(1);
    }
    $pos = strpos($src, $anchor);
    $splices[] = match ($mode) {
        'replace' => [$pos, strlen($anchor), $text],
        'before' => [$pos, 0, $text],
        'after' => [$pos + strlen($anchor), 0, $text],
    };
}

// Apply from the end of the file backwards so earlier offsets stay valid.
usort($splices, fn($a, $b) => $b[0] <=> $a[0]);
$end = PHP_INT_MAX;
foreach ($splices as [$pos, $len, $text]) {
    if ($pos + $len > $end) {
        fwrite(STDERR, "Two edits overlap near byte $pos of index.php.\n");
        exit(1);
    }
    $src = substr_replace($src, $text, $pos, $len);
    $end = $pos;
}

if (!is_dir(dirname($output))) {
    mkdir(dirname($output), 0777, true);
}
file_put_contents($output, $src);

exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($output) . ' 2>&1', $lint, $status);
if ($status !== 0) {
    fwrite(STDERR, implode("\n", $lint) . "\n");
    exit(1);
}
echo "Built $output\n";
