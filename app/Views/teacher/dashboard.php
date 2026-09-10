<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<div class="topbar">
    <div>
        <h1 class="page-title">Dashboard Guru</h1>
        <p class="muted">Ringkasan room dan bank soal.</p>
    </div>
    <a class="button" href="/teacher/games/create">Buat Game</a>
</div>

<section class="grid cols-3">
    <div class="card metric">
        <span class="muted">Permainan Aktif</span>
        <strong><?= count($activeRooms) ?></strong>
    </div>
    <div class="card metric">
        <span class="muted">Soal Tersedia</span>
        <strong><?= esc((string) $questionCount) ?></strong>
    </div>
    <div class="card metric">
        <span class="muted">Room Terakhir</span>
        <strong><?= count($recentRooms) ?></strong>
    </div>
</section>

<section class="panel" style="margin-top:16px">
    <h2>Permainan Terakhir</h2>
    <table class="table">
        <thead>
        <tr><th>Judul</th><th>PIN</th><th>Status</th><th>Aksi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($recentRooms as $room): ?>
            <tr>
                <td><?= esc(\App\Services\Game\GameRoomPresenter::displayTitle($room)) ?></td>
                <td><strong><?= esc($room['pin']) ?></strong></td>
                <td><span class="badge <?= strtolower(esc($room['status'])) ?>"><?= esc($room['status']) ?></span></td>
                <td><a class="button secondary" href="/teacher/games/<?= esc($room['public_uuid']) ?>">Buka</a></td>
            </tr>
        <?php endforeach ?>
        <?php if ($recentRooms === []): ?>
            <tr><td colspan="4" class="muted">Belum ada game.</td></tr>
        <?php endif ?>
        </tbody>
    </table>
</section>
<?= $this->endSection() ?>
