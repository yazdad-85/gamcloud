<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<div class="topbar">
    <div>
        <h1 class="page-title">Daftar Guru</h1>
        <p class="muted">Admin platform dapat melihat guru yang terdaftar dan aktivitas dasarnya.</p>
    </div>
    <a class="button secondary" href="/superadmin">Admin Platform</a>
</div>

<section class="panel">
    <table class="table">
        <thead>
        <tr><th>Guru</th><th>Email</th><th>Status Akun</th><th>Room</th><th>Aktif Terakhir</th></tr>
        </thead>
        <tbody>
        <?php foreach ($teachers as $teacher): ?>
            <tr>
                <td><?= esc($teacher['name']) ?></td>
                <td><?= esc($teacher['email']) ?></td>
                <td><?= (int) ($teacher['active'] ?? 0) === 1 ? 'Aktif' : 'Belum aktif' ?></td>
                <td><?= esc((string) $teacher['room_count']) ?></td>
                <td><?= esc($teacher['last_active'] ?? '-') ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</section>
<?= $this->endSection() ?>
