<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Ular Tangga Edukatif') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<?php $currentPath = trim(service('uri')->getPath(), '/'); ?>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">Ular Tangga<br>Edukatif</div>
        <nav class="nav">
            <a class="<?= $currentPath === 'teacher' ? 'active' : '' ?>" href="/teacher">Dashboard</a>
            <a class="<?= str_starts_with($currentPath, 'teacher/questions') ? 'active' : '' ?>" href="/teacher/questions">Bank Soal</a>
            <a class="<?= str_starts_with($currentPath, 'teacher/games') ? 'active' : '' ?>" href="/teacher/games">Game</a>
            <a class="<?= str_starts_with($currentPath, 'join') ? 'active' : '' ?>" href="/join">Join Tim</a>
            <?php if (auth()->loggedIn() && auth()->user()->inGroup('superadmin')): ?>
                <a class="<?= $currentPath === 'superadmin' ? 'active' : '' ?>" href="/superadmin">Admin Platform</a>
                <a class="<?= str_starts_with($currentPath, 'superadmin/registrations') ? 'active' : '' ?>" href="/superadmin/registrations">Riwayat Pendaftaran</a>
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
