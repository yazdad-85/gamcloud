<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<div class="topbar">
    <div>
        <h1 class="page-title">Profil Superadmin</h1>
        <p class="muted">Perbarui nama tampilan, email, dan password akun admin.</p>
    </div>
    <a class="button secondary" href="/superadmin">Kembali</a>
</div>

<?php if (! empty($message)): ?>
    <div class="alert success"><?= esc($message) ?></div>
<?php endif ?>
<?php if (! empty($error)): ?>
    <div class="alert"><?= esc($error) ?></div>
<?php endif ?>

<form class="panel" method="post" action="/superadmin/profile" style="margin-top:16px">
    <?= csrf_field() ?>
    <div class="form-grid">
        <label>
            Username / nama tampilan
            <input type="text" name="username" value="<?= esc(old('username', (string) ($user->username ?? ''))) ?>" required minlength="3" maxlength="80">
        </label>
        <label>
            Email
            <input type="email" name="email" value="<?= esc(old('email', (string) ($user->getEmail() ?? ''))) ?>" required maxlength="190">
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
