<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="public-report">
<header class="public-head">
    <div>
        <h1><?= e($workspace) ?></h1>
        <p class="muted"><?= e($scope) ?> · <?= e(date('M j', strtotime($a['from'])) . ' – ' . date('M j, Y', strtotime($a['to']))) ?></p>
    </div>
    <a class="btn" href="<?= e($pdfUrl) ?>"><?= icon('download') ?> PDF</a>
</header>
<main class="content">
    <?php partial('reports/report', ['a' => $a, 'tz' => $tz]); ?>
    <p class="muted small center">Made with <?= e(App\Config::get('app_name', 'MixCreate')) ?></p>
</main>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
