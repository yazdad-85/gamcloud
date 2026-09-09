<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<div class="topbar">
    <div>
        <h1 class="page-title">Admin Platform</h1>
        <p class="muted">Pantauan seluruh guru, room, dan aktivitas dasar aplikasi.</p>
    </div>
    <div class="inline-actions">
        <a class="button secondary" href="/superadmin/settings">Pengaturan</a>
        <a class="button secondary" href="/superadmin/profile">Profil Admin</a>
        <a class="button secondary" href="/superadmin/registrations">Riwayat Pendaftaran</a>
        <a class="button secondary" href="/superadmin/teachers">Daftar Guru</a>
    </div>
</div>

<section class="grid cols-4">
    <div class="card metric">
        <span class="muted">Guru</span>
        <strong><?= esc((string) $teacherCount) ?></strong>
    </div>
    <div class="card metric">
        <span class="muted">Room</span>
        <strong><?= esc((string) $roomCount) ?></strong>
    </div>
    <div class="card metric">
        <span class="muted">Room Aktif</span>
        <strong><?= esc((string) $activeRoomCount) ?></strong>
    </div>
    <div class="card metric">
        <span class="muted">Menunggu Email</span>
        <strong><?= esc((string) $pendingRegistrationCount) ?></strong>
    </div>
</section>

<section class="panel" style="margin-top:16px">
    <h2>Guru Terbaru</h2>
    <table class="table">
        <thead>
        <tr><th>Nama</th><th>Email</th><th>Username</th><th>Aktif Terakhir</th></tr>
        </thead>
        <tbody>
        <?php foreach ($recentTeachers as $teacher): ?>
            <tr>
                <td><?= esc($teacher['name']) ?></td>
                <td><?= esc($teacher['email']) ?></td>
                <td><?= esc($teacher['username'] ?? '-') ?></td>
                <td><?= esc($teacher['last_active'] ?? '-') ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</section>
<?= $this->endSection() ?>
