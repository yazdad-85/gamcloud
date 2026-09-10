<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<?php
    $selectedTeacherId = old('teacher_id');
    $emptyQuestionSummary = [
        'total' => 0,
        'difficulty' => ['EASY' => 0, 'MEDIUM' => 0, 'HARD' => 0],
        'type' => [],
        'zone_strategy_ready' => false,
    ];
    $initialTopicCatalog = ! empty($isSuperadmin)
        ? ($questionTopicCatalogs[(int) $selectedTeacherId] ?? [])
        : ($questionTopicCatalog ?? []);
    $selectedTopicUuids = old('question_topic_uuids', []);
    $selectedTopicUuids = is_array($selectedTopicUuids) ? array_map('strval', $selectedTopicUuids) : [];
    $initialQuestionSummary = $emptyQuestionSummary;
    foreach ($initialTopicCatalog as $topic) {
        if (! in_array((string) $topic['uuid'], $selectedTopicUuids, true)) {
            continue;
        }
        $initialQuestionSummary['total'] += (int) $topic['summary']['total'];
        foreach (['EASY', 'MEDIUM', 'HARD'] as $difficulty) {
            $initialQuestionSummary['difficulty'][$difficulty] += (int) $topic['summary']['difficulty'][$difficulty];
        }
    }
    $initialQuestionSummary['zone_strategy_ready'] = $initialQuestionSummary['difficulty']['EASY'] > 0
        && $initialQuestionSummary['difficulty']['MEDIUM'] > 0
        && $initialQuestionSummary['difficulty']['HARD'] > 0;
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
        <div class="field">
            <label>Topik Soal</label>
            <div class="topic-selection-grid" data-topic-options>
                <?php foreach ($initialTopicCatalog as $topic): ?>
                    <?php $topicTotal = (int) $topic['summary']['total']; ?>
                    <label class="topic-selection-option <?= $topicTotal < 1 ? 'is-disabled' : '' ?>">
                        <input
                            type="checkbox"
                            name="question_topic_uuids[]"
                            value="<?= esc($topic['uuid']) ?>"
                            data-summary="<?= esc(json_encode($topic['summary'], JSON_UNESCAPED_SLASHES)) ?>"
                            <?= in_array((string) $topic['uuid'], $selectedTopicUuids, true) ? 'checked' : '' ?>
                            <?= $topicTotal < 1 ? 'disabled' : '' ?>
                        >
                        <span>
                            <strong><?= esc($topic['name']) ?></strong>
                            <small><?= esc((string) $topicTotal) ?> soal published</small>
                        </span>
                    </label>
                <?php endforeach ?>
                <?php if ($initialTopicCatalog === []): ?>
                    <p class="topic-selection-empty">Pilih guru untuk melihat topik, atau buat topik dan isi soal published terlebih dahulu.</p>
                <?php endif ?>
            </div>
            <p class="field-help">Pilih satu atau beberapa topik. Hanya soal published dari topik tersebut yang masuk ke room.</p>
        </div>
        <div class="question-bank-panel" data-question-bank>
            <div>
                <h2>Soal Dalam Room</h2>
                <p class="muted">Ringkasan otomatis mengikuti topik yang dipilih.</p>
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
                <?= $initialQuestionSummary['total'] < 1
                    ? 'Pilih topik yang memiliki soal published.'
                    : ($initialQuestionSummary['zone_strategy_ready']
                        ? 'Stok soal sudah siap untuk difficulty zone.'
                        : 'Difficulty yang kosong akan mengambil soal lain, tetapi tetap dari topik terpilih.') ?>
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
            <p class="field-help">Zona difficulty: 30% awal EASY, 40% tengah MEDIUM, dan 30% akhir HARD. Jika stok zona kosong, game fallback ke soal published lain dalam topik terpilih.</p>
            <p class="field-help">Soal muncul di setiap giliran lempar dadu, disesuaikan dengan kotak yang dituju dadu. Kotak BONUS/TRAP/SAFE/MYSTERY adalah efek tambahan yang berlaku setelah jawaban benar, bukan syarat munculnya soal.</p>
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
            <label>Mode Partisipasi Tim</label>
            <div class="check-grid">
                <label class="check-option">
                    <input type="radio" name="participation_mode" value="TEAM_DEVICE" <?= old('participation_mode', 'TEAM_DEVICE') === 'TEAM_DEVICE' ? 'checked' : '' ?>>
                    <span>Device per Tim</span>
                </label>
                <label class="check-option">
                    <input type="radio" name="participation_mode" value="TEACHER_CENTRALIZED" <?= old('participation_mode') === 'TEACHER_CENTRALIZED' ? 'checked' : '' ?>>
                    <span>Tanpa Device (Terpusat)</span>
                </label>
            </div>
            <p class="field-help">Tanpa Device: tidak ada join PIN, guru mengoperasikan dadu &amp; jawaban dari halaman Control Game (1 laptop + projector). Cocok untuk sekolah yang melarang HP siswa.</p>
        </div>
        <div class="field" data-snakes-only>
            <label>Tema Papan</label>
            <div class="theme-grid">
                <label class="theme-option">
                    <input type="radio" name="board_template_id" value="" <?= (string) old('board_template_id', '') === '' ? 'checked' : '' ?>>
                    <span class="theme-preview theme-preview-auto">Otomatis</span>
                    <strong>Pilih Otomatis</strong>
                    <span class="muted">Sistem pilih tema aktif pertama</span>
                </label>
                <?php foreach ($boards as $board): ?>
                    <?php
                        $theme = json_decode((string) ($board['theme_json'] ?? ''), true) ?: [];
                        $palette = $theme['palette'] ?? [];
                    ?>
                    <label class="theme-option">
                        <input type="radio" name="board_template_id" value="<?= esc((string) $board['id']) ?>" <?= (string) old('board_template_id') === (string) $board['id'] ? 'checked' : '' ?>>
                        <span class="theme-preview theme-preview-board" style="--preview-board:<?= esc($palette['board'] ?? '#111827') ?>;--preview-tile-a:<?= esc($palette['tileA'] ?? '#f8fafc') ?>;--preview-tile-b:<?= esc($palette['tileB'] ?? '#e0f2fe') ?>;--preview-snake:<?= esc($palette['snake'] ?? '#22c55e') ?>;--preview-ladder:<?= esc($palette['ladder'] ?? '#facc15') ?>;--preview-accent:<?= esc($palette['accent'] ?? '#f97316') ?>;background:<?= esc($palette['board'] ?? '#111827') ?>">
                            <span class="theme-preview-grid">
                                <?php for ($i = 0; $i < 16; $i++): ?>
                                    <span class="theme-preview-tile" style="background:<?= esc($i % 2 === 0 ? ($palette['tileA'] ?? '#f8fafc') : ($palette['tileB'] ?? '#e0f2fe')) ?>"></span>
                                <?php endfor ?>
                            </span>
                            <svg class="theme-preview-paths" viewBox="0 0 100 100" aria-hidden="true">
                                <path class="theme-preview-ladder-rail" d="M28 78 L72 22" />
                                <path class="theme-preview-ladder-rail" d="M36 80 L80 24" />
                                <path class="theme-preview-ladder-rung" d="M31 68 L39 70" />
                                <path class="theme-preview-ladder-rung" d="M42 52 L50 54" />
                                <path class="theme-preview-ladder-rung" d="M54 36 L62 38" />
                                <path class="theme-preview-snake-outline" d="M78 20 C58 34, 42 52, 24 78" />
                                <path class="theme-preview-snake-body" d="M78 20 C58 34, 42 52, 24 78" />
                                <ellipse class="theme-preview-snake-head" cx="78" cy="20" rx="7" ry="5" transform="rotate(-35 78 20)" />
                                <circle class="theme-preview-snake-eye" cx="80" cy="18" r="1.1" />
                                <path class="theme-preview-snake-tail" d="M24 78 L18 76 L16 78 L18 80 Z" />
                            </svg>
                        </span>
                        <strong><?= esc($theme['name'] ?? $board['name']) ?></strong>
                        <span class="muted"><?= esc((string) $board['tile_count']) ?> kotak</span>
                    </label>
                <?php endforeach ?>
            </div>
            <p class="field-help">Tema memengaruhi suasana papan (warna, ular, tangga), bukan mengganti soal satu per satu.</p>
        </div>
        <div class="field" data-snakes-only>
            <label for="mystery_tile_count">Jumlah Kotak Mystery</label>
            <input type="number" id="mystery_tile_count" name="mystery_tile_count" min="0" max="6" step="1" value="<?= esc((string) old('mystery_tile_count', 2)) ?>" required>
            <p class="field-help">Kotak Mystery ditempatkan acak di papan saat room dibuat, tidak menumpuk dengan kotak spesial lain.</p>
        </div>
        <div class="field" data-snakes-only>
            <label>Ukuran Papan</label>
            <div class="check-grid">
                <label class="check-option">
                    <input type="radio" name="board_size" value="50" <?= old('board_size') === '50' ? 'checked' : '' ?>>
                    <span>Kecil (50 kotak)</span>
                </label>
                <label class="check-option">
                    <input type="radio" name="board_size" value="70" <?= old('board_size') === '70' ? 'checked' : '' ?>>
                    <span>Sedang (70 kotak)</span>
                </label>
                <label class="check-option">
                    <input type="radio" name="board_size" value="100" <?= old('board_size', '100') === '100' ? 'checked' : '' ?>>
                    <span>Besar (100 kotak)</span>
                </label>
            </div>
            <p class="field-help">Ukuran memengaruhi jumlah kotak dan tata letak ular/tangga/kotak spesial, tidak mengganti tema warna papan.</p>
        </div>
        <div class="field">
            <label for="turn_order_mode">Giliran Pertama</label>
            <select id="turn_order_mode" name="turn_order_mode">
                <option value="random" <?= old('turn_order_mode', 'random') === 'random' ? 'selected' : '' ?>>Acak otomatis</option>
                <option value="join_order" <?= old('turn_order_mode') === 'join_order' ? 'selected' : '' ?>>Tim yang join lebih dulu</option>
            </select>
            <p class="field-help">Mode acak lebih adil untuk kelas karena tidak bergantung urutan masuk lobby.</p>
        </div>
        <div class="field" data-snakes-only>
            <label for="finish_rule">Aturan Finish</label>
            <select id="finish_rule" name="finish_rule">
                <option value="clamp_finish" <?= old('finish_rule', 'clamp_finish') === 'clamp_finish' ? 'selected' : '' ?>>Langsung finish jika melewati kotak akhir</option>
                <option value="exact_finish" <?= old('finish_rule') === 'exact_finish' ? 'selected' : '' ?>>Harus pas, jika lebih akan memantul mundur</option>
            </select>
            <p class="field-help">Mode harus pas membuat akhir permainan lebih tegang.</p>
        </div>
        <div class="field hidden" data-race-only>
            <label>Tema Lintasan</label>
            <div class="theme-grid">
                <label class="theme-option">
                    <input type="radio" name="board_template_id" value="" <?= (string) old('board_template_id', '') === '' ? 'checked' : '' ?>>
                    <span class="theme-preview theme-preview-auto">Otomatis</span>
                    <strong>Pilih Otomatis</strong>
                    <span class="muted">Sistem pilih tema lintasan pertama</span>
                </label>
                <?php foreach ($raceBoards as $board): ?>
                    <?php
                        $raceTheme = json_decode((string) ($board['theme_json'] ?? ''), true) ?: [];
                        $racePalette = $raceTheme['palette'] ?? [];
                    ?>
                    <label class="theme-option">
                        <input type="radio" name="board_template_id" value="<?= esc((string) $board['id']) ?>" <?= (string) old('board_template_id') === (string) $board['id'] ? 'checked' : '' ?>>
                        <span class="theme-preview theme-preview-track" style="background:<?= esc($racePalette['board'] ?? '#111827') ?>">
                            <span class="theme-preview-lane" style="background:<?= esc($racePalette['tileA'] ?? '#f8fafc') ?>"></span>
                            <span class="theme-preview-lane" style="background:<?= esc($racePalette['tileB'] ?? '#e0f2fe') ?>"></span>
                            <span class="theme-preview-finish" style="background:<?= esc($racePalette['accent'] ?? '#f97316') ?>"></span>
                        </span>
                        <strong><?= esc($raceTheme['name'] ?? $board['name']) ?></strong>
                        <span class="muted">Tema lintasan balap</span>
                    </label>
                <?php endforeach ?>
            </div>
            <p class="field-help">Tema memengaruhi suasana lintasan (warna &amp; nuansa), bukan mengganti soal.</p>
        </div>
        <div class="field hidden" data-race-only>
            <label for="track_length">Panjang Lintasan</label>
            <input type="number" id="track_length" name="track_length" min="6" max="60" step="1" value="<?= esc((string) old('track_length', 24)) ?>" required>
            <p class="field-help">Jumlah kotak dari garis start ke garis finish.</p>
        </div>
        <div class="field hidden" data-race-only>
            <label for="lap_count">Jumlah Lap</label>
            <input type="number" id="lap_count" name="lap_count" min="1" max="10" step="1" value="<?= esc((string) old('lap_count', 5)) ?>" required>
            <p class="field-help">Lintasan dibagi rata jadi beberapa lap; melewati batas lap memberi bonus 1 langkah instan.</p>
            <p class="field-help" data-race-bank-note></p>
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
<script type="application/json" id="question-topic-catalogs"><?= json_encode($questionTopicCatalogs ?? [], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
    const teacherSelect = document.querySelector('#teacher_id');
    const optionsContainer = document.querySelector('[data-topic-options]');
    const source = document.querySelector('#question-topic-catalogs');
    if (!optionsContainer) {
        return;
    }

    const emptySummary = <?= json_encode($emptyQuestionSummary, JSON_UNESCAPED_SLASHES) ?>;
    const catalogs = source ? JSON.parse(source.textContent || '{}') : {};
    const total = document.querySelector('[data-bank-total]');
    const easy = document.querySelector('[data-bank-easy]');
    const medium = document.querySelector('[data-bank-medium]');
    const hard = document.querySelector('[data-bank-hard]');
    const note = document.querySelector('[data-bank-note]');

    function selectedSummary() {
        const summary = JSON.parse(JSON.stringify(emptySummary));
        optionsContainer.querySelectorAll('input[name="question_topic_uuids[]"]:checked').forEach(function (input) {
            const item = JSON.parse(input.dataset.summary || '{}');
            summary.total += Number(item.total || 0);
            ['EASY', 'MEDIUM', 'HARD'].forEach(function (difficulty) {
                summary.difficulty[difficulty] += Number(item.difficulty && item.difficulty[difficulty] || 0);
            });
        });
        summary.zone_strategy_ready = summary.difficulty.EASY > 0
            && summary.difficulty.MEDIUM > 0
            && summary.difficulty.HARD > 0;

        return summary;
    }

    function drawSummary() {
        const summary = selectedSummary();
        total.textContent = summary.total;
        easy.textContent = summary.difficulty.EASY;
        medium.textContent = summary.difficulty.MEDIUM;
        hard.textContent = summary.difficulty.HARD;
        note.textContent = summary.total < 1
            ? 'Pilih topik yang memiliki soal published.'
            : (summary.zone_strategy_ready
            ? 'Stok soal sudah siap untuk difficulty zone.'
            : 'Difficulty yang kosong akan mengambil soal lain, tetapi tetap dari topik terpilih.');
    }

    function topicOption(topic) {
        const topicTotal = Number(topic.summary && topic.summary.total || 0);
        const label = document.createElement('label');
        label.className = 'topic-selection-option' + (topicTotal < 1 ? ' is-disabled' : '');

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.name = 'question_topic_uuids[]';
        input.value = topic.uuid;
        input.disabled = topicTotal < 1;
        input.dataset.summary = JSON.stringify(topic.summary || emptySummary);

        const text = document.createElement('span');
        const name = document.createElement('strong');
        const count = document.createElement('small');
        name.textContent = topic.name;
        count.textContent = topicTotal + ' soal published';
        text.append(name, count);
        label.append(input, text);

        return label;
    }

    function drawTopics() {
        if (!teacherSelect) {
            return;
        }

        const topics = catalogs[teacherSelect.value] || [];
        optionsContainer.replaceChildren();
        topics.forEach(function (topic) {
            optionsContainer.append(topicOption(topic));
        });
        if (topics.length < 1) {
            const empty = document.createElement('p');
            empty.className = 'topic-selection-empty';
            empty.textContent = 'Pilih guru untuk melihat topik, atau buat topik dan isi soal published terlebih dahulu.';
            optionsContainer.append(empty);
        }
        drawSummary();
    }

    optionsContainer.addEventListener('change', drawSummary);
    if (teacherSelect) {
        teacherSelect.addEventListener('change', drawTopics);
    }
    drawSummary();
})();
</script>
<script>
(function () {
    const raceOnlyFields = document.querySelectorAll('[data-race-only]');
    const snakesOnlyFields = document.querySelectorAll('[data-snakes-only]');
    const nearFinishCheckbox = document.querySelector('input[name="near_finish_bonus"]');
    const teamDeviceRadio = document.querySelector('input[name="participation_mode"][value="TEAM_DEVICE"]');
    const centralizedRadio = document.querySelector('input[name="participation_mode"][value="TEACHER_CENTRALIZED"]');
    const trackLengthInput = document.querySelector('#track_length');
    const raceBankNote = document.querySelector('[data-race-bank-note]');

    function currentGameMode() {
        const checked = document.querySelector('input[name="game_mode"]:checked');
        return checked ? checked.value : 'SNAKES_LADDERS';
    }

    function updateRaceBankNote() {
        if (!raceBankNote) {
            return;
        }
        if (currentGameMode() !== 'QUIZ_RACE') {
            raceBankNote.textContent = '';
            return;
        }
        const trackLength = Number(trackLengthInput ? trackLengthInput.value : 0) || 0;
        const totalEl = document.querySelector('[data-bank-total]');
        const totalAvailable = Number(totalEl ? totalEl.textContent : 0) || 0;
        const maxTeams = 6;
        const estimatedNeeded = maxTeams * Math.ceil(trackLength / 2);
        raceBankNote.textContent = totalAvailable < estimatedNeeded
            ? 'Bank soal topik ini diperkirakan kurang untuk lintasan sepanjang ini (perkiraan butuh ~' + estimatedNeeded + ' soal untuk ' + maxTeams + ' tim) — soal kemungkinan akan berulang sebelum tim mencapai finish.'
            : '';
    }

    let previousNearFinishChecked = null;
    let previousParticipationMode = null;

    function applyModeVisibility() {
        const isRace = currentGameMode() === 'QUIZ_RACE';
        raceOnlyFields.forEach((field) => field.classList.toggle('hidden', !isRace));
        snakesOnlyFields.forEach((field) => field.classList.toggle('hidden', isRace));

        if (nearFinishCheckbox) {
            if (isRace) {
                if (!nearFinishCheckbox.disabled) {
                    previousNearFinishChecked = nearFinishCheckbox.checked;
                }
                nearFinishCheckbox.disabled = true;
                nearFinishCheckbox.checked = false;
            } else {
                nearFinishCheckbox.disabled = false;
                if (previousNearFinishChecked !== null) {
                    nearFinishCheckbox.checked = previousNearFinishChecked;
                    previousNearFinishChecked = null;
                }
            }
        }

        if (teamDeviceRadio && centralizedRadio) {
            if (isRace) {
                if (!teamDeviceRadio.disabled && teamDeviceRadio.checked) {
                    previousParticipationMode = 'TEAM_DEVICE';
                    centralizedRadio.checked = true;
                }
                teamDeviceRadio.disabled = true;
            } else {
                teamDeviceRadio.disabled = false;
                if (previousParticipationMode === 'TEAM_DEVICE') {
                    teamDeviceRadio.checked = true;
                    previousParticipationMode = null;
                }
            }
        }

        updateRaceBankNote();
    }

    document.querySelectorAll('input[name="game_mode"]').forEach((radio) => radio.addEventListener('change', applyModeVisibility));
    if (trackLengthInput) {
        trackLengthInput.addEventListener('input', updateRaceBankNote);
    }
    // updateRaceBankNote() reads the topic checkboxes' checked state, but relies on the
    // existing topic-selection script's 'change' listener (on optionsContainer, an ancestor
    // of the checkboxes) running first via DOM event-bubbling order to redraw totals — if the
    // topic checkboxes are ever moved outside optionsContainer, this will silently go stale.
    document.addEventListener('change', function (event) {
        if (event.target && event.target.name === 'question_topic_uuids[]') {
            updateRaceBankNote();
        }
    });
    applyModeVisibility();
})();
</script>
<?= $this->endSection() ?>
