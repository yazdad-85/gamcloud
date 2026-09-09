<?php
$settings = new \App\Services\Platform\PlatformSettingsService();
$branding = $settings->branding();
$siteName = (string) ($branding['site_name'] ?? 'Edugame');
$favicon = (string) ($branding['favicon_path'] ?? '/assets/brand/favicon.svg');
$faviconType = $settings->faviconMimeType($favicon);
$logoPath = (string) ($branding['logo_path'] ?? '/assets/brand/logo.svg');
$currentPath = trim(service('uri')->getPath(), '/');
$isSuperadmin = auth()->loggedIn() && auth()->user()->inGroup('superadmin');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" href="<?= esc($favicon) ?>" type="<?= esc($faviconType) ?>">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title><?= esc($title ?? $siteName) ?></title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= esc((string) @filemtime(FCPATH . 'assets/app.css')) ?>">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <a class="brand brand-with-logo" href="<?= $isSuperadmin ? '/superadmin' : '/teacher' ?>">
            <img src="<?= esc($logoPath) ?>" alt="<?= esc($siteName) ?>" width="140" height="32">
            <span><?= esc($siteName) ?></span>
        </a>
        <nav class="nav">
            <a class="<?= $currentPath === 'teacher' ? 'active' : '' ?>" href="/teacher">Dashboard</a>
            <a class="<?= str_starts_with($currentPath, 'teacher/questions') ? 'active' : '' ?>" href="/teacher/questions">Bank Soal</a>
            <a class="<?= str_starts_with($currentPath, 'teacher/games') ? 'active' : '' ?>" href="/teacher/games">Game</a>
            <?php if (! $isSuperadmin): ?>
                <a class="<?= $currentPath === 'teacher/profile' ? 'active' : '' ?>" href="/teacher/profile">Profil</a>
            <?php endif ?>
            <a class="<?= str_starts_with($currentPath, 'join') ? 'active' : '' ?>" href="/join">Join Tim</a>
            <?php if ($isSuperadmin): ?>
                <a class="<?= $currentPath === 'superadmin' ? 'active' : '' ?>" href="/superadmin">Admin Platform</a>
                <a class="<?= str_starts_with($currentPath, 'superadmin/settings') ? 'active' : '' ?>" href="/superadmin/settings">Pengaturan</a>
                <a class="<?= str_starts_with($currentPath, 'superadmin/profile') ? 'active' : '' ?>" href="/superadmin/profile">Profil Admin</a>
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
        <?= view('partials/site_footer', ['footerVariant' => 'app', 'branding' => $branding]) ?>
    </main>
</div>
<script src="/assets/app.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
