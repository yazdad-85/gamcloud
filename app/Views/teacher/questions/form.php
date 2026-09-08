<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<?php
$isEdit = $question !== null;
$optionsByLabel = [];
foreach ($options as $option) {
    $optionsByLabel[$option['label']] = $option;
}
$postedOptions = old('options');
$selectedType = (string) old('question_type', $question['question_type'] ?? 'MULTIPLE_CHOICE');
$selectedTopicId = (string) old('topic_id', $question['topic_id'] ?? '');
$selectedOwnerId = (string) old('owner_teacher_id', $ownerTeacherId ?: '');
$correctOption = '';
foreach ($options as $option) {
    if ((int) $option['is_correct'] === 1) {
        $correctOption = $option['label'];
        break;
    }
}
$correctOption = (string) old('correct_option', $correctOption);
$questionMeta = json_decode((string) ($question['meta_json'] ?? ''), true) ?: [];
$questionImages = is_array($questionMeta['images'] ?? null) ? array_values(array_filter($questionMeta['images'], 'is_string')) : [];
?>

<div class="topbar question-form-header">
    <div>
        <p class="page-eyebrow">Bank soal</p>
        <h1 class="page-title"><?= $isEdit ? 'Edit Soal' : 'Tambah Soal' ?></h1>
        <p class="muted"><?= $isEdit ? 'Perbarui pertanyaan tanpa mengubah kepemilikan soal.' : 'Buat satu soal secara manual untuk melengkapi hasil import DOCX.' ?></p>
    </div>
    <a class="button secondary" href="/teacher/questions">Kembali</a>
</div>

<?php if (session('error') !== null): ?>
    <div class="alert"><?= esc(session('error')) ?></div>
<?php endif ?>

<?php if ($topics === []): ?>
    <div class="alert question-form-warning">
        Belum ada topik yang dapat dipilih. Buat topik terlebih dahulu dari halaman Bank Soal.
    </div>
<?php endif ?>

