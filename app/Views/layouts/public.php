<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    <?= $this->include('partials/seo_head') ?>
    <link rel="stylesheet" href="/assets/app.css?v=<?= esc((string) @filemtime(FCPATH . 'assets/app.css')) ?>">
</head>
<body class="<?= esc($bodyClass ?? '') ?>">
<main class="public-page <?= esc($publicPageClass ?? '') ?>">
    <div class="public-page-body">
        <?= $this->renderSection('content') ?>
    </div>
    <?= $this->include('partials/site_footer', ['footerVariant' => 'public']) ?>
</main>
</body>
</html>
