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
        <button class="button" data-start-timer type="button" hidden>Mulai Waktu Jawab</button>
        <a class="button secondary" target="_blank" href="/game/<?= esc($room['uuid']) ?>/projector?t=<?= esc((string) ($room['projector_token'] ?? '')) ?>">Projector</a>
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

<section class="panel roster-panel hidden" data-roster-panel style="margin-top:16px">
    <h2>Tambah Tim</h2>
    <p class="muted">Room ini memakai Mode Tanpa Device — tambahkan tim di sini sebelum menekan Start, tidak ada join PIN.</p>
    <form class="roster-add-form" data-roster-add-form>
        <input type="text" name="team_name" placeholder="Nama tim, mis. Tim Rajawali" maxlength="80" required>
        <button class="button" type="submit">Tambah Tim</button>
    </form>
    <div class="alert hidden" data-roster-error></div>
    <ul class="roster-list" data-roster-list></ul>
</section>

<section class="panel gameplay-panel hidden" data-gameplay-panel style="margin-top:16px">
    <h2>Giliran Sekarang</h2>
    <div class="team-identity">
        <span class="team-avatar-badge" data-team-avatar><span data-team-avatar-initials></span></span>
        <div>
            <h3 data-team-name>Tim</h3>
            <p><span data-turn-info>Menunggu giliran</span></p>
        </div>
    </div>
    <p>Skor <strong data-team-score>0</strong> / Kotak <strong data-team-position>1</strong></p>

    <div class="move-feedback hidden" data-move-feedback></div>

    <div class="dice-panel" data-dice-panel>
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

    <div class="gameplay-section hidden" data-question>
        <h3 data-question-title>Pertanyaan</h3>
        <p data-question-stem></p>
        <p class="question-meta" data-question-meta></p>
        <div data-question-media></div>
        <div class="answer-list" data-options></div>
    </div>

    <div class="gameplay-section hidden" data-mystery-choice>
        <h3>Kotak Misteri</h3>
        <p class="muted">Guru pilih niat tim sebelum soal HARD tampil.</p>
        <button class="button" type="button" data-mystery-self>Untuk Timku</button>
        <div class="answer-list" data-mystery-opponents></div>
    </div>
</section>

<section class="panel" style="margin-top:16px">
    <div class="board" data-board></div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="/assets/game-fx.js?v=<?= esc((string) @filemtime(FCPATH . 'assets/game-fx.js')) ?>"></script>
<script>
UlarTangga.teacherControl({
    roomUuid: <?= json_encode($room['uuid'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    snapshot: <?= json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
});
if (<?= json_encode(($snapshot['room']['participation_mode'] ?? 'TEAM_DEVICE') === 'TEACHER_CENTRALIZED') ?>) {
    UlarTangga.controller({
        roomUuid: <?= json_encode($room['uuid'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        snapshot: <?= json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        confirmBeforeAnswer: true,
        teamUuidResolver: function () {
            var el = document.querySelector('[data-current-team]');
            return el ? el.dataset.teamUuid : null;
        },
    });
}
</script>
<?= $this->endSection() ?>
