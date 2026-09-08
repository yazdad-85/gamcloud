<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<div class="topbar">
    <div>
        <h1 class="page-title">Riwayat Pendaftaran</h1>
        <p class="muted">Pantau pendaftaran guru. Akun aktif otomatis setelah email berhasil diverifikasi.</p>
    </div>
    <a class="button secondary" href="/superadmin">Admin Platform</a>
</div>

<?php if (session('error') !== null): ?>
    <div class="alert" style="margin-bottom:14px"><?= esc(session('error')) ?></div>
<?php endif ?>

<?php if (session('message') !== null): ?>
    <div class="alert success-alert" style="margin-bottom:14px"><?= esc(session('message')) ?></div>
<?php endif ?>

<section class="panel">
    <table class="table">
        <thead>
        <tr>
            <th>Guru</th>
            <th>Email</th>
            <th>Instansi</th>
            <th>Status</th>
            <th>Diajukan</th>
            <th>Keterangan</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($requests === []): ?>
            <tr>
                <td colspan="6" class="muted">Belum ada pengajuan.</td>
            </tr>
        <?php endif ?>
        <?php foreach ($requests as $request): ?>
            <tr>
                <td><?= esc($request['name']) ?></td>
                <td><?= esc($request['email']) ?></td>
                <td><?= esc($request['school_name'] ?? '-') ?></td>
                <td><span class="badge"><?= esc($request['status']) ?></span></td>
                <td><?= esc($request['created_at'] ?? '-') ?></td>
                <td>
                    <?php if ($request['status'] === 'PENDING_EMAIL'): ?>
                        <span class="muted">Menunggu verifikasi email</span>
                    <?php elseif ($request['status'] === 'EMAIL_VERIFIED' || $request['status'] === 'APPROVED'): ?>
                        <span class="muted">Email valid, akun sudah aktif</span>
                    <?php elseif ($request['status'] === 'REJECTED'): ?>
                        <span class="muted">Pendaftaran ditolak</span>
                    <?php else: ?>
                        <span class="muted">Selesai</span>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</section>
<?= $this->endSection() ?>
