<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<div class="topbar">
    <div>
        <h1 class="page-title">Bank Soal</h1>
        <p class="muted">Kelola soal yang akan dipakai dalam permainan.</p>
    </div>
</div>

<?php if (session('message') !== null): ?>
    <div class="alert success-alert"><?= esc(session('message')) ?></div>
<?php endif ?>
<?php if (session('error') !== null): ?>
    <div class="alert"><?= esc(session('error')) ?></div>
<?php endif ?>

<section class="panel">
    <h2>Topik</h2>
    <p class="muted">Kelompokkan bank soal supaya lebih mudah dikelola.</p>
    <div class="check-grid">
        <a class="check-option" href="/teacher/questions" style="text-decoration:none">
            <span>Semua Soal (<?= esc((string) count($questions)) ?>)</span>
        </a>
        <a class="check-option" href="/teacher/questions?topic=none" style="text-decoration:none">
            <span>Tanpa Topik</span>
        </a>
        <?php foreach ($topics as $topic): ?>
            <div class="check-option" style="justify-content:space-between">
                <a href="/teacher/questions?topic=<?= esc($topic['public_uuid']) ?>" style="text-decoration:none"><span><?= esc($topic['name']) ?></span></a>
                <form method="post" action="/teacher/topics/<?= esc($topic['public_uuid']) ?>/delete" onsubmit="return confirm('Hapus topik ini? Soal di dalamnya TIDAK ikut terhapus, hanya jadi Tanpa Topik.');" style="display:inline">
                    <?= csrf_field() ?>
                    <button class="button danger" type="submit" style="min-height:auto;padding:4px 8px">Hapus</button>
                </form>
            </div>
        <?php endforeach ?>
    </div>
    <form class="form" method="post" action="/teacher/topics" style="margin-top:12px">
        <?= csrf_field() ?>
        <?php if (! empty($isSuperadmin)): ?>
            <input type="hidden" name="owner_teacher_id" value="<?= esc((string) old('owner_teacher_id')) ?>">
        <?php endif ?>
        <div class="field">
            <label for="topic_name">Topik Baru</label>
            <input id="topic_name" name="name" placeholder="Contoh: Bab 1 - Pecahan" required>
        </div>
        <button class="button secondary" type="submit">Buat Topik</button>
    </form>
</section>

<section class="panel import-panel">
    <div>
        <h2>Import Soal DOCX</h2>
        <p class="muted">Format: nomor soal, opsi A-D, lalu baris Kunci/Jawaban. Gambar di bawah soal atau opsi akan ikut disimpan.</p>
        <pre class="format-sample">1. Planet merah adalah ...
A. Venus
B. Mars (benar)
C. Jupiter
D. Merkurius

2. [TRUE_FALSE] Air mendidih pada suhu 100 derajat Celcius.
Jawaban: Benar</pre>
        <div class="inline-actions template-actions">
            <a class="button secondary" href="/teacher/questions/template-docx">Download Template DOCX</a>
        </div>
    </div>
    <form class="form" method="post" action="/teacher/questions/import-docx" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <?php if (! empty($isSuperadmin)): ?>
            <div class="field">
                <label for="owner_teacher_id">Guru Pemilik Soal</label>
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
            <p class="field-help">Maksimal 5 MB. Gambar yang diterima: JPG, PNG, GIF, WEBP.</p>
        </div>
        <div class="field">
            <label for="topic_id">Topik</label>
            <select id="topic_id" name="topic_id">
                <option value="">Tanpa Topik</option>
                <?php foreach ($topics as $topic): ?>
                    <option value="<?= esc((string) $topic['id']) ?>"><?= esc($topic['name']) ?></option>
                <?php endforeach ?>
            </select>
            <p class="field-help">Atau isi nama topik baru di bawah ini (mengabaikan pilihan di atas kalau diisi):</p>
            <input id="new_topic_name" name="new_topic_name" placeholder="Nama topik baru (opsional)">
        </div>
        <button class="button" type="submit">Import ke Bank Soal</button>
    </form>
</section>

<section class="grid">
    <?php foreach ($questions as $question): ?>
        <?php $questionMeta = json_decode((string) ($question['meta_json'] ?? ''), true) ?: []; ?>
        <?php $questionImages = is_array($questionMeta['images'] ?? null) ? array_values(array_filter($questionMeta['images'], 'is_string')) : []; ?>
        <article class="card">
            <h2><?= esc($question['stem']) ?></h2>
            <p class="muted"><?= esc($question['source_type']) ?> / <?= esc($question['question_type']) ?> / <?= esc($question['difficulty']) ?> / <?= esc($question['status']) ?></p>
            <p class="muted">Topik: <?= esc($topicNames[$question['topic_id']] ?? 'Tanpa Topik') ?></p>
            <?php if ($questionImages !== []): ?>
                <div class="question-media">
                    <?php foreach ($questionImages as $image): ?>
                        <img src="<?= esc($image) ?>" alt="Gambar soal">
                    <?php endforeach ?>
                </div>
            <?php endif ?>
            <div class="grid cols-2">
                <?php foreach ($options[$question['id']] ?? [] as $option): ?>
                    <?php $optionMedia = json_decode((string) ($option['media_json'] ?? ''), true) ?: []; ?>
                    <?php $optionImages = is_array($optionMedia['images'] ?? null) ? array_values(array_filter($optionMedia['images'], 'is_string')) : []; ?>
                    <div class="panel">
                        <strong><?= esc($option['label']) ?>.</strong>
                        <?= esc($option['body']) ?>
                        <?php if ($optionImages !== []): ?>
                            <div class="option-media">
                                <?php foreach ($optionImages as $image): ?>
                                    <img src="<?= esc($image) ?>" alt="Gambar opsi <?= esc($option['label']) ?>">
                                <?php endforeach ?>
                            </div>
                        <?php endif ?>
                        <?php if ((int) $option['is_correct'] === 1): ?>
                            <span class="badge playing">Benar</span>
                        <?php endif ?>
                    </div>
                <?php endforeach ?>
            </div>
        </article>
    <?php endforeach ?>
    <?php if ($questions === []): ?>
        <article class="card">
            <h2>Belum ada soal</h2>
            <p class="muted">Import DOCX atau tambahkan soal untuk mulai membuat permainan.</p>
        </article>
    <?php endif ?>
</section>
<?= $this->endSection() ?>
