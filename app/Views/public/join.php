<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>
<?php
$branding = (new \App\Services\Platform\PlatformSettingsService())->branding();
$joinRules = $joinRules ?? [
    'mode_label' => 'Game Kuis Kelas',
    'summary' => 'Masukkan PIN room dari guru dan baca aturan permainan sebelum masuk.',
    'can_join' => true,
    'items' => [],
];
$joinRoom = $joinRoom ?? null;
$canJoin = (bool) ($joinRules['can_join'] ?? true);
?>
<section class="panel join-panel">
    <div style="margin-bottom:16px">
        <?= $this->include('partials/brand_logo', ['branding' => $branding, 'href' => '/', 'markClass' => 'dark-mark']) ?>
    </div>
    <h1 class="page-title">Join Tim</h1>
    <p class="muted"><?= esc((string) ($joinRules['summary'] ?? 'Masukkan PIN room dan nama tim.')) ?></p>
    <?php if ($error): ?>
        <div class="alert"><?= esc($error) ?></div>
    <?php endif ?>
    <?php if ($joinRoom !== null): ?>
        <div class="alert success-alert">
            Room ditemukan: <strong><?= esc($joinRoom['title']) ?></strong>
            / Mode <strong><?= esc((string) ($joinRules['mode_label'] ?? 'Game Kuis')) ?></strong>
            / Status <strong><?= esc($joinRoom['status']) ?></strong>
        </div>
    <?php endif ?>

    <div class="rules-box" data-game-rules>
        <h2>Aturan <?= esc((string) ($joinRules['mode_label'] ?? 'Permainan')) ?></h2>
        <ul>
            <?php foreach ($joinRules['items'] ?? [] as $rule): ?>
                <li><?= esc($rule) ?></li>
            <?php endforeach ?>
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
            <input id="team_name" name="team_name" value="<?= esc(old('team_name')) ?>" maxlength="80" <?= $canJoin ? 'required' : 'disabled' ?>>
        </div>
        <div class="field">
            <label for="avatar">Avatar Tim</label>
            <select id="avatar" name="avatar" <?= $canJoin ? '' : 'disabled' ?>>
                <?php foreach (['robot' => 'Robot', 'explorer' => 'Explorer', 'rocket' => 'Rocket', 'knight' => 'Knight', 'scientist' => 'Scientist', 'runner' => 'Runner'] as $value => $label): ?>
                    <option value="<?= esc($value) ?>" <?= old('avatar', 'robot') === $value ? 'selected' : '' ?>>
                        <?= esc($label) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label>
                <input type="checkbox" name="rules_accepted" value="1" data-rules-accepted <?= $canJoin ? 'required' : 'disabled' ?>>
                Saya sudah membaca dan memahami aturan permainan.
            </label>
        </div>
        <button class="button" type="submit" data-join-submit data-can-join="<?= $canJoin ? '1' : '0' ?>" disabled><?= $canJoin ? 'Masuk' : 'Ikuti dari layar guru' ?></button>
    </form>
</section>
<script>
(function () {
    var box = document.querySelector('[data-rules-accepted]');
    var button = document.querySelector('[data-join-submit]');
    if (!box || !button) return;
    function sync() { button.disabled = button.dataset.canJoin !== '1' || !box.checked; }
    box.addEventListener('change', sync);
    sync();
})();
</script>
<?= $this->endSection() ?>
