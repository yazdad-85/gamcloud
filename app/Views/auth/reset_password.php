<?php $branding = (new \App\Services\Platform\PlatformSettingsService())->branding(); ?>
<?php $this->setData([
    'title' => 'Buat Password Baru - ' . $branding['site_name'],
    'publicPageClass' => 'public-page-scroll',
]); ?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>
<section class="registration-page compact-registration">
    <div class="registration-copy">
        <?= $this->include('partials/brand_logo', ['branding' => $branding, 'href' => '/', 'markClass' => 'dark-mark']) ?>
        <p class="login-eyebrow">Pemulihan akun</p>
        <h1>Tentukan password baru.</h1>
        <p>Gunakan minimal 10 karakter dan simpan password di tempat yang aman.</p>
    </div>

    <aside class="login-card register-card">
        <div class="login-card-head">
            <span class="login-lock">Password baru</span>
            <h2>Perbarui Password</h2>
        </div>

        <?php if (session('error') !== null): ?>
            <div class="alert"><?= esc(session('error')) ?></div>
        <?php endif ?>

        <?php if (! $tokenValid): ?>
            <div class="alert">Tautan pemulihan tidak valid atau sudah kedaluwarsa.</div>
            <a class="button login-submit" href="/lupa-password">Minta Tautan Baru</a>
        <?php else: ?>
            <form class="form login-form" action="/lupa-password/reset" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= esc($token) ?>">
                <div class="field floating-field">
                    <label for="password">Password Baru</label>
                    <input id="password" type="password" name="password" autocomplete="new-password" minlength="10" maxlength="128" required>
                </div>
                <div class="field floating-field">
                    <label for="password_confirm">Ulangi Password Baru</label>
                    <input id="password_confirm" type="password" name="password_confirm" autocomplete="new-password" minlength="10" maxlength="128" required>
                </div>
                <button class="button login-submit" type="submit">Simpan Password Baru</button>
            </form>
        <?php endif ?>

        <p class="auth-switch"><a href="/login">Kembali ke login</a></p>
    </aside>
</section>
<?= $this->endSection() ?>
