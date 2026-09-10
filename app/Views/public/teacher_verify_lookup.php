<?php $branding = (new \App\Services\Platform\PlatformSettingsService())->branding(); ?>
<?php $this->setData([
    'title' => 'Lanjutkan Verifikasi Email - ' . $branding['site_name'],
    'publicPageClass' => 'public-page-scroll',
]); ?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>
<section class="registration-page compact-registration">
    <div class="registration-copy">
        <?= $this->include('partials/brand_logo', ['branding' => $branding, 'href' => '/', 'markClass' => 'dark-mark']) ?>
        <p class="login-eyebrow">Verifikasi akun</p>
        <h1>Lanjutkan verifikasi email guru.</h1>
        <p>Masukkan email yang digunakan saat mendaftar untuk kembali ke pengajuan Anda dan meminta kode baru.</p>
    </div>

    <aside class="login-card register-card">
        <div class="login-card-head">
            <span class="login-lock">Akun belum aktif</span>
            <h2>Cari Pengajuan</h2>
            <p>Kami akan membuka kembali langkah verifikasi yang belum selesai.</p>
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

        <form class="form login-form" action="/daftar-guru/verifikasi" method="post">
            <?= csrf_field() ?>
            <div class="field floating-field">
                <label for="email">Email Pendaftaran</label>
                <input id="email" type="email" name="email" inputmode="email" autocomplete="email" value="<?= esc(old('email')) ?>" maxlength="190" required>
            </div>
            <button class="button login-submit" type="submit">Lanjutkan Verifikasi</button>
        </form>

        <p class="auth-switch">Akun sudah aktif? <a href="/login">Login guru</a></p>
    </aside>
</section>
<?= $this->endSection() ?>