<section class="question-form-shell">
    <form class="form question-editor" method="post" action="<?= $isEdit ? '/teacher/questions/' . esc($question['public_uuid']) . '/update' : '/teacher/questions' ?>">
        <?= csrf_field() ?>

        <div class="question-form-section">
            <div class="question-form-section-head">
                <span>01</span>
                <div>
                    <h2>Informasi soal</h2>
                    <p class="muted">Tentukan topik dan tulis pertanyaan dengan jelas.</p>
                </div>
            </div>

            <div class="question-form-fields">
                <?php if (! empty($isSuperadmin) && ! $isEdit): ?>
                    <div class="field">
                        <label for="owner_teacher_id">Guru pemilik</label>
                        <select id="owner_teacher_id" name="owner_teacher_id" required>
                            <option value="">Pilih guru</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= esc((string) $teacher['id']) ?>" <?= $selectedOwnerId === (string) $teacher['id'] ? 'selected' : '' ?>>
                                    <?= esc($teacher['name']) ?><?= $teacher['email'] ? ' - ' . esc($teacher['email']) : '' ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                <?php elseif (! empty($isSuperadmin) && $isEdit): ?>
                    <div class="field">
                        <label>Guru pemilik</label>
                        <div class="readonly-field"><?= esc($teacherNames[$ownerTeacherId] ?? 'Guru #' . $ownerTeacherId) ?></div>
                    </div>
                <?php endif ?>

                <div class="field">
                    <label for="topic_id">Topik</label>
                    <select id="topic_id" name="topic_id" required>
                        <option value="">Pilih topik</option>
                        <?php foreach ($topics as $topic): ?>
                            <option
                                value="<?= esc((string) $topic['id']) ?>"
                                data-owner="<?= esc((string) $topic['owner_teacher_id']) ?>"
                                <?= $selectedTopicId === (string) $topic['id'] ? 'selected' : '' ?>
                            >
                                <?= esc($topic['name']) ?>
                                <?php if (! empty($isSuperadmin) && ! $isEdit): ?>
                                    - <?= esc($teacherNames[$topic['owner_teacher_id']] ?? 'Guru #' . $topic['owner_teacher_id']) ?>
                                <?php endif ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                    <p class="field-help">Topik menentukan kelompok soal yang dapat dipilih saat menyiapkan game.</p>
                </div>

                <div class="field field-wide">
                    <label for="stem">Pertanyaan</label>
                    <textarea id="stem" name="stem" rows="5" maxlength="5000" placeholder="Tulis pertanyaan di sini..." required><?= esc((string) old('stem', $question['stem'] ?? '')) ?></textarea>
                </div>

                <?php if ($questionImages !== []): ?>
                    <div class="field field-wide">
                        <label>Gambar dari hasil import</label>
                        <div class="form-existing-media">
                            <?php foreach ($questionImages as $image): ?>
                                <img src="<?= esc($image) ?>" alt="Gambar soal">
                            <?php endforeach ?>
                        </div>
                        <p class="field-help">Gambar tetap dipertahankan saat teks soal diedit.</p>
                    </div>
                <?php endif ?>
            </div>
        </div>

        <div class="question-form-section">
            <div class="question-form-section-head">
                <span>02</span>
                <div>
                    <h2>Jenis dan tingkat soal</h2>
                    <p class="muted">Pengaturan ini menentukan tampilan soal dan zona difficulty.</p>
                </div>
            </div>

            <div class="question-settings-grid">
                <div class="field">
                    <label for="question_type">Tipe soal</label>
                    <select id="question_type" name="question_type">
                        <option value="MULTIPLE_CHOICE" <?= $selectedType === 'MULTIPLE_CHOICE' ? 'selected' : '' ?>>Pilihan Ganda</option>
                        <option value="TRUE_FALSE" <?= $selectedType === 'TRUE_FALSE' ? 'selected' : '' ?>>Benar / Salah</option>
                    </select>
                </div>
                <div class="field">
                    <label for="difficulty">Difficulty</label>
                    <?php $difficulty = (string) old('difficulty', $question['difficulty'] ?? 'MEDIUM'); ?>
                    <select id="difficulty" name="difficulty">
                        <option value="EASY" <?= $difficulty === 'EASY' ? 'selected' : '' ?>>Mudah</option>
                        <option value="MEDIUM" <?= $difficulty === 'MEDIUM' ? 'selected' : '' ?>>Sedang</option>
                        <option value="HARD" <?= $difficulty === 'HARD' ? 'selected' : '' ?>>Sulit</option>
                    </select>
                </div>
                <div class="field">
                    <label for="points">Poin</label>
                    <input id="points" name="points" type="number" min="10" max="1000" value="<?= esc((string) old('points', $question['points'] ?? 100)) ?>" required>
                </div>
                <div class="field">
                    <label for="time_limit_seconds">Waktu menjawab</label>
                    <div class="input-suffix">
                        <input id="time_limit_seconds" name="time_limit_seconds" type="number" min="5" max="300" value="<?= esc((string) old('time_limit_seconds', $question['time_limit_seconds'] ?? 30)) ?>" required>
                        <span>detik</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="question-form-section">
            <div class="question-form-section-head">
                <span>03</span>
                <div>
                    <h2>Pilihan dan kunci jawaban</h2>
                    <p class="muted">Isi minimal dua opsi. Opsi E dapat dibiarkan kosong jika tidak diperlukan.</p>
                </div>
            </div>

            <div class="option-editor" data-option-editor>
                <?php foreach (range('A', 'E') as $label): ?>
                    <?php
                    $option = $optionsByLabel[$label] ?? null;
                    $body = is_array($postedOptions) && array_key_exists($label, $postedOptions)
                        ? (string) $postedOptions[$label]
                        : (string) ($option['body'] ?? '');
                    if ($selectedType === 'TRUE_FALSE') {
                        $body = $label === 'A' ? 'Benar' : ($label === 'B' ? 'Salah' : $body);
                    }
                    $optionMedia = json_decode((string) ($option['media_json'] ?? ''), true) ?: [];
                    $optionImages = is_array($optionMedia['images'] ?? null) ? array_values(array_filter($optionMedia['images'], 'is_string')) : [];
                    ?>
                    <div class="option-edit-row" data-option-row="<?= $label ?>">
                        <label class="correct-choice" title="Jadikan opsi <?= $label ?> sebagai kunci jawaban">
                            <input type="radio" name="correct_option" value="<?= $label ?>" <?= $correctOption === $label ? 'checked' : '' ?> required>
                            <span><?= $label ?></span>
                        </label>
                        <div class="option-input-wrap">
                            <label for="option_<?= $label ?>">Opsi <?= $label ?></label>
                            <input id="option_<?= $label ?>" name="options[<?= $label ?>]" value="<?= esc($body) ?>" maxlength="3000" placeholder="Isi pilihan <?= $label ?>">
                            <?php if ($optionImages !== []): ?>
                                <div class="form-existing-media option-existing-media">
                                    <?php foreach ($optionImages as $image): ?>
                                        <img src="<?= esc($image) ?>" alt="Gambar opsi <?= $label ?>">
                                    <?php endforeach ?>
                                </div>
                            <?php endif ?>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
            <p class="field-help option-key-help">Pilih kotak huruf di sebelah kiri untuk menentukan kunci jawaban.</p>
        </div>

        <div class="question-form-section">
            <div class="question-form-section-head">
                <span>04</span>
                <div>
                    <h2>Publikasi</h2>
                    <p class="muted">Soal draft tersimpan tetapi tidak akan dipilih oleh game.</p>
                </div>
            </div>

            <div class="question-form-fields">
                <div class="field">
                    <label for="status">Status</label>
                    <?php $status = (string) old('status', $question['status'] ?? 'PUBLISHED'); ?>
                    <select id="status" name="status">
                        <option value="PUBLISHED" <?= $status === 'PUBLISHED' ? 'selected' : '' ?>>Siap digunakan</option>
                        <option value="DRAFT" <?= $status === 'DRAFT' ? 'selected' : '' ?>>Draft</option>
                    </select>
                </div>
                <div class="field field-wide">
                    <label for="explanation">Pembahasan (opsional)</label>
                    <textarea id="explanation" name="explanation" rows="4" maxlength="5000" placeholder="Jelaskan alasan jawaban yang benar... "><?= esc((string) old('explanation', $question['explanation'] ?? '')) ?></textarea>
                </div>
            </div>
        </div>

        <div class="question-form-actions">
            <a class="button secondary" href="/teacher/questions">Batal</a>
            <button class="button" type="submit" <?= $topics === [] ? 'disabled' : '' ?>><?= $isEdit ? 'Simpan Perubahan' : 'Tambah Soal' ?></button>
        </div>
    </form>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    const typeSelect = document.querySelector('#question_type');
    const optionRows = Array.from(document.querySelectorAll('[data-option-row]'));
    const ownerSelect = document.querySelector('#owner_teacher_id');
    const topicSelect = document.querySelector('#topic_id');

    function syncQuestionType() {
        const isTrueFalse = typeSelect && typeSelect.value === 'TRUE_FALSE';
        optionRows.forEach(function (row) {
            const label = row.dataset.optionRow;
            const input = row.querySelector('input[type="text"], input:not([type])');
            const radio = row.querySelector('input[type="radio"]');
            const isVisible = !isTrueFalse || label === 'A' || label === 'B';
            row.hidden = !isVisible;
            radio.disabled = !isVisible;

            if (!input) {
                return;
            }
            if (isTrueFalse && (label === 'A' || label === 'B')) {
                if (!input.readOnly) {
                    input.dataset.multipleChoiceValue = input.value;
                }
                input.value = label === 'A' ? 'Benar' : 'Salah';
                input.readOnly = true;
            } else if (!isTrueFalse) {
                input.readOnly = false;
                if (input.dataset.multipleChoiceValue !== undefined) {
                    input.value = input.dataset.multipleChoiceValue;
                    delete input.dataset.multipleChoiceValue;
                }
            }
        });
    }

    function syncTopics() {
        if (!ownerSelect || !topicSelect) {
            return;
        }

        const ownerId = ownerSelect.value;
        Array.from(topicSelect.options).forEach(function (option) {
            if (!option.dataset.owner) {
                return;
            }
            const matches = ownerId !== '' && option.dataset.owner === ownerId;
            option.hidden = !matches;
            option.disabled = !matches;
            if (!matches && option.selected) {
                topicSelect.value = '';
            }
        });
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', syncQuestionType);
        syncQuestionType();
    }
    if (ownerSelect) {
        ownerSelect.addEventListener('change', syncTopics);
        syncTopics();
    }
})();
</script>
<?= $this->endSection() ?>
