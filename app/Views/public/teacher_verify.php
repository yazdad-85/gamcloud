<?php $this->setData(['title' => 'Verifikasi Email Guru', 'publicPageClass' => 'public-page-scroll']); ?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>
<section class="registration-page compact-registration">
    <div class="registration-copy">
        <a class="brand-mark dark-mark" href="/login" aria-label="Ruang Main Guru"><span>RG</span></a>
        <p class="login-eyebrow">Verifikasi email</p>
        <h1>Kami perlu memastikan email guru benar-benar aktif.</h1>
        <p>Kode berlaku 15 menit. Setelah berhasil, akun guru langsung aktif dan bisa digunakan.</p>
    </div>

    <aside class="login-card register-card">
        <?php if ($request === null): ?>
            <div class="login-card-head">
                <span class="login-lock">Tidak ditemukan</span>
                <h2>Pengajuan Tidak Ada</h2>
                <p>Link verifikasi tidak valid atau pengajuan sudah tidak tersedia.</p>
            </div>
            <a class="button login-submit" href="/daftar-guru">Ajukan Akun Baru</a>
        <?php else: ?>
            <div class="login-card-head">
                <span class="login-lock"><?= esc($request['status']) ?></span>
                <h2><?= esc($request['name']) ?></h2>
                <p><?= esc($request['email']) ?></p>
            </div>

            <?php if (session('error') !== null): ?>
                <div class="alert"><?= esc(session('error')) ?></div>
            <?php endif ?>

            <?php if (session('message') !== null): ?>
                <div class="alert success-alert"><?= esc(session('message')) ?></div>
            <?php endif ?>

            <?php if ($request['status'] === 'PENDING_EMAIL'): ?>
                <form class="form login-form" action="/daftar-guru/verifikasi/<?= esc($uuid) ?>" method="post">
                    <?= csrf_field() ?>
                    <div class="field floating-field">
                        <label for="code">Kode 6 Digit</label>
                        <input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" value="<?= esc(old('code')) ?>" required>
                    </div>
                    <button class="button login-submit" type="submit">Verifikasi Email</button>
                </form>

                <form action="/daftar-guru/resend/<?= esc($uuid) ?>" method="post">
                    <?= csrf_field() ?>
                    <button class="button secondary full-button" type="submit">Kirim Ulang Kode</button>
                </form>
            <?php elseif ($request['status'] === 'EMAIL_VERIFIED'): ?>
                <div class="login-note">
                    <span></span>
                    <p>Email sudah valid. Akun guru sedang diaktifkan otomatis.</p>
                </div>
            <?php elseif ($request['status'] === 'APPROVED'): ?>
                <div class="login-note">
                    <span></span>
                    <p>Akun sudah aktif. Silakan login dengan email dan password yang Anda daftarkan.</p>
                </div>
                <a class="button login-submit" href="/login">Login Guru</a>
            <?php else: ?>
                <div class="alert">Pengajuan ini ditolak. Hubungi pengelola aplikasi jika perlu klarifikasi.</div>
            <?php endif ?>
        <?php endif ?>
    </aside>
</section>
<?= $this->endSection() ?>
