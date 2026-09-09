<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<div class="topbar">
    <div>
        <h1 class="page-title">Profil Guru</h1>
        <p class="muted">Perbarui data profil dan password akun Anda.</p>
    </div>
</div>

<?php if (! empty($message)): ?>
    <div class="alert success"><?= esc($message) ?></div>
<?php endif ?>
<?php if (! empty($error)): ?>
    <div class="alert"><?= esc($error) ?></div>
<?php endif ?>

<form class="panel" method="post" action="/teacher/profile" style="margin-top:16px">
    <?= csrf_field() ?>
    <div class="form-grid">
        <label>
            Nama
            <input type="text" name="name" value="<?= esc(old('name', $teacher['name'] ?? '')) ?>" required minlength="3" maxlength="140">
        </label>
        <label>
            Email
            <input type="email" value="<?= esc($teacher['email'] ?? '') ?>" disabled>
            <span class="muted">Email tidak bisa diubah dari sini.</span>
        </label>
        <label class="full">
            Nama sekolah
            <input type="text" name="school_name" value="<?= esc(old('school_name', $teacher['school_name'] ?? '')) ?>" maxlength="190">
        </label>
    </div>

    <h2 style="margin-top:24px">Ganti password (opsional)</h2>
    <div class="form-grid">
        <label>
            Password saat ini
            <input type="password" name="current_password" autocomplete="current-password">
        </label>
        <label>
            Password baru
            <input type="password" name="password" autocomplete="new-password" minlength="10" maxlength="128">
        </label>
        <label>
            Konfirmasi password baru
            <input type="password" name="password_confirm" autocomplete="new-password" minlength="10" maxlength="128">
        </label>
    </div>

    <div style="margin-top:16px">
        <button class="button" type="submit">Simpan Profil</button>
    </div>
</form>
<?= $this->endSection() ?>
