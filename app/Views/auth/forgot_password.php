<?php $branding = (new \App\Services\Platform\PlatformSettingsService())->branding(); ?>
<?php $this->setData([
    'title' => 'Lupa Password - ' . $branding['site_name'],
    'publicPageClass' => 'public-page-scroll',
]); ?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>
<section class="registration-page compact-registration">
    <div class="registration-copy">
        <?= $this->include('partials/brand_logo', ['branding' => $branding, 'href' => '/', 'markClass' => 'dark-mark']) ?>
        <p class="login-eyebrow">Pemulihan akun</p>
        <h1>Buat password baru melalui email.</h1>
        <p>Tautan pemulihan hanya dapat digunakan satu kali dan berlaku selama satu jam.</p>
    </div>

    <aside class="login-card register-card">
        <div class="login-card-head">
            <span class="login-lock">Lupa password</span>
            <h2>Pulihkan Akun</h2>
            <p>Masukkan email akun guru yang sudah aktif.</p>
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

        <?php if (session('message') !== null): ?>
            <div class="alert success-alert"><?= esc(session('message')) ?></div>
        <?php endif ?>

        <form class="form login-form" action="/lupa-password" method="post">
            <?= csrf_field() ?>
            <div class="field floating-field">
                <label for="email">Email Akun Guru</label>
                <input id="email" type="email" name="email" inputmode="email" autocomplete="email" value="<?= esc(old('email')) ?>" maxlength="190" required>
            </div>
            <button class="button login-submit" type="submit">Kirim Tautan Pemulihan</button>
        </form>

        <p class="auth-switch">Belum verifikasi email? <a href="/daftar-guru/verifikasi">Lanjutkan verifikasi</a></p>
        <p class="auth-switch"><a href="/login">Kembali ke login</a></p>
    </aside>
</section>
<?= $this->endSection() ?>
