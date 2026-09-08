<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Ular Tangga Edukatif') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">Ular Tangga<br>Edukatif</div>
        <nav class="nav">
            <a href="/teacher">Dashboard</a>
            <a href="/teacher/questions">Bank Soal</a>
            <a href="/teacher/games">Game</a>
            <a href="/join">Join Tim</a>
            <?php if (auth()->loggedIn() && auth()->user()->inGroup('superadmin')): ?>
                <a href="/superadmin">Admin Platform</a>
                <a href="/superadmin/registrations">Riwayat Pendaftaran</a>
            <?php endif ?>
            <form method="post" action="/logout">
                <?= csrf_field() ?>
                <button type="submit">Logout</button>
            </form>
        </nav>
    </aside>
    <main class="main">
        <?= $this->renderSection('content') ?>
    </main>
</div>
<script src="/assets/app.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
