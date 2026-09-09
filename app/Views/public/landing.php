<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>
<?php $branding = (new \App\Services\Platform\PlatformSettingsService())->branding(); ?>
<section class="login-landing" aria-label="Beranda platform">
    <div class="login-hero">
        <?= $this->include('partials/brand_logo', ['branding' => $branding, 'href' => '/']) ?>
        <p class="login-eyebrow"><?= esc($branding['tagline'] !== '' ? $branding['tagline'] : $branding['site_name']) ?></p>
        <h1>Game kuis kelas yang interaktif dan siap berkembang.</h1>
        <p class="login-copy">
            Guru menyiapkan soal, siswa bermain sebagai tim, proyektor menampilkan permainan —
            belajar tetap seru tanpa kehilangan arah. Mulai dari ular tangga, mode lain menyusul.
        </p>

        <div class="game-preview" aria-hidden="true">
            <div class="preview-board">
                <span class="tile glow">1</span>
                <span class="tile">2</span>
                <span class="tile ladder">3</span>
                <span class="tile">4</span>
                <span class="tile snake">5</span>
                <span class="tile active">6</span>
                <span class="tile">7</span>
                <span class="tile finish">8</span>
            </div>
            <div class="preview-card">
                <span class="preview-pill">Giliran Tim A</span>
                <strong>4</strong>
                <small>Dadu bergerak, pertanyaan terbuka</small>
            </div>
        </div>

        <div class="feature-strip" aria-label="Fitur utama">
            <div>
                <strong>Bank soal milik guru</strong>
                <span>Susun dan pakai soal sendiri di setiap room.</span>
            </div>
            <div>
                <strong>Proyektor kelas</strong>
                <span>Papan, giliran, dan skor terbaca jelas dari depan ruang.</span>
            </div>
            <div>
                <strong>Laporan hasil</strong>
                <span>Lihat pemenang, analisis soal, dan unduh PDF.</span>
            </div>
        </div>
    </div>

    <aside class="login-card" aria-label="Mulai sekarang">
        <div class="login-card-head">
            <span class="login-lock">Untuk guru &amp; kelas</span>
            <h2>Mulai di sini</h2>
            <p><?= esc($branding['seo_description']) ?></p>
        </div>

        <div class="landing-actions">
            <a class="button login-submit" href="/daftar-guru">
                <span>Daftar Guru</span>
                <span aria-hidden="true">></span>
            </a>
            <a class="button secondary landing-action-secondary" href="/login">Login Guru</a>
            <a class="button secondary landing-action-secondary" href="/join">Join Tim</a>
        </div>

        <div class="login-note">
            <span></span>
            <p>Sudah punya akun? Masuk lewat Login Guru. Tim siswa memakai PIN room di Join Tim.</p>
        </div>

        <p class="auth-switch">© <?= esc(date('Y')) ?> <?= esc($branding['site_name']) ?></p>
    </aside>
</section>
<?= $this->endSection() ?>
