<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ? $title . ' · ' : '') . App\Config::get('app_name', 'MixCreate')) ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script>try{var t=localStorage.getItem('mc-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
</head>
<body class="auth-body">
<main class="auth-wrap">
    <a class="brand auth-brand" href="<?= e(url('/')) ?>"><?= icon('logo', 'brand-mark') ?><span><?= e(App\Config::get('app_name', 'MixCreate')) ?></span></a>
    <div class="flashes">
        <?php foreach (flashes() as $f): ?>
            <div class="flash flash-<?= e($f['type']) ?>" role="status">
                <?= icon($f['type'] === 'error' ? 'alert' : 'check') ?><span><?= e($f['message']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?= $content ?>
</main>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
