<?= $this->extend('layouts/projector') ?>

<?= $this->section('content') ?>
<?php $room = $snapshot['room']; ?>
<div class="projector-grid">
    <section>
        <div class="board" data-board></div>
        <div class="projector-event-overlay hidden" data-event-overlay></div>
    </section>
    <aside class="grid">
        <div class="panel">
            <h1 class="page-title"><?= esc($room['title']) ?></h1>
            <p>Mode <strong><?= esc($snapshot['mode_state']['label'] ?? 'Ular Tangga Kuis') ?></strong></p>
            <p>PIN <strong><?= esc($room['pin']) ?></strong></p>
            <p>Status <strong data-room-status><?= esc($room['status']) ?></strong></p>
            <p>Giliran <strong data-current-team>-</strong></p>
            <div class="countdown-card">
                <span>Sisa waktu</span>
                <strong data-countdown>-</strong>
                <div class="countdown-track"><span data-countdown-bar></span></div>
            </div>
        </div>
        <div class="panel">
            <h2>Leaderboard</h2>
            <div class="leaderboard" data-leaderboard></div>
        </div>
        <div class="panel">
            <h2>Event</h2>
            <div class="event-log" data-events></div>
        </div>
        <div class="alert hidden" data-error></div>
    </aside>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
UlarTangga.projector({
    roomUuid: <?= json_encode($room['uuid']) ?>,
    snapshot: <?= json_encode($snapshot, JSON_UNESCAPED_SLASHES) ?>
});
</script>
<?= $this->endSection() ?>
