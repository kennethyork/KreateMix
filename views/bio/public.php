<?php
$style = $themes[$theme['style'] ?? 'light'] ?? $themes['light'];
$accent = $theme['accent'] ?? '#4f46e5';
$bg = $style['bg'] === 'accent' ? $accent : $style['bg'];
$radius = ($theme['button'] ?? 'rounded') === 'square' ? '8px' : '999px';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page['title'] ?: 'Links') ?></title>
<meta name="description" content="<?= e(str_limit($page['bio'], 150)) ?>">
<meta property="og:title" content="<?= e($page['title']) ?>">
<meta property="og:description" content="<?= e(str_limit($page['bio'], 150)) ?>">
<?php if ($avatar): ?><meta property="og:image" content="<?= e(abs_url(ltrim(substr($avatar, strlen(base_path())), '/'))) ?>"><?php endif; ?>
<?php if ($preview): ?><meta name="robots" content="noindex"><?php endif; ?>
<style>
    *{box-sizing:border-box}
    body{margin:0;min-height:100vh;font:16px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:<?= e($bg) ?>;color:<?= e($style['text']) ?>;-webkit-font-smoothing:antialiased}
    .wrap{max-width:560px;margin:0 auto;padding:48px 20px 40px;display:flex;flex-direction:column;align-items:center;gap:14px}
    .avatar{width:96px;height:96px;border-radius:50%;object-fit:cover;background:<?= e($style['card']) ?>;display:grid;place-items:center;font-weight:700;font-size:32px;color:<?= e($accent) ?>;box-shadow:0 4px 16px rgba(0,0,0,.12)}
    h1{font-size:22px;margin:6px 0 0;text-align:center;letter-spacing:-.01em<?= $style['bg'] === 'accent' ? ';color:#fff' : '' ?>}
    .bio{margin:0;text-align:center;opacity:.8;max-width:440px;white-space:pre-line<?= $style['bg'] === 'accent' ? ';color:#fff' : '' ?>}
    .socials{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin:4px 0}
    .socials a{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:<?= e($style['card']) ?>;color:<?= e($style['text']) ?>;text-decoration:none;font-weight:800;font-size:12px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
    .links{width:100%;display:grid;gap:12px;margin-top:8px}
    .links a{display:block;text-align:center;padding:15px 20px;border-radius:<?= $radius ?>;background:<?= e($style['card']) ?>;color:<?= e($style['text']) ?>;text-decoration:none;font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,.08);border:2px solid transparent;transition:transform .12s,border-color .12s}
    .links a:hover{transform:translateY(-2px);border-color:<?= e($accent) ?>}
    .grid{width:100%;display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:18px}
    .grid a{aspect-ratio:1;border-radius:10px;overflow:hidden;display:block;background:<?= e($style['card']) ?>}
    .grid img{width:100%;height:100%;object-fit:cover;display:block}
    .foot{margin-top:28px;font-size:12px;opacity:.55<?= $style['bg'] === 'accent' ? ';color:#fff' : '' ?>}
    .preview{position:fixed;top:0;left:0;right:0;background:#111;color:#fff;font-size:13px;text-align:center;padding:6px}
</style>
</head>
<body>
<?php if ($preview && !$page['published']): ?><div class="preview">Preview — this page isn’t published yet.</div><?php endif; ?>
<main class="wrap">
    <?php if ($avatar): ?>
        <img class="avatar" src="<?= e($avatar) ?>" alt="">
    <?php else: ?>
        <div class="avatar"><?= e(initials($page['title'] ?: '?')) ?></div>
    <?php endif; ?>
    <h1><?= e($page['title']) ?></h1>
    <?php if ($page['bio'] !== ''): ?><p class="bio"><?= e($page['bio']) ?></p><?php endif; ?>
    <?php if ($socials): ?>
        <nav class="socials" aria-label="Social profiles">
            <?php foreach ($socials as $href => $a): $abbr = ['x' => 'X', 'mastodon' => 'M', 'bluesky' => 'B', 'facebook' => 'f', 'instagram' => 'IG', 'linkedin' => 'in', 'youtube' => 'YT', 'threads' => '@', 'tiktok' => 'TT', 'pinterest' => 'P'][$a['provider']] ?? '•'; ?>
                <a href="<?= e($href) ?>" target="_blank" rel="noopener me" title="<?= e(provider_meta($a['provider'])['name']) ?>" aria-label="<?= e(provider_meta($a['provider'])['name']) ?>"><?= e($abbr) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <div class="links">
        <?php foreach ($links as $l): ?>
            <a href="<?= e(url('@' . $page['slug'] . '/l/' . $l['id'])) ?>" rel="noopener"><?= e($l['label']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($posts): ?>
        <div class="grid">
            <?php foreach ($posts as $p): ?>
                <a href="<?= e($p['url']) ?>" target="_blank" rel="noopener"><img src="<?= e($p['img']) ?>" alt="<?= e($p['alt']) ?>" loading="lazy"></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="foot"><?= e(App\Config::get('app_name', 'MixCreate')) ?></div>
</main>
</body>
</html>
