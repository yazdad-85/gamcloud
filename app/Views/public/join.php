<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>
<?php $branding = (new \App\Services\Platform\PlatformSettingsService())->branding(); ?>
<section class="panel join-panel">
    <div style="margin-bottom:16px">
        <?= $this->include('partials/brand_logo', ['branding' => $branding, 'href' => '/', 'markClass' => 'dark-mark']) ?>
    </div>
    <h1 class="page-title">Join Tim</h1>
    <p class="muted">Masukkan PIN room dan nama tim.</p>
    <?php if ($error): ?>
        <div class="alert"><?= esc($error) ?></div>
    <?php endif ?>

    <div class="rules-box" data-game-rules>
        <h2>Aturan Permainan</h2>
        <ul>
            <li>Ini permainan <strong>ular tangga kuis</strong>. Pemenang utama: tim yang <strong>pertama sampai kotak finish</strong>.</li>
            <li><strong>Skor</strong> mengukur prestasi menjawab; skor tinggi tidak menggantikan juara papan.</li>
            <li>Lempar dadu → jawab soal. <strong>Jawaban salah atau waktu habis: pion menetap.</strong> Jawaban benar: pion maju sesuai dadu.</li>
            <li>Mendarat di <strong>ular</strong>: soal <strong>sulit (HARD)</strong> untuk menyelamatkan diri. Benar = bertahan; salah = turun.</li>
            <li>Mendarat di <strong>tangga</strong>: soal <strong>sulit (HARD)</strong> untuk naik. Benar = naik; salah = tetap di pangkal.</li>
            <li>Jawablah jujur sesuai pengetahuan — tipu-tipu merugikan belajar dan semangat fair play.</li>
            <li>Ikuti arahan guru di layar projector.</li>
        </ul>
    </div>

    <form class="form" method="post" action="/join" data-join-form>
        <?= csrf_field() ?>
        <div class="field">
            <label for="pin">PIN</label>
            <input id="pin" name="pin" value="<?= esc($pin ?? old('pin')) ?>" inputmode="numeric" autocomplete="off" required>
        </div>
        <div class="field">
            <label for="team_name">Nama Tim</label>
            <input id="team_name" name="team_name" value="<?= esc(old('team_name')) ?>" maxlength="80" required>
        </div>
        <div class="field">
            <label for="avatar">Avatar Tim</label>
            <select id="avatar" name="avatar">
                <?php foreach (['robot' => 'Robot', 'explorer' => 'Explorer', 'rocket' => 'Rocket', 'knight' => 'Knight', 'scientist' => 'Scientist', 'runner' => 'Runner'] as $value => $label): ?>
                    <option value="<?= esc($value) ?>" <?= old('avatar', 'robot') === $value ? 'selected' : '' ?>>
                        <?= esc($label) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label>
                <input type="checkbox" name="rules_accepted" value="1" data-rules-accepted required>
                Saya sudah membaca dan memahami aturan permainan.
            </label>
        </div>
        <button class="button" type="submit" data-join-submit disabled>Masuk</button>
    </form>
</section>
<script>
(function () {
    var box = document.querySelector('[data-rules-accepted]');
    var button = document.querySelector('[data-join-submit]');
    if (!box || !button) return;
    function sync() { button.disabled = !box.checked; }
    box.addEventListener('change', sync);
    sync();
})();
</script>
<?= $this->endSection() ?>
