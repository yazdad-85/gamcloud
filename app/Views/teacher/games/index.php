<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<div class="topbar">
    <div>
        <h1 class="page-title">Daftar Game</h1>
        <p class="muted">Kelola room game yang sudah dibuat.</p>
    </div>
    <a class="button" href="/teacher/games/create">Buat Game</a>
</div>

<?php if (session('message') !== null): ?>
    <div class="alert success-alert" style="margin-bottom:14px"><?= esc(session('message')) ?></div>
<?php endif ?>
<?php if (session('error') !== null): ?>
    <div class="alert" style="margin-bottom:14px"><?= esc(session('error')) ?></div>
<?php endif ?>

<section class="panel">
    <table class="table">
        <thead>
        <tr><th>Judul</th><th>Mode</th><th>PIN</th><th>Status</th><th>Versi State</th><th>Aksi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rooms as $room): ?>
            <tr>
                <td><?= esc(\App\Services\Game\GameRoomPresenter::displayTitle($room)) ?></td>
                <td><?= esc(\App\Services\Game\GameRoomPresenter::modeLabel($room['game_mode'] ?? 'SNAKES_LADDERS')) ?></td>
                <td><strong><?= esc($room['pin']) ?></strong></td>
                <td><span class="badge <?= strtolower(esc($room['status'])) ?>"><?= esc($room['status']) ?></span></td>
                <td><?= esc((string) $room['state_version']) ?></td>
                <td>
                    <div class="inline-actions">
                        <a class="button secondary" href="/teacher/games/<?= esc($room['public_uuid']) ?>">Detail</a>
                        <a class="button secondary" href="/teacher/games/<?= esc($room['public_uuid']) ?>/report">Laporan</a>
                        <?php if (in_array($room['status'], ['LOBBY', 'FINISHED', 'EXPIRED'], true)): ?>
                            <form action="/teacher/games/<?= esc($room['public_uuid']) ?>/delete" method="post" onsubmit="return confirm('Hapus room game ini? Data tim, event, dan laporan room ini akan ikut dihapus.');">
                                <?= csrf_field() ?>
                                <button class="button danger" type="submit">Hapus</button>
                            </form>
                        <?php endif ?>
                    </div>
                </td>
            </tr>
        <?php endforeach ?>
        <?php if ($rooms === []): ?>
            <tr><td colspan="6" class="muted">Belum ada game.</td></tr>
        <?php endif ?>
        </tbody>
    </table>
</section>
<?= $this->endSection() ?>
