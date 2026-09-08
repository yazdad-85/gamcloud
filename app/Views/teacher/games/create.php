<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<?php
    $selectedTeacherId = old('teacher_id');
    $initialQuestionSummary = $questionBankSummary ?? null;
    if (! empty($isSuperadmin) && $selectedTeacherId !== null && isset($questionBankSummaries[(int) $selectedTeacherId])) {
        $initialQuestionSummary = $questionBankSummaries[(int) $selectedTeacherId];
    }
    $emptyQuestionSummary = [
        'total' => 0,
        'difficulty' => ['EASY' => 0, 'MEDIUM' => 0, 'HARD' => 0],
        'type' => [],
        'zone_strategy_ready' => false,
    ];
    $initialQuestionSummary ??= $emptyQuestionSummary;
?>
<div class="topbar">
    <div>
        <h1 class="page-title">Buat Game</h1>
        <p class="muted">Room memakai bank soal guru pemilik room dan akan mendapat PIN join.</p>
    </div>
</div>

<section class="panel" style="max-width:760px">
    <?php if (session('error') !== null): ?>
        <div class="alert" style="margin-bottom:14px"><?= esc(session('error')) ?></div>
    <?php endif ?>

    <form class="form" method="post" action="/teacher/games">
        <?= csrf_field() ?>
        <?php if (! empty($isSuperadmin)): ?>
            <div class="field">
                <label for="teacher_id">Guru Pemilik Room</label>
                <select id="teacher_id" name="teacher_id" required>
                    <option value="">Pilih guru</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= esc((string) $teacher['id']) ?>" <?= old('teacher_id') == $teacher['id'] ? 'selected' : '' ?>>
                            <?= esc($teacher['name']) ?><?= $teacher['email'] ? ' - ' . esc($teacher['email']) : '' ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </div>
        <?php endif ?>
        <div class="field">
            <label for="title">Judul Game</label>
            <input id="title" name="title" placeholder="Contoh: Review IPA Kelas 6">
        </div>
        <div class="question-bank-panel" data-question-bank>
            <div>
                <h2>Bank Soal Yang Dipakai</h2>
                <p class="muted">Semua soal published milik guru pemilik room akan dipakai dalam game ini.</p>
            </div>
            <div class="question-bank-metrics">
                <div>
                    <span>Total</span>
                    <strong data-bank-total><?= esc((string) $initialQuestionSummary['total']) ?></strong>
                </div>
                <div>
                    <span>Easy</span>
                    <strong data-bank-easy><?= esc((string) $initialQuestionSummary['difficulty']['EASY']) ?></strong>
                </div>
                <div>
                    <span>Medium</span>
                    <strong data-bank-medium><?= esc((string) $initialQuestionSummary['difficulty']['MEDIUM']) ?></strong>
                </div>
                <div>
                    <span>Hard</span>
                    <strong data-bank-hard><?= esc((string) $initialQuestionSummary['difficulty']['HARD']) ?></strong>
                </div>
            </div>
            <p class="field-help" data-bank-note>
                <?= $initialQuestionSummary['zone_strategy_ready']
                    ? 'Stok soal sudah siap untuk difficulty zone.'
                    : 'Jika salah satu difficulty kosong, game otomatis mengambil soal published lain sebagai fallback.' ?>
            </p>
        </div>
        <div class="field">
            <label>Pengambilan Soal</label>
            <div class="check-grid">
                <label class="check-option">
                    <input type="radio" name="question_selection_strategy" value="difficulty_zone" <?= old('question_selection_strategy', 'difficulty_zone') === 'difficulty_zone' ? 'checked' : '' ?>>
                    <span>Zona difficulty</span>
                </label>
                <label class="check-option">
                    <input type="radio" name="question_selection_strategy" value="random" <?= old('question_selection_strategy') === 'random' ? 'checked' : '' ?>>
                    <span>Acak semua soal</span>
                </label>
            </div>
            <p class="field-help">Zona difficulty: kotak 1-30 EASY, 31-70 MEDIUM, 71-100 HARD. Jika stok zona kosong, game fallback ke soal published lain.</p>
        </div>
        <div class="field">
            <label>Mode Game</label>
            <div class="mode-grid">
                <?php foreach ($gameModes as $mode): ?>
                    <?php $selected = old('game_mode', 'SNAKES_LADDERS') === $mode['key']; ?>
                    <label class="mode-option <?= $mode['playable'] ? '' : 'is-disabled' ?>">
                        <input
                            type="radio"
                            name="game_mode"
                            value="<?= esc($mode['key']) ?>"
                            <?= $selected && $mode['playable'] ? 'checked' : '' ?>
                            <?= $mode['playable'] ? '' : 'disabled' ?>
                        >
                        <strong><?= esc($mode['label']) ?></strong>
                        <span><?= $mode['playable'] ? 'Siap dimainkan' : 'Tahap berikutnya' ?></span>
                    </label>
                <?php endforeach ?>
            </div>
            <p class="field-help">Mode lain disiapkan sebagai fondasi platform, tetapi room aktif saat ini tetap memakai ular tangga kuis.</p>
        </div>
        <div class="field">
            <label for="board_template_id">Tema Papan</label>
            <select id="board_template_id" name="board_template_id">
                <option value="">Pilih otomatis</option>
                <?php foreach ($boards as $board): ?>
                    <?php $theme = json_decode((string) ($board['theme_json'] ?? ''), true) ?: []; ?>
                    <option value="<?= esc((string) $board['id']) ?>" <?= old('board_template_id') == $board['id'] ? 'selected' : '' ?>>
                        <?= esc($theme['name'] ?? $board['name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <p class="field-help">Tema memengaruhi suasana papan, bukan mengganti soal satu per satu.</p>
        </div>
        <div class="field">
            <label for="turn_order_mode">Giliran Pertama</label>
            <select id="turn_order_mode" name="turn_order_mode">
                <option value="random" <?= old('turn_order_mode', 'random') === 'random' ? 'selected' : '' ?>>Acak otomatis</option>
                <option value="join_order" <?= old('turn_order_mode') === 'join_order' ? 'selected' : '' ?>>Tim yang join lebih dulu</option>
            </select>
            <p class="field-help">Mode acak lebih adil untuk kelas karena tidak bergantung urutan masuk lobby.</p>
        </div>
        <div class="field">
            <label for="finish_rule">Aturan Finish</label>
            <select id="finish_rule" name="finish_rule">
                <option value="clamp_finish" <?= old('finish_rule', 'clamp_finish') === 'clamp_finish' ? 'selected' : '' ?>>Langsung finish jika melewati kotak akhir</option>
                <option value="exact_finish" <?= old('finish_rule') === 'exact_finish' ? 'selected' : '' ?>>Harus pas, jika lebih akan memantul mundur</option>
            </select>
            <p class="field-help">Mode harus pas membuat akhir permainan lebih tegang.</p>
        </div>
        <div class="field">
            <label>Scoring Tension</label>
            <div class="check-grid">
                <label class="check-option">
                    <input type="checkbox" name="time_bonus" value="1" <?= old('time_bonus', '1') === '1' ? 'checked' : '' ?>>
                    <span>Bonus jawab cepat</span>
                </label>
                <label class="check-option">
                    <input type="checkbox" name="streak_bonus" value="1" <?= old('streak_bonus', '1') === '1' ? 'checked' : '' ?>>
                    <span>Bonus streak benar</span>
                </label>
                <label class="check-option">
                    <input type="checkbox" name="near_finish_bonus" value="1" <?= old('near_finish_bonus', '1') === '1' ? 'checked' : '' ?>>
                    <span>Bonus dekat finish</span>
                </label>
                <label class="check-option">
                    <input type="checkbox" name="wrong_penalty" value="1" <?= old('wrong_penalty') === '1' ? 'checked' : '' ?>>
                    <span>Penalti jawaban salah</span>
                </label>
                <label class="check-option">
                    <input type="checkbox" name="timeout_penalty" value="1" <?= old('timeout_penalty') === '1' ? 'checked' : '' ?>>
                    <span>Penalti waktu habis</span>
                </label>
            </div>
            <p class="field-help">Bonus aktif default. Penalti opsional agar guru bisa menyesuaikan suasana kelas.</p>
        </div>
        <button class="button" type="submit">Buat Room</button>
    </form>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if (! empty($isSuperadmin)): ?>
<script type="application/json" id="question-bank-summaries"><?= json_encode($questionBankSummaries, JSON_UNESCAPED_SLASHES) ?></script>
<script>
(function () {
    const select = document.querySelector('#teacher_id');
    const source = document.querySelector('#question-bank-summaries');
    if (!select || !source) {
        return;
    }

    const summaries = JSON.parse(source.textContent || '{}');
    const emptySummary = <?= json_encode($emptyQuestionSummary, JSON_UNESCAPED_SLASHES) ?>;
    const total = document.querySelector('[data-bank-total]');
    const easy = document.querySelector('[data-bank-easy]');
    const medium = document.querySelector('[data-bank-medium]');
    const hard = document.querySelector('[data-bank-hard]');
    const note = document.querySelector('[data-bank-note]');

    function draw() {
        const summary = summaries[select.value] || emptySummary;
        total.textContent = summary.total || 0;
        easy.textContent = summary.difficulty && summary.difficulty.EASY || 0;
        medium.textContent = summary.difficulty && summary.difficulty.MEDIUM || 0;
        hard.textContent = summary.difficulty && summary.difficulty.HARD || 0;
        note.textContent = summary.zone_strategy_ready
            ? 'Stok soal sudah siap untuk difficulty zone.'
            : 'Jika salah satu difficulty kosong, game otomatis mengambil soal published lain sebagai fallback.';
    }

    select.addEventListener('change', draw);
    draw();
})();
</script>
<?php endif ?>
<?= $this->endSection() ?>
