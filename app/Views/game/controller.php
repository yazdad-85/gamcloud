<?= $this->extend('layouts/controller') ?>

<?= $this->section('content') ?>
<?php $room = $snapshot['room']; ?>
<section class="controller-wrap">
    <div class="panel">
        <p class="muted"><?= esc($room['display_title'] ?? $room['title']) ?></p>
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
        <div class="tier-select hidden" data-tier-select>
            <button class="button tier-button" data-tier="EASY" type="button">EASY <span>+1</span></button>
            <button class="button tier-button" data-tier="MEDIUM" type="button">MEDIUM <span>+2</span></button>
            <button class="button tier-button" data-tier="HARD" type="button">HARD <span>+3</span></button>
        </div>
    </div>

    <div class="panel hidden" data-question>
        <h2 data-question-title>Pertanyaan</h2>
        <p data-question-stem></p>
        <p class="question-meta" data-question-meta></p>
        <div data-question-media></div>
        <div class="answer-list" data-options></div>
    </div>

    <div class="panel hidden" data-race-panel>
        <div class="race-progress">
            <span data-race-round-progress>Ronde -/-</span>
            <span data-race-question-progress>Soal -/-</span>
            <span class="team-countdown" data-race-countdown>-</span>
        </div>

        <div class="hidden" data-race-question-box>
            <h2>Pertanyaan</h2>
            <p data-race-question-stem></p>
            <p class="question-meta" data-race-question-meta></p>
            <div data-race-question-media></div>
            <div class="answer-list" data-race-options></div>
        </div>

        <div class="race-state hidden" data-race-waiting>
            <p>Jawaban terkirim, menunggu soal ditutup...</p>
        </div>

        <div class="race-state hidden" data-race-resolving>
            <p>Menghitung hasil...</p>
        </div>

        <div class="race-state hidden" data-race-result>
            <h2 data-race-result-outcome></h2>
            <p data-race-result-fastest class="hidden">Tercepat! Bonus gerak +2.</p>
            <p data-race-result-movement></p>
            <p data-race-result-tile class="hidden"></p>
            <p data-race-result-score></p>
        </div>

        <div class="race-state hidden" data-race-checkpoint>
            <h2>Checkpoint Ronde</h2>
            <p data-race-checkpoint-winners></p>
            <p data-race-checkpoint-prize></p>
        </div>

        <div class="race-state hidden" data-race-finished>
            <h2 data-race-finish-title>Race Selesai!</h2>
            <p data-race-finish-summary></p>
        </div>

        <div class="race-state hidden" data-race-waiting-room>
            <p data-race-waiting-room-text>Menunggu permainan dimulai.</p>
        </div>
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
    roomUuid: <?= json_encode($room['uuid'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    teamUuid: <?= json_encode($teamUuid, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    snapshot: <?= json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
});
</script>
<?= $this->endSection() ?>
