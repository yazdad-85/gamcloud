<?php
$room = $report['room'];
$winner = $report['winner'];
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
            <strong>Pemenang (Juara Papan):</strong> <?= esc($winner['name']) ?><br>
            Kotak <?= esc((string) $winner['position']) ?> / <?= esc((string) ($room['max_position'] ?? '-')) ?> ·
            Skor <?= esc((string) $winner['score']) ?>
        <?php else: ?>
            <strong>Pemenang:</strong> Belum ada pemenang (status <?= esc($room['status']) ?>)
        <?php endif ?>
    </div>

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
</body>
</html>
