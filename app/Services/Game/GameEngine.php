<?php

namespace App\Services\Game;

use App\Models\BoardTemplateModel;
use App\Models\GameAnswerModel;
use App\Models\GameEventModel;
use App\Models\GameRoundAnswerModel;
use App\Models\GameRoundModel;
use App\Models\GameRoundQuestionModel;
use App\Models\GameRoomModel;
use App\Models\GameTeamModel;
use App\Models\GameTurnModel;
use App\Models\IdempotencyKeyModel;
use App\Models\QuestionModel;
use App\Models\QuestionOptionModel;
use App\Models\QuestionTopicModel;
use App\Models\ScoreTransactionModel;
use App\Services\Game\Modes\GameModeCatalog;
use App\Services\Realtime\ChannelName;
use App\Services\Realtime\RealtimeService;
use App\Services\Security\TenantContext;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Database;
use Config\Game as GameConfig;
use DomainException;

class GameEngine
{
    private BaseConnection $db;
    private GameConfig $config;
    private GameModeCatalog $modes;
    private RaceTrackService $race;
    private RaceRoundService $raceRounds;
    private RaceQuestionService $raceQuestions;

    public function __construct(
        private readonly RealtimeService $realtime = new RealtimeService()
    ) {
        $this->db = Database::connect();
        $this->config = config(GameConfig::class);
        $this->modes = new GameModeCatalog();
        $this->race = new RaceTrackService();
        $this->raceRounds = new RaceRoundService();
        $this->raceQuestions = new RaceQuestionService($this->race);
    }

    public function createRoom(int $teacherId, string $title, array $options = []): array
    {
        if (empty($options['skip_quota'])) {
            $this->assertTeacherRoomQuota($teacherId);
        }

        $gameModeKey = $this->validOption(strtoupper((string) ($options['game_mode'] ?? 'SNAKES_LADDERS')), $this->modes->playableKeys(), 'SNAKES_LADDERS');
        $gameMode = $this->modes->resolve($gameModeKey);
        $participationMode = $this->validOption(
            strtoupper((string) ($options['participation_mode'] ?? 'TEAM_DEVICE')),
            ['TEAM_DEVICE', 'TEACHER_CENTRALIZED'],
            'TEAM_DEVICE'
        );
        $boards = new BoardTemplateModel();
        $boardTemplateId = (int) ($options['board_template_id'] ?? 0);
        $board = null;
        if ($boardTemplateId > 0) {
            $board = $boards->where('id', $boardTemplateId)->where('status', 'ACTIVE')->where('game_mode', $gameModeKey)->first();
        }
        $board ??= (new BoardTemplateModel())->where('status', 'ACTIVE')->where('game_mode', $gameModeKey)->first();
        if ($board === null) {
            throw new DomainException('Board template belum tersedia. Jalankan seeder demo lebih dulu.');
        }

        $finishRule = $this->validOption((string) ($options['finish_rule'] ?? 'clamp_finish'), ['clamp_finish', 'exact_finish'], 'clamp_finish');
        $lapCount = 1;
        $raceQuestionLimit = null;
        $raceRoundQuestionCounts = null;
        $raceRoundWinnerBonusPoints = null;

        if ($gameModeKey === 'QUIZ_RACE') {
            $trackLength = max(6, min(60, (int) ($options['track_length'] ?? 24)));
            $board = $this->applyRaceTrackLength($board, $trackLength);
            if ($participationMode === 'TEAM_DEVICE') {
                $allocationSource = $options['race_round_question_counts']
                    ?? $options['race_round_question_counts_json']
                    ?? null;
                $raceRoundQuestionCounts = $this->raceRounds->normalizeAllocation($allocationSource);
                $raceQuestionLimit = array_sum($raceRoundQuestionCounts);
                $raceRoundWinnerBonusPoints = max(0, (int) ($options['race_round_winner_bonus_points'] ?? 100));
                $lapCount = count($raceRoundQuestionCounts);
            } else {
                $lapCount = max(1, min(10, (int) ($options['lap_count'] ?? 5)));
            }
            $finishRule = 'clamp_finish';
        } else {
            if (isset($options['board_size']) && in_array((int) $options['board_size'], [50, 70], true)) {
                $board = $this->applyBoardSize($board, (int) $options['board_size']);
            }

            if (isset($options['mystery_tile_count'])) {
                $requestedMysteryCount = max(0, min(6, (int) $options['mystery_tile_count']));
                $board = $this->applyMysteryTileCount($board, $requestedMysteryCount);
                if ($this->specialTileCount($board, 'MYSTERY') !== $requestedMysteryCount) {
                    throw new DomainException('Konfigurasi Kotak Mystery gagal diterapkan. Room tidak dibuat.');
                }
            }
        }

        $pin = $this->uniquePin();
        $projectorToken = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $turnOrderMode = $this->validOption((string) ($options['turn_order_mode'] ?? 'random'), ['random', 'join_order'], 'random');
        $scoring = $this->scoringRules($options['scoring'] ?? []);
        if ($gameModeKey === 'QUIZ_RACE') {
            $scoring['near_finish_bonus'] = false;
        }
        $questionSelectionSource = is_array($options['question_selection'] ?? null)
            ? $options['question_selection']
            : [];
        if (array_key_exists('topic_uuids', $questionSelectionSource)) {
            $questionSelectionSource = array_merge(
                $questionSelectionSource,
                $this->resolveQuestionTopics($teacherId, $questionSelectionSource['topic_uuids'])
            );
        }
        $questionSelection = $this->questionSelectionRules($questionSelectionSource, (int) $board['tile_count']);
        $baseRoomState = [
            'max_position' => (int) $board['tile_count'],
        ];
        $roomId = (new GameRoomModel())->insert([
            'public_uuid' => Uuid::v4(),
            'teacher_id' => $teacherId,
            'board_template_id' => $board['id'],
            'pin' => $pin,
            'projector_token' => $projectorToken,
            'projector_token_hash' => hash('sha256', $projectorToken),
            'title' => $title,
            'status' => 'LOBBY',
            'state_version' => 1,
            'question_time_seconds' => $this->config->defaultQuestionTime,
            'redemption_time_seconds' => $this->config->redemptionTime,
            'max_teams' => 6,
            'max_position' => (int) $board['tile_count'],
            'lap_count' => $lapCount,
            'game_mode' => $gameMode->key(),
            'participation_mode' => $participationMode,
            'mode_state_json' => json_encode($gameMode->initialState($baseRoomState, $board), JSON_UNESCAPED_SLASHES),
            'turn_order_mode' => $turnOrderMode,
            'finish_rule' => $finishRule,
            'scoring_json' => json_encode($scoring, JSON_UNESCAPED_SLASHES),
            'question_selection_json' => json_encode($questionSelection, JSON_UNESCAPED_SLASHES),
            'race_question_limit' => $raceQuestionLimit,
            'race_round_question_counts_json' => $raceRoundQuestionCounts,
            'race_round_winner_bonus_points' => $raceRoundWinnerBonusPoints,
            'expires_at' => date('Y-m-d H:i:s', time() + ($this->config->pinTtlMinutes * 60)),
            'created_at' => $now,
            'updated_at' => $now,
        ], true);

        $room = $this->roomById((int) $roomId);
        $this->recordEvent($room, 'room.created', [
            'pin' => $pin,
            'title' => $title,
            'game_mode' => $gameMode->key(),
            'question_topics' => $questionSelection['topics'],
            'mystery_tile_count' => $this->specialTileCount($board, 'MYSTERY'),
        ]);

        return $this->snapshot($room['public_uuid'], null, true);
    }

    public function joinByPin(string $pin, string $teamName, string $avatar = 'robot'): array
    {
        $room = (new GameRoomModel())->where('pin', strtoupper($pin))->first();
        if ($room === null) {
            throw new DomainException('PIN tidak ditemukan.');
        }
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa. Minta guru membuat room baru.');

        if ($room['status'] !== 'LOBBY') {
            throw new DomainException('Room sudah tidak menerima tim baru.');
        }

        if (($room['participation_mode'] ?? 'TEAM_DEVICE') === 'TEACHER_CENTRALIZED') {
            throw new DomainException('Room ini memakai Mode Tanpa Device — ikuti permainan dari layar guru di depan kelas, tidak perlu join PIN.');
        }

        $result = $this->insertTeamIntoRoom($room, $teamName, $avatar);

        return $result + ['snapshot' => $this->snapshot($result['room']['public_uuid'])];
    }

    public function addTeamByOwner(string $roomUuid, string $teamName, string $avatar = 'robot'): array
    {
        $room = (new TenantContext())->assertRoomOwner($roomUuid);
        if (($room['participation_mode'] ?? 'TEAM_DEVICE') !== 'TEACHER_CENTRALIZED') {
            throw new DomainException('Room ini memakai Device per Tim, tim ditambahkan lewat join PIN.');
        }
        if ($room['status'] !== 'LOBBY') {
            throw new DomainException('Tim hanya bisa ditambah selama room di status LOBBY.');
        }

        $result = $this->insertTeamIntoRoom($room, $teamName, $avatar);

        return $result + ['snapshot' => $this->snapshot($result['room']['public_uuid'])];
    }

    public function removeTeamByOwner(string $roomUuid, string $teamUuid): array
    {
        $room = (new TenantContext())->assertRoomOwner($roomUuid);
        if ($room['status'] !== 'LOBBY') {
            throw new DomainException('Tim hanya bisa dihapus selama room di status LOBBY.');
        }

        $team = $this->teamByUuid($teamUuid, (int) $room['id']);
        (new GameTeamModel())->delete($team['id']);
        $this->bumpRoom($room['id']);
        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'room.team_removed', [
            'team_uuid' => $team['public_uuid'],
            'team_name' => $team['name'],
        ]);

