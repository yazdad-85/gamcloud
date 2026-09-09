<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>
<?php $branding = (new \App\Services\Platform\PlatformSettingsService())->branding(); ?>
<div class="landing">
    <header class="landing-top">
        <a class="landing-brand" href="/">
            <img src="<?= esc($branding['logo_path']) ?>" width="220" height="44" alt="<?= esc($branding['site_name']) ?>">
        </a>
        <nav class="landing-nav" aria-label="Navigasi utama">
            <a href="/join">Join Tim</a>
            <a href="/login">Login</a>
            <a class="button landing-nav-cta" href="/daftar-guru">Daftar Guru</a>
        </nav>
    </header>

    <section class="landing-hero" aria-label="Beranda">
        <div class="landing-hero-copy">
            <p class="landing-eyebrow"><?= esc($branding['site_name']) ?></p>
            <h1>Kuis kelas yang bergerak di papan permainan.</h1>
            <p class="landing-lead">
                Guru menyiapkan soal, siswa bermain sebagai tim, proyektor menampilkan papan —
                belajar tetap seru tanpa kehilangan arah.
            </p>
            <div class="landing-cta">
                <a class="button" href="/daftar-guru">Daftar Guru</a>
                <a class="button secondary" href="/login">Login Guru</a>
                <a class="button secondary" href="/join">Join Tim</a>
            </div>
        </div>
        <div class="landing-hero-visual" aria-hidden="true">
            <div class="landing-board">
                <?php for ($i = 1; $i <= 16; $i++): ?>
                    <span class="landing-tile<?= in_array($i, [3, 12], true) ? ' is-ladder' : '' ?><?= in_array($i, [7, 14], true) ? ' is-snake' : '' ?><?= $i === 16 ? ' is-finish' : '' ?><?= $i === 6 ? ' is-active' : '' ?>"><?= $i ?></span>
                <?php endfor ?>
            </div>
        </div>
    </section>

    <section class="landing-benefits" aria-label="Manfaat">
        <h2>Satu alat untuk kelas yang hidup</h2>
        <p class="landing-benefits-lead">Fokus pada tiga hal yang guru butuhkan saat bermain.</p>
        <ul>
            <li>
                <strong>Bank soal milik guru</strong>
                <span>Susun dan pakai soal sendiri di setiap room.</span>
            </li>
            <li>
                <strong>Proyektor kelas</strong>
                <span>Papan, giliran, dan skor terbaca jelas dari depan ruang.</span>
            </li>
            <li>
                <strong>Laporan hasil</strong>
                <span>Lihat pemenang, analisis soal, dan unduh PDF.</span>
            </li>
        </ul>
    </section>

    <footer class="landing-foot">
        <p>© <?= esc(date('Y')) ?> Ular Tangga Edukatif</p>
        <p><a href="/login">Masuk ke ruang guru</a></p>
    </footer>
</div>
<?= $this->endSection() ?>
