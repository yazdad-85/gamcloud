<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<?php $room = $snapshot['room']; ?>
<div class="topbar">
    <div>
        <h1 class="page-title"><?= esc($room['display_title'] ?? $room['title']) ?></h1>
        <p class="muted">PIN <strong><?= esc($room['pin']) ?></strong> / status <span data-room-status><?= esc($room['status']) ?></span></p>
        <p class="muted">
            Papan: <strong><?= esc((string) $snapshot['board']['tile_count']) ?> kotak</strong>
            / Mystery: <strong><?= esc((string) ($snapshot['board']['mystery_tile_count'] ?? 0)) ?> kotak</strong>
            /
            Mode:
            <strong><?= esc($room['mode_label'] ?? $snapshot['mode_state']['label'] ?? $room['game_mode'] ?? 'Ular Tangga Kuis') ?></strong>
            /
            Giliran pertama:
            <strong><?= esc(($room['turn_order_mode'] ?? 'random') === 'join_order' ? 'Urutan join' : 'Acak otomatis') ?></strong>
            / Finish:
            <strong><?= esc(($room['finish_rule'] ?? 'clamp_finish') === 'exact_finish' ? 'Harus pas' : 'Langsung finish') ?></strong>
        </p>
        <p class="muted">
            Soal:
            <strong><?= esc(($room['question_selection']['strategy'] ?? 'difficulty_zone') === 'difficulty_zone' ? 'Zona difficulty' : 'Acak semua soal') ?></strong>
            / Topik:
            <strong><?= esc(($room['question_selection']['topics'] ?? []) === [] ? 'Semua topik (room lama)' : implode(', ', array_column($room['question_selection']['topics'], 'name'))) ?></strong>
            / Total soal room:
            <strong><?= esc((string) ($snapshot['question_bank']['total'] ?? 0)) ?></strong>
        </p>
    </div>
    <div class="inline-actions">
        <a class="button secondary" href="/join/<?= esc($room['pin']) ?>">Join</a>
        <a class="button secondary" href="/game/<?= esc($room['uuid']) ?>/projector?t=<?= esc((string) ($room['projector_token'] ?? '')) ?>" target="_blank">Projector</a>
        <a class="button secondary" href="/teacher/games/<?= esc($room['uuid']) ?>/report">Laporan</a>
        <a class="button" href="/teacher/games/<?= esc($room['uuid']) ?>/control">Control</a>
        <?php if (in_array($room['status'], ['LOBBY', 'FINISHED', 'EXPIRED'], true)): ?>
            <form action="/teacher/games/<?= esc($room['uuid']) ?>/delete" method="post" onsubmit="return confirm('Hapus room game ini? Data tim, event, dan laporan room ini akan ikut dihapus.');">
                <?= csrf_field() ?>
                <button class="button danger" type="submit">Hapus</button>
            </form>
        <?php endif ?>
    </div>
</div>

<?php if (session('error') !== null): ?>
    <div class="alert" style="margin-bottom:14px"><?= esc(session('error')) ?></div>
<?php endif ?>

<section class="grid cols-2">
    <div class="panel">
        <h2>Tim</h2>
        <div class="leaderboard">
            <?php foreach ($snapshot['teams'] as $team): ?>
                <div class="leader-row">
                    <span class="pawn pawn-token avatar-<?= esc($team['avatar'] ?? 'robot') ?>" style="--team-color:<?= esc($team['color']) ?>; background:<?= esc($team['color']) ?>">
                        <span><?= esc(strtoupper(substr($team['name'], 0, 2))) ?></span>
                    </span>
                    <strong>
                        <?= esc($team['name']) ?>
                        <?php
                            $badges = [];
                            if (($team['streak_count'] ?? 0) >= 2) {
                                $badges[] = 'Streak x' . $team['streak_count'];
                            }
                            if (($team['active_effects']['safe_shield'] ?? 0) > 0) {
                                $badges[] = 'Perisai ' . $team['active_effects']['safe_shield'];
                            }
                        ?>
                        <?php if ($badges !== []): ?>
                            <small><?= esc(implode(' / ', $badges)) ?></small>
                        <?php endif ?>
                    </strong>
                    <span><?= esc((string) $team['score']) ?> poin</span>
                </div>
            <?php endforeach ?>
            <?php if ($snapshot['teams'] === []): ?>
                <p class="muted">Belum ada tim. Bagikan link join atau PIN.</p>
            <?php endif ?>
        </div>
    </div>
    <div class="panel">
        <h2>Link Cepat</h2>
        <p><a class="button secondary" href="/join/<?= esc($room['pin']) ?>">/join/<?= esc($room['pin']) ?></a></p>
        <p><a class="button secondary" href="/game/<?= esc($room['uuid']) ?>/projector?t=<?= esc((string) ($room['projector_token'] ?? '')) ?>">Buka Projector</a></p>
    </div>
</section>

<section class="panel" style="margin-top:16px">
    <h2>Bank Soal Game</h2>
    <p class="muted">Game hanya mengambil soal published dari topik yang dipilih saat room dibuat.</p>
    <div class="question-bank-metrics">
        <div><span>Total</span><strong><?= esc((string) ($snapshot['question_bank']['total'] ?? 0)) ?></strong></div>
        <div><span>Easy</span><strong><?= esc((string) ($snapshot['question_bank']['difficulty']['EASY'] ?? 0)) ?></strong></div>
        <div><span>Medium</span><strong><?= esc((string) ($snapshot['question_bank']['difficulty']['MEDIUM'] ?? 0)) ?></strong></div>
        <div><span>Hard</span><strong><?= esc((string) ($snapshot['question_bank']['difficulty']['HARD'] ?? 0)) ?></strong></div>
    </div>
    <p class="field-help">Jika stok suatu difficulty kosong, game mengambil difficulty lain tetapi tetap berada dalam topik terpilih.</p>
</section>
<?= $this->endSection() ?>
