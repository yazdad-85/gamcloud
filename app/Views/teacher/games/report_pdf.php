<?php
$room = $report['room'];
$winner = $report['winner'];
$winners = $report['winners'];
$finishLabels = ['TRACK_FINISH' => 'Mencapai garis akhir', 'QUESTION_LIMIT' => 'Batas soal tercapai'];
$outcomeLabels = ['CORRECT' => 'Benar', 'WRONG' => 'Belum tepat', 'TIMEOUT' => 'Waktu habis'];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        h2 { font-size: 14px; margin: 18px 0 8px; }
        .muted { color: #555; }
        .box { border: 1px solid #ccc; padding: 10px; margin: 12px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f3f3f3; }
    </style>
</head>
<body>
    <h1>Laporan Analisis Soal</h1>
    <p class="muted"><?= esc(\App\Services\Game\GameRoomPresenter::displayTitle($room)) ?> · PIN <?= esc($room['pin']) ?> · <?= esc(date('Y-m-d H:i')) ?></p>

    <div class="box">
        <?php if ($winner): ?>
            <strong><?= count($winners) > 1 ? 'Pemenang Bersama' : 'Pemenang' ?>:</strong> <?= esc(implode(', ', array_column($winners, 'name'))) ?><br>
            Alasan selesai: <?= esc($finishLabels[$report['finish_reason']] ?? 'Game selesai') ?><br>
            Kotak <?= esc((string) $winner['position']) ?> / <?= esc((string) ($room['max_position'] ?? '-')) ?> ·
            Skor <?= esc((string) $winner['score']) ?>
        <?php else: ?>
            <strong>Pemenang:</strong> Belum ada pemenang (status <?= esc($room['status']) ?>)
        <?php endif ?>
    </div>

    <?php if ($report['rounds'] !== []): ?>
        <h2>Ringkasan Ronde</h2>
        <table>
            <thead><tr><th>Ronde</th><th>Status</th><th>Soal</th><th>Pemenang</th><th>Hadiah</th></tr></thead>
            <tbody>
            <?php foreach ($report['rounds'] as $round): ?>
                <tr>
                    <td><?= esc((string) $round['round_number']) ?></td>
                    <td><?= esc($round['state']) ?></td>
                    <td><?= esc((string) $round['question_resolved_count']) ?> / <?= esc((string) $round['question_target_count']) ?></td>
                    <td><?= $round['winner_team_names'] === [] ? '-' : esc(implode(', ', $round['winner_team_names'])) ?></td>
                    <td><?= $round['prize_points'] > 0 ? esc((string) $round['prize_points']) . ' poin per pemenang' : '-' ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>

    <h2>Analisis Soal</h2>
    <table>
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
            <tr><td colspan="4">Belum ada jawaban.</td></tr>
        <?php endif ?>
        </tbody>
    </table>

    <h2>Jawaban Tim</h2>
    <table>
        <thead>
        <tr><th>Tim</th><th>Urutan</th><th>Soal/Jawaban</th><th>Hasil</th><th>Respons</th><th>Poin</th></tr>
        </thead>
        <tbody>
        <?php foreach ($report['answers'] as $answer): ?>
            <tr>
                <td><?= esc($answer['team_name']) ?></td>
                <td><?= $answer['round_number'] === null ? 'Giliran ' . esc((string) $answer['question_number']) : 'Ronde ' . esc((string) $answer['round_number']) . ' / Soal ' . esc((string) $answer['question_number']) ?></td>
                <td><?= esc($answer['question_stem']) ?><br><span class="muted"><?= esc($answer['option_label'] ?? '-') ?><?= $answer['answer_text'] ? '. ' . esc((string) $answer['answer_text']) : '' ?></span></td>
                <td><?= esc($outcomeLabels[$answer['outcome']] ?? $answer['outcome']) ?></td>
                <td><?= $answer['response_ms'] === null ? '-' : esc(number_format($answer['response_ms'] / 1000, 2, ',', '.')) . ' dtk' ?></td>
                <td><?= $answer['score_delta'] === null ? '-' : esc(($answer['score_delta'] > 0 ? '+' : '') . (string) $answer['score_delta']) ?></td>
            </tr>
        <?php endforeach ?>
        <?php if ($report['answers'] === []): ?>
            <tr><td colspan="6">Belum ada jawaban.</td></tr>
        <?php endif ?>
        </tbody>
    </table>
</body>
</html>
