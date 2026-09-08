<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<?php $room = $snapshot['room']; ?>
<div class="topbar">
    <div>
        <h1 class="page-title">Control Game</h1>
        <p class="muted"><?= esc($room['title']) ?> / PIN <strong><?= esc($room['pin']) ?></strong></p>
    </div>
    <div class="control-actions">
        <button class="button" data-start <?= $room['status'] !== 'LOBBY' ? 'disabled' : '' ?>>Start</button>
        <button class="button secondary" data-pause type="button">Pause</button>
        <button class="button secondary" data-resume type="button">Resume</button>
        <button class="button secondary" data-skip-turn type="button">Skip Turn</button>
        <button class="button danger" data-force-timeout type="button">Force Timeout</button>
        <a class="button secondary" target="_blank" href="/game/<?= esc($room['uuid']) ?>/projector">Projector</a>
    </div>
</div>

<div class="alert hidden" data-error></div>

<section class="grid cols-2">
    <div class="panel">
        <h2>Status</h2>
        <p>Status: <strong data-room-status><?= esc($room['status']) ?></strong></p>
        <p>State version: <strong data-state-version><?= esc((string) $room['state_version']) ?></strong></p>
        <p>Giliran: <strong data-current-team>-</strong></p>
        <p>Mode game: <strong><?= esc($snapshot['mode_state']['label'] ?? $room['game_mode'] ?? 'Ular Tangga Kuis') ?></strong></p>
        <p>Kotak Mystery: <strong><?= esc((string) ($snapshot['board']['mystery_tile_count'] ?? 0)) ?></strong></p>
        <p>Pengambilan soal: <strong><?= esc(($room['question_selection']['strategy'] ?? 'difficulty_zone') === 'difficulty_zone' ? 'Zona difficulty' : 'Acak semua soal') ?></strong></p>
        <p>Topik soal: <strong><?= esc(($room['question_selection']['topics'] ?? []) === [] ? 'Semua topik (room lama)' : implode(', ', array_column($room['question_selection']['topics'], 'name'))) ?></strong></p>
        <p>Mode giliran: <strong><?= esc(($room['turn_order_mode'] ?? 'random') === 'join_order' ? 'Urutan join' : 'Acak otomatis') ?></strong></p>
        <p>Aturan finish: <strong><?= esc(($room['finish_rule'] ?? 'clamp_finish') === 'exact_finish' ? 'Harus pas' : 'Langsung finish') ?></strong></p>
        <p>Bank soal: <strong><?= esc((string) ($snapshot['question_bank']['total'] ?? 0)) ?></strong> soal published</p>
        <div class="leaderboard" data-leaderboard></div>
    </div>
    <div class="panel">
        <h2>Event</h2>
        <div class="event-log" data-events></div>
    </div>
</section>

<section class="panel" style="margin-top:16px">
    <div class="board" data-board></div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
UlarTangga.teacherControl({
    roomUuid: <?= json_encode($room['uuid'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    snapshot: <?= json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
});
</script>
<?= $this->endSection() ?>
