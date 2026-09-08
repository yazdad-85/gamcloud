<?php $this->setData(['title' => 'Daftar Akun Guru', 'publicPageClass' => 'public-page-scroll']); ?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>
<section class="registration-page">
    <div class="registration-copy">
        <a class="brand-mark dark-mark" href="/login" aria-label="Ruang Main Guru"><span>RG</span></a>
        <p class="login-eyebrow">Daftar akun guru</p>
        <h1>Mulai siapkan kelas yang bermain sambil berpikir.</h1>
        <p>
            Setelah email terverifikasi, akun guru langsung aktif dan siap dipakai untuk membuat kelas bermain.
        </p>
        <div class="step-list">
            <span>1. Isi data guru</span>
            <span>2. Masukkan kode email</span>
            <span>3. Login ke dashboard</span>
        </div>
    </div>

    <aside class="login-card register-card">
        <div class="login-card-head">
            <span class="login-lock">Verifikasi email</span>
            <h2>Ajukan Akun</h2>
            <p>Gunakan email aktif karena kode verifikasi dikirim ke alamat ini.</p>
        </div>

        <?php if (session('error') !== null): ?>
            <div class="alert"><?= esc(session('error')) ?></div>
        <?php elseif (session('errors') !== null): ?>
            <div class="alert">
                <?php foreach ((array) session('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach ?>
            </div>
        <?php endif ?>

        <form class="form login-form" action="/daftar-guru" method="post">
            <?= csrf_field() ?>
            <div class="field floating-field">
                <label for="name">Nama Guru</label>
                <input id="name" name="name" value="<?= esc(old('name')) ?>" autocomplete="name" maxlength="140" required>
            </div>
            <div class="field floating-field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" inputmode="email" autocomplete="email" value="<?= esc(old('email')) ?>" maxlength="190" required>
            </div>
            <div class="field floating-field">
                <label for="school_name">Instansi</label>
                <input id="school_name" name="school_name" value="<?= esc(old('school_name')) ?>" maxlength="190" placeholder="Sekolah, madrasah, lembaga, atau komunitas">
            </div>
            <div class="field floating-field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" autocomplete="new-password" minlength="10" maxlength="128" required>
                <p class="field-help">Minimal 10 karakter.</p>
            </div>
            <div class="field floating-field">
                <label for="password_confirm">Ulangi Password</label>
                <input id="password_confirm" type="password" name="password_confirm" autocomplete="new-password" minlength="10" maxlength="128" required>
            </div>
            <button class="button login-submit" type="submit">Kirim Kode Verifikasi</button>
        </form>

        <p class="auth-switch">Sudah punya akun? <a href="/login">Login guru</a></p>
    </aside>
</section>
<?= $this->endSection() ?>
