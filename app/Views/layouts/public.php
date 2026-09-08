<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Join Game') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="<?= esc($bodyClass ?? '') ?>">
<main class="public-page <?= esc($publicPageClass ?? '') ?>">
    <?= $this->renderSection('content') ?>
</main>
</body>
</html>
