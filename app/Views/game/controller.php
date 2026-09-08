<?= $this->extend('layouts/controller') ?>

<?= $this->section('content') ?>
<?php $room = $snapshot['room']; ?>
<section class="controller-wrap">
    <div class="panel">
        <p class="muted"><?= esc($room['title']) ?></p>
        <div class="team-identity">
            <span class="team-avatar-badge" data-team-avatar><span data-team-avatar-initials></span></span>
            <div>
                <h1 class="page-title" data-team-name>Tim</h1>
                <p><span data-turn-info>Menunggu giliran</span></p>
            </div>
        </div>
        <p>Skor <strong data-team-score>0</strong> / Kotak <strong data-team-position>1</strong></p>
    </div>

    <div class="alert hidden" data-error></div>
    <div class="move-feedback hidden" data-move-feedback></div>

    <div class="panel dice-panel" data-dice-panel>
        <div class="dice-stage">
            <div class="dice-face" data-dice-display>?</div>
        <div>
            <p class="dice-caption" data-dice-caption>Menunggu giliran</p>
            <p class="muted" data-roll-reason>Guru belum memulai permainan.</p>
            <p class="team-countdown" data-countdown>-</p>
        </div>
    </div>
        <button class="button roll-button" data-roll type="button">Lempar Dadu</button>
    </div>

    <div class="panel hidden" data-question>
        <h2 data-question-title>Pertanyaan</h2>
        <p data-question-stem></p>
        <p class="question-meta" data-question-meta></p>
        <div data-question-media></div>
        <div class="answer-list" data-options></div>
    </div>

    <div class="panel hidden" data-mystery-choice>
        <h2>Kotak Misteri</h2>
        <p class="muted">Pilih niatmu sebelum menjawab soal HARD.</p>
        <button class="button" type="button" data-mystery-self>Untuk Timku</button>
        <div class="answer-list" data-mystery-opponents></div>
    </div>

    <div class="panel">
        <h2>Papan</h2>
        <div class="board" data-board></div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
UlarTangga.controller({
    roomUuid: <?= json_encode($room['uuid']) ?>,
    teamUuid: <?= json_encode($teamUuid) ?>,
    snapshot: <?= json_encode($snapshot, JSON_UNESCAPED_SLASHES) ?>
});
</script>
<?= $this->endSection() ?>
