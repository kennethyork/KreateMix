<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<style>:root { --accent: <?= e($brand['color']) ?>; --accent-soft: color-mix(in srgb, <?= e($brand['color']) ?> 12%, white); } .public-head { border-top: 4px solid <?= e($brand['color']) ?>; }</style>
</head>
<body class="public-report">
<header class="public-head">
    <div class="public-brand">
        <?php if ($brand['logo_url']): ?><img class="public-logo" src="<?= e($brand['logo_url']) ?>" alt=""><?php endif; ?>
        <div>
        <h1><?= e($brand['name']) ?></h1>
        <p class="muted"><?= e($scope) ?> · <?= e(date('M j', strtotime($a['from'])) . ' – ' . date('M j, Y', strtotime($a['to']))) ?></p>
        </div>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e($pdfUrl) ?>"><?= icon('download') ?> PDF</a>
        <a class="btn" href="<?= e($pptxUrl) ?>"><?= icon('download') ?> PowerPoint</a>
    </div>
</header>
<main class="content">
    <?php partial('reports/report', ['a' => $a, 'tz' => $tz]); ?>
    <?php if ($brand['footer'] !== ''): ?><p class="muted small center"><?= e($brand['footer']) ?></p><?php endif; ?>
    <?php if (!$brand['hide_powered_by']): ?><p class="muted small center">Made with <?= e(App\Config::get('app_name', 'MixCreate')) ?></p><?php endif; ?>
</main>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
