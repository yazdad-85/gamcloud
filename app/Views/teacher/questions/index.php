<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<?php
$typeLabels = [
    'MULTIPLE_CHOICE' => 'Pilihan Ganda',
    'TRUE_FALSE' => 'Benar / Salah',
    'SHORT_ANSWER' => 'Jawaban Singkat',
];
$difficultyLabels = [
    'EASY' => 'Mudah',
    'MEDIUM' => 'Sedang',
    'HARD' => 'Sulit',
];
$selectedTopicName = 'Semua soal';
if ($topicFilter === 'none') {
    $selectedTopicName = 'Tanpa topik';
} elseif ($topicFilter !== '') {
    foreach ($topics as $topic) {
        if ($topic['public_uuid'] === $topicFilter) {
            $selectedTopicName = $topic['name'];
            break;
        }
    }
}
$questionNumber = (int) $pagination['from'];
?>

<div class="topbar question-page-header">
    <div>
        <p class="page-eyebrow">Materi permainan</p>
        <h1 class="page-title">Bank Soal</h1>
        <p class="muted">Buat topik terlebih dahulu, lalu import bank soal dari file DOCX ke topik tersebut. Soal yang sudah tersusun dapat digunakan saat membuat room game.</p>
    </div>
    <div class="question-header-actions">
        <div class="question-total">
            <strong><?= esc((string) $allQuestionCount) ?></strong>
            <span>Total soal</span>
        </div>
        <a class="button" href="/teacher/questions/create">Tambah Soal</a>
    </div>
</div>

<?php if (session('message') !== null): ?>
    <div class="alert success-alert"><?= esc(session('message')) ?></div>
<?php endif ?>
<?php if (session('error') !== null): ?>
    <div class="alert"><?= esc(session('error')) ?></div>
<?php endif ?>

<div class="question-workspace">
    <aside class="question-topic-sidebar" aria-label="Filter topik soal">
        <div class="topic-sidebar-head">
            <div>
                <span class="section-kicker">Koleksi</span>
                <h2>Topik</h2>
            </div>
            <span class="topic-count"><?= esc((string) count($topics)) ?></span>
        </div>

        <nav class="topic-list">
            <a class="topic-item <?= $topicFilter === '' ? 'is-active' : '' ?>" href="/teacher/questions" <?= $topicFilter === '' ? 'aria-current="page"' : '' ?>>
                <span>Semua soal</span>
                <strong><?= esc((string) $allQuestionCount) ?></strong>
            </a>
            <a class="topic-item <?= $topicFilter === 'none' ? 'is-active' : '' ?>" href="/teacher/questions?topic=none" <?= $topicFilter === 'none' ? 'aria-current="page"' : '' ?>>
                <span>Tanpa topik</span>
                <strong><?= esc((string) ($questionCounts['none'] ?? 0)) ?></strong>
            </a>
            <?php foreach ($topics as $topic): ?>
                <?php $isActiveTopic = $topicFilter === $topic['public_uuid']; ?>
                <div class="topic-item-row <?= $isActiveTopic ? 'is-active' : '' ?>">
                    <a href="/teacher/questions?topic=<?= esc($topic['public_uuid']) ?>" <?= $isActiveTopic ? 'aria-current="page"' : '' ?>>
                        <span><?= esc($topic['name']) ?></span>
                        <strong><?= esc((string) ($questionCounts[(string) $topic['id']] ?? 0)) ?></strong>
                    </a>
                    <form method="post" action="/teacher/topics/<?= esc($topic['public_uuid']) ?>/delete" onsubmit="return confirm('Hapus topik ini? Soal di dalamnya tidak ikut terhapus dan akan menjadi Tanpa Topik.');">
                        <?= csrf_field() ?>
                        <button class="topic-delete" type="submit" title="Hapus topik <?= esc($topic['name']) ?>" aria-label="Hapus topik <?= esc($topic['name']) ?>">&times;</button>
                    </form>
                </div>
            <?php endforeach ?>
        </nav>

        <details class="topic-create" <?= old('name') !== null ? 'open' : '' ?>>
            <summary>Tambah topik</summary>
            <form class="form compact-form" method="post" action="/teacher/topics">
                <?= csrf_field() ?>
                <?php if (! empty($isSuperadmin)): ?>
                    <div class="field">
                        <label for="topic_owner_teacher_id">Guru pemilik</label>
                        <select id="topic_owner_teacher_id" name="owner_teacher_id" required>
                            <option value="">Pilih guru</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= esc((string) $teacher['id']) ?>" <?= old('owner_teacher_id') == $teacher['id'] ? 'selected' : '' ?>>
                                    <?= esc($teacher['name']) ?><?= $teacher['email'] ? ' - ' . esc($teacher['email']) : '' ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                <?php endif ?>
                <div class="field">
                    <label for="topic_name">Nama topik</label>
                    <input id="topic_name" name="name" value="<?= esc((string) old('name')) ?>" placeholder="Contoh: Bab 1 - Pecahan" maxlength="140" required>
                </div>
                <button class="button secondary full-button" type="submit">Simpan Topik</button>
            </form>
        </details>
    </aside>

    <section class="question-bank-content">
        <details class="question-import" id="import-soal">
            <summary>
                <span>
                    <strong>Import soal dari DOCX</strong>
                    <small>Unggah banyak soal beserta gambar dalam satu langkah.</small>
                </span>
                <span class="import-toggle">Buka</span>
            </summary>
            <div class="question-import-body">
                <div class="import-guide">
                    <span class="section-kicker">Format dokumen</span>
                    <h2>Siapkan soal dengan pola yang konsisten</h2>
                    <p class="muted">Gunakan nomor soal, opsi A-E, lalu tulis kunci atau tandai opsi benar. Gambar di bawah soal maupun opsi akan ikut disimpan.</p>
                    <pre class="format-sample">1. Planet merah adalah ...
