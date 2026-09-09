<?php
$settings = new \App\Services\Platform\PlatformSettingsService();
$branding = $settings->branding();
$favicon = (string) ($branding['favicon_path'] ?? '/assets/brand/favicon.svg');
$faviconType = $settings->faviconMimeType($favicon);
$assetV = (string) @filemtime(FCPATH . 'assets/app.js');
$cssV = (string) @filemtime(FCPATH . 'assets/app.css');
$fxCssV = (string) @filemtime(FCPATH . 'assets/game-fx.css');
$fxJsV = (string) @filemtime(FCPATH . 'assets/game-fx.js');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" href="<?= esc($favicon) ?>" type="<?= esc($faviconType) ?>">
    <title><?= esc($title ?? 'Controller Tim') ?></title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= esc($cssV) ?>">
    <link rel="stylesheet" href="/assets/game-fx.css?v=<?= esc($fxCssV) ?>">
</head>
<body class="controller-page">
<main class="game-screen">
    <?= $this->renderSection('content') ?>
</main>
<script src="/assets/app.js?v=<?= esc($assetV) ?>"></script>
<script src="/assets/game-fx.js?v=<?= esc($fxJsV) ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