        return $this->snapshot($room['public_uuid'], null, true);
    }

    private function insertTeamIntoRoom(array $room, string $teamName, string $avatar): array
    {
        $teamName = trim($teamName);
        if ($teamName === '') {
            throw new DomainException('Nama tim wajib diisi.');
        }
        if (strlen($teamName) > 80) {
            throw new DomainException('Nama tim maksimal 80 karakter.');
        }

        $teamCount = (new GameTeamModel())->where('room_id', $room['id'])->countAllResults();
        if ($teamCount >= (int) $room['max_teams']) {
            throw new DomainException('Room sudah penuh.');
        }

        $token = bin2hex(random_bytes(24));
        $colors = ['#2563eb', '#dc2626', '#16a34a', '#9333ea', '#ea580c', '#0891b2'];
        $avatar = $this->validOption($avatar, $this->avatarKeys(), 'robot');
        $teamId = (new GameTeamModel())->insert([
            'public_uuid' => Uuid::v4(),
            'room_id' => $room['id'],
            'name' => $teamName,
            'color' => $colors[$teamCount % count($colors)],
            'avatar' => $avatar,
            'session_token_hash' => hash('sha256', $token),
            'position' => 1,
            'score' => 0,
            'streak_count' => 0,
            'active_effects_json' => json_encode(['safe_shield' => 0], JSON_UNESCAPED_SLASHES),
            'is_connected' => 1,
            'joined_at' => date('Y-m-d H:i:s'),
        ], true);

        $team = (new GameTeamModel())->find($teamId);
        $this->bumpRoom($room['id']);
        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'room.team_joined', ['team' => $this->publicTeam($team)]);

        return ['room' => $room, 'team' => $team, 'token' => $token];
    }

    public function start(string $roomUuid): array
    {
        $room = $this->roomByUuid($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa dan tidak bisa dimulai.');
        if ($room['status'] !== 'LOBBY') {
            throw new DomainException('Game hanya dapat dimulai dari status LOBBY.');
        }

        $teams = $this->teams((int) $room['id']);
        if (count($teams) < 1) {
            throw new DomainException('Minimal satu tim harus join sebelum game dimulai.');
        }

        if (($room['game_mode'] ?? 'SNAKES_LADDERS') === 'QUIZ_RACE'
            && ($room['participation_mode'] ?? 'TEAM_DEVICE') === 'TEAM_DEVICE') {
            return $this->startTeamDeviceRace($room, $teams);
        }

        $firstTeam = $this->firstTeamForStart($teams, (string) ($room['turn_order_mode'] ?? 'random'));

        $this->db->transStart();

        (new GameRoomModel())->update($room['id'], [
            'status' => 'PLAYING',
            'current_team_id' => $firstTeam['id'],
            'started_at' => date('Y-m-d H:i:s'),
        ]);
        $this->bumpRoom($room['id']);
        $room = $this->roomById((int) $room['id']);
        $this->createTurn($room, $firstTeam, 1);

        $this->db->transComplete();

        $this->recordEvent($room, 'turn_order.selected', [
            'mode' => $room['turn_order_mode'] ?? 'random',
            'current_team_uuid' => $firstTeam['public_uuid'],
        ]);
        $this->recordEvent($room, 'game.started', [
            'current_team_uuid' => $firstTeam['public_uuid'],
            'teams' => array_map([$this, 'publicTeam'], $teams),
        ]);

        return $this->snapshot($room['public_uuid']);
    }

    private function startTeamDeviceRace(array $room, array $teams): array
    {
        $startedAtEpochMs = (int) floor(microtime(true) * 1000);
        $startedAt = date('Y-m-d H:i:s', intdiv($startedAtEpochMs, 1000));

        $this->db->transBegin();
        try {
            (new GameRoomModel())->update($room['id'], [
                'status' => 'PLAYING',
                'current_team_id' => null,
                'started_at' => $startedAt,
            ]);
            $this->bumpRoom((int) $room['id']);
            $room = $this->roomById((int) $room['id']);
            $round = $this->createRaceRound($room, 1, $startedAt);
            $questionResult = $this->createRaceQuestion($room, $round, 1, $startedAtEpochMs);

            if (! $this->db->transStatus()) {
                throw new DomainException('Quiz Race gagal dimulai secara atomik.');
            }
            $this->db->transCommit();
        } catch (\Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }

        $question = $questionResult['record'];
        $selectedQuestion = $questionResult['selected_question'];
        $this->recordEvent($room, 'race.round_started', [
            'round_uuid' => $round['public_uuid'],
            'round_number' => (int) $round['round_number'],
            'question_target_count' => (int) $round['question_target_count'],
            'difficulty_schedule' => $round['difficulty_schedule_json'],
        ]);
        if ($questionResult['pool_recycled']) {
            $this->recordEvent($room, 'question.pool_recycled', [
                'reason' => 'exhausted',
                'scope' => 'race_question',
            ]);
        }
        $this->recordEvent($room, 'race.question_started', [
            'round_uuid' => $round['public_uuid'],
            'question_uuid' => $question['public_uuid'],
            'question_number' => (int) $question['question_number'],
            'question_id' => (int) $question['question_id'],
            'difficulty' => $question['difficulty'],
            'started_at_epoch_ms' => (int) $question['started_at_epoch_ms'],
            'deadline_epoch_ms' => (int) $question['deadline_epoch_ms'],
            'selection' => [
                'requested_difficulty' => $question['difficulty'],
                'selected_difficulty' => $selectedQuestion['difficulty'],
            ],
        ]);
        $this->recordEvent($room, 'game.started', [
            'current_team_uuid' => null,
            'teams' => array_map([$this, 'publicTeam'], $teams),
        ]);

        return $this->snapshot($room['public_uuid']);
    }

    private function createRaceRound(array $room, int $roundNumber, string $startedAt): array
    {
        $allocation = $this->raceRounds->normalizeAllocation($room['race_round_question_counts_json'] ?? null);
        $questionTarget = $allocation[$roundNumber - 1] ?? null;
        if ($questionTarget === null) {
            throw new DomainException('Alokasi ronde Quiz Race tidak tersedia.');
        }

        $roundId = (new GameRoundModel())->insert([
            'public_uuid' => Uuid::v4(),
            'room_id' => $room['id'],
            'round_number' => $roundNumber,
            'state' => 'ROUND_ACTIVE',
            'question_target_count' => $questionTarget,
            'question_resolved_count' => 0,
            'difficulty_schedule_json' => $this->raceRounds->difficultySchedule($questionTarget),
            'started_at' => $startedAt,
        ], true);

        $this->db->table('game_teams')
            ->set('streak_count', 0)
            ->where('room_id', $room['id'])
            ->update();

        return (new GameRoundModel())->find($roundId);
    }

    /**
     * @return array{record:array<string,mixed>,selected_question:array<string,mixed>,pool_recycled:bool}
     */
    private function createRaceQuestion(
        array $room,
        array $round,
        int $questionNumber,
        int $startedAtEpochMs
    ): array {
        $schedule = $round['difficulty_schedule_json'] ?? [];
        $difficulty = $schedule[$questionNumber - 1] ?? null;
        if (! is_string($difficulty)) {
            throw new DomainException('Jadwal difficulty ronde Quiz Race tidak valid.');
        }

        $selection = $this->questionSelectionRules(
            $room['question_selection_json'] ?? [],
            (int) $room['max_position']
        );
        $recycled = false;
        $selectedQuestion = $this->selectQuestion(
            (int) $room['teacher_id'],
            $difficulty,
            $selection['topic_ids'],
            (int) $room['id'],
            $recycled
        );
        $deadlineAtEpochMs = $startedAtEpochMs + ((int) $room['question_time_seconds'] * 1000);
        $questionId = (new GameRoundQuestionModel())->insert([
            'public_uuid' => Uuid::v4(),
            'round_id' => $round['id'],
            'question_number' => $questionNumber,
            'question_id' => $selectedQuestion['id'],
            'difficulty' => $difficulty,
            'state' => 'QUESTION_ACTIVE',
            'answer_count' => 0,
            'started_at' => date('Y-m-d H:i:s', intdiv($startedAtEpochMs, 1000)),
            'started_at_epoch_ms' => $startedAtEpochMs,
            'deadline_at' => date('Y-m-d H:i:s', intdiv($deadlineAtEpochMs, 1000)),
            'deadline_epoch_ms' => $deadlineAtEpochMs,
        ], true);

        return [
            'record' => (new GameRoundQuestionModel())->find($questionId),
            'selected_question' => $selectedQuestion,
            'pool_recycled' => $recycled,
        ];
    }

    public function raceQuestionAnswer(
        string $roomUuid,
        string $teamUuid,
        int $optionId,
        ?string $idempotencyKey = null
    ): array {
        $scope = null;
        $roundQuestionId = null;
        $teamId = null;
        $response = null;

        $this->db->transBegin();
        try {
            $room = $this->roomByUuid($roomUuid);
            $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa. Permainan tidak bisa dilanjutkan.');
            if ($room['status'] !== 'PLAYING') {
                throw new DomainException('Game belum dalam status PLAYING.');
            }
            if (($room['game_mode'] ?? 'SNAKES_LADDERS') !== 'QUIZ_RACE'
                || ($room['participation_mode'] ?? 'TEAM_DEVICE') !== 'TEAM_DEVICE') {
                throw new DomainException('Jawaban siklus soal hanya tersedia untuk Quiz Race Device per Tim.');
            }

            $team = $this->teamByUuid($teamUuid, (int) $room['id']);
            $teamId = (int) $team['id'];
            $round = $this->activeRaceRound((int) $room['id']);
            if ($round === null) {
                throw new DomainException('Tidak ada ronde Quiz Race aktif.');
            }
            $question = $this->activeRaceQuestion((int) $round['id']);
            if ($question === null) {
                throw new DomainException('Tidak ada pertanyaan Quiz Race aktif untuk dijawab.');
            }

            $roundQuestionId = (int) $question['id'];
            $scope = implode(':', [
                'race-question-answer',
                $room['public_uuid'],
                $question['public_uuid'],
                $team['public_uuid'],
            ]);
            if ($existing = $this->idempotentResponse($scope, $idempotencyKey)) {
                $this->db->transCommit();

                return $existing;
            }

            $option = (new QuestionOptionModel())
                ->where('question_id', $question['question_id'])
                ->where('id', $optionId)
                ->first();
            if ($option === null) {
                throw new DomainException('Pilihan jawaban tidak valid.');
            }

            $answeredAtEpochMs = $this->currentEpochMs();
            if ($answeredAtEpochMs >= (int) $question['deadline_epoch_ms']) {
                throw new DomainException('Waktu menjawab pertanyaan Quiz Race sudah habis.');
            }

            $this->db->table('game_round_questions')
                ->set('answer_count', 'answer_count + 1', false)
                ->where('id', $roundQuestionId)
                ->where('state', 'QUESTION_ACTIVE')
                ->where('deadline_epoch_ms >', $answeredAtEpochMs)
                ->update();
            if ($this->db->affectedRows() !== 1) {
                throw new DomainException('Pertanyaan Quiz Race sudah ditutup atau melewati deadline.');
            }

            $isCorrect = (int) $option['is_correct'] === 1;
            (new GameRoundAnswerModel())->insert([
                'public_uuid' => Uuid::v4(),
                'round_question_id' => $roundQuestionId,
                'team_id' => $teamId,
                'question_id' => $question['question_id'],
                'option_id' => $option['id'],
                'answer_text' => $option['body'],
                'is_correct' => $isCorrect ? 1 : 0,
                'outcome' => $isCorrect ? 'CORRECT' : 'WRONG',
                'answered_at' => date('Y-m-d H:i:s', intdiv($answeredAtEpochMs, 1000)),
                'answered_at_epoch_ms' => $answeredAtEpochMs,
                'response_ms' => max(0, $answeredAtEpochMs - (int) $question['started_at_epoch_ms']),
            ]);

            $this->bumpRoom((int) $room['id']);
            $response = $this->snapshot($room['public_uuid']);
            $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

            if (! $this->db->transStatus()) {
                throw new DomainException('Jawaban Quiz Race gagal disimpan secara atomik.');
            }
            $this->db->transCommit();
        } catch (\Throwable $error) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
            if ($scope !== null && ($existing = $this->idempotentResponse($scope, $idempotencyKey))) {
                return $existing;
            }
            if ($roundQuestionId !== null && $teamId !== null
                && (new GameRoundAnswerModel())
                    ->where('round_question_id', $roundQuestionId)
                    ->where('team_id', $teamId)
                    ->first() !== null) {
                throw new DomainException('Tim sudah menjawab pertanyaan Quiz Race ini.');
            }

            throw $error;
        }

        $room = $this->roomByUuid($roomUuid);
        $question = (new GameRoundQuestionModel())->find($roundQuestionId);
        if ((int) $question['answer_count'] >= count($this->teams((int) $room['id']))) {
            $this->afterRaceQuestionAnswersComplete($room, $question);
        }
        $this->recordEvent($room, 'race.answer_submitted', [
            'question_uuid' => $question['public_uuid'],
            'team_uuid' => $teamUuid,
            'answered' => true,
        ]);

        return $response;
    }

    private function activeRaceRound(int $roomId): ?array
    {
        return (new GameRoundModel())
            ->where('room_id', $roomId)
            ->where('state', 'ROUND_ACTIVE')
            ->orderBy('round_number', 'DESC')
            ->first();
    }

    private function activeRaceQuestion(int $roundId): ?array
    {
        return (new GameRoundQuestionModel())
            ->where('round_id', $roundId)
            ->where('state', 'QUESTION_ACTIVE')
            ->orderBy('question_number', 'DESC')
            ->first();
    }

    private function afterRaceQuestionAnswersComplete(array $room, array $question): void
    {
        try {
            $this->resolveRaceQuestion($room['public_uuid']);
        } catch (DomainException) {
            // A concurrent resolver (polling or teacher force) already claimed this question.
        }
    }

    public function resolveRaceQuestion(
        string $roomUuid,
        bool $force = false,
        ?string $idempotencyKey = null
    ): array {
        $scope = null;
        $response = null;
        $eventContext = null;

        $this->db->transBegin();
        try {
            $room = $this->roomByUuid($roomUuid);
            $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa. Permainan tidak bisa dilanjutkan.');
            if ($room['status'] !== 'PLAYING') {
                throw new DomainException('Game belum dalam status PLAYING.');
            }
            if (($room['game_mode'] ?? 'SNAKES_LADDERS') !== 'QUIZ_RACE'
                || ($room['participation_mode'] ?? 'TEAM_DEVICE') !== 'TEAM_DEVICE') {
                throw new DomainException('Resolusi siklus soal hanya tersedia untuk Quiz Race Device per Tim.');
            }

            $round = $this->activeRaceRound((int) $room['id']);
            if ($round === null) {
                throw new DomainException('Tidak ada ronde Quiz Race aktif.');
            }
            $question = $this->activeRaceQuestion((int) $round['id']);
            if ($question === null) {
                throw new DomainException('Tidak ada pertanyaan Quiz Race aktif untuk diselesaikan.');
            }

            $roundQuestionId = (int) $question['id'];
            $scope = implode(':', ['race-question-resolve', $room['public_uuid'], $question['public_uuid']]);
            if ($existing = $this->idempotentResponse($scope, $idempotencyKey)) {
                $this->db->transCommit();

                return $existing;
            }

            $teams = $this->teams((int) $room['id']);
            $answeredCount = (new GameRoundAnswerModel())->where('round_question_id', $roundQuestionId)->countAllResults();
            $resolveEpochMs = $this->currentEpochMs();
            $deadlinePassed = $resolveEpochMs >= (int) $question['deadline_epoch_ms'];
            if (! $force && $answeredCount < count($teams) && ! $deadlinePassed) {
                throw new DomainException('Belum semua tim menjawab dan waktu belum habis.');
            }

            $this->db->table('game_round_questions')
                ->set('state', 'QUESTION_RESOLVING')
                ->where('id', $roundQuestionId)
                ->where('state', 'QUESTION_ACTIVE')
                ->update();
            if ($this->db->affectedRows() !== 1) {
                throw new DomainException('Pertanyaan Quiz Race sudah diselesaikan atau tidak aktif lagi.');
            }

            $resolvedAt = date('Y-m-d H:i:s', intdiv($resolveEpochMs, 1000));
            $answeredTeamIds = array_map(
                static fn (array $answer): int => (int) $answer['team_id'],
                (new GameRoundAnswerModel())->where('round_question_id', $roundQuestionId)->findAll()
            );
            foreach ($teams as $team) {
                if (in_array((int) $team['id'], $answeredTeamIds, true)) {
                    continue;
                }
                (new GameRoundAnswerModel())->insert([
                    'public_uuid' => Uuid::v4(),
                    'round_question_id' => $roundQuestionId,
                    'team_id' => $team['id'],
                    'question_id' => $question['question_id'],
                    'outcome' => 'TIMEOUT',
                    'is_correct' => 0,
                    'answered_at' => $resolvedAt,
                ]);
            }

            $answers = (new GameRoundAnswerModel())->where('round_question_id', $roundQuestionId)->findAll();
            $answersByTeamId = [];
            foreach ($answers as $answer) {
                $answersByTeamId[(int) $answer['team_id']] = $answer;
            }
            $answerInput = array_map(static fn (array $answer): array => [
                'team_id' => (int) $answer['team_id'],
                'outcome' => $answer['outcome'],
                'is_correct' => (bool) $answer['is_correct'],
                'response_ms' => $answer['response_ms'] !== null ? (int) $answer['response_ms'] : null,
            ], $answers);

            $board = (new BoardTemplateModel())->find($room['board_template_id']);
            $resolution = $this->raceQuestions->resolveMovements($teams, $answerInput, $room, $board);

            $rules = $this->scoringRules($room['scoring_json'] ?? []);
            $teamsById = [];
            foreach ($teams as $team) {
                $teamsById[(int) $team['id']] = $team;
            }

            $movementSummary = [];
            foreach ($resolution['movements'] as $movement) {
                $teamId = (int) $movement['team_id'];
                $team = $teamsById[$teamId];
                $isCorrect = $movement['outcome'] === 'CORRECT';
                $newStreak = $isCorrect ? ((int) ($team['streak_count'] ?? 0) + 1) : 0;

                $basePoints = $this->raceAnswerBasePoints($movement['outcome'], $rules);
                $timeBonus = ($isCorrect && $rules['time_bonus'])
                    ? $this->raceTimeBonus($movement['response_ms'], $question)
                    : 0;
                $streakBonus = ($isCorrect && $rules['streak_bonus'])
                    ? $this->streakBonus($newStreak)
                    : 0;
                $specialPoints = (int) ($movement['score_delta'] ?? 0);
                $totalDelta = $basePoints + $timeBonus + $streakBonus + $specialPoints;
                $breakdown = [
                    'answer' => $basePoints,
                    'time_bonus' => $timeBonus,
                    'streak_bonus' => $streakBonus,
                    'near_finish_bonus' => 0,
                ];

                (new GameTeamModel())->update($teamId, [
                    'position' => $movement['to'],
                    'score' => (int) $team['score'] + $totalDelta,
                    'streak_count' => $newStreak,
                    'active_effects_json' => json_encode($movement['active_effects'], JSON_UNESCAPED_SLASHES),
                ]);

                (new GameRoundAnswerModel())->update($answersByTeamId[$teamId]['id'], [
                    'score_delta' => $totalDelta,
                    'score_breakdown_json' => $breakdown,
                ]);

                if ($basePoints !== 0) {
                    $type = $isCorrect ? 'ANSWER_CORRECT' : ($movement['outcome'] === 'TIMEOUT' ? 'ANSWER_TIMEOUT' : 'ANSWER_WRONG');
                    $reason = $isCorrect ? 'Jawaban benar Quiz Race' : ($movement['outcome'] === 'TIMEOUT' ? 'Penalti timeout Quiz Race' : 'Penalti jawaban salah Quiz Race');
                    $this->recordScore($room, $team, $type, $basePoints, $reason);
                }
                if ($timeBonus !== 0) {
                    $this->recordScore($room, $team, 'TIME_BONUS', $timeBonus, 'Bonus jawab cepat Quiz Race');
                }
                if ($streakBonus !== 0) {
                    $this->recordScore($room, $team, 'STREAK_BONUS', $streakBonus, 'Bonus streak Quiz Race');
                }

                $movementSummary[(string) $teamId] = [
                    'team_uuid' => $team['public_uuid'],
                    'from' => $movement['from'],
                    'landed' => $movement['landed'],
                    'to' => $movement['to'],
                    'special' => $movement['special'],
                    'effects' => $movement['effects'],
                    'outcome' => $movement['outcome'],
                    'response_ms' => $movement['response_ms'],
                    'steps' => $movement['steps'],
                    'score_delta' => $totalDelta,
                    'score_breakdown' => $breakdown,
                ];
            }

            (new GameRoundModel())->update($round['id'], [
                'question_resolved_count' => (int) $round['question_resolved_count'] + 1,
            ]);

            $fastestTeamIds = $resolution['fastest_team_ids'];
            $finisherTeamIds = $resolution['finisher_team_ids'];
            $finished = $finisherTeamIds !== [];
            $revealEpochMs = $resolveEpochMs + ($this->config->raceQuestionRevealSeconds * 1000);

            (new GameRoundQuestionModel())->update($roundQuestionId, [
                'state' => 'QUESTION_RESOLVED',
                'resolved_at' => $resolvedAt,
                'reveal_until' => date('Y-m-d H:i:s', intdiv($revealEpochMs, 1000)),
                'reveal_until_epoch_ms' => $revealEpochMs,
                'fastest_team_ids_json' => $fastestTeamIds,
                'finisher_team_ids_json' => $finisherTeamIds,
                'movement_summary_json' => $movementSummary,
            ]);

            $winnerTeamIds = [];
            if ($finished) {
                $aggregateStats = $this->raceTeamAggregateStats((int) $room['id']);
                $updatedTeamsById = [];
                foreach ((new GameTeamModel())->whereIn('id', $finisherTeamIds)->findAll() as $updatedTeam) {
                    $updatedTeamsById[(int) $updatedTeam['id']] = $updatedTeam;
                }
                $finisherStats = array_map(static function (int $teamId) use ($updatedTeamsById, $aggregateStats): array {
                    $stats = $aggregateStats[$teamId] ?? ['correct_count' => 0, 'correct_response_ms' => 0];

                    return [
                        'team_id' => $teamId,
                        'score' => (int) ($updatedTeamsById[$teamId]['score'] ?? 0),
                        'correct_count' => $stats['correct_count'],
                        'correct_response_ms' => $stats['correct_response_ms'],
                    ];
                }, $finisherTeamIds);
                $winnerTeamIds = $this->raceQuestions->rankFinishers($finisherStats)['winner_team_ids'];

                (new GameRoomModel())->update($room['id'], [
                    'status' => 'FINISHED',
                    'finished_at' => $resolvedAt,
                ]);
                (new GameRoundModel())->update($round['id'], [
                    'state' => 'ROUND_INTERRUPTED',
                    'completed_at' => $resolvedAt,
                ]);
            }

            $this->bumpRoom((int) $room['id']);
            $room = $this->roomById((int) $room['id']);
            $response = $this->snapshot($room['public_uuid']);
            $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

            $eventContext = [
                'room' => $room,
                'round' => $round,
                'question' => (new GameRoundQuestionModel())->find($roundQuestionId),
                'movement_summary' => $movementSummary,
                'fastest_team_ids' => $fastestTeamIds,
                'finisher_team_ids' => $finisherTeamIds,
                'finished' => $finished,
                'winner_team_ids' => $winnerTeamIds,
            ];

            if (! $this->db->transStatus()) {
                throw new DomainException('Resolusi Quiz Race gagal disimpan secara atomik.');
            }
            $this->db->transCommit();
        } catch (\Throwable $error) {
            $this->db->transRollback();
            $this->db->resetTransStatus();
            if ($scope !== null && ($existing = $this->idempotentResponse($scope, $idempotencyKey))) {
                return $existing;
            }

            throw $error;
        }

        $this->publishRaceQuestionResolvedEvents($eventContext);

        return $response;
    }

    private function publishRaceQuestionResolvedEvents(array $context): void
    {
        $room = $context['room'];
        $round = $context['round'];
        $question = $context['question'];

        $teamUuidsById = [];
        foreach ($this->teams((int) $room['id']) as $team) {
            $teamUuidsById[(int) $team['id']] = $team['public_uuid'];
        }
        $toUuids = static fn (array $ids): array => array_values(array_filter(array_map(
            static fn ($id) => $teamUuidsById[(int) $id] ?? null,
            $ids
        )));

        $this->recordEvent($room, 'race.question_resolved', [
            'round_uuid' => $round['public_uuid'],
            'round_number' => (int) $round['round_number'],
            'question_uuid' => $question['public_uuid'],
            'question_number' => (int) $question['question_number'],
            'movement' => $context['movement_summary'],
            'fastest_team_uuids' => $toUuids($context['fastest_team_ids']),
            'finisher_team_uuids' => $toUuids($context['finisher_team_ids']),
            'reveal_until_epoch_ms' => (int) $question['reveal_until_epoch_ms'],
        ]);

        foreach ($context['movement_summary'] as $summary) {
            foreach ($summary['effects'] as $effect) {
                $this->recordEvent($room, 'tile.special_triggered', [
                    'team_uuid' => $summary['team_uuid'],
                    'effect' => $effect,
                    'movement' => [
                        'from' => $summary['from'],
                        'landed' => $summary['landed'],
                        'to' => $summary['to'],
                    ],
                ]);
            }
        }

        if ($context['finished']) {
            $winnerUuids = $toUuids($context['winner_team_ids']);
            $this->recordEvent($room, 'race.round_interrupted', [
                'round_uuid' => $round['public_uuid'],
                'round_number' => (int) $round['round_number'],
                'reason' => 'TRACK_FINISH',
            ]);
            $this->recordEvent($room, 'game.finished', [
                'finish_reason' => 'TRACK_FINISH',
                'winner_team_uuids' => $winnerUuids,
                'winner_team_uuid' => $winnerUuids[0] ?? null,
            ]);
        }
    }

    private function raceAnswerBasePoints(string $outcome, array $rules): int
    {
        if ($outcome === 'CORRECT') {
            return $this->config->correctAnswerPoints;
        }
        if ($outcome === 'TIMEOUT') {
            return $rules['timeout_penalty'] ? $this->config->timeoutPenaltyPoints : $this->config->wrongAnswerPoints;
        }

        return $rules['wrong_penalty'] ? $this->config->wrongPenaltyPoints : $this->config->wrongAnswerPoints;
    }

    private function raceTimeBonus(?int $responseMs, array $question): int
    {
        if ($responseMs === null) {
            return 0;
        }

        $total = (int) $question['deadline_epoch_ms'] - (int) $question['started_at_epoch_ms'];
        if ($total <= 0) {
            return 0;
        }

        $remaining = max(0, $total - $responseMs);

        return min($this->config->maxTimeBonusPoints, max(0, (int) floor(($remaining / $total) * $this->config->maxTimeBonusPoints)));
    }

    /**
     * @return array<int, array{correct_count:int, correct_response_ms:int}>
     */
    private function raceTeamAggregateStats(int $roomId): array
    {
        $rows = $this->db->table('game_round_answers')
            ->select('game_round_answers.team_id, game_round_answers.is_correct, game_round_answers.response_ms')
            ->join('game_round_questions', 'game_round_questions.id = game_round_answers.round_question_id')
            ->join('game_rounds', 'game_rounds.id = game_round_questions.round_id')
            ->where('game_rounds.room_id', $roomId)
            ->get()
            ->getResultArray();

        $stats = [];
        foreach ($rows as $row) {
            $teamId = (int) $row['team_id'];
            $stats[$teamId] ??= ['correct_count' => 0, 'correct_response_ms' => 0];
            if ((int) $row['is_correct'] === 1) {
                $stats[$teamId]['correct_count']++;
                $stats[$teamId]['correct_response_ms'] += (int) ($row['response_ms'] ?? 0);
            }
        }

        return $stats;
    }

    protected function currentEpochMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public function pause(string $roomUuid): array
    {
        $room = $this->roomByUuid($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa.');
        if ($room['status'] !== 'PLAYING') {
            throw new DomainException('Game hanya bisa dijeda saat sedang bermain.');
        }

        (new GameRoomModel())->update($room['id'], [
            'status' => 'PAUSED',
        ]);
        $this->bumpRoom((int) $room['id']);
        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'game.paused', []);
        $this->recordEvent($room, 'teacher.override', ['action' => 'pause']);

        return $this->snapshot($room['public_uuid']);
    }

    public function resume(string $roomUuid): array
    {
        $room = $this->roomByUuid($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa.');
        if ($room['status'] !== 'PAUSED') {
            throw new DomainException('Game hanya bisa dilanjutkan dari status PAUSED.');
        }

        $turn = $this->activeTurn((int) $room['id']);
        $updates = ['status' => 'PLAYING'];
        if ($turn !== null && $turn['state'] === 'QUESTION_ACTIVE') {
            (new GameTurnModel())->update($turn['id'], [
                'question_deadline_at' => date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']),
            ]);
        }

        (new GameRoomModel())->update($room['id'], $updates);
        $this->bumpRoom((int) $room['id']);
        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'game.resumed', []);
        $this->recordEvent($room, 'teacher.override', ['action' => 'resume']);

        return $this->snapshot($room['public_uuid']);
    }

    public function skipTurn(string $roomUuid): array
    {
        $room = $this->roomByUuid($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa.');
        if ($room['status'] !== 'PLAYING') {
            throw new DomainException('Skip turn hanya bisa dilakukan saat game PLAYING.');
        }

        $turn = $this->activeTurn((int) $room['id']);
        if ($turn === null) {
            throw new DomainException('Belum ada turn aktif.');
        }

        $team = (new GameTeamModel())->find($turn['team_id']);
        if ($team === null) {
            throw new DomainException('Tim aktif tidak ditemukan.');
        }
        $nextTeam = $this->nextTeam((int) $room['id'], (int) $team['id']);

        $this->db->transStart();

        (new GameTurnModel())->update($turn['id'], [
            'state' => 'TURN_SKIPPED',
            'answer_is_correct' => 0,
        ]);
        (new GameTeamModel())->update($team['id'], [
            'streak_count' => 0,
        ]);
        (new GameRoomModel())->update($room['id'], [
            'current_team_id' => $nextTeam['id'],
        ]);
        $this->createTurn($room, $nextTeam, ((int) $turn['turn_number']) + 1);
        $this->bumpRoom((int) $room['id']);

        $this->db->transComplete();

        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'turn.skipped', [
            'team_uuid' => $team['public_uuid'],
            'next_team_uuid' => $nextTeam['public_uuid'],
        ]);
        $this->recordEvent($room, 'teacher.override', ['action' => 'skip_turn']);

        return $this->snapshot($room['public_uuid']);
    }

    public function forceTimeout(string $roomUuid): array
    {
        $room = $this->roomByUuid($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa.');
        if ($room['status'] !== 'PLAYING') {
            throw new DomainException('Force timeout hanya bisa dilakukan saat game PLAYING.');
        }

        $turn = $this->activeTurn((int) $room['id']);
        $challengeStates = ['SNAKE_REDEMPTION_ACTIVE', 'LADDER_CHALLENGE_ACTIVE'];
        $timeoutStates = array_merge(['QUESTION_ACTIVE'], $challengeStates);
        if ($turn === null || ! in_array($turn['state'], $timeoutStates, true)) {
            throw new DomainException('Tidak ada pertanyaan aktif untuk dipaksa timeout.');
        }

        $team = (new GameTeamModel())->find($turn['team_id']);
        if ($team === null) {
            throw new DomainException('Tim aktif tidak ditemukan.');
        }

        if (in_array($turn['state'], $challengeStates, true)) {
            $this->resolveBoardChallengeTimeout($room, $team, $turn);
        } else {
            $this->resolveTimedOutTurn($room, $team, $turn);
        }
        $room = $this->roomByUuid($roomUuid);
        $this->recordEvent($room, 'teacher.override', ['action' => 'force_timeout']);

        return $this->snapshot($roomUuid);
    }

    public function startAnswerTimer(string $roomUuid): array
    {
        $room = (new TenantContext())->assertRoomOwner($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa.');

        $turn = $this->activeTurn((int) $room['id']);
        $pendingStates = ['QUESTION_PENDING_START', 'MYSTERY_QUESTION_PENDING_START'];
        if ($turn === null || ! in_array($turn['state'], $pendingStates, true)) {
            throw new DomainException('Tidak ada soal yang menunggu waktu jawab dimulai.');
        }

        $nextState = $turn['state'] === 'QUESTION_PENDING_START'
            ? 'QUESTION_ACTIVE'
            : 'MYSTERY_QUESTION_ACTIVE';
        $now = date('Y-m-d H:i:s');
        $deadline = date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => $nextState,
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
        $this->bumpRoom((int) $room['id']);

        return $this->snapshot($roomUuid);
    }

    public function selectDifficultyTier(string $roomUuid, string $teamUuid, string $tier, ?string $idempotencyKey = null): array
    {
        $room = $this->roomByUuid($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa. Permainan tidak bisa dilanjutkan.');
        if (($room['game_mode'] ?? 'SNAKES_LADDERS') !== 'QUIZ_RACE') {
            throw new DomainException('Aksi ini hanya berlaku untuk mode Quiz Race.');
        }

        $team = $this->teamByUuid($teamUuid, (int) $room['id']);
        $scope = 'select-tier:' . $room['public_uuid'] . ':' . $team['public_uuid'];
        if ($existing = $this->idempotentResponse($scope, $idempotencyKey)) {
            return $existing;
        }

        if ($room['status'] !== 'PLAYING') {
            throw new DomainException('Game belum dalam status PLAYING.');
        }
        if ((int) $room['current_team_id'] !== (int) $team['id']) {
            throw new DomainException('Belum giliran tim ini.');
        }

        $turn = $this->activeTurn((int) $room['id']);
        if ($turn === null || $turn['state'] !== 'ROLL_READY') {
            throw new DomainException('Tingkat soal hanya bisa dipilih saat ROLL_READY.');
        }

        $tier = strtoupper($tier);
        if (! in_array($tier, ['EASY', 'MEDIUM', 'HARD'], true)) {
            throw new DomainException('Tingkat soal tidak valid.');
        }

        $activeEffects = $this->teamEffects($team);
        if (! empty($activeEffects['oil_spill_lock'])) {
            if ($tier !== 'EASY') {
                throw new DomainException('Tim terkena Oil Spill — giliran ini hanya bisa memilih tingkat EASY.');
            }
            $activeEffects['oil_spill_lock'] = false;
            (new GameTeamModel())->update($team['id'], [
                'active_effects_json' => json_encode($activeEffects, JSON_UNESCAPED_SLASHES),
            ]);
        }

        $selectionRules = $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position']);
        $poolRecycled = false;
        $question = $this->selectQuestion(
            (int) $room['teacher_id'],
            $tier,
            $selectionRules['topic_ids'],
            (int) $room['id'],
            $poolRecycled
        );

        $now = date('Y-m-d H:i:s');
        $deferTimer = ($room['participation_mode'] ?? 'TEAM_DEVICE') === 'TEACHER_CENTRALIZED';
        $deadline = $deferTimer ? null : date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => $deferTimer ? 'QUESTION_PENDING_START' : 'QUESTION_ACTIVE',
            'selected_tier' => $tier,
            'question_id' => $question['id'],
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
        $this->bumpRoom($room['id']);
        $room = $this->roomById((int) $room['id']);

        if ($poolRecycled) {
            $this->recordEvent($room, 'question.pool_recycled', [
                'room_uuid' => $room['public_uuid'],
                'difficulty' => $tier,
                'topic_ids' => $selectionRules['topic_ids'],
                'reason' => 'exhausted',
            ]);
        }
        $this->recordEvent($room, 'tier.selected', [
            'team_uuid' => $team['public_uuid'],
            'tier' => $tier,
        ]);
        $this->recordEvent($room, 'question.started', [
            'team_uuid' => $team['public_uuid'],
            'turn_uuid' => $turn['public_uuid'],
            'question' => $this->publicQuestion($question),
            'selection' => [
                'strategy' => 'team_choice',
                'requested_difficulty' => $tier,
                'selected_difficulty' => $question['difficulty'],
            ],
            'deadline_at' => $deadline,
        ]);

        $response = $this->snapshot($room['public_uuid']);
        $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

        return $response;
    }

    public function roll(string $roomUuid, string $teamUuid, ?string $idempotencyKey = null): array
    {
        $room = $this->roomByUuid($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa. Permainan tidak bisa dilanjutkan.');
        $team = $this->teamByUuid($teamUuid, (int) $room['id']);
        $scope = 'roll:' . $room['public_uuid'] . ':' . $team['public_uuid'];
        if ($existing = $this->idempotentResponse($scope, $idempotencyKey)) {
            return $existing;
        }

        if ($room['status'] !== 'PLAYING') {
            throw new DomainException('Game belum dalam status PLAYING.');
        }

        if ((int) $room['current_team_id'] !== (int) $team['id']) {
            throw new DomainException('Belum giliran tim ini.');
        }

        $turn = $this->activeTurn((int) $room['id']);
        if ($turn === null || $turn['state'] !== 'ROLL_READY') {
            throw new DomainException('Dadu hanya bisa dilempar saat ROLL_READY.');
        }

        $dice = random_int(1, 6);
        $landedTile = $this->computeLandedTile((int) $team['position'], $dice, $room);
        $selectionRules = $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position']);
        $targetDifficulty = $this->targetDifficultyForTurn($room, $landedTile);
        $poolRecycled = false;
        $question = $this->selectQuestion(
            (int) $room['teacher_id'],
            $targetDifficulty,
            $selectionRules['topic_ids'],
            (int) $room['id'],
            $poolRecycled
        );
        $now = date('Y-m-d H:i:s');
        $deferTimer = ($room['participation_mode'] ?? 'TEAM_DEVICE') === 'TEACHER_CENTRALIZED';
        $deadline = $deferTimer ? null : date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => $deferTimer ? 'QUESTION_PENDING_START' : 'QUESTION_ACTIVE',
            'dice_value' => $dice,
            'question_id' => $question['id'],
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
        $this->bumpRoom($room['id']);
        $room = $this->roomById((int) $room['id']);

        if ($poolRecycled) {
            $this->recordEvent($room, 'question.pool_recycled', [
                'room_uuid' => $room['public_uuid'],
                'difficulty' => $targetDifficulty,
                'topic_ids' => $selectionRules['topic_ids'],
                'reason' => 'exhausted',
            ]);
        }
        $this->recordEvent($room, 'dice.rolled', [
            'team_uuid' => $team['public_uuid'],
            'dice_value' => $dice,
        ]);
        $this->recordEvent($room, 'question.started', [
            'team_uuid' => $team['public_uuid'],
            'turn_uuid' => $turn['public_uuid'],
            'question' => $this->publicQuestion($question),
            'selection' => [
                'strategy' => $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position'])['strategy'],
                'requested_difficulty' => $targetDifficulty,
                'selected_difficulty' => $question['difficulty'],
                'based_on_position' => $landedTile,
            ],
            'deadline_at' => $deadline,
        ]);

        $response = $this->snapshot($room['public_uuid']);
        $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

        return $response;
    }

    public function answer(string $roomUuid, string $teamUuid, int $optionId, ?string $idempotencyKey = null): array
    {
        $room = $this->roomByUuid($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa. Permainan tidak bisa dilanjutkan.');
        $team = $this->teamByUuid($teamUuid, (int) $room['id']);
        $scope = 'answer:' . $room['public_uuid'] . ':' . $team['public_uuid'];
        if ($existing = $this->idempotentResponse($scope, $idempotencyKey)) {
            return $existing;
        }

        if ($room['status'] !== 'PLAYING') {
            throw new DomainException('Game belum dalam status PLAYING.');
        }

        if ((int) $room['current_team_id'] !== (int) $team['id']) {
            throw new DomainException('Belum giliran tim ini.');
        }

        $turn = $this->activeTurn((int) $room['id']);
        if ($turn === null || $turn['state'] !== 'QUESTION_ACTIVE') {
            throw new DomainException('Tidak ada pertanyaan aktif untuk dijawab.');
        }

        if ($this->isTurnExpired($turn)) {
            $response = $this->resolveTimedOutTurn($room, $team, $turn);
            $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

            return $response;
        }

        $option = (new QuestionOptionModel())
            ->where('question_id', $turn['question_id'])
            ->where('id', $optionId)
            ->first();
        if ($option === null) {
            throw new DomainException('Pilihan jawaban tidak valid.');
        }

        $board = (new BoardTemplateModel())->find($room['board_template_id']);
        $isCorrect = (int) $option['is_correct'] === 1;
        $dice = (int) $turn['dice_value'];
        $from = (int) $team['position'];
        $isQuizRace = ($room['game_mode'] ?? 'SNAKES_LADDERS') === 'QUIZ_RACE';
        $movement = $isCorrect
            ? ($isQuizRace
                ? $this->movementForRaceTierAnswer($from, (string) $turn['selected_tier'], $room, $board, $team)
                : $this->movementForCorrectAnswer($from, $dice, $room, $board, $team))
            : [
                'from' => $from,
                'rolled_to' => $from,
                'landed' => $from,
                'to' => $from,
                'special' => null,
                'effects' => [],
                'score_delta' => 0,
                'active_effects' => $this->teamEffects($team),
                'finish_bounced' => false,
            ];

        if ($isQuizRace && $isCorrect && $this->race->checkpointCrossed($movement['from'], $movement['to'], (int) $room['max_position'], (int) ($room['lap_count'] ?? 1))) {
            $movement['to'] = min((int) $room['max_position'], $movement['to'] + 1);
            $movement['lap_checkpoint'] = true;
        }
        $to = $movement['to'];
        $responseMs = $this->responseMs($turn);
        $newStreak = $isCorrect ? ((int) ($team['streak_count'] ?? 0) + 1) : 0;
        $scoreBreakdown = $this->scoreBreakdown($isCorrect, $room, $turn, $movement, $newStreak);
        $basePoints = (int) $scoreBreakdown['answer'];
        $bonusPoints = (int) $scoreBreakdown['time_bonus']
            + (int) $scoreBreakdown['streak_bonus']
            + (int) $scoreBreakdown['near_finish_bonus'];
        $specialPoints = (int) ($movement['score_delta'] ?? 0);
        $points = $basePoints + $bonusPoints + $specialPoints;

        $this->db->transStart();

        (new GameAnswerModel())->insert([
            'turn_id' => $turn['id'],
            'team_id' => $team['id'],
            'question_id' => $turn['question_id'],
            'option_id' => $optionId,
            'answer_text' => $option['body'],
            'is_correct' => $isCorrect ? 1 : 0,
            'answered_at' => date('Y-m-d H:i:s'),
            'response_ms' => $responseMs,
        ]);

        (new GameTeamModel())->update($team['id'], [
            'position' => $to,
            'score' => (int) $team['score'] + $points,
            'streak_count' => $newStreak,
            'active_effects_json' => json_encode($movement['active_effects'] ?? $this->teamEffects($team), JSON_UNESCAPED_SLASHES),
        ]);

        $this->recordScore($room, $team, $isCorrect ? 'ANSWER_CORRECT' : 'ANSWER_WRONG', $basePoints, $isCorrect ? 'Jawaban benar' : 'Jawaban belum tepat');
        if ((int) $scoreBreakdown['time_bonus'] !== 0) {
            $this->recordScore($room, $team, 'TIME_BONUS', (int) $scoreBreakdown['time_bonus'], 'Bonus jawab cepat');
        }
        if ((int) $scoreBreakdown['streak_bonus'] !== 0) {
            $this->recordScore($room, $team, 'STREAK_BONUS', (int) $scoreBreakdown['streak_bonus'], 'Bonus streak benar');
        }
        if ((int) $scoreBreakdown['near_finish_bonus'] !== 0) {
            $this->recordScore($room, $team, 'NEAR_FINISH_BONUS', (int) $scoreBreakdown['near_finish_bonus'], 'Bonus mendekati finish');
        }

        if ($specialPoints !== 0) {
            $this->recordScore($room, $team, 'SPECIAL_TILE', $specialPoints, $specialPoints > 0 ? 'Bonus tile khusus' : 'Penalti tile khusus');
        }

        $pendingChallenge = $isCorrect ? ($movement['pending_board_challenge'] ?? null) : null;
        $isMysteryLanding = $isCorrect && ($movement['special'] ?? null) === 'MYSTERY' && $pendingChallenge === null;
        $finished = ! $isMysteryLanding && $pendingChallenge === null && $to >= (int) $room['max_position'];
        $nextTeam = null;
        $hardQuestion = null;
        $poolRecycled = false;
        $selectionRules = $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position']);

        if ($pendingChallenge === 'SNAKE' || $pendingChallenge === 'LADDER') {
            $hardQuestion = $this->selectQuestion(
                (int) $room['teacher_id'],
                'HARD',
                $selectionRules['topic_ids'],
                (int) $room['id'],
                $poolRecycled
            );
            $deadlineSeconds = $pendingChallenge === 'SNAKE'
                ? (int) ($room['redemption_time_seconds'] ?: $room['question_time_seconds'])
                : (int) $room['question_time_seconds'];
            $state = $pendingChallenge === 'SNAKE' ? 'SNAKE_REDEMPTION_ACTIVE' : 'LADDER_CHALLENGE_ACTIVE';
            (new GameTurnModel())->update($turn['id'], [
                'state' => $state,
                'answer_is_correct' => 1,
                'question_id' => $hardQuestion['id'],
                'question_started_at' => date('Y-m-d H:i:s'),
                'question_deadline_at' => date('Y-m-d H:i:s', time() + $deadlineSeconds),
            ]);
        } elseif ($isMysteryLanding) {
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'MYSTERY_CHOICE_PENDING',
                'answer_is_correct' => 1,
                'question_started_at' => date('Y-m-d H:i:s'),
                'question_deadline_at' => date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']),
            ]);
        } elseif ($finished) {
            (new GameRoomModel())->update($room['id'], [
                'status' => 'FINISHED',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'TURN_COMPLETED',
                'answer_is_correct' => $isCorrect ? 1 : 0,
            ]);
        } else {
            $nextTeam = $this->nextTeam((int) $room['id'], (int) $team['id']);
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'TURN_COMPLETED',
                'answer_is_correct' => $isCorrect ? 1 : 0,
            ]);
            (new GameRoomModel())->update($room['id'], [
                'current_team_id' => $nextTeam['id'],
            ]);
            $this->createTurn($room, $nextTeam, ((int) $turn['turn_number']) + 1);
        }

        $this->bumpRoom($room['id']);
        $this->db->transComplete();

        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'answer.resolved', [
            'team_uuid' => $team['public_uuid'],
            'is_correct' => $isCorrect,
            'points' => $points,
            'score_breakdown' => $scoreBreakdown + ['special_tile' => $specialPoints, 'total' => $points],
            'streak_count' => $newStreak,
            'response_ms' => $responseMs,
            'movement' => $movement,
            'next_team_uuid' => $nextTeam['public_uuid'] ?? null,
            'finished' => $finished,
        ]);

        foreach ($movement['effects'] ?? [] as $effect) {
            $this->recordEvent($room, 'tile.special_triggered', [
                'team_uuid' => $team['public_uuid'],
                'effect' => $effect,
                'movement' => [
                    'from' => $movement['from'],
                    'landed' => $movement['landed'],
                    'to' => $movement['to'],
                ],
            ]);
        }

        if (! empty($movement['lap_checkpoint'])) {
            $this->recordEvent($room, 'lap.checkpoint', [
                'team_uuid' => $team['public_uuid'],
                'lap' => $this->race->lapForPosition($movement['to'], (int) $room['max_position'], (int) ($room['lap_count'] ?? 1)),
                'bonus_steps' => 1,
            ]);
        }

        if ($pendingChallenge === 'SNAKE' || $pendingChallenge === 'LADDER') {
            if ($poolRecycled) {
                $this->recordEvent($room, 'question.pool_recycled', [
                    'room_uuid' => $room['public_uuid'],
                    'difficulty' => 'HARD',
                    'topic_ids' => $selectionRules['topic_ids'],
                    'reason' => 'exhausted',
                ]);
            }
            $eventName = $pendingChallenge === 'SNAKE' ? 'snake.redemption_started' : 'ladder.challenge_started';
            $this->recordEvent($room, $eventName, [
                'team_uuid' => $team['public_uuid'],
                'from' => $movement['from'],
                'landed' => $movement['landed'],
                'challenge_to' => $movement['challenge_to'],
                'question' => $this->publicQuestion($hardQuestion),
            ]);
        }

        if ($finished) {
            $this->recordEvent($room, 'game.finished', [
                'winner_team_uuid' => $team['public_uuid'],
            ]);
        }

        $response = $this->snapshot($room['public_uuid']);
        $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

        return $response;
    }

    public function chooseMysteryTarget(string $roomUuid, string $teamUuid, string $target, ?string $idempotencyKey = null): array
    {
        $room = $this->roomByUuid($roomUuid);
        $team = $this->teamByUuid($teamUuid, (int) $room['id']);
        $scope = 'mystery_choose:' . $room['public_uuid'] . ':' . $team['public_uuid'];
        if ($existing = $this->idempotentResponse($scope, $idempotencyKey)) {
            return $existing;
        }

        $turn = $this->activeTurn((int) $room['id']);
        if ($turn === null || $turn['state'] !== 'MYSTERY_CHOICE_PENDING' || (int) $turn['team_id'] !== (int) $team['id']) {
            throw new DomainException('Tidak ada Kotak Misteri yang menunggu pilihan tim ini.');
        }

        if ($this->isTurnExpired($turn)) {
            $response = $this->resolveMysteryChoiceTimeout($room, $team, $turn);
            $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

            return $response;
        }

        $targetTeamId = null;
        if ($target !== 'SELF') {
            $targetTeam = (new GameTeamModel())
                ->where('room_id', $room['id'])
                ->where('public_uuid', $target)
                ->first();
            if ($targetTeam === null || (int) $targetTeam['id'] === (int) $team['id']) {
                throw new DomainException('Target Kotak Misteri tidak valid.');
            }
            $targetTeamId = (int) $targetTeam['id'];
        }

        $selectionRules = $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position']);
        $poolRecycled = false;
        $question = $this->selectQuestion(
            (int) $room['teacher_id'],
            'HARD',
            $selectionRules['topic_ids'],
            (int) $room['id'],
            $poolRecycled
        );
        $now = date('Y-m-d H:i:s');
        $deferTimer = ($room['participation_mode'] ?? 'TEAM_DEVICE') === 'TEACHER_CENTRALIZED';
        $deadline = $deferTimer ? null : date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => $deferTimer ? 'MYSTERY_QUESTION_PENDING_START' : 'MYSTERY_QUESTION_ACTIVE',
            'mystery_target_team_id' => $targetTeamId,
            'question_id' => $question['id'],
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
        $this->bumpRoom($room['id']);
        $room = $this->roomById((int) $room['id']);

        if ($poolRecycled) {
            $this->recordEvent($room, 'question.pool_recycled', [
                'room_uuid' => $room['public_uuid'],
                'difficulty' => 'HARD',
                'topic_ids' => $selectionRules['topic_ids'],
                'reason' => 'exhausted',
            ]);
        }
        $this->recordEvent($room, 'mystery.target_chosen', [
            'team_uuid' => $team['public_uuid'],
            'target' => $target,
        ]);

        $response = $this->snapshot($room['public_uuid']);
        $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

        return $response;
    }

    private function resolveMysteryChoiceTimeout(array $room, array $team, array $turn): array
    {
        $nextTeam = $this->nextTeam((int) $room['id'], (int) $team['id']);

        $this->db->transStart();
        (new GameTurnModel())->update($turn['id'], [
            'state' => 'QUESTION_TIMEOUT',
        ]);
        (new GameRoomModel())->update($room['id'], [
            'current_team_id' => $nextTeam['id'],
        ]);
        $this->createTurn($room, $nextTeam, ((int) $turn['turn_number']) + 1);
        $this->bumpRoom($room['id']);
        $this->db->transComplete();

        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'turn.timeout', [
            'team_uuid' => $team['public_uuid'],
            'turn_uuid' => $turn['public_uuid'],
            'next_team_uuid' => $nextTeam['public_uuid'],
            'points' => 0,
            'reason' => 'mystery_choice_expired',
        ]);

        return $this->snapshot($room['public_uuid']);
    }

    public function answerMystery(string $roomUuid, string $teamUuid, int $optionId, ?string $idempotencyKey = null): array
    {
        $room = $this->roomByUuid($roomUuid);
        $team = $this->teamByUuid($teamUuid, (int) $room['id']);
        $scope = 'mystery_answer:' . $room['public_uuid'] . ':' . $team['public_uuid'];
        if ($existing = $this->idempotentResponse($scope, $idempotencyKey)) {
            return $existing;
        }

        $turn = $this->activeTurn((int) $room['id']);
        if ($turn === null || $turn['state'] !== 'MYSTERY_QUESTION_ACTIVE' || (int) $turn['team_id'] !== (int) $team['id']) {
            throw new DomainException('Tidak ada soal Kotak Misteri yang menunggu jawaban tim ini.');
        }

        if ($this->isTurnExpired($turn)) {
            $response = $this->resolveMysteryOutcome($room, $team, $turn, false);
            $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

            return $response;
        }

        $option = (new QuestionOptionModel())
            ->where('question_id', $turn['question_id'])
            ->where('id', $optionId)
            ->first();
        if ($option === null) {
            throw new DomainException('Pilihan jawaban tidak valid.');
        }

        $response = $this->resolveMysteryOutcome($room, $team, $turn, (int) $option['is_correct'] === 1);
        $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

        return $response;
    }

    private function resolveMysteryOutcome(array $room, array $team, array $turn, bool $isCorrect): array
    {
        $maxPosition = (int) $room['max_position'];
        $targetTeamId = $turn['mystery_target_team_id'] !== null ? (int) $turn['mystery_target_team_id'] : null;
        $finished = false;
        $finishedTeamUuid = null;

        if ($isCorrect && $targetTeamId === null) {
            $fromPosition = (int) $team['position'];
            $toPosition = $this->applyMysteryDeltaToTeam($room, $team, 80, 3, $maxPosition);
            $outcome = 'REWARD_SELF';
            $affectedTeamUuid = $team['public_uuid'];
            if ($toPosition >= $maxPosition) {
                $finished = true;
                $finishedTeamUuid = $team['public_uuid'];
            }
        } elseif ($isCorrect && $targetTeamId !== null) {
            $opponent = (new GameTeamModel())->find($targetTeamId);
            if ($opponent === null) {
                throw new DomainException('Tim target Kotak Misteri sudah tidak ada.');
            }
            $fromPosition = (int) $opponent['position'];
            $toPosition = $this->applyMysteryDeltaToTeam($room, $opponent, -60, -4, $maxPosition);
            $outcome = 'PUNISH_OPPONENT';
            $affectedTeamUuid = $opponent['public_uuid'];
        } else {
            // Wrong answer or timeout always punishes the answering team,
            // regardless of whether they had chosen SELF or an opponent.
            $fromPosition = (int) $team['position'];
            $toPosition = $this->applyMysteryDeltaToTeam($room, $team, -60, -4, $maxPosition);
            $outcome = 'BOOMERANG_SELF';
            $affectedTeamUuid = $team['public_uuid'];
        }

        $this->db->transStart();
        if ($finished) {
            (new GameRoomModel())->update($room['id'], [
                'status' => 'FINISHED',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'TURN_COMPLETED',
                'answer_is_correct' => $isCorrect ? 1 : 0,
            ]);
        } else {
            $nextTeam = $this->nextTeam((int) $room['id'], (int) $team['id']);
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'TURN_COMPLETED',
                'answer_is_correct' => $isCorrect ? 1 : 0,
            ]);
            (new GameRoomModel())->update($room['id'], [
                'current_team_id' => $nextTeam['id'],
            ]);
            $this->createTurn($room, $nextTeam, ((int) $turn['turn_number']) + 1);
        }
        $this->bumpRoom($room['id']);
        $this->db->transComplete();

        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'mystery.resolved', [
            'team_uuid' => $team['public_uuid'],
            'affected_team_uuid' => $affectedTeamUuid,
            'is_correct' => $isCorrect,
            'outcome' => $outcome,
            'movement' => ['from' => $fromPosition, 'landed' => $fromPosition, 'to' => $toPosition],
        ]);
        if ($finished) {
            $this->recordEvent($room, 'game.finished', [
                'winner_team_uuid' => $finishedTeamUuid,
            ]);
        }

        return $this->snapshot($room['public_uuid']);
    }

    private function applyMysteryDeltaToTeam(array $room, array $team, int $pointsDelta, int $stepsDelta, int $maxPosition): int
    {
        $newPosition = max(1, min($maxPosition, (int) $team['position'] + $stepsDelta));
        (new GameTeamModel())->update($team['id'], [
            'position' => $newPosition,
            'score' => (int) $team['score'] + $pointsDelta,
        ]);
        $this->recordScore($room, $team, 'SPECIAL_TILE', $pointsDelta, $pointsDelta >= 0 ? 'Bonus Kotak Misteri' : 'Penalti Kotak Misteri');

        return $newPosition;
    }

    public function answerBoardChallenge(string $roomUuid, string $teamUuid, int $optionId, ?string $idempotencyKey = null): array
    {
        $room = $this->roomByUuid($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa. Permainan tidak bisa dilanjutkan.');
        $team = $this->teamByUuid($teamUuid, (int) $room['id']);
        $scope = 'board-challenge:' . $room['public_uuid'] . ':' . $team['public_uuid'];
        if ($existing = $this->idempotentResponse($scope, $idempotencyKey)) {
            return $existing;
        }

        if ($room['status'] !== 'PLAYING') {
            throw new DomainException('Game belum dalam status PLAYING.');
        }
        if ((int) $room['current_team_id'] !== (int) $team['id']) {
            throw new DomainException('Belum giliran tim ini.');
        }

        $turn = $this->activeTurn((int) $room['id']);
        $allowed = ['SNAKE_REDEMPTION_ACTIVE', 'LADDER_CHALLENGE_ACTIVE'];
        if ($turn === null || ! in_array($turn['state'], $allowed, true)) {
            throw new DomainException('Tidak ada tantangan ular/tangga aktif.');
        }

        if ($this->isTurnExpired($turn)) {
            $response = $this->resolveBoardChallengeTimeout($room, $team, $turn);
            $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

            return $response;
        }

        $option = (new QuestionOptionModel())
            ->where('question_id', $turn['question_id'])
            ->where('id', $optionId)
            ->first();
        if ($option === null) {
            throw new DomainException('Pilihan jawaban tidak valid.');
        }

        $response = $this->finalizeBoardChallenge(
            $room,
            $team,
            $turn,
            (int) $option['is_correct'] === 1,
            $option
        );
        $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

        return $response;
    }

    private function boardChallengeTarget(int $from, string $kind, array $board): int
    {
        $key = $kind === 'SNAKE' ? 'snakes_json' : 'ladders_json';
        foreach (json_decode((string) $board[$key], true) ?: [] as $item) {
            if ((int) $item['from'] === $from) {
                return (int) $item['to'];
            }
        }

        throw new DomainException('Target ular/tangga tidak ditemukan di papan.');
    }

    private function resolveBoardChallengeTimeout(array $room, array $team, array $turn): array
    {
        return $this->finalizeBoardChallenge($room, $team, $turn, false, null);
    }

    private function finalizeBoardChallenge(
        array $room,
        array $team,
        array $turn,
        bool $isCorrect,
        ?array $option
    ): array {
        $kind = $turn['state'] === 'SNAKE_REDEMPTION_ACTIVE' ? 'SNAKE' : 'LADDER';
        $board = (new BoardTemplateModel())->find($room['board_template_id']);
        $from = (int) $team['position'];
        $challengeTo = $this->boardChallengeTarget($from, $kind, $board);
        $to = $isCorrect
            ? ($kind === 'LADDER' ? $challengeTo : $from)
            : ($kind === 'SNAKE' ? $challengeTo : $from);
        $points = $isCorrect ? ($kind === 'SNAKE' ? 75 : 150) : 0;

        $this->db->transStart();
        (new GameAnswerModel())->insert([
            'turn_id' => $turn['id'],
            'team_id' => $team['id'],
            'question_id' => $turn['question_id'],
            'option_id' => $option['id'] ?? null,
            'answer_text' => $option['body'] ?? null,
            'is_correct' => $isCorrect ? 1 : 0,
            'answered_at' => date('Y-m-d H:i:s'),
            'response_ms' => $this->responseMs($turn),
        ]);
        (new GameTeamModel())->update($team['id'], [
            'position' => $to,
            'score' => (int) $team['score'] + $points,
        ]);
        if ($points !== 0) {
            $this->recordScore(
                $room,
                $team,
                $kind === 'SNAKE' ? 'SNAKE_REDEMPTION' : 'LADDER_CHALLENGE',
                $points,
                $kind === 'SNAKE' ? 'Lolos ular' : 'Naik tangga'
            );
        }

        $finished = $to >= (int) $room['max_position'];
        if ($finished) {
            (new GameRoomModel())->update($room['id'], [
                'status' => 'FINISHED',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'TURN_COMPLETED',
                'answer_is_correct' => $isCorrect ? 1 : 0,
            ]);
        } else {
            $nextTeam = $this->nextTeam((int) $room['id'], (int) $team['id']);
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'TURN_COMPLETED',
                'answer_is_correct' => $isCorrect ? 1 : 0,
            ]);
            (new GameRoomModel())->update($room['id'], ['current_team_id' => $nextTeam['id']]);
            $this->createTurn($room, $nextTeam, ((int) $turn['turn_number']) + 1);
        }
        $this->bumpRoom($room['id']);
        $this->db->transComplete();

        $room = $this->roomById((int) $room['id']);
        $eventName = $kind === 'SNAKE' ? 'snake.redemption_resolved' : 'ladder.challenge_resolved';
        $movement = ['from' => $from, 'landed' => $from, 'to' => $to];
        $this->recordEvent($room, $eventName, [
            'team_uuid' => $team['public_uuid'],
            'is_correct' => $isCorrect,
            'points' => $points,
            'movement' => $movement,
        ]);
        if ($kind === 'SNAKE' && ! $isCorrect) {
            $this->recordEvent($room, 'tile.special_triggered', [
                'team_uuid' => $team['public_uuid'],
                'effect' => [
                    'type' => 'SNAKE',
                    'tile' => $from,
                    'to' => $to,
                    'label' => 'Ular',
                ],
                'movement' => $movement,
            ]);
        }
        if ($kind === 'LADDER' && $isCorrect) {
            $this->recordEvent($room, 'tile.special_triggered', [
                'team_uuid' => $team['public_uuid'],
                'effect' => [
                    'type' => 'LADDER',
                    'tile' => $from,
                    'to' => $to,
                    'label' => 'Tangga',
                ],
                'movement' => $movement,
            ]);
        }
        if ($finished) {
            $this->recordEvent($room, 'game.finished', [
                'winner_team_uuid' => $team['public_uuid'],
            ]);
        }

        return $this->snapshot($room['public_uuid']);
    }

    public function snapshot(string $roomUuid, ?string $projectorToken = null, bool $forOwner = false): array
    {
        $room = $this->roomByUuid($roomUuid);
        $teams = array_map([$this, 'publicTeam'], $this->teams((int) $room['id']));
        $turn = $this->activeTurn((int) $room['id']);
        $board = (new BoardTemplateModel())->find($room['board_template_id']);
        $includePin = $forOwner || $this->isValidProjectorToken($room, $projectorToken);

        return [
            'room' => $this->publicRoom($room, $includePin, $forOwner),
            'question_bank' => $this->questionBankSummary(
                (int) $room['teacher_id'],
                $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position'])['topic_ids']
            ),
            'board' => [
                'tile_count' => (int) $board['tile_count'],
                'ladders' => json_decode((string) $board['ladders_json'], true) ?: [],
                'snakes' => json_decode((string) $board['snakes_json'], true) ?: [],
                'special_tiles' => $this->boardSpecialTiles($board),
                'mystery_tile_count' => $this->specialTileCount($board, 'MYSTERY'),
                'theme' => $this->publicTheme($board),
            ],
            'teams' => $teams,
            'current_turn' => $turn ? $this->publicTurn($turn) : null,
            'current_round' => $this->publicActiveRaceRound($room),
            'mode_state' => $this->publicModeState($room, $board, $turn, $teams),
            'leaderboard' => $this->leaderboard((int) $room['id']),
            'events' => $this->recentEvents((int) $room['id']),
        ];
    }

    private function publicActiveRaceRound(array $room): ?array
    {
        if (($room['game_mode'] ?? 'SNAKES_LADDERS') !== 'QUIZ_RACE'
            || ($room['participation_mode'] ?? 'TEAM_DEVICE') !== 'TEAM_DEVICE') {
            return null;
        }

        $round = $this->activeRaceRound((int) $room['id']);
        if ($round === null) {
            return null;
        }
        $question = $this->activeRaceQuestion((int) $round['id']);
        $publicQuestion = null;
        if ($question !== null) {
            $answeredTeamIds = array_fill_keys(array_map(
                static fn (array $answer): int => (int) $answer['team_id'],
                (new GameRoundAnswerModel())
                    ->select('team_id')
                    ->where('round_question_id', $question['id'])
                    ->findAll()
            ), true);
            $publicQuestion = [
                'uuid' => $question['public_uuid'],
                'question_number' => (int) $question['question_number'],
                'state' => $question['state'],
                'question' => $this->publicQuestion((new QuestionModel())->find($question['question_id'])),
                'deadline_epoch_ms' => (int) $question['deadline_epoch_ms'],
                'answers' => array_map(static fn (array $team): array => [
                    'team_uuid' => $team['public_uuid'],
                    'answered' => isset($answeredTeamIds[(int) $team['id']]),
                ], $this->teams((int) $room['id'])),
            ];
        }

        return [
            'uuid' => $round['public_uuid'],
            'round_number' => (int) $round['round_number'],
            'state' => $round['state'],
            'question_target_count' => (int) $round['question_target_count'],
            'question_resolved_count' => (int) $round['question_resolved_count'],
            'current_question' => $publicQuestion,
        ];
    }

    public function isValidProjectorToken(array $room, ?string $projectorToken): bool
    {
        if ($projectorToken === null || $projectorToken === '') {
            return false;
        }

        $hash = (string) ($room['projector_token_hash'] ?? '');
        if ($hash === '') {
            return false;
        }

        return hash_equals($hash, hash('sha256', $projectorToken));
    }

    public function deleteRoom(string $roomUuid): void
    {
        $room = $this->roomByUuid($roomUuid);
        if (in_array($room['status'], ['PLAYING', 'PAUSED'], true)) {
            throw new DomainException('Room yang sedang berjalan atau dijeda tidak bisa dihapus. Selesaikan game lebih dulu.');
        }

        $turnIds = array_map(
            static fn (array $turn): int => (int) $turn['id'],
            (new GameTurnModel())->select('id')->where('room_id', $room['id'])->findAll(),
        );
        $board = (new BoardTemplateModel())->find($room['board_template_id']);

        $this->db->transStart();

        if ($turnIds !== []) {
            $this->db->table('game_answers')->whereIn('turn_id', $turnIds)->delete();
        }

        $this->db->table('score_transactions')->where('room_id', $room['id'])->delete();
        $this->db->table('game_turns')->where('room_id', $room['id'])->delete();
        $this->db->table('game_teams')->where('room_id', $room['id'])->delete();
        $this->db->table('game_events')->where('room_id', $room['id'])->delete();
        $this->db->table('realtime_outbox')->where('room_id', $room['id'])->delete();
        $this->db->table('idempotency_keys')
            ->groupStart()
            ->like('scope', 'roll:' . $room['public_uuid'] . ':', 'after')
            ->orLike('scope', 'answer:' . $room['public_uuid'] . ':', 'after')
            ->orLike('scope', 'mystery_choose:' . $room['public_uuid'] . ':', 'after')
            ->orLike('scope', 'mystery_answer:' . $room['public_uuid'] . ':', 'after')
            ->groupEnd()
            ->delete();
        $this->db->table('game_rooms')->where('id', $room['id'])->delete();
        if ($board !== null && $board['status'] === 'ROOM_INSTANCE') {
            $this->db->table('board_templates')->where('id', $board['id'])->delete();
        }

        $this->db->transComplete();
    }

    public function roomByUuid(string $roomUuid): array
    {
        $room = (new GameRoomModel())->where('public_uuid', $roomUuid)->first();
        if ($room === null) {
            throw PageNotFoundException::forPageNotFound('Room tidak ditemukan.');
        }

        return $room;
    }

    public function firstTeacherId(): int
    {
        $teacher = $this->db->table('teachers')->orderBy('id', 'ASC')->get()->getRowArray();
        if ($teacher === null) {
            throw new DomainException('Guru demo belum tersedia. Jalankan seeder demo.');
        }

        return (int) $teacher['id'];
    }

    private function roomById(int $id): array
    {
        return (new GameRoomModel())->find($id);
    }

    private function teamByUuid(string $teamUuid, int $roomId): array
    {
        $team = (new GameTeamModel())->where('room_id', $roomId)->where('public_uuid', $teamUuid)->first();
        if ($team === null) {
            throw PageNotFoundException::forPageNotFound('Tim tidak ditemukan.');
        }

        return $team;
    }

    private function teams(int $roomId): array
    {
        return (new GameTeamModel())->where('room_id', $roomId)->orderBy('id', 'ASC')->findAll();
    }

    private function activeTurn(int $roomId): ?array
    {
        return (new GameTurnModel())->where('room_id', $roomId)->orderBy('id', 'DESC')->first();
    }

    private function createTurn(array $room, array $team, int $turnNumber): void
    {
        (new GameTurnModel())->insert([
            'public_uuid' => Uuid::v4(),
            'room_id' => $room['id'],
            'team_id' => $team['id'],
            'state' => 'ROLL_READY',
            'turn_number' => $turnNumber,
        ]);
    }

    private function usedQuestionIdsForRoom(int $roomId): array
    {
        $answersTable = $this->db->prefixTable('game_answers');
        $turnsTable = $this->db->prefixTable('game_turns');
        $roundQuestionsTable = $this->db->prefixTable('game_round_questions');

        $fromAnswers = array_map(
            static fn (array $row): int => (int) $row['question_id'],
            $this->db->table('game_answers')
                ->select('game_answers.question_id')
                ->join('game_turns', 'game_turns.id = game_answers.turn_id')
                ->where('game_turns.room_id', $roomId)
                ->where("{$answersTable}.question_id IS NOT NULL", null, false)
                ->get()
                ->getResultArray()
        );

        $fromTurns = array_map(
            static fn (array $row): int => (int) $row['question_id'],
            $this->db->table('game_turns')
                ->select('question_id')
                ->where('room_id', $roomId)
                ->where("{$turnsTable}.question_id IS NOT NULL", null, false)
                ->get()
                ->getResultArray()
        );

        $fromRaceQuestions = array_map(
            static fn (array $row): int => (int) $row['question_id'],
            $this->db->table('game_round_questions')
                ->select('game_round_questions.question_id')
                ->join('game_rounds', 'game_rounds.id = game_round_questions.round_id')
                ->where('game_rounds.room_id', $roomId)
                ->where("{$roundQuestionsTable}.question_id IS NOT NULL", null, false)
                ->get()
                ->getResultArray()
        );

        return array_values(array_unique(array_filter(array_merge($fromAnswers, $fromTurns, $fromRaceQuestions))));
    }

    private function selectQuestion(
        int $teacherId,
        ?string $difficulty = null,
        array $topicIds = [],
        ?int $roomId = null,
        ?bool &$recycled = null
    ): array {
        $usedIds = $roomId !== null ? $this->usedQuestionIdsForRoom($roomId) : [];
        $recycled = false;

        $pick = function (bool $excludeUsed) use ($teacherId, $difficulty, $topicIds, $usedIds): array {
            $query = (new QuestionModel())
                ->where('owner_teacher_id', $teacherId)
                ->where('status', 'PUBLISHED');
            if ($topicIds !== []) {
                $query->whereIn('topic_id', $topicIds);
            }
            if ($difficulty !== null) {
                $query->where('difficulty', $difficulty);
            }
            if ($excludeUsed && $usedIds !== []) {
                $query->whereNotIn('id', $usedIds);
            }
            $questions = $query->findAll();
            if ($questions === [] && $difficulty !== null) {
                $fallbackQuery = (new QuestionModel())
                    ->where('owner_teacher_id', $teacherId)
                    ->where('status', 'PUBLISHED');
                if ($topicIds !== []) {
                    $fallbackQuery->whereIn('topic_id', $topicIds);
                }
                if ($excludeUsed && $usedIds !== []) {
                    $fallbackQuery->whereNotIn('id', $usedIds);
                }
                $questions = $fallbackQuery->findAll();
            }

            return $questions;
        };

        $questions = $pick(true);
        if ($questions === []) {
            $questions = $pick(false);
            $recycled = $roomId !== null && $usedIds !== [];
        }

        if ($questions === []) {
            throw new DomainException('Bank soal masih kosong.');
        }

        return $questions[array_rand($questions)];
    }

    private function applyBoardJump(int $position, array $board, array &$activeEffects): array
    {
        foreach (json_decode((string) $board['ladders_json'], true) ?: [] as $ladder) {
            if ((int) $ladder['from'] === $position) {
                return [
                    'type' => 'LADDER',
                    'to' => (int) $ladder['to'],
                    'effects' => [],
                ];
            }
        }

        foreach (json_decode((string) $board['snakes_json'], true) ?: [] as $snake) {
            if ((int) $snake['from'] === $position) {
                if (($activeEffects['safe_shield'] ?? 0) > 0) {
                    $activeEffects['safe_shield']--;

                    return [
                        'type' => 'SAFE_BLOCK',
                        'to' => $position,
                        'effects' => [[
                            'type' => 'SAFE_BLOCK',
                            'tile' => $position,
                            'blocked_type' => 'SNAKE',
                            'blocked_to' => (int) $snake['to'],
                            'label' => 'Perisai menahan ular',
                        ]],
                    ];
                }

                return [
                    'type' => 'SNAKE',
                    'to' => (int) $snake['to'],
                    'effects' => [],
                ];
            }
        }

        return [
            'type' => null,
            'to' => $position,
            'effects' => [],
        ];
    }

    private function computeLandedTile(int $from, int $dice, array $room): int
    {
        $maxPosition = (int) $room['max_position'];
        $rolledTo = $from + $dice;

        if (($room['finish_rule'] ?? 'clamp_finish') === 'exact_finish' && $rolledTo > $maxPosition) {
            return max(1, $maxPosition - ($rolledTo - $maxPosition));
        }

        return min($maxPosition, $rolledTo);
    }

    private function movementForCorrectAnswer(int $from, int $dice, array $room, array $board, array $team): array
    {
        $maxPosition = (int) $room['max_position'];
        $rolledTo = $from + $dice;
        $finishBounced = ($room['finish_rule'] ?? 'clamp_finish') === 'exact_finish' && $rolledTo > $maxPosition;
        $landed = $this->computeLandedTile($from, $dice, $room);
        $activeEffects = $this->teamEffects($team);

        // Peek snake/ladder at landed. Safe shield still consumes and blocks without redemption.
        foreach (json_decode((string) $board['snakes_json'], true) ?: [] as $snake) {
            if ((int) $snake['from'] === $landed) {
                if (($activeEffects['safe_shield'] ?? 0) > 0) {
                    $activeEffects['safe_shield']--;

                    return [
                        'from' => $from,
                        'rolled_to' => $rolledTo,
                        'landed' => $landed,
                        'to' => $landed,
                        'special' => 'SAFE_BLOCK',
                        'effects' => [[
                            'type' => 'SAFE_BLOCK',
                            'tile' => $landed,
                            'blocked_type' => 'SNAKE',
                            'blocked_to' => (int) $snake['to'],
                            'label' => 'Perisai menahan ular',
                        ]],
                        'score_delta' => 0,
                        'active_effects' => $activeEffects,
                        'finish_bounced' => $finishBounced,
                        'pending_board_challenge' => null,
                    ];
                }

                return [
                    'from' => $from,
                    'rolled_to' => $rolledTo,
                    'landed' => $landed,
                    'to' => $landed,
                    'special' => 'SNAKE',
                    'effects' => [],
                    'score_delta' => 0,
                    'active_effects' => $activeEffects,
                    'finish_bounced' => $finishBounced,
                    'pending_board_challenge' => 'SNAKE',
                    'challenge_to' => (int) $snake['to'],
                ];
            }
        }

        foreach (json_decode((string) $board['ladders_json'], true) ?: [] as $ladder) {
            if ((int) $ladder['from'] === $landed) {
                return [
                    'from' => $from,
                    'rolled_to' => $rolledTo,
                    'landed' => $landed,
                    'to' => $landed,
                    'special' => 'LADDER',
                    'effects' => [],
                    'score_delta' => 0,
                    'active_effects' => $activeEffects,
                    'finish_bounced' => $finishBounced,
                    'pending_board_challenge' => 'LADDER',
                    'challenge_to' => (int) $ladder['to'],
                ];
            }
        }

        $boardJump = $this->applyBoardJump($landed, $board, $activeEffects);
        $tileEffect = $this->applySpecialTileEffect($boardJump['to'], $board, $activeEffects);
        $effects = array_merge($boardJump['effects'], $tileEffect['effects']);
        $special = $tileEffect['special'] ?? $boardJump['type'];

        return [
            'from' => $from,
            'rolled_to' => $rolledTo,
            'landed' => $landed,
            'to' => $tileEffect['to'],
            'special' => $special,
            'effects' => $effects,
            'score_delta' => (int) $tileEffect['score_delta'],
            'active_effects' => $activeEffects,
            'finish_bounced' => $finishBounced,
            'pending_board_challenge' => null,
        ];
    }

    private function movementForRaceTierAnswer(int $from, string $tier, array $room, array $board, array $team): array
    {
        $result = $this->race->movementForTierAnswer($from, $tier, $room, $board);
        $activeEffects = $this->teamEffects($team);
        if (($result['special'] ?? null) === 'OIL_SPILL') {
            $activeEffects['oil_spill_lock'] = true;
        }

        return $result + [
            'rolled_to' => $result['to'],
            'active_effects' => $activeEffects,
            'finish_bounced' => false,
            'pending_board_challenge' => null,
        ];
    }

    private function applySpecialTileEffect(int $position, array $board, array &$activeEffects): array
    {
        $tile = $this->specialTileAt($position, $board);
        if ($tile === null) {
            return [
                'to' => $position,
                'special' => null,
                'effects' => [],
                'score_delta' => 0,
            ];
        }

        $type = strtoupper((string) ($tile['type'] ?? 'NORMAL'));
        $label = (string) ($tile['label'] ?? $type);
        if ($type === 'BONUS') {
            $points = max(10, min(200, (int) ($tile['points'] ?? 50)));

            return [
                'to' => $position,
                'special' => 'BONUS',
                'effects' => [[
                    'type' => 'BONUS',
                    'tile' => $position,
                    'points' => $points,
                    'label' => $label,
                ]],
                'score_delta' => $points,
            ];
        }

        if ($type === 'TRAP') {
            $steps = max(1, min(12, (int) ($tile['steps'] ?? 3)));
            $to = max(1, $position - $steps);

            return [
                'to' => $to,
                'special' => 'TRAP',
                'effects' => [[
                    'type' => 'TRAP',
                    'tile' => $position,
                    'steps' => $steps,
                    'to' => $to,
                    'label' => $label,
                ]],
                'score_delta' => 0,
            ];
        }

        if ($type === 'SAFE') {
            $activeEffects['safe_shield'] = min(3, (int) ($activeEffects['safe_shield'] ?? 0) + 1);

            return [
                'to' => $position,
                'special' => 'SAFE',
                'effects' => [[
                    'type' => 'SAFE',
                    'tile' => $position,
                    'safe_shield' => (int) $activeEffects['safe_shield'],
                    'label' => $label,
                ]],
                'score_delta' => 0,
            ];
        }

        if ($type === 'MYSTERY') {
            return [
                'to' => $position,
                'special' => 'MYSTERY',
                'effects' => [],
                'score_delta' => 0,
            ];
        }

        return [
            'to' => $position,
            'special' => null,
            'effects' => [],
            'score_delta' => 0,
        ];
    }

    private function isTurnExpired(array $turn): bool
    {
        return $turn['question_deadline_at'] !== null
            && strtotime((string) $turn['question_deadline_at']) < time();
    }

    private function resolveTimedOutTurn(array $room, array $team, array $turn): array
    {
        $nextTeam = $this->nextTeam((int) $room['id'], (int) $team['id']);
        $rules = $this->scoringRules($room['scoring_json'] ?? []);
        $penalty = $rules['timeout_penalty'] ? $this->config->timeoutPenaltyPoints : 0;

        $this->db->transStart();

        (new GameAnswerModel())->insert([
            'turn_id' => $turn['id'],
            'team_id' => $team['id'],
            'question_id' => $turn['question_id'],
            'option_id' => null,
            'answer_text' => null,
            'is_correct' => 0,
            'answered_at' => date('Y-m-d H:i:s'),
            'response_ms' => $this->responseMs($turn),
        ]);

        (new GameTeamModel())->update($team['id'], [
            'score' => (int) $team['score'] + $penalty,
            'streak_count' => 0,
        ]);
        if ($penalty !== 0) {
            $this->recordScore($room, $team, 'TIMEOUT_PENALTY', $penalty, 'Penalti waktu habis');
        }

        (new GameTurnModel())->update($turn['id'], [
            'state' => 'QUESTION_TIMEOUT',
            'answer_is_correct' => 0,
        ]);
        (new GameRoomModel())->update($room['id'], [
            'current_team_id' => $nextTeam['id'],
        ]);
        $this->createTurn($room, $nextTeam, ((int) $turn['turn_number']) + 1);
        $this->bumpRoom($room['id']);

        $this->db->transComplete();

        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'turn.timeout', [
            'team_uuid' => $team['public_uuid'],
            'turn_uuid' => $turn['public_uuid'],
            'next_team_uuid' => $nextTeam['public_uuid'],
            'points' => $penalty,
        ]);

        return $this->snapshot($room['public_uuid']);
    }

    private function firstTeamForStart(array $teams, string $mode): array
    {
        if ($mode === 'random') {
            return $teams[random_int(0, count($teams) - 1)];
        }

        return $teams[0];
    }

    private function nextTeam(int $roomId, int $currentTeamId): array
    {
        $teams = $this->teams($roomId);
        foreach ($teams as $index => $team) {
            if ((int) $team['id'] === $currentTeamId) {
                return $teams[($index + 1) % count($teams)];
            }
        }

        return $teams[0];
    }

    private function bumpRoom(int $roomId): void
    {
        $this->db->table('game_rooms')
            ->set('state_version', 'state_version + 1', false)
            ->set('updated_at', date('Y-m-d H:i:s'))
            ->where('id', $roomId)
            ->update();
    }

    private function recordEvent(array $room, string $type, array $payload): void
    {
        $event = [
            'event_id' => Uuid::v4(),
            'event' => $type,
            'room_uuid' => $room['public_uuid'],
            'state_version' => (int) $room['state_version'],
            'occurred_at' => date(DATE_ATOM),
            'payload' => $payload,
        ];

        (new GameEventModel())->insert([
            'public_uuid' => $event['event_id'],
            'room_id' => $room['id'],
            'type' => $type,
            'state_version' => $event['state_version'],
            'payload_json' => json_encode($event, JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->realtime->publish((int) $room['id'], ChannelName::room($room['public_uuid']), $type, $event);
    }

    private function idempotentResponse(string $scope, ?string $key): ?array
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        $record = (new IdempotencyKeyModel())
            ->where('scope', $scope)
            ->where('key_hash', hash('sha256', $key))
            ->first();

        return $record ? json_decode((string) $record['response_json'], true) : null;
    }

    private function saveIdempotentResponse(string $scope, ?string $key, array $response): void
    {
        if ($key === null || trim($key) === '') {
            return;
        }

        (new IdempotencyKeyModel())->insert([
            'scope' => $scope,
            'key_hash' => hash('sha256', $key),
            'response_json' => json_encode($response, JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);
    }

    private function recordScore(array $room, array $team, string $type, int $points, string $reason): void
    {
        (new ScoreTransactionModel())->insert([
            'room_id' => $room['id'],
            'team_id' => $team['id'],
            'type' => $type,
            'points' => $points,
            'reason' => $reason,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function scoreBreakdown(bool $isCorrect, array $room, array $turn, array $movement, int $newStreak): array
    {
        $rules = $this->scoringRules($room['scoring_json'] ?? []);
        if (! $isCorrect) {
            return [
                'answer' => $rules['wrong_penalty'] ? $this->config->wrongPenaltyPoints : $this->config->wrongAnswerPoints,
                'time_bonus' => 0,
                'streak_bonus' => 0,
                'near_finish_bonus' => 0,
            ];
        }

        $to = (int) ($movement['to'] ?? 1);

        return [
            'answer' => $this->config->correctAnswerPoints,
            'time_bonus' => $rules['time_bonus'] ? $this->timeBonus($turn) : 0,
            'streak_bonus' => $rules['streak_bonus'] ? $this->streakBonus($newStreak) : 0,
            'near_finish_bonus' => $rules['near_finish_bonus'] && $to >= $this->config->nearFinishThreshold ? $this->config->nearFinishBonusPoints : 0,
        ];
    }

    private function timeBonus(array $turn): int
    {
        if ($turn['question_started_at'] === null || $turn['question_deadline_at'] === null) {
            return 0;
        }

        $start = strtotime((string) $turn['question_started_at']);
        $deadline = strtotime((string) $turn['question_deadline_at']);
        if ($start === false || $deadline === false || $deadline <= $start) {
            return 0;
        }

        $remaining = max(0, $deadline - time());
        $total = max(1, $deadline - $start);

        return min($this->config->maxTimeBonusPoints, max(0, (int) floor(($remaining / $total) * $this->config->maxTimeBonusPoints)));
    }

    private function streakBonus(int $streak): int
    {
        if ($streak < 2) {
            return 0;
        }

        return min($this->config->maxStreakBonusPoints, ($streak - 1) * $this->config->streakBonusStepPoints);
    }

    private function responseMs(array $turn): ?int
    {
        if ($turn['question_started_at'] === null) {
            return null;
        }

        $start = strtotime((string) $turn['question_started_at']);
        if ($start === false) {
            return null;
        }

        return max(0, (time() - $start) * 1000);
    }

    private function scoringRules($source): array
    {
        if (is_string($source)) {
            $source = json_decode($source, true) ?: [];
        }
        if (! is_array($source)) {
            $source = [];
        }

        return [
            'time_bonus' => $this->boolRule($source, 'time_bonus', true),
            'streak_bonus' => $this->boolRule($source, 'streak_bonus', true),
            'near_finish_bonus' => $this->boolRule($source, 'near_finish_bonus', true),
            'wrong_penalty' => $this->boolRule($source, 'wrong_penalty', false),
            'timeout_penalty' => $this->boolRule($source, 'timeout_penalty', false),
        ];
    }

    private function questionSelectionRules($source, int $maxPosition): array
    {
        if (is_string($source)) {
            $source = json_decode($source, true) ?: [];
        }
        if (! is_array($source)) {
            $source = [];
        }

        $strategy = $this->validOption((string) ($source['strategy'] ?? 'difficulty_zone'), ['difficulty_zone', 'random'], 'difficulty_zone');
        $maxPosition = max(1, $maxPosition);
        $easyTo = max(1, (int) round($maxPosition * 0.3));
        $mediumTo = max($easyTo, (int) round($maxPosition * 0.7));

        return [
            'strategy' => $strategy,
            'topic_ids' => array_values(array_unique(array_filter(
                array_map('intval', is_array($source['topic_ids'] ?? null) ? $source['topic_ids'] : []),
                static fn (int $topicId): bool => $topicId > 0
            ))),
            'topics' => array_values(array_filter(
                is_array($source['topics'] ?? null) ? $source['topics'] : [],
                static fn ($topic): bool => is_array($topic)
                    && isset($topic['uuid'], $topic['name'])
                    && is_string($topic['uuid'])
                    && is_string($topic['name'])
            )),
            'zones' => [
                ['from' => 1, 'to' => $easyTo, 'difficulty' => 'EASY'],
                ['from' => $easyTo + 1, 'to' => $mediumTo, 'difficulty' => 'MEDIUM'],
                ['from' => $mediumTo + 1, 'to' => $maxPosition, 'difficulty' => 'HARD'],
            ],
            'fallback' => 'any_published_question_in_selected_topics',
        ];
    }

    private function resolveQuestionTopics(int $teacherId, $topicUuids): array
    {
        if (! is_array($topicUuids)) {
            $topicUuids = [];
        }

        $topicUuids = array_values(array_unique(array_filter(
            array_map(static fn ($uuid): string => trim((string) $uuid), $topicUuids),
            static fn (string $uuid): bool => $uuid !== ''
        )));
        if ($topicUuids === []) {
            throw new DomainException('Pilih minimal satu topik soal untuk game.');
        }
        if (count($topicUuids) > 20) {
            throw new DomainException('Maksimal 20 topik dapat dipakai dalam satu game.');
        }

        $topics = (new QuestionTopicModel())
            ->where('owner_teacher_id', $teacherId)
            ->whereIn('public_uuid', $topicUuids)
            ->findAll();
        if (count($topics) !== count($topicUuids)) {
            throw new DomainException('Pilihan topik tidak valid atau bukan milik guru pemilik room.');
        }

        $topicIds = array_map(static fn (array $topic): int => (int) $topic['id'], $topics);
        if ($this->questionBankSummary($teacherId, $topicIds)['total'] < 1) {
            throw new DomainException('Topik yang dipilih belum memiliki soal published.');
        }

        usort($topics, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return [
            'topic_ids' => $topicIds,
            'topics' => array_map(static fn (array $topic): array => [
                'uuid' => $topic['public_uuid'],
                'name' => $topic['name'],
            ], $topics),
        ];
    }

    private function publicQuestionSelectionRules(array $room): array
    {
        $rules = $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position']);
        unset($rules['topic_ids']);

        return $rules;
    }

    private function targetDifficultyForTurn(array $room, int $position): ?string
    {
        $rules = $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position']);
        if ($rules['strategy'] !== 'difficulty_zone') {
            return null;
        }

        foreach ($rules['zones'] as $zone) {
            if ($position >= (int) $zone['from'] && $position <= (int) $zone['to']) {
                return $zone['difficulty'];
            }
        }

        return $rules['zones'][count($rules['zones']) - 1]['difficulty'];
    }

    private function boolRule(array $source, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $source)) {
            return $default;
        }

        return filter_var($source[$key], FILTER_VALIDATE_BOOLEAN);
    }

    private function assertTeacherRoomQuota(int $teacherId): void
    {
        $activeCount = (new GameRoomModel())
            ->where('teacher_id', $teacherId)
            ->whereIn('status', ['LOBBY', 'PLAYING', 'PAUSED'])
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->countAllResults();
        if ($activeCount >= $this->config->freeActiveRoomLimit) {
            throw new DomainException('Batas room aktif tercapai. Selesaikan atau tunggu room lama kedaluwarsa.');
        }

        $todayCount = (new GameRoomModel())
            ->where('teacher_id', $teacherId)
            ->where('created_at >=', date('Y-m-d 00:00:00'))
            ->countAllResults();
        if ($todayCount >= $this->config->freeDailyRoomLimit) {
            throw new DomainException('Batas pembuatan room hari ini tercapai.');
        }

        $monthlyCount = (new GameRoomModel())
            ->where('teacher_id', $teacherId)
            ->where('created_at >=', date('Y-m-01 00:00:00'))
            ->countAllResults();
        if ($monthlyCount >= $this->config->freeMonthlyRoomLimit) {
            throw new DomainException('Batas pembuatan room bulan ini tercapai.');
        }
    }

    private function assertRoomNotExpired(array $room, string $message): void
    {
        if (($room['expires_at'] ?? null) !== null && strtotime((string) $room['expires_at']) < time()) {
            throw new DomainException($message);
        }
    }

    private function uniquePin(): string
    {
        $rooms = new GameRoomModel();

        do {
            $pin = (string) random_int(100000, 999999);
        } while ($rooms->where('pin', $pin)->first() !== null);

        return $pin;
    }

    private function validOption(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function avatarKeys(): array
    {
        return ['robot', 'explorer', 'rocket', 'knight', 'scientist', 'runner'];
    }

    private function boardSpecialTiles(array $board): array
    {
        $tiles = json_decode((string) ($board['special_tiles_json'] ?? ''), true);
        if (! is_array($tiles)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($tile): ?array {
            if (! is_array($tile)) {
                return null;
            }

            $position = (int) ($tile['tile'] ?? 0);
            $type = strtoupper((string) ($tile['type'] ?? ''));
            if ($position < 1 || ! in_array($type, ['BONUS', 'TRAP', 'SAFE', 'MYSTERY'], true)) {
                return null;
            }

            return [
                'tile' => $position,
                'type' => $type,
                'label' => (string) ($tile['label'] ?? $type),
                'points' => isset($tile['points']) ? (int) $tile['points'] : null,
                'steps' => isset($tile['steps']) ? (int) $tile['steps'] : null,
            ];
        }, $tiles)));
    }

    private function specialTileAt(int $position, array $board): ?array
    {
        foreach ($this->boardSpecialTiles($board) as $tile) {
            if ((int) $tile['tile'] === $position) {
                return $tile;
            }
        }

        return null;
    }

    private function specialTileCount(array $board, string $type): int
    {
        $type = strtoupper($type);

        return count(array_filter(
            $this->boardSpecialTiles($board),
            static fn (array $tile): bool => $tile['type'] === $type
        ));
    }

    private const BOARD_SIZE_LAYOUTS = [
        50 => [
            'ladders' => [
                ['from' => 3, 'to' => 9],
                ['from' => 8, 'to' => 18],
                ['from' => 14, 'to' => 28],
                ['from' => 19, 'to' => 34],
                ['from' => 26, 'to' => 39],
            ],
            'snakes' => [
                ['from' => 12, 'to' => 4],
                ['from' => 22, 'to' => 11],
                ['from' => 31, 'to' => 16],
                ['from' => 42, 'to' => 24],
                ['from' => 47, 'to' => 32],
            ],
            'special_tiles' => [
                ['tile' => 6, 'type' => 'BONUS', 'points' => 50, 'label' => 'Bonus 50'],
                ['tile' => 15, 'type' => 'TRAP', 'steps' => 3, 'label' => 'Trap mundur 3'],
                ['tile' => 21, 'type' => 'SAFE', 'label' => 'Perisai aman'],
                ['tile' => 30, 'type' => 'MYSTERY', 'label' => 'Misteri'],
                ['tile' => 37, 'type' => 'BONUS', 'points' => 75, 'label' => 'Bonus 75'],
            ],
        ],
        70 => [
            'ladders' => [
                ['from' => 5, 'to' => 14],
                ['from' => 11, 'to' => 27],
                ['from' => 18, 'to' => 38],
                ['from' => 24, 'to' => 45],
                ['from' => 33, 'to' => 52],
                ['from' => 41, 'to' => 60],
            ],
            'snakes' => [
                ['from' => 16, 'to' => 6],
                ['from' => 30, 'to' => 13],
                ['from' => 47, 'to' => 22],
                ['from' => 55, 'to' => 31],
                ['from' => 64, 'to' => 50],
                ['from' => 68, 'to' => 57],
            ],
            'special_tiles' => [
                ['tile' => 9, 'type' => 'BONUS', 'points' => 50, 'label' => 'Bonus 50'],
                ['tile' => 20, 'type' => 'TRAP', 'steps' => 3, 'label' => 'Trap mundur 3'],
                ['tile' => 36, 'type' => 'SAFE', 'label' => 'Perisai aman'],
                ['tile' => 43, 'type' => 'MYSTERY', 'label' => 'Misteri'],
                ['tile' => 53, 'type' => 'BONUS', 'points' => 75, 'label' => 'Bonus 75'],
                ['tile' => 62, 'type' => 'TRAP', 'steps' => 4, 'label' => 'Trap mundur 4'],
            ],
        ],
    ];

    private function applyBoardSize(array $board, int $tileCount): array
    {
        $layout = self::BOARD_SIZE_LAYOUTS[$tileCount] ?? null;
        if ($layout === null) {
            return $board;
        }

        $newBoardId = (new BoardTemplateModel())->insert([
            'public_uuid' => Uuid::v4(),
            'name' => $board['name'] . ' (' . $tileCount . ' Kotak)',
            'tile_count' => $tileCount,
            'ladders_json' => json_encode($layout['ladders'], JSON_UNESCAPED_SLASHES),
            'snakes_json' => json_encode($layout['snakes'], JSON_UNESCAPED_SLASHES),
            'special_tiles_json' => json_encode($layout['special_tiles'], JSON_UNESCAPED_SLASHES),
            'theme_json' => $board['theme_json'],
            'status' => 'ROOM_INSTANCE',
        ], true);

        return (new BoardTemplateModel())->find($newBoardId);
    }

    private function applyRaceTrackLength(array $board, int $trackLength): array
    {
        $newBoardId = (new BoardTemplateModel())->insert([
            'public_uuid' => Uuid::v4(),
            'name' => $board['name'] . ' (' . $trackLength . ' Kotak)',
            'game_mode' => 'QUIZ_RACE',
            'tile_count' => $trackLength,
            'ladders_json' => json_encode([], JSON_UNESCAPED_SLASHES),
            'snakes_json' => json_encode([], JSON_UNESCAPED_SLASHES),
            'special_tiles_json' => json_encode($this->race->generateTrackTiles($trackLength), JSON_UNESCAPED_SLASHES),
            'theme_json' => $board['theme_json'],
            'status' => 'ROOM_INSTANCE',
        ], true);

        return (new BoardTemplateModel())->find($newBoardId);
    }

    private function applyMysteryTileCount(array $board, int $mysteryCount): array
    {
        $mysteryCount = max(0, min(6, $mysteryCount));
        $allTiles = json_decode((string) ($board['special_tiles_json'] ?? ''), true);
        if (! is_array($allTiles)) {
            $allTiles = [];
        }

        $nonMysteryTiles = array_values(array_filter(
            $allTiles,
            static fn ($tile): bool => is_array($tile) && strtoupper((string) ($tile['type'] ?? '')) !== 'MYSTERY',
        ));
        $currentMysteryCount = count($allTiles) - count($nonMysteryTiles);

        if ($mysteryCount === $currentMysteryCount) {
            return $board;
        }

        $tileCount = (int) $board['tile_count'];
        $occupied = [];
        foreach ($nonMysteryTiles as $tile) {
            $occupied[(int) $tile['tile']] = true;
        }
        foreach (json_decode((string) $board['ladders_json'], true) ?: [] as $ladder) {
            $occupied[(int) $ladder['from']] = true;
            $occupied[(int) $ladder['to']] = true;
        }
        foreach (json_decode((string) $board['snakes_json'], true) ?: [] as $snake) {
            $occupied[(int) $snake['from']] = true;
            $occupied[(int) $snake['to']] = true;
        }

        $pool = [];
        for ($tile = 2; $tile < $tileCount; $tile++) {
            if (! isset($occupied[$tile])) {
                $pool[] = $tile;
            }
        }
        shuffle($pool);
        $newMysteryTiles = array_map(
            static fn (int $tile): array => ['tile' => $tile, 'type' => 'MYSTERY', 'label' => 'Misteri'],
            array_slice($pool, 0, $mysteryCount),
        );

        $newSpecialTiles = array_merge($nonMysteryTiles, $newMysteryTiles);

        // If $board is already a private per-room clone (e.g. from applyBoardSize()),
        // update it in place instead of inserting a second clone — otherwise the
        // first clone is never referenced by the room and is orphaned forever,
        // since deleteRoom() only knows how to clean up the room's final board.
        if ($board['status'] === 'ROOM_INSTANCE') {
            (new BoardTemplateModel())->update($board['id'], [
                'special_tiles_json' => json_encode($newSpecialTiles, JSON_UNESCAPED_SLASHES),
            ]);

            return (new BoardTemplateModel())->find($board['id']);
        }

        $newBoardId = (new BoardTemplateModel())->insert([
            'public_uuid' => Uuid::v4(),
            'name' => $board['name'] . ' (Room)',
            'tile_count' => $tileCount,
            'ladders_json' => $board['ladders_json'],
            'snakes_json' => $board['snakes_json'],
            'special_tiles_json' => json_encode($newSpecialTiles, JSON_UNESCAPED_SLASHES),
            'theme_json' => $board['theme_json'],
            'status' => 'ROOM_INSTANCE',
        ], true);

        return (new BoardTemplateModel())->find($newBoardId);
    }

    private function teamEffects(array $team): array
    {
        $effects = json_decode((string) ($team['active_effects_json'] ?? ''), true);
        if (! is_array($effects)) {
            $effects = [];
        }

        return [
            'safe_shield' => max(0, min(3, (int) ($effects['safe_shield'] ?? 0))),
            'oil_spill_lock' => (bool) ($effects['oil_spill_lock'] ?? false),
        ];
    }

    private function publicTheme(array $board): array
    {
        $theme = json_decode((string) ($board['theme_json'] ?? ''), true) ?: [];
        $palette = is_array($theme['palette'] ?? null) ? $theme['palette'] : [];

        return [
            'key' => $theme['theme_key'] ?? 'classic_arena',
            'name' => $theme['name'] ?? $board['name'],
            'palette' => [
                'board' => $palette['board'] ?? '#10251f',
                'board2' => $palette['board2'] ?? '#172033',
                'tileA' => $palette['tileA'] ?? '#f8fafc',
                'tileB' => $palette['tileB'] ?? '#e0f2fe',
                'accent' => $palette['accent'] ?? '#f97316',
                'snake' => $palette['snake'] ?? '#22c55e',
                'ladder' => $palette['ladder'] ?? '#facc15',
            ],
        ];
    }

    private function publicRoom(array $room, bool $includePin = false, bool $forOwner = false): array
    {
        $gameMode = $room['game_mode'] ?? 'SNAKES_LADDERS';
        $payload = [
            'uuid' => $room['public_uuid'],
            'title' => $room['title'],
            'display_title' => GameRoomPresenter::displayTitle($room),
            'status' => $room['status'],
            'state_version' => (int) $room['state_version'],
            'current_team_uuid' => $this->currentTeamUuid($room),
            'question_time_seconds' => (int) $room['question_time_seconds'],
            'max_position' => (int) $room['max_position'],
            'lap_count' => (int) ($room['lap_count'] ?? 1),
            'game_mode' => $gameMode,
            'mode_label' => GameRoomPresenter::modeLabel($gameMode),
            'participation_mode' => $room['participation_mode'] ?? 'TEAM_DEVICE',
            'turn_order_mode' => $room['turn_order_mode'] ?? 'random',
            'finish_rule' => $room['finish_rule'] ?? 'clamp_finish',
            'scoring' => $this->scoringRules($room['scoring_json'] ?? []),
            'question_selection' => $this->publicQuestionSelectionRules($room),
            'started_at' => $room['started_at'],
            'finished_at' => $room['finished_at'],
        ];

        if ($includePin) {
            $payload['pin'] = $room['pin'];
        }

        if ($forOwner) {
            $payload['projector_token'] = (string) ($room['projector_token'] ?? '');
        }

        return $payload;
    }

    private function publicModeState(array $room, array $board, ?array $turn, array $teams): array
    {
        $mode = $this->modes->resolve($room['game_mode'] ?? 'SNAKES_LADDERS');
        $stored = json_decode((string) ($room['mode_state_json'] ?? ''), true);
        if (! is_array($stored)) {
            $stored = [];
        }

        return [
            'stored' => $stored,
        ] + $mode->publicState($room, $board, $turn, $teams);
    }

    private function currentTeamUuid(array $room): ?string
    {
        if ($room['current_team_id'] === null) {
            return null;
        }

        $team = (new GameTeamModel())->find($room['current_team_id']);

        return $team['public_uuid'] ?? null;
    }

    private function publicTeam(array $team): array
    {
        return [
            'uuid' => $team['public_uuid'],
            'name' => $team['name'],
            'color' => $team['color'],
            'avatar' => $team['avatar'],
            'position' => (int) $team['position'],
            'score' => (int) $team['score'],
            'streak_count' => (int) ($team['streak_count'] ?? 0),
            'active_effects' => $this->teamEffects($team),
            'is_connected' => (bool) $team['is_connected'],
        ];
    }

    private function publicTurn(array $turn): array
    {
        $question = $turn['question_id'] ? (new QuestionModel())->find($turn['question_id']) : null;
        $team = (new GameTeamModel())->find($turn['team_id']);

        return [
            'uuid' => $turn['public_uuid'],
            'state' => $turn['state'],
            'turn_number' => (int) $turn['turn_number'],
            'team_uuid' => $team['public_uuid'] ?? null,
            'dice_value' => $turn['dice_value'] !== null ? (int) $turn['dice_value'] : null,
            'question' => $question ? $this->publicQuestion($question) : null,
            'deadline_at' => $turn['question_deadline_at'],
            'deadline_epoch_ms' => $turn['question_deadline_at'] !== null ? strtotime((string) $turn['question_deadline_at']) * 1000 : null,
        ];
    }

    private function publicQuestion(array $question): array
    {
        $options = (new QuestionOptionModel())
            ->where('question_id', $question['id'])
            ->orderBy('sort_order', 'ASC')
            ->findAll();
        $questionMeta = json_decode((string) ($question['meta_json'] ?? ''), true) ?: [];
        $questionImages = is_array($questionMeta['images'] ?? null)
            ? array_values(array_filter($questionMeta['images'], 'is_string'))
            : [];

        return [
            'uuid' => $question['public_uuid'],
            'stem' => $question['stem'],
            'type' => $question['question_type'],
            'difficulty' => $question['difficulty'],
            'points' => (int) $question['points'],
            'time_limit_seconds' => (int) $question['time_limit_seconds'],
            'media' => [
                'images' => $questionImages,
            ],
            'options' => array_map(static function (array $option): array {
                $media = json_decode((string) ($option['media_json'] ?? ''), true) ?: [];
                $images = is_array($media['images'] ?? null)
                    ? array_values(array_filter($media['images'], 'is_string'))
                    : [];

                return [
                    'id' => (int) $option['id'],
                    'label' => $option['label'],
                    'body' => $option['body'],
                    'media' => [
                        'images' => $images,
                    ],
                ];
            }, $options),
        ];
    }

    public function questionBankSummary(int $teacherId, array $topicIds = []): array
    {
        $query = (new QuestionModel())
            ->select('difficulty, question_type, COUNT(*) AS total')
            ->where('owner_teacher_id', $teacherId)
            ->where('status', 'PUBLISHED')
            ->groupBy('difficulty, question_type');

        if ($topicIds !== []) {
            $query->whereIn('topic_id', $topicIds);
        }

        $rows = $query->findAll();

        return $this->summarizeQuestionRows($rows);
    }

    public function questionTopicCatalog(int $teacherId): array
    {
        $topics = (new QuestionTopicModel())
            ->where('owner_teacher_id', $teacherId)
            ->orderBy('name', 'ASC')
            ->findAll();

        $rows = (new QuestionModel())
            ->select('topic_id, difficulty, question_type, COUNT(*) AS total')
            ->where('owner_teacher_id', $teacherId)
            ->where('status', 'PUBLISHED')
            ->where('topic_id IS NOT NULL', null, false)
            ->groupBy('topic_id, difficulty, question_type')
            ->findAll();
        $rowsByTopic = [];
        foreach ($rows as $row) {
            $rowsByTopic[(int) $row['topic_id']][] = $row;
        }

        return array_map(function (array $topic) use ($rowsByTopic): array {
            return [
                'uuid' => $topic['public_uuid'],
                'name' => $topic['name'],
                'summary' => $this->summarizeQuestionRows($rowsByTopic[(int) $topic['id']] ?? []),
            ];
        }, $topics);
    }

    private function summarizeQuestionRows(array $rows): array
    {
        $summary = [
            'total' => 0,
            'difficulty' => [
                'EASY' => 0,
                'MEDIUM' => 0,
                'HARD' => 0,
            ],
            'type' => [],
            'zone_strategy_ready' => false,
        ];

        foreach ($rows as $row) {
            $total = (int) ($row['total'] ?? 0);
            $difficulty = strtoupper((string) ($row['difficulty'] ?? 'MEDIUM'));
            $type = strtoupper((string) ($row['question_type'] ?? 'MULTIPLE_CHOICE'));
            $summary['total'] += $total;
            if (isset($summary['difficulty'][$difficulty])) {
                $summary['difficulty'][$difficulty] += $total;
            }
            $summary['type'][$type] = ($summary['type'][$type] ?? 0) + $total;
        }

        $summary['zone_strategy_ready'] = $summary['difficulty']['EASY'] > 0
            && $summary['difficulty']['MEDIUM'] > 0
            && $summary['difficulty']['HARD'] > 0;

        return $summary;
    }

    private function leaderboard(int $roomId): array
    {
        return array_map([$this, 'publicTeam'], (new GameTeamModel())
            ->where('room_id', $roomId)
            ->orderBy('score', 'DESC')
            ->orderBy('position', 'DESC')
            ->findAll());
    }

    private function recentEvents(int $roomId): array
    {
        $rows = (new GameEventModel())
            ->where('room_id', $roomId)
            ->orderBy('id', 'DESC')
            ->limit(20)
            ->findAll();

        return array_reverse(array_map(static fn (array $row): array => json_decode((string) $row['payload_json'], true), $rows));
    }
}
