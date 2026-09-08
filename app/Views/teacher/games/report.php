<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<?php $room = $report['room']; ?>
<div class="topbar">
    <div>
        <h1 class="page-title">Laporan Game</h1>
        <p class="muted"><?= esc($room['title']) ?> / PIN <strong><?= esc($room['pin']) ?></strong></p>
    </div>
    <a class="button secondary" href="/teacher/games/<?= esc($room['public_uuid']) ?>">Kembali</a>
</div>

<section class="grid cols-3">
    <div class="card metric">
        <span class="muted">Tim</span>
        <strong><?= count($report['teams']) ?></strong>
    </div>
    <div class="card metric">
        <span class="muted">Jawaban</span>
        <strong><?= count($report['answers']) ?></strong>
    </div>
    <div class="card metric">
        <span class="muted">Event</span>
        <strong><?= count($report['events']) ?></strong>
    </div>
</section>

<section class="panel" style="margin-top:16px">
    <h2>Leaderboard Final</h2>
    <table class="table">
        <thead>
        <tr><th>Tim</th><th>Skor</th><th>Posisi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($report['teams'] as $team): ?>
            <tr>
                <td><span class="pawn" style="background:<?= esc($team['color']) ?>"></span> <?= esc($team['name']) ?></td>
                <td><?= esc((string) $team['score']) ?></td>
                <td>Kotak <?= esc((string) $team['position']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</section>

<section class="panel" style="margin-top:16px">
    <h2>Analisis Soal</h2>
    <table class="table">
        <thead>
        <tr><th>Soal</th><th>Jawaban</th><th>Benar</th><th>Akurasi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($report['questionStats'] as $stat): ?>
            <?php
                $total = (int) $stat['total_answers'];
                $correct = (int) $stat['correct_answers'];
                $accuracy = $total > 0 ? round(($correct / $total) * 100) : 0;
            ?>
            <tr>
                <td><?= esc($stat['stem']) ?></td>
                <td><?= esc((string) $total) ?></td>
                <td><?= esc((string) $correct) ?></td>
                <td><?= esc((string) $accuracy) ?>%</td>
            </tr>
        <?php endforeach ?>
        <?php if ($report['questionStats'] === []): ?>
            <tr><td colspan="4" class="muted">Belum ada jawaban.</td></tr>
        <?php endif ?>
        </tbody>
    </table>
</section>

<section class="panel" style="margin-top:16px">
    <h2>Jawaban Tim</h2>
    <table class="table">
        <thead>
        <tr><th>Tim</th><th>Soal</th><th>Jawaban</th><th>Status</th><th>Waktu</th></tr>
        </thead>
        <tbody>
        <?php foreach ($report['answers'] as $answer): ?>
            <tr>
                <td><?= esc($answer['team_name']) ?></td>
                <td><?= esc($answer['question_stem']) ?></td>
                <td><?= esc($answer['option_label'] ?? '-') ?>. <?= esc($answer['answer_text']) ?></td>
                <td><?= (int) $answer['is_correct'] === 1 ? 'Benar' : 'Belum tepat' ?></td>
                <td><?= esc($answer['answered_at'] ?? '-') ?></td>
            </tr>
        <?php endforeach ?>
        <?php if ($report['answers'] === []): ?>
            <tr><td colspan="5" class="muted">Belum ada jawaban.</td></tr>
        <?php endif ?>
        </tbody>
    </table>
</section>
<?= $this->endSection() ?>
