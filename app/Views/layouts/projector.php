<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Projector Game') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="projector">
<main class="game-screen">
    <?= $this->renderSection('content') ?>
</main>
<script src="/assets/app.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
