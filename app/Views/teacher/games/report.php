<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<?php
$room = $report['room'];
$winner = $report['winner'];
$soalPage = $report['questionStatsPage'];
$jawabPage = $report['answersPage'];
$baseUrl = '/teacher/games/' . $room['public_uuid'] . '/report';
?>
<div class="topbar">
    <div>
        <h1 class="page-title">Laporan Game</h1>
        <p class="muted"><?= esc($room['title']) ?> / PIN <strong><?= esc($room['pin']) ?></strong> / Status <strong><?= esc($room['status']) ?></strong></p>
    </div>
    <div class="topbar-actions">
        <a class="button secondary" href="/teacher/games/<?= esc($room['public_uuid']) ?>">Kembali</a>
        <a class="button" href="/teacher/games/<?= esc($room['public_uuid']) ?>/report/pdf">Export PDF Analisis Soal</a>
    </div>
</div>

<section class="grid cols-3">
    <div class="card metric"><span class="muted">Tim</span><strong><?= count($report['teams']) ?></strong></div>
    <div class="card metric"><span class="muted">Jawaban</span><strong><?= count($report['answers']) ?></strong></div>
    <div class="card metric"><span class="muted">Event</span><strong><?= count($report['events']) ?></strong></div>
</section>

<section class="panel report-winner <?= $winner ? 'is-winner' : 'is-pending' ?>" style="margin-top:16px">
    <?php if ($winner): ?>
        <p class="muted">Pemenang (Juara Papan)</p>
        <h2><?= esc($winner['name']) ?></h2>
        <p>Kotak <strong><?= esc((string) $winner['position']) ?></strong> / <?= esc((string) ($room['max_position'] ?? '-')) ?> · Skor <strong><?= esc((string) $winner['score']) ?></strong></p>
    <?php else: ?>
        <p class="muted">Pemenang</p>
        <h2>Belum ada pemenang</h2>
        <p class="muted">Status room: <?= esc($room['status']) ?>. Juara utama = tim pertama sampai finish.</p>
    <?php endif ?>
</section>

<section class="panel" style="margin-top:16px">
    <h2>Leaderboard Final</h2>
    <table class="table">
        <thead>
        <tr><th>#</th><th>Tim</th><th>Skor</th><th>Posisi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($report['teams'] as $index => $team): ?>
            <tr class="<?= ! empty($team['is_board_winner']) ? 'is-board-winner' : '' ?>">
                <td><?= $index + 1 ?></td>
                <td>
                    <?= esc($team['name']) ?>
                    <?php if (! empty($team['is_board_winner'])): ?>
                        <span class="badge-winner">Juara Papan</span>
                    <?php endif ?>
                </td>
                <td><?= esc((string) $team['score']) ?></td>
                <td>Kotak <?= esc((string) $team['position']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</section>

<section class="panel" style="margin-top:16px">
    <h2>Grafik</h2>
    <?php if ($report['answers'] === []): ?>
        <p class="muted">Belum ada data untuk grafik.</p>
    <?php else: ?>
        <div class="report-charts">
            <div class="report-chart-card">
                <h3>Akurasi per Soal (%)</h3>
                <canvas id="chart-accuracy" height="160"></canvas>
            </div>
            <div class="report-chart-card">
                <h3>Benar vs Salah per Tim</h3>
                <canvas id="chart-teams" height="160"></canvas>
            </div>
        </div>
    <?php endif ?>
</section>

<section class="panel" style="margin-top:16px" id="analisis-soal">
    <div class="panel-head">
        <h2>Analisis Soal</h2>
        <a class="button secondary" href="/teacher/games/<?= esc($room['public_uuid']) ?>/report/pdf">Export PDF</a>
    </div>
    <table class="table">
        <thead>
        <tr><th>Soal</th><th>Jawaban</th><th>Benar</th><th>Akurasi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($soalPage['items'] as $stat): ?>
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
        <?php if ($soalPage['items'] === []): ?>
            <tr><td colspan="4" class="muted">Belum ada jawaban.</td></tr>
        <?php endif ?>
        </tbody>
    </table>
    <?php if ($soalPage['total_pages'] > 1): ?>
        <nav class="pagination">
            <?php for ($p = 1; $p <= $soalPage['total_pages']; $p++): ?>
                <a class="<?= $p === $soalPage['page'] ? 'active' : '' ?>"
                   href="<?= esc($baseUrl . '?soal_page=' . $p . '&jawab_page=' . $jawabPage['page']) ?>#analisis-soal"><?= $p ?></a>
            <?php endfor ?>
        </nav>
    <?php endif ?>
</section>

<section class="panel" style="margin-top:16px" id="jawaban-tim">
    <h2>Jawaban Tim</h2>
    <table class="table">
        <thead>
        <tr><th>Tim</th><th>Soal</th><th>Jawaban</th><th>Status</th><th>Waktu</th></tr>
        </thead>
        <tbody>
        <?php foreach ($jawabPage['items'] as $answer): ?>
            <tr>
                <td><?= esc($answer['team_name']) ?></td>
                <td><?= esc($answer['question_stem']) ?></td>
                <td><?= esc($answer['option_label'] ?? '-') ?>. <?= esc($answer['answer_text']) ?></td>
                <td><?= (int) $answer['is_correct'] === 1 ? 'Benar' : 'Belum tepat' ?></td>
                <td><?= esc($answer['answered_at'] ?? '-') ?></td>
            </tr>
        <?php endforeach ?>
        <?php if ($jawabPage['items'] === []): ?>
            <tr><td colspan="5" class="muted">Belum ada jawaban.</td></tr>
        <?php endif ?>
        </tbody>
    </table>
    <?php if ($jawabPage['total_pages'] > 1): ?>
        <nav class="pagination">
            <?php for ($p = 1; $p <= $jawabPage['total_pages']; $p++): ?>
                <a class="<?= $p === $jawabPage['page'] ? 'active' : '' ?>"
                   href="<?= esc($baseUrl . '?soal_page=' . $soalPage['page'] . '&jawab_page=' . $p) ?>#jawaban-tim"><?= $p ?></a>
            <?php endfor ?>
        </nav>
    <?php endif ?>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($report['answers'] !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    var charts = <?= json_encode($report['charts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var acc = document.getElementById('chart-accuracy');
    if (acc && charts.accuracy && charts.accuracy.labels.length) {
        new Chart(acc, {
            type: 'bar',
            data: {
                labels: charts.accuracy.labels,
                datasets: [{ label: 'Akurasi %', data: charts.accuracy.values, backgroundColor: '#2563eb' }]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, max: 100 } },
                plugins: { legend: { display: false } }
            }
        });
    }
    var teams = document.getElementById('chart-teams');
    if (teams && charts.team_results && charts.team_results.labels.length) {
        new Chart(teams, {
            type: 'bar',
            data: {
                labels: charts.team_results.labels,
                datasets: [
                    { label: 'Benar', data: charts.team_results.correct, backgroundColor: '#16a34a' },
                    { label: 'Belum tepat', data: charts.team_results.wrong, backgroundColor: '#dc2626' }
                ]
            },
            options: {
                responsive: true,
                scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }
            }
        });
    }
})();
</script>
<?php endif ?>
<?= $this->endSection() ?>
