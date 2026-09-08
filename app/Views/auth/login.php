<?php $this->setData(['title' => 'Ruang Main Guru']); ?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>
<section class="login-landing" aria-label="Platform game kuis kelas">
    <div class="login-hero">
        <div class="brand-mark" aria-label="Ruang Main Guru">
            <span>RG</span>
        </div>
        <p class="login-eyebrow">Platform game kuis untuk kelas yang hidup</p>
        <h1>Ubah bank soal menjadi permainan yang ditunggu siswa.</h1>
        <p class="login-copy">
            Guru menyiapkan pertanyaan, siswa masuk sebagai tim, lalu kelas bergerak dalam tantangan, skor,
            papan bermain, dan momen seru yang tetap terarah.
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
                <strong>Bank soal guru</strong>
                <span>Materi tersimpan rapi per akun.</span>
            </div>
            <div>
                <strong>Mode tim</strong>
                <span>Siswa bermain bersama di kelas.</span>
            </div>
            <div>
                <strong>Banyak game</strong>
                <span>Ular tangga sekarang, mode lain berikutnya.</span>
            </div>
        </div>
    </div>

    <aside class="login-card" aria-label="Login guru">
        <div class="login-card-head">
            <span class="login-lock">Masuk aman</span>
            <h2>Login Guru</h2>
            <p>Kelola bank soal, buat room, dan mulai permainan dari dashboard Anda.</p>
        </div>

        <?php if (session('error') !== null): ?>
            <div class="alert"><?= esc(session('error')) ?></div>
        <?php elseif (session('errors') !== null): ?>
            <div class="alert">
                <?php if (is_array(session('errors'))): ?>
                    <?php foreach (session('errors') as $error): ?>
                        <?= esc($error) ?><br>
                    <?php endforeach ?>
                <?php else: ?>
                    <?= esc(session('errors')) ?>
                <?php endif ?>
            </div>
        <?php endif ?>

        <?php if (session('message') !== null): ?>
            <div class="alert success-alert"><?= esc(session('message')) ?></div>
        <?php endif ?>

        <form class="form login-form" action="<?= url_to('login') ?>" method="post">
            <?= csrf_field() ?>
            <div class="field floating-field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" inputmode="email" autocomplete="email" value="<?= esc(old('email')) ?>" placeholder="guru@sekolah.sch.id" required>
            </div>
            <div class="field floating-field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" autocomplete="current-password" placeholder="Masukkan password" required>
            </div>
            <button class="button login-submit" type="submit">
                <span>Masuk ke Dashboard</span>
                <span aria-hidden="true">></span>
            </button>
        </form>

        <div class="login-note">
            <span></span>
            <p>Setiap guru hanya melihat soal dan room miliknya sendiri.</p>
        </div>

        <p class="auth-switch">Belum punya akun? <a href="/daftar-guru">Ajukan akun guru</a></p>
    </aside>
</section>
<?= $this->endSection() ?>
