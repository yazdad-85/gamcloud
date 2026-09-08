<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>
<section class="panel join-panel">
    <h1 class="page-title">Join Ular Tangga</h1>
    <p class="muted">Masukkan PIN room dan nama tim.</p>
    <?php if ($error): ?>
        <div class="alert"><?= esc($error) ?></div>
    <?php endif ?>
    <form class="form" method="post" action="/join">
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
        <button class="button" type="submit">Masuk</button>
    </form>
</section>
<?= $this->endSection() ?>