A. Venus
B. Mars (benar)
C. Jupiter
D. Merkurius
E. Saturnus

2. [TRUE_FALSE] Air mendidih pada 100 C.
Jawaban: Benar</pre>
                    <a class="button secondary" href="/teacher/questions/template-docx">Download Template DOCX</a>
                </div>

                <form class="form import-form" method="post" action="/teacher/questions/import-docx" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <?php if (! empty($isSuperadmin)): ?>
                        <div class="field">
                            <label for="owner_teacher_id">Guru pemilik soal</label>
                            <select id="owner_teacher_id" name="owner_teacher_id" required>
                                <option value="">Pilih guru</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?= esc((string) $teacher['id']) ?>" <?= old('owner_teacher_id') == $teacher['id'] ? 'selected' : '' ?>>
                                        <?= esc($teacher['name']) ?><?= $teacher['email'] ? ' - ' . esc($teacher['email']) : '' ?>
                                    </option>
                                <?php endforeach ?>
                            </select>
                        </div>
                    <?php endif ?>
                    <div class="field">
                        <label for="docx_file">File DOCX</label>
                        <input id="docx_file" name="docx_file" type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                        <p class="field-help">Maksimal 5 MB. Mendukung gambar JPG, PNG, GIF, dan WEBP.</p>
                    </div>
                    <div class="field">
                        <label for="topic_id">Masukkan ke topik</label>
                        <select id="topic_id" name="topic_id">
                            <option value="">Tanpa topik</option>
                            <?php foreach ($topics as $topic): ?>
                                <option value="<?= esc((string) $topic['id']) ?>"><?= esc($topic['name']) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="new_topic_name">Atau buat topik baru</label>
                        <input id="new_topic_name" name="new_topic_name" placeholder="Nama topik baru (opsional)" maxlength="140">
                        <p class="field-help">Jika diisi, topik baru akan menggantikan pilihan di atas.</p>
                    </div>
                    <button class="button full-button" type="submit">Import ke Bank Soal</button>
                </form>
            </div>
        </details>

        <div class="question-list-head">
            <div>
                <span class="section-kicker">Daftar soal</span>
                <h2><?= esc($selectedTopicName) ?></h2>
            </div>
            <?php if ($pagination['total'] > 0): ?>
                <p class="muted">Menampilkan <?= esc((string) $pagination['from']) ?>-<?= esc((string) $pagination['to']) ?> dari <?= esc((string) $pagination['total']) ?></p>
            <?php endif ?>
        </div>

        <section class="question-list" aria-label="Daftar soal">
            <?php foreach ($questions as $question): ?>
                <?php
                $questionMeta = json_decode((string) ($question['meta_json'] ?? ''), true) ?: [];
                $questionImages = is_array($questionMeta['images'] ?? null) ? array_values(array_filter($questionMeta['images'], 'is_string')) : [];
                $questionOptions = $options[$question['id']] ?? [];
                $difficulty = strtoupper((string) $question['difficulty']);
                ?>
                <article class="question-row">
                    <div class="question-row-main">
                        <div class="question-index" aria-hidden="true"><?= esc((string) $questionNumber) ?></div>
                        <div class="question-row-copy">
                            <div class="question-badges">
                                <span class="question-badge type"><?= esc($typeLabels[$question['question_type']] ?? $question['question_type']) ?></span>
                                <span class="question-badge difficulty-<?= esc(strtolower($difficulty)) ?>"><?= esc($difficultyLabels[$difficulty] ?? $difficulty) ?></span>
                                <span class="question-topic-label"><?= esc($topicNames[$question['topic_id']] ?? 'Tanpa topik') ?></span>
                                <span class="question-badge status-<?= esc(strtolower((string) $question['status'])) ?>"><?= $question['status'] === 'PUBLISHED' ? 'Siap digunakan' : 'Draft' ?></span>
                            </div>
                            <h3><?= esc($question['stem']) ?></h3>
                            <p class="question-facts">
                                <?= esc((string) $question['points']) ?> poin
                                <span aria-hidden="true">&bull;</span>
                                <?= esc((string) $question['time_limit_seconds']) ?> detik
                                <span aria-hidden="true">&bull;</span>
                                <?= esc((string) count($questionOptions)) ?> opsi
                            </p>
                        </div>
                        <div class="question-row-side">
                            <?php if ($questionImages !== []): ?>
                                <div class="question-thumbnails">
                                    <?php foreach ($questionImages as $image): ?>
                                        <img src="<?= esc($image) ?>" alt="Gambar soal">
                                    <?php endforeach ?>
                                </div>
                            <?php endif ?>
                            <div class="question-row-actions">
                                <a class="button secondary small-button" href="/teacher/questions/<?= esc($question['public_uuid']) ?>/edit">Edit</a>
                                <form method="post" action="/teacher/questions/<?= esc($question['public_uuid']) ?>/delete" onsubmit="return confirm('Hapus soal ini dari bank soal? Riwayat jawaban game yang sudah selesai tetap disimpan.');">
                                    <?= csrf_field() ?>
                                    <button class="button danger-outline small-button" type="submit">Hapus</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <?php if ($questionOptions !== []): ?>
                        <details class="answer-details">
                            <summary>Lihat pilihan dan kunci jawaban</summary>
                            <div class="answer-list">
                                <?php foreach ($questionOptions as $option): ?>
                                    <?php
                                    $optionMedia = json_decode((string) ($option['media_json'] ?? ''), true) ?: [];
                                    $optionImages = is_array($optionMedia['images'] ?? null) ? array_values(array_filter($optionMedia['images'], 'is_string')) : [];
                                    ?>
                                    <div class="answer-row <?= (int) $option['is_correct'] === 1 ? 'is-correct' : '' ?>">
                                        <span class="answer-label"><?= esc($option['label']) ?></span>
                                        <div class="answer-copy">
                                            <span><?= esc($option['body']) ?></span>
                                            <?php if ($optionImages !== []): ?>
                                                <div class="option-media">
                                                    <?php foreach ($optionImages as $image): ?>
                                                        <img src="<?= esc($image) ?>" alt="Gambar opsi <?= esc($option['label']) ?>">
                                                    <?php endforeach ?>
                                                </div>
                                            <?php endif ?>
                                        </div>
                                        <?php if ((int) $option['is_correct'] === 1): ?>
                                            <strong class="correct-label">Kunci</strong>
                                        <?php endif ?>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        </details>
                    <?php endif ?>
                </article>
                <?php $questionNumber++; ?>
            <?php endforeach ?>

            <?php if ($questions === []): ?>
                <div class="question-empty">
                    <strong>Belum ada soal di <?= esc(strtolower($selectedTopicName)) ?></strong>
                    <p class="muted">Buka panel import DOCX di atas untuk mulai menyiapkan pertanyaan permainan.</p>
                </div>
            <?php endif ?>
        </section>

        <?php if ($pagination['total'] > 0): ?>
            <div class="pagination-bar question-pagination">
                <p class="muted">Halaman <?= esc((string) $pager->getCurrentPage('questions')) ?> dari <?= esc((string) max(1, $pagination['page_count'])) ?></p>
                <?php if ($pagination['page_count'] > 1): ?>
                    <?= $pager->links('questions') ?>
                <?php endif ?>
            </div>
        <?php endif ?>
    </section>
</div>
<?= $this->endSection() ?>
