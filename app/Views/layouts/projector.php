<?php
$settings = new \App\Services\Platform\PlatformSettingsService();
$branding = $settings->branding();
$favicon = (string) ($branding['favicon_path'] ?? '/assets/brand/favicon.svg');
$faviconType = $settings->faviconMimeType($favicon);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" href="<?= esc($favicon) ?>" type="<?= esc($faviconType) ?>">
    <title><?= esc($title ?? 'Projector Game') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/game-fx.css">
</head>
<body class="projector">
<main class="game-screen">
    <?= $this->renderSection('content') ?>
</main>
<script src="/assets/app.js"></script>
<script src="/assets/game-fx.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
