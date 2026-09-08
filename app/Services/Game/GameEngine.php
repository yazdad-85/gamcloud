<?php

namespace App\Services\Game;

use App\Models\BoardTemplateModel;
use App\Models\GameAnswerModel;
use App\Models\GameEventModel;
use App\Models\GameRoomModel;
use App\Models\GameTeamModel;
use App\Models\GameTurnModel;
use App\Models\IdempotencyKeyModel;
use App\Models\QuestionModel;
use App\Models\QuestionOptionModel;
use App\Models\ScoreTransactionModel;
use App\Services\Game\Modes\GameModeCatalog;
use App\Services\Realtime\ChannelName;
use App\Services\Realtime\RealtimeService;
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

    public function __construct(
        private readonly RealtimeService $realtime = new RealtimeService()
    ) {
        $this->db = Database::connect();
        $this->config = config(GameConfig::class);
        $this->modes = new GameModeCatalog();
    }

    public function createRoom(int $teacherId, string $title, array $options = []): array
    {
        if (empty($options['skip_quota'])) {
            $this->assertTeacherRoomQuota($teacherId);
        }

        $boards = new BoardTemplateModel();
        $boardTemplateId = (int) ($options['board_template_id'] ?? 0);
        $board = null;
        if ($boardTemplateId > 0) {
            $board = $boards->where('id', $boardTemplateId)->where('status', 'ACTIVE')->first();
        }
        $board ??= (new BoardTemplateModel())->where('status', 'ACTIVE')->first();
        if ($board === null) {
            throw new DomainException('Board template belum tersedia. Jalankan seeder demo lebih dulu.');
        }

        if (isset($options['mystery_tile_count'])) {
            $board = $this->applyMysteryTileCount($board, (int) $options['mystery_tile_count']);
        }

        $pin = $this->uniquePin();
        $now = date('Y-m-d H:i:s');
        $turnOrderMode = $this->validOption((string) ($options['turn_order_mode'] ?? 'random'), ['random', 'join_order'], 'random');
        $finishRule = $this->validOption((string) ($options['finish_rule'] ?? 'clamp_finish'), ['clamp_finish', 'exact_finish'], 'clamp_finish');
        $scoring = $this->scoringRules($options['scoring'] ?? []);
        $questionSelection = $this->questionSelectionRules($options['question_selection'] ?? [], (int) $board['tile_count']);
        $gameModeKey = $this->validOption(strtoupper((string) ($options['game_mode'] ?? 'SNAKES_LADDERS')), $this->modes->playableKeys(), 'SNAKES_LADDERS');
        $gameMode = $this->modes->resolve($gameModeKey);
        $baseRoomState = [
            'max_position' => (int) $board['tile_count'],
        ];
        $roomId = (new GameRoomModel())->insert([
            'public_uuid' => Uuid::v4(),
            'teacher_id' => $teacherId,
            'board_template_id' => $board['id'],
            'pin' => $pin,
            'title' => $title,
            'status' => 'LOBBY',
            'state_version' => 1,
            'question_time_seconds' => $this->config->defaultQuestionTime,
            'redemption_time_seconds' => $this->config->redemptionTime,
            'max_teams' => 6,
            'max_position' => (int) $board['tile_count'],
            'game_mode' => $gameMode->key(),
            'mode_state_json' => json_encode($gameMode->initialState($baseRoomState, $board), JSON_UNESCAPED_SLASHES),
            'turn_order_mode' => $turnOrderMode,
            'finish_rule' => $finishRule,
            'scoring_json' => json_encode($scoring, JSON_UNESCAPED_SLASHES),
            'question_selection_json' => json_encode($questionSelection, JSON_UNESCAPED_SLASHES),
            'expires_at' => date('Y-m-d H:i:s', time() + ($this->config->pinTtlMinutes * 60)),
            'created_at' => $now,
            'updated_at' => $now,
        ], true);

        $room = $this->roomById((int) $roomId);
        $this->recordEvent($room, 'room.created', [
            'pin' => $pin,
            'title' => $title,
            'game_mode' => $gameMode->key(),
        ]);

        return $this->snapshot($room['public_uuid']);
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

        return [
            'room' => $room,
            'team' => $team,
            'token' => $token,
            'snapshot' => $this->snapshot($room['public_uuid']),
        ];
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
        if ($turn === null || $turn['state'] !== 'QUESTION_ACTIVE') {
            throw new DomainException('Tidak ada pertanyaan aktif untuk dipaksa timeout.');
        }

        $team = (new GameTeamModel())->find($turn['team_id']);
        if ($team === null) {
            throw new DomainException('Tim aktif tidak ditemukan.');
        }

        $this->resolveTimedOutTurn($room, $team, $turn);
        $room = $this->roomByUuid($roomUuid);
        $this->recordEvent($room, 'teacher.override', ['action' => 'force_timeout']);

        return $this->snapshot($roomUuid);
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
        $targetDifficulty = $this->targetDifficultyForTurn($room, $landedTile);
        $question = $this->selectQuestion((int) $room['teacher_id'], $targetDifficulty);
        $now = date('Y-m-d H:i:s');
        $deadline = date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => 'QUESTION_ACTIVE',
            'dice_value' => $dice,
            'question_id' => $question['id'],
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
        $this->bumpRoom($room['id']);
        $room = $this->roomById((int) $room['id']);

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
        $movement = $isCorrect
            ? $this->movementForCorrectAnswer($from, $dice, $room, $board, $team)
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

        $isMysteryLanding = $isCorrect && ($movement['special'] ?? null) === 'MYSTERY';
        $finished = ! $isMysteryLanding && $to >= (int) $room['max_position'];
        $nextTeam = null;
        if ($isMysteryLanding) {
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

        $question = $this->selectQuestion((int) $room['teacher_id'], 'HARD');
        $now = date('Y-m-d H:i:s');
        $deadline = date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => 'MYSTERY_QUESTION_ACTIVE',
            'mystery_target_team_id' => $targetTeamId,
            'question_id' => $question['id'],
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
        $this->bumpRoom($room['id']);
        $room = $this->roomById((int) $room['id']);

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
            $newPosition = $this->applyMysteryDeltaToTeam($room, $team, 80, 3, $maxPosition);
            $outcome = 'REWARD_SELF';
            $affectedTeamUuid = $team['public_uuid'];
            if ($newPosition >= $maxPosition) {
                $finished = true;
                $finishedTeamUuid = $team['public_uuid'];
            }
        } elseif ($isCorrect && $targetTeamId !== null) {
            $opponent = (new GameTeamModel())->find($targetTeamId);
            $this->applyMysteryDeltaToTeam($room, $opponent, -60, -4, $maxPosition);
            $outcome = 'PUNISH_OPPONENT';
            $affectedTeamUuid = $opponent['public_uuid'];
        } else {
            $this->applyMysteryDeltaToTeam($room, $team, -60, -4, $maxPosition);
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

    public function snapshot(string $roomUuid): array
    {
        $room = $this->roomByUuid($roomUuid);
        $teams = array_map([$this, 'publicTeam'], $this->teams((int) $room['id']));
        $turn = $this->activeTurn((int) $room['id']);
        $board = (new BoardTemplateModel())->find($room['board_template_id']);

        return [
            'room' => $this->publicRoom($room),
            'question_bank' => $this->questionBankSummary((int) $room['teacher_id']),
            'board' => [
                'tile_count' => (int) $board['tile_count'],
                'ladders' => json_decode((string) $board['ladders_json'], true) ?: [],
                'snakes' => json_decode((string) $board['snakes_json'], true) ?: [],
                'special_tiles' => $this->boardSpecialTiles($board),
                'theme' => $this->publicTheme($board),
            ],
            'teams' => $teams,
            'current_turn' => $turn ? $this->publicTurn($turn) : null,
            'mode_state' => $this->publicModeState($room, $board, $turn, $teams),
            'leaderboard' => $this->leaderboard((int) $room['id']),
            'events' => $this->recentEvents((int) $room['id']),
        ];
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

    private function selectQuestion(int $teacherId, ?string $difficulty = null): array
    {
        $query = (new QuestionModel())
            ->where('owner_teacher_id', $teacherId)
            ->where('status', 'PUBLISHED');

        if ($difficulty !== null) {
            $query->where('difficulty', $difficulty);
        }

        $questions = $query->findAll();
        if ($questions === [] && $difficulty !== null) {
            $questions = (new QuestionModel())
                ->where('owner_teacher_id', $teacherId)
                ->where('status', 'PUBLISHED')
                ->findAll();
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
            'zones' => [
                ['from' => 1, 'to' => $easyTo, 'difficulty' => 'EASY'],
                ['from' => $easyTo + 1, 'to' => $mediumTo, 'difficulty' => 'MEDIUM'],
                ['from' => $mediumTo + 1, 'to' => $maxPosition, 'difficulty' => 'HARD'],
            ],
            'fallback' => 'any_published_question',
        ];
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

    private function publicRoom(array $room): array
    {
        return [
            'uuid' => $room['public_uuid'],
            'pin' => $room['pin'],
            'title' => $room['title'],
            'status' => $room['status'],
            'state_version' => (int) $room['state_version'],
            'current_team_uuid' => $this->currentTeamUuid($room),
            'question_time_seconds' => (int) $room['question_time_seconds'],
            'max_position' => (int) $room['max_position'],
            'game_mode' => $room['game_mode'] ?? 'SNAKES_LADDERS',
            'turn_order_mode' => $room['turn_order_mode'] ?? 'random',
            'finish_rule' => $room['finish_rule'] ?? 'clamp_finish',
            'scoring' => $this->scoringRules($room['scoring_json'] ?? []),
            'question_selection' => $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position']),
            'started_at' => $room['started_at'],
            'finished_at' => $room['finished_at'],
        ];
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

    public function questionBankSummary(int $teacherId): array
    {
        $rows = (new QuestionModel())
            ->select('difficulty, question_type, COUNT(*) AS total')
            ->where('owner_teacher_id', $teacherId)
            ->where('status', 'PUBLISHED')
            ->groupBy('difficulty, question_type')
            ->findAll();

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
