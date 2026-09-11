<?php

use App\Models\GameRoomModel;
use App\Models\GameRoundAnswerModel;
use App\Models\GameRoundModel;
use App\Models\GameRoundQuestionModel;
use App\Models\GameTeamModel;
use App\Models\GameTurnModel;
use App\Models\QuestionModel;
use App\Models\QuestionOptionModel;
use App\Models\QuestionTopicModel;
use App\Models\ScoreTransactionModel;
use App\Services\Game\GameEngine;
use App\Services\Game\Uuid;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class QuizRaceTeamDeviceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = ['App'];
    protected $seed = App\Database\Seeds\DemoGameSeeder::class;

    public function testStartCreatesFirstMultiQuestionRaceRoundAtomically(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Quiz Race Start Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];
        $firstTeam = $engine->joinByPin($room['pin'], 'Tim A')['team'];
        $secondTeam = $engine->joinByPin($room['pin'], 'Tim B')['team'];
        (new GameTeamModel())->update($firstTeam['id'], ['streak_count' => 4]);
        (new GameTeamModel())->update($secondTeam['id'], ['streak_count' => 2]);

        $beforeStartMs = (int) floor(microtime(true) * 1000);
        $snapshot = $engine->start($room['uuid']);
        $afterStartMs = (int) floor(microtime(true) * 1000);

        $storedRoom = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        $round = (new GameRoundModel())->where('room_id', $storedRoom['id'])->where('round_number', 1)->first();
        $question = (new GameRoundQuestionModel())->where('round_id', $round['id'])->where('question_number', 1)->first();
        $schedule = $round['difficulty_schedule_json'];
        $difficultyCounts = array_count_values($schedule);

        $this->assertSame('PLAYING', $storedRoom['status']);
        $this->assertNull($storedRoom['current_team_id']);
        $this->assertNull($snapshot['room']['current_team_uuid']);
        $this->assertSame(0, (new GameTurnModel())->where('room_id', $storedRoom['id'])->countAllResults());
        $this->assertSame('ROUND_ACTIVE', $round['state']);
        $this->assertSame(15, $round['question_target_count']);
        $this->assertCount(15, $schedule);
        $this->assertSame(5, $difficultyCounts['EASY'] ?? 0);
        $this->assertSame(5, $difficultyCounts['MEDIUM'] ?? 0);
        $this->assertSame(5, $difficultyCounts['HARD'] ?? 0);
        $this->assertSame('QUESTION_ACTIVE', $question['state']);
        $this->assertSame(1, $question['question_number']);
        $this->assertSame($schedule[0], $question['difficulty']);
        $this->assertGreaterThanOrEqual($beforeStartMs, $question['started_at_epoch_ms']);
        $this->assertLessThanOrEqual($afterStartMs, $question['started_at_epoch_ms']);
        $this->assertSame(30000, $question['deadline_epoch_ms'] - $question['started_at_epoch_ms']);
        $this->assertSame(
            intdiv($question['started_at_epoch_ms'], 1000),
            strtotime($question['started_at'])
        );
        $this->assertSame(
            intdiv($question['deadline_epoch_ms'], 1000),
            strtotime($question['deadline_at'])
        );
        $this->assertSame([0, 0], array_map(
            static fn (array $team): int => (int) $team['streak_count'],
            (new GameTeamModel())->where('room_id', $storedRoom['id'])->orderBy('id', 'ASC')->findAll()
        ));

        foreach (['race.round_started', 'race.question_started', 'game.started'] as $eventType) {
            $this->assertSame(1, $this->db->table('game_events')
                ->where('room_id', $storedRoom['id'])
                ->where('type', $eventType)
                ->countAllResults());
            $this->assertSame(1, $this->db->table('realtime_outbox')
                ->where('room_id', $storedRoom['id'])
                ->where('event', $eventType)
                ->countAllResults());
        }
    }

    public function testStartUsesSelectedTopicAndExcludesRaceQuestionHistory(): void
    {
        $selectedTopicId = $this->seedTopic('Topik Race Terpilih');
        $otherTopicId = $this->seedTopic('Topik Race Lain');
        $usedByDifficulty = [];
        $eligibleByDifficulty = [];
        foreach (['EASY', 'MEDIUM', 'HARD'] as $difficulty) {
            $usedByDifficulty[$difficulty] = $this->seedQuestion($selectedTopicId, "Used {$difficulty}", $difficulty);
            $eligibleByDifficulty[$difficulty] = $this->seedQuestion($selectedTopicId, "Eligible {$difficulty}", $difficulty);
            $this->seedQuestion($otherTopicId, "Other {$difficulty}", $difficulty);
        }

        $topic = $this->db->table('question_topics')->where('id', $selectedTopicId)->get()->getRowArray();
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Quiz Race Topic Start Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
            'question_selection' => [
                'strategy' => 'difficulty_zone',
                'topic_uuids' => [$topic['public_uuid']],
            ],
        ])['room'];
        $engine->joinByPin($room['pin'], 'Tim Topic');
        $storedRoom = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();

        $historyRoundId = (new GameRoundModel())->insert([
            'public_uuid' => Uuid::v4(),
            'room_id' => $storedRoom['id'],
            'round_number' => 0,
            'state' => 'ROUND_CLOSED',
            'question_target_count' => 3,
            'difficulty_schedule_json' => ['EASY', 'MEDIUM', 'HARD'],
        ], true);
        foreach (array_values($usedByDifficulty) as $index => $usedQuestionId) {
            $startedAtEpochMs = 1789113600000 + ($index * 30000);
            (new GameRoundQuestionModel())->insert([
                'public_uuid' => Uuid::v4(),
                'round_id' => $historyRoundId,
                'question_number' => $index + 1,
                'question_id' => $usedQuestionId,
                'difficulty' => array_keys($usedByDifficulty)[$index],
                'state' => 'QUESTION_CLOSED',
                'started_at' => date('Y-m-d H:i:s', intdiv($startedAtEpochMs, 1000)),
                'started_at_epoch_ms' => $startedAtEpochMs,
                'deadline_at' => date('Y-m-d H:i:s', intdiv($startedAtEpochMs + 30000, 1000)),
                'deadline_epoch_ms' => $startedAtEpochMs + 30000,
            ]);
        }

        $engine->start($room['uuid']);

        $round = (new GameRoundModel())->where('room_id', $storedRoom['id'])->where('round_number', 1)->first();
        $question = (new GameRoundQuestionModel())->where('round_id', $round['id'])->first();
        $selectedQuestion = (new QuestionModel())->find($question['question_id']);

        $this->assertSame($eligibleByDifficulty[$question['difficulty']], (int) $question['question_id']);
        $this->assertNotContains((int) $question['question_id'], array_values($usedByDifficulty));
        $this->assertSame($selectedTopicId, (int) $selectedQuestion['topic_id']);
        $this->assertSame(0, (new GameTurnModel())->where('room_id', $storedRoom['id'])->countAllResults());
    }

    /**
     * @dataProvider legacyStartModeProvider
     */
    public function testStartKeepsLegacyTurnBasedModesUnchanged(string $gameMode, string $participationMode): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, "Legacy Start {$gameMode}", [
            'game_mode' => $gameMode,
            'participation_mode' => $participationMode,
            'turn_order_mode' => 'join_order',
        ])['room'];

        if ($participationMode === 'TEAM_DEVICE') {
            $team = $engine->joinByPin($room['pin'], 'Tim Legacy')['team'];
        } else {
            $storedRoom = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
            $teamId = (new GameTeamModel())->insert([
                'public_uuid' => Uuid::v4(),
                'room_id' => $storedRoom['id'],
                'name' => 'Tim Centralized',
                'color' => '#2563eb',
                'avatar' => 'robot',
                'session_token_hash' => hash('sha256', 'centralized-start-test'),
                'position' => 1,
                'score' => 0,
                'streak_count' => 0,
                'active_effects_json' => json_encode(['safe_shield' => 0]),
                'is_connected' => 1,
                'joined_at' => date('Y-m-d H:i:s'),
            ], true);
            $team = (new GameTeamModel())->find($teamId);
        }

        $snapshot = $engine->start($room['uuid']);
        $storedRoom = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        $turn = (new GameTurnModel())->where('room_id', $storedRoom['id'])->first();

        $this->assertSame('PLAYING', $storedRoom['status']);
        $this->assertSame((int) $team['id'], (int) $storedRoom['current_team_id']);
        $this->assertSame($team['public_uuid'], $snapshot['room']['current_team_uuid']);
        $this->assertNotNull($turn);
        $this->assertSame('ROLL_READY', $turn['state']);
        $this->assertSame(0, (new GameRoundModel())->where('room_id', $storedRoom['id'])->countAllResults());
    }

    public static function legacyStartModeProvider(): array
    {
        return [
            'Ular Tangga Team Device' => ['SNAKES_LADDERS', 'TEAM_DEVICE'],
            'Quiz Race centralized' => ['QUIZ_RACE', 'TEACHER_CENTRALIZED'],
        ];
    }

    public function testRaceQuestionAnswerPersistsAtomicallyWithoutScoringOrMovement(): void
    {
        $fixture = $this->startAnswerRace(2);
        $option = $this->optionForQuestion($fixture['question']);
        $beforeTeam = (new GameTeamModel())->find($fixture['teams'][0]['id']);

        $snapshot = $fixture['engine']->raceQuestionAnswer(
            $fixture['room']['uuid'],
            $fixture['teams'][0]['public_uuid'],
            (int) $option['id']
        );

        $answer = (new GameRoundAnswerModel())
            ->where('round_question_id', $fixture['question']['id'])
            ->where('team_id', $fixture['teams'][0]['id'])
            ->first();
        $question = (new GameRoundQuestionModel())->find($fixture['question']['id']);
        $afterTeam = (new GameTeamModel())->find($fixture['teams'][0]['id']);

        $this->assertSame((int) $option['id'], $answer['option_id']);
        $this->assertSame((int) $fixture['question']['question_id'], $answer['question_id']);
        $this->assertSame((bool) $option['is_correct'], $answer['is_correct']);
        $this->assertSame($answer['is_correct'] ? 'CORRECT' : 'WRONG', $answer['outcome']);
        $this->assertGreaterThanOrEqual(0, $answer['response_ms']);
        $this->assertSame(0, $answer['score_delta']);
        $this->assertSame(1, $question['answer_count']);
        $this->assertSame('QUESTION_ACTIVE', $question['state']);
        $this->assertSame($beforeTeam['position'], $afterTeam['position']);
        $this->assertSame($beforeTeam['score'], $afterTeam['score']);
        $this->assertTrue($this->snapshotAnswerFlag($snapshot, $fixture['teams'][0]['public_uuid']));
        $this->assertFalse($this->snapshotAnswerFlag($snapshot, $fixture['teams'][1]['public_uuid']));

        $eventRow = $this->db->table('game_events')
            ->where('room_id', $fixture['stored_room']['id'])
            ->where('type', 'race.answer_submitted')
            ->get()
            ->getRowArray();
        $event = json_decode((string) $eventRow['payload_json'], true);
        $this->assertSame([
            'question_uuid' => $fixture['question']['public_uuid'],
            'team_uuid' => $fixture['teams'][0]['public_uuid'],
            'answered' => true,
        ], $event['payload']);
    }

    public function testRaceQuestionAnswerDuplicateRollsBackCounterAndIdempotentReplayIsStable(): void
    {
        $fixture = $this->startAnswerRace(2);
        $option = $this->optionForQuestion($fixture['question']);

        $first = $fixture['engine']->raceQuestionAnswer(
            $fixture['room']['uuid'],
            $fixture['teams'][0]['public_uuid'],
            (int) $option['id'],
            'same-request'
        );
        $replayed = $fixture['engine']->raceQuestionAnswer(
            $fixture['room']['uuid'],
            $fixture['teams'][0]['public_uuid'],
            (int) $option['id'],
            'same-request'
        );

        $this->assertSame($first, $replayed);
        $this->assertDomainFailure(
            fn () => $fixture['engine']->raceQuestionAnswer(
                $fixture['room']['uuid'],
                $fixture['teams'][0]['public_uuid'],
                (int) $option['id']
            ),
            'sudah menjawab'
        );
        $this->assertSame(1, (new GameRoundAnswerModel())
            ->where('round_question_id', $fixture['question']['id'])
            ->countAllResults());
        $this->assertSame(1, (new GameRoundQuestionModel())->find($fixture['question']['id'])['answer_count']);
        $this->assertSame(1, $this->db->table('game_events')
            ->where('room_id', $fixture['stored_room']['id'])
            ->where('type', 'race.answer_submitted')
            ->countAllResults());
    }

    public function testRaceQuestionAnswerIdempotencyScopeAllowsSameTeamOnNextQuestion(): void
    {
        $fixture = $this->startAnswerRace(1);
        $option = $this->optionForQuestion($fixture['question']);
        $fixture['engine']->raceQuestionAnswer(
            $fixture['room']['uuid'],
            $fixture['teams'][0]['public_uuid'],
            (int) $option['id'],
            'reused-client-key'
        );

        (new GameRoundQuestionModel())->update($fixture['question']['id'], ['state' => 'QUESTION_CLOSED']);
        $now = (int) floor(microtime(true) * 1000);
        $nextQuestionId = (new GameRoundQuestionModel())->insert([
            'public_uuid' => Uuid::v4(),
            'round_id' => $fixture['round']['id'],
            'question_number' => 2,
            'question_id' => $fixture['question']['question_id'],
            'difficulty' => $fixture['question']['difficulty'],
            'state' => 'QUESTION_ACTIVE',
            'started_at' => date('Y-m-d H:i:s', intdiv($now, 1000)),
            'started_at_epoch_ms' => $now,
            'deadline_at' => date('Y-m-d H:i:s', intdiv($now + 30000, 1000)),
            'deadline_epoch_ms' => $now + 30000,
        ], true);

        $fixture['engine']->raceQuestionAnswer(
            $fixture['room']['uuid'],
            $fixture['teams'][0]['public_uuid'],
            (int) $option['id'],
            'reused-client-key'
        );

        $this->assertSame(2, (new GameRoundAnswerModel())
            ->where('team_id', $fixture['teams'][0]['id'])
            ->countAllResults());
        $this->assertSame(1, (new GameRoundQuestionModel())->find($nextQuestionId)['answer_count']);
        $nextQuestion = (new GameRoundQuestionModel())->find($nextQuestionId);
        $scopes = array_column($this->db->table('idempotency_keys')
            ->like('scope', 'race-question-answer:' . $fixture['room']['uuid'] . ':', 'after')
            ->orderBy('scope', 'ASC')
            ->get()
            ->getResultArray(), 'scope');
        $expectedScopes = [
            implode(':', [
                'race-question-answer',
                $fixture['room']['uuid'],
                $fixture['question']['public_uuid'],
                $fixture['teams'][0]['public_uuid'],
            ]),
            implode(':', [
                'race-question-answer',
                $fixture['room']['uuid'],
                $nextQuestion['public_uuid'],
                $fixture['teams'][0]['public_uuid'],
            ]),
        ];
        sort($expectedScopes);
        $this->assertSame($expectedScopes, $scopes);
    }

    public function testRaceQuestionAnswerRejectsCrossRoomTeamAndInvalidOption(): void
    {
        $first = $this->startAnswerRace(1);
        $second = $this->startAnswerRace(1);
        $option = $this->optionForQuestion($first['question']);

        $this->assertDomainFailure(
            fn () => $first['engine']->raceQuestionAnswer(
                $first['room']['uuid'],
                $second['teams'][0]['public_uuid'],
                (int) $option['id']
            ),
            'Tim tidak ditemukan'
        );
        $this->assertDomainFailure(
            fn () => $first['engine']->raceQuestionAnswer(
                $first['room']['uuid'],
                $first['teams'][0]['public_uuid'],
                PHP_INT_MAX
            ),
            'Pilihan jawaban tidak valid'
        );

        $this->assertSame(0, (new GameRoundAnswerModel())
            ->where('round_question_id', $first['question']['id'])
            ->countAllResults());
        $this->assertSame(0, (new GameRoundQuestionModel())->find($first['question']['id'])['answer_count']);
    }

    public function testRaceQuestionAnswerRejectsTurnBasedGameMode(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Legacy Answer Contract', [
            'game_mode' => 'SNAKES_LADDERS',
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Legacy Answer')['team'];
        $engine->start($room['uuid']);

        $this->assertDomainFailure(
            fn () => $engine->raceQuestionAnswer($room['uuid'], $team['public_uuid'], 1),
            'hanya tersedia untuk Quiz Race Device per Tim'
        );
        $this->assertSame(0, (new GameRoundAnswerModel())->countAllResults());
    }

    /**
     * @dataProvider raceQuestionLateOffsetProvider
     */
    public function testRaceQuestionAnswerAtOrAfterDeadlineIsRejected(int $lateOffsetMs): void
    {
        $fixture = $this->startAnswerRace(1);
        $option = $this->optionForQuestion($fixture['question']);
        $startedAtEpochMs = 2000000000000;
        $deadlineEpochMs = $startedAtEpochMs + 30000;
        $this->setQuestionWindow($fixture['question']['id'], $startedAtEpochMs, $deadlineEpochMs);
        $engine = $this->engineAt([$deadlineEpochMs + $lateOffsetMs]);

        $this->assertDomainFailure(
            fn () => $engine->raceQuestionAnswer(
                $fixture['room']['uuid'],
                $fixture['teams'][0]['public_uuid'],
                (int) $option['id']
            ),
            'sudah habis'
        );
        $this->assertSame(0, (new GameRoundAnswerModel())
            ->where('round_question_id', $fixture['question']['id'])
            ->countAllResults());
        $this->assertSame(0, (new GameRoundQuestionModel())->find($fixture['question']['id'])['answer_count']);
    }

    public static function raceQuestionLateOffsetProvider(): array
    {
        return [
            'exactly at deadline' => [0],
            'after deadline' => [1],
        ];
    }

    public function testRaceQuestionAnswerPreservesDeterministicMillisecondOrdering(): void
    {
        $fixture = $this->startAnswerRace(2);
        $option = $this->optionForQuestion($fixture['question']);
        $startedAtEpochMs = 2000000000000;
        $this->setQuestionWindow($fixture['question']['id'], $startedAtEpochMs, $startedAtEpochMs + 30000);
        $engine = $this->engineAt([$startedAtEpochMs + 50, $startedAtEpochMs + 125, $startedAtEpochMs + 200]);

        foreach ($fixture['teams'] as $team) {
            $engine->raceQuestionAnswer(
                $fixture['room']['uuid'],
                $team['public_uuid'],
                (int) $option['id']
            );
        }

        $answers = (new GameRoundAnswerModel())
            ->where('round_question_id', $fixture['question']['id'])
            ->orderBy('answered_at_epoch_ms', 'ASC')
            ->findAll();
        $this->assertSame([50, 125], array_column($answers, 'response_ms'));
        $this->assertSame(75, $answers[1]['answered_at_epoch_ms'] - $answers[0]['answered_at_epoch_ms']);
        $question = (new GameRoundQuestionModel())->find($fixture['question']['id']);
        $this->assertSame(2, $question['answer_count']);
        $this->assertSame('QUESTION_RESOLVED', $question['state']);
    }

    public function testRaceQuestionAnswerActiveSnapshotDoesNotLeakAnswerDetails(): void
    {
        $fixture = $this->startAnswerRace(2);
        $option = $this->optionForQuestion($fixture['question']);
        $snapshot = $fixture['engine']->raceQuestionAnswer(
            $fixture['room']['uuid'],
            $fixture['teams'][0]['public_uuid'],
            (int) $option['id']
        );

        $answers = $snapshot['current_round']['current_question']['answers'];
        $this->assertCount(2, $answers);
        foreach ($answers as $answer) {
            $this->assertSame(['team_uuid', 'answered'], array_keys($answer));
            $this->assertArrayNotHasKey('option_id', $answer);
            $this->assertArrayNotHasKey('is_correct', $answer);
            $this->assertArrayNotHasKey('outcome', $answer);
            $this->assertArrayNotHasKey('response_ms', $answer);
        }
        $this->assertTrue($this->snapshotAnswerFlag($snapshot, $fixture['teams'][0]['public_uuid']));
        $this->assertFalse($this->snapshotAnswerFlag($snapshot, $fixture['teams'][1]['public_uuid']));
    }

    public function testRaceQuestionAnswerAndResolverClaimHaveAllOrNothingOrderingOnSqlite(): void
    {
        $fixture = $this->startAnswerRace(2);
        $option = $this->optionForQuestion($fixture['question']);
        (new GameRoundQuestionModel())->update($fixture['question']['id'], ['state' => 'QUESTION_RESOLVING']);

        $this->assertDomainFailure(
            fn () => $fixture['engine']->raceQuestionAnswer(
                $fixture['room']['uuid'],
                $fixture['teams'][0]['public_uuid'],
                (int) $option['id']
            ),
            'Tidak ada pertanyaan'
        );
        $this->assertSame(0, (new GameRoundAnswerModel())
            ->where('round_question_id', $fixture['question']['id'])
            ->countAllResults());
        $this->assertSame(0, (new GameRoundQuestionModel())->find($fixture['question']['id'])['answer_count']);

        (new GameRoundQuestionModel())->update($fixture['question']['id'], ['state' => 'QUESTION_ACTIVE']);
        $fixture['engine']->raceQuestionAnswer(
            $fixture['room']['uuid'],
            $fixture['teams'][0]['public_uuid'],
            (int) $option['id']
        );
        $this->db->table('game_round_questions')
            ->set('state', 'QUESTION_RESOLVING')
            ->where('id', $fixture['question']['id'])
            ->where('state', 'QUESTION_ACTIVE')
            ->update();

        $this->assertSame(1, $this->db->affectedRows());
        $this->assertSame(1, (new GameRoundAnswerModel())
            ->where('round_question_id', $fixture['question']['id'])
            ->countAllResults());
        $this->assertSame(1, (new GameRoundQuestionModel())->find($fixture['question']['id'])['answer_count']);
    }

    public function testResolveRaceQuestionMovesFastestCorrectThreeStepsAndPlainCorrectOneStep(): void
    {
        $fixture = $this->startAnswerRace(2);
        // Start both teams away from tile 4 (Boost) so plain movement math is isolated from tile effects.
        (new GameTeamModel())->update($fixture['teams'][0]['id'], ['position' => 2]);
        (new GameTeamModel())->update($fixture['teams'][1]['id'], ['position' => 2]);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $startedAtEpochMs = (int) $fixture['question']['started_at_epoch_ms'];
        $engine = $this->engineAt([$startedAtEpochMs + 50, $startedAtEpochMs + 500, $startedAtEpochMs + 501]);

        $engine->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $engine->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][1]['public_uuid'], (int) $correct['id']);

        $teamA = (new GameTeamModel())->find($fixture['teams'][0]['id']);
        $teamB = (new GameTeamModel())->find($fixture['teams'][1]['id']);

        $this->assertSame(5, $teamA['position']);
        $this->assertSame(3, $teamB['position']);
    }

    public function testResolveRaceQuestionKeepsWrongAndTimeoutTeamsInPlaceWithNoScore(): void
    {
        $fixture = $this->startAnswerRace(2);
        $wrong = $this->wrongOptionForQuestion($fixture['question']);
        $beforeA = (new GameTeamModel())->find($fixture['teams'][0]['id']);
        $beforeB = (new GameTeamModel())->find($fixture['teams'][1]['id']);

        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $wrong['id']);
        $fixture['engine']->resolveRaceQuestion($fixture['room']['uuid'], true);

        $afterA = (new GameTeamModel())->find($fixture['teams'][0]['id']);
        $afterB = (new GameTeamModel())->find($fixture['teams'][1]['id']);
        $answers = (new GameRoundAnswerModel())->where('round_question_id', $fixture['question']['id'])->findAll();

        $this->assertSame($beforeA['position'], $afterA['position']);
        $this->assertSame($beforeA['score'], $afterA['score']);
        $this->assertSame($beforeB['position'], $afterB['position']);
        $this->assertSame($beforeB['score'], $afterB['score']);
        $this->assertCount(2, $answers);
        $outcomesByTeam = array_combine(array_column($answers, 'team_id'), array_column($answers, 'outcome'));
        $this->assertSame('WRONG', $outcomesByTeam[$fixture['teams'][0]['id']]);
        $this->assertSame('TIMEOUT', $outcomesByTeam[$fixture['teams'][1]['id']]);
    }

    public function testResolveRaceQuestionRejectsWithoutForceBeforeAllAnsweredOrDeadline(): void
    {
        $fixture = $this->startAnswerRace(2);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);

        $this->assertDomainFailure(
            fn () => $fixture['engine']->resolveRaceQuestion($fixture['room']['uuid']),
            'Belum semua tim menjawab'
        );
        $this->assertSame('QUESTION_ACTIVE', (new GameRoundQuestionModel())->find($fixture['question']['id'])['state']);
    }

    public function testResolveRaceQuestionTimeBonusUsesPersistedResponseMsNotWallClock(): void
    {
        $fixture = $this->startAnswerRace(2);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $startedAtEpochMs = (int) $fixture['question']['started_at_epoch_ms'];
        $deadlineEpochMs = (int) $fixture['question']['deadline_epoch_ms'];
        $this->setQuestionWindow($fixture['question']['id'], $startedAtEpochMs, $deadlineEpochMs);

        $engine = $this->engineAt([$startedAtEpochMs + 100, $deadlineEpochMs - 1, $deadlineEpochMs + 5000]);
        $engine->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $engine->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][1]['public_uuid'], (int) $correct['id']);

        $answers = (new GameRoundAnswerModel())->where('round_question_id', $fixture['question']['id'])->findAll();
        $byTeam = [];
        foreach ($answers as $answer) {
            $byTeam[$answer['team_id']] = $answer;
        }
        $fastAnswer = $byTeam[$fixture['teams'][0]['id']];
        $slowAnswer = $byTeam[$fixture['teams'][1]['id']];

        $this->assertGreaterThan($slowAnswer['score_breakdown_json']['time_bonus'], $fastAnswer['score_breakdown_json']['time_bonus']);
        $this->assertSame(0, $slowAnswer['score_breakdown_json']['time_bonus']);
    }

    public function testResolveRaceQuestionAppliesBoostAfterLandedPositionWithoutAddingScore(): void
    {
        $fixture = $this->startAnswerRace(2);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $startedAtEpochMs = (int) $fixture['question']['started_at_epoch_ms'];
        $engine = $this->engineAt([$startedAtEpochMs + 50, $startedAtEpochMs + 500, $startedAtEpochMs + 501]);

        $engine->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $engine->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][1]['public_uuid'], (int) $correct['id']);

        $teamA = (new GameTeamModel())->find($fixture['teams'][0]['id']);
        $teamB = (new GameTeamModel())->find($fixture['teams'][1]['id']);
        $answers = (new GameRoundAnswerModel())->where('round_question_id', $fixture['question']['id'])->findAll();
        $byTeam = [];
        foreach ($answers as $answer) {
            $byTeam[$answer['team_id']] = $answer;
        }

        // Team A is fastest+correct from position 1: base(+1) + fastest(+2) lands on tile 4 (Boost, +2).
        $this->assertSame(6, $teamA['position']);
        // Team B is correct-but-slower from position 1: base(+1) only, no tile.
        $this->assertSame(2, $teamB['position']);

        $boostedAnswer = $byTeam[$fixture['teams'][0]['id']];
        $breakdown = $boostedAnswer['score_breakdown_json'];
        $this->assertSame(
            $breakdown['answer'] + $breakdown['time_bonus'] + $breakdown['streak_bonus'],
            $boostedAnswer['score_delta'],
            'Boost tidak boleh menambah skor di luar jawaban/time/streak bonus.'
        );
    }

    public function testResolveRaceQuestionOilSpillLocksFastestBonusForNextQuestionOnly(): void
    {
        $fixture = $this->startAnswerRace(1);
        // Single team is trivially "fastest": base(+1) + fastest(+2) = 3 steps, landing exactly on tile 8 (Oil Spill).
        (new GameTeamModel())->update($fixture['teams'][0]['id'], ['position' => 5]);
        $correct = $this->correctOptionForQuestion($fixture['question']);

        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);

        $team = (new GameTeamModel())->find($fixture['teams'][0]['id']);
        $effects = json_decode((string) $team['active_effects_json'], true);
        $this->assertSame(8, $team['position']);
        $this->assertTrue($effects['oil_spill_lock']);
    }

    public function testResolveRaceQuestionFinishesRoomImmediatelyAndInterruptsRoundEvenOnLastAllocatedQuestion(): void
    {
        $fixture = $this->startAnswerRace(1);
        // Simulate this being the round's last allocated question.
        (new GameRoundModel())->update($fixture['round']['id'], ['question_target_count' => 1]);
        (new GameTeamModel())->update($fixture['teams'][0]['id'], ['position' => 23]);
        $correct = $this->correctOptionForQuestion($fixture['question']);

        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);

        $storedRoom = (new GameRoomModel())->where('public_uuid', $fixture['room']['uuid'])->first();
        $round = (new GameRoundModel())->find($fixture['round']['id']);
        $question = (new GameRoundQuestionModel())->find($fixture['question']['id']);
        $nextQuestionCount = (new GameRoundQuestionModel())->where('round_id', $fixture['round']['id'])->where('question_number', 2)->countAllResults();

        $this->assertSame('FINISHED', $storedRoom['status']);
        $this->assertSame('ROUND_INTERRUPTED', $round['state']);
        $this->assertNull($round['round_winner_team_ids_json']);
        $this->assertSame('QUESTION_RESOLVED', $question['state']);
        $this->assertSame([$fixture['teams'][0]['id']], $question['finisher_team_ids_json']);
        $this->assertSame(0, $nextQuestionCount);
        $this->assertSame(0, (new ScoreTransactionModel())->where('room_id', $storedRoom['id'])->where('type', 'RACE_ROUND_WINNER')->countAllResults());

        $finishedEvent = $this->db->table('game_events')->where('room_id', $storedRoom['id'])->where('type', 'game.finished')->get()->getRowArray();
        $payload = json_decode((string) $finishedEvent['payload_json'], true)['payload'];
        $this->assertSame('TRACK_FINISH', $payload['finish_reason']);
        $this->assertSame([$fixture['teams'][0]['public_uuid']], $payload['winner_team_uuids']);
        $this->assertSame($fixture['teams'][0]['public_uuid'], $payload['winner_team_uuid']);
        $this->assertSame(1, $this->db->table('game_events')->where('room_id', $storedRoom['id'])->where('type', 'race.round_interrupted')->countAllResults());
    }

    public function testResolveRaceQuestionSimultaneousFinishExactTieProducesCoWinners(): void
    {
        $fixture = $this->startAnswerRace(2, ['track_length' => 24, 'scoring' => ['time_bonus' => false, 'streak_bonus' => false]]);
        (new GameTeamModel())->update($fixture['teams'][0]['id'], ['position' => 21]);
        (new GameTeamModel())->update($fixture['teams'][1]['id'], ['position' => 21]);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $startedAtEpochMs = (int) $fixture['question']['started_at_epoch_ms'];
        $engine = $this->engineAt([$startedAtEpochMs + 200, $startedAtEpochMs + 200, $startedAtEpochMs + 201]);

        $engine->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $engine->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][1]['public_uuid'], (int) $correct['id']);

        $storedRoom = (new GameRoomModel())->where('public_uuid', $fixture['room']['uuid'])->first();
        $this->assertSame('FINISHED', $storedRoom['status']);
        $finishedEvent = $this->db->table('game_events')->where('room_id', $storedRoom['id'])->where('type', 'game.finished')->get()->getRowArray();
        $payload = json_decode((string) $finishedEvent['payload_json'], true)['payload'];
        $winnerUuids = $payload['winner_team_uuids'];
        sort($winnerUuids);
        $expected = [$fixture['teams'][0]['public_uuid'], $fixture['teams'][1]['public_uuid']];
        sort($expected);
        $this->assertSame($expected, $winnerUuids);
    }

    public function testResolveRaceQuestionClaimIsAtomicAndSecondCallIsRejected(): void
    {
        $fixture = $this->startAnswerRace(1);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);

        $team = (new GameTeamModel())->find($fixture['teams'][0]['id']);
        $this->assertSame(6, $team['position']);

        $this->assertDomainFailure(
            fn () => $fixture['engine']->resolveRaceQuestion($fixture['room']['uuid'], true),
            'Tidak ada pertanyaan'
        );

        $teamAfter = (new GameTeamModel())->find($fixture['teams'][0]['id']);
        $this->assertSame($team['position'], $teamAfter['position']);
        $this->assertSame($team['score'], $teamAfter['score']);
        $this->assertSame(1, $this->db->table('game_events')->where('room_id', (new GameRoomModel())->where('public_uuid', $fixture['room']['uuid'])->first()['id'])->where('type', 'race.question_resolved')->countAllResults());
    }

    public function testAdvanceKeepsResolvedQuestionVisibleUntilRevealEnds(): void
    {
        $fixture = $this->startAnswerRace(2);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][1]['public_uuid'], (int) $correct['id']);

        $resolved = (new GameRoundQuestionModel())->find($fixture['question']['id']);
        $this->assertSame('QUESTION_RESOLVED', $resolved['state']);

        $fixture['engine']->snapshot($fixture['room']['uuid']);

        $stillResolved = (new GameRoundQuestionModel())->find($fixture['question']['id']);
        $this->assertSame('QUESTION_RESOLVED', $stillResolved['state']);
        $this->assertSame(0, (new GameRoundQuestionModel())->where('round_id', $fixture['round']['id'])->where('question_number', 2)->countAllResults());
    }

    public function testAdvanceCreatesExactlyOneNextQuestionWhenRoundStillHasQuota(): void
    {
        $fixture = $this->startAnswerRace(2);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][1]['public_uuid'], (int) $correct['id']);
        $this->forceQuestionRevealElapsed($fixture['question']['id']);

        $fixture['engine']->snapshot($fixture['room']['uuid']);
        $fixture['engine']->snapshot($fixture['room']['uuid']);

        $closed = (new GameRoundQuestionModel())->find($fixture['question']['id']);
        $nextQuestions = (new GameRoundQuestionModel())->where('round_id', $fixture['round']['id'])->where('question_number', 2)->findAll();

        $this->assertSame('QUESTION_CLOSED', $closed['state']);
        $this->assertCount(1, $nextQuestions);
        $this->assertSame('QUESTION_ACTIVE', $nextQuestions[0]['state']);
    }

    public function testAdvanceCompletesRoundInsteadOfCreatingNextQuestionWhenQuotaMet(): void
    {
        $fixture = $this->startAnswerRace(2);
        // Simulate this being the round's last allocated question (target already met after this resolve).
        (new GameRoundModel())->update($fixture['round']['id'], ['question_target_count' => 1]);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][1]['public_uuid'], (int) $correct['id']);
        $this->forceQuestionRevealElapsed($fixture['question']['id']);

        $fixture['engine']->snapshot($fixture['room']['uuid']);

        $round = (new GameRoundModel())->find($fixture['round']['id']);
        $nextQuestionCount = (new GameRoundQuestionModel())->where('round_id', $fixture['round']['id'])->where('question_number', 2)->countAllResults();

        $this->assertSame('ROUND_COMPLETED', $round['state']);
        $this->assertSame(0, $nextQuestionCount);
        $this->assertNotNull($round['round_winner_team_ids_json']);
    }

    public function testCompleteRaceRoundAwardsPrizeOnRoundScoreBeforePrizeWithExactCoWinnersAndNoMovement(): void
    {
        $fixture = $this->startAnswerRace(2, ['scoring' => ['time_bonus' => false, 'streak_bonus' => false]]);
        (new GameRoundModel())->update($fixture['round']['id'], ['question_target_count' => 1]);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $startedAtEpochMs = (int) $fixture['question']['started_at_epoch_ms'];
        $engine = $this->engineAt([$startedAtEpochMs + 300, $startedAtEpochMs + 300, $startedAtEpochMs + 301]);

        $engine->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $engine->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][1]['public_uuid'], (int) $correct['id']);
        $this->forceQuestionRevealElapsed($fixture['question']['id']);

        $beforeA = (new GameTeamModel())->find($fixture['teams'][0]['id']);
        $beforeB = (new GameTeamModel())->find($fixture['teams'][1]['id']);

        $fixture['engine']->snapshot($fixture['room']['uuid']);

        $round = (new GameRoundModel())->find($fixture['round']['id']);
        $afterA = (new GameTeamModel())->find($fixture['teams'][0]['id']);
        $afterB = (new GameTeamModel())->find($fixture['teams'][1]['id']);

        $winnerIds = $round['round_winner_team_ids_json'];
        sort($winnerIds);
        $expectedWinnerIds = [(int) $fixture['teams'][0]['id'], (int) $fixture['teams'][1]['id']];
        sort($expectedWinnerIds);

        $this->assertSame($expectedWinnerIds, $winnerIds);
        $this->assertSame($beforeA['score'] + 100, $afterA['score']);
        $this->assertSame($beforeB['score'] + 100, $afterB['score']);
        $this->assertSame($beforeA['position'], $afterA['position']);
        $this->assertSame($beforeB['position'], $afterB['position']);
        $this->assertSame(1, (new ScoreTransactionModel())
            ->where('room_id', $fixture['stored_room']['id'])
            ->where('team_id', $fixture['teams'][0]['id'])
            ->where('type', 'RACE_ROUND_WINNER')
            ->countAllResults());
    }

    public function testAdvanceClosesCheckpointAndStartsExactlyOneNextRoundAfterReveal(): void
    {
        $fixture = $this->startAnswerRace(1);
        (new GameRoundModel())->update($fixture['round']['id'], ['question_target_count' => 1]);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $this->forceQuestionRevealElapsed($fixture['question']['id']);
        $fixture['engine']->snapshot($fixture['room']['uuid']);

        $round = (new GameRoundModel())->find($fixture['round']['id']);
        $this->assertSame('ROUND_COMPLETED', $round['state']);

        $this->forceRoundRevealElapsed($fixture['round']['id']);
        $fixture['engine']->snapshot($fixture['room']['uuid']);
        $fixture['engine']->snapshot($fixture['room']['uuid']);

        $closedRound = (new GameRoundModel())->find($fixture['round']['id']);
        $nextRounds = (new GameRoundModel())->where('room_id', $fixture['stored_room']['id'])->where('round_number', 2)->findAll();

        $this->assertSame('ROUND_CLOSED', $closedRound['state']);
        $this->assertCount(1, $nextRounds);
        $this->assertSame('ROUND_ACTIVE', $nextRounds[0]['state']);
        $this->assertSame(15, $nextRounds[0]['question_target_count']);
        $firstQuestionOfRound2 = (new GameRoundQuestionModel())->where('round_id', $nextRounds[0]['id'])->where('question_number', 1)->first();
        $this->assertNotNull($firstQuestionOfRound2);
        $this->assertSame('QUESTION_ACTIVE', $firstQuestionOfRound2['state']);
    }

    public function testAdvanceFinishesWithQuestionLimitRankingWhenAllocationExhaustedWithoutFinisher(): void
    {
        $fixture = $this->startAnswerRace(2);
        (new GameTeamModel())->update($fixture['teams'][0]['id'], ['position' => 10, 'score' => 50]);
        (new GameTeamModel())->update($fixture['teams'][1]['id'], ['position' => 15, 'score' => 20]);
        // Simulate round 3 (last of the default [15,15,20] allocation) having just completed its checkpoint reveal.
        (new GameRoundModel())->update($fixture['round']['id'], [
            'round_number' => 3,
            'question_target_count' => 1,
            'question_resolved_count' => 1,
            'state' => 'ROUND_COMPLETED',
            'reveal_until_epoch_ms' => 0,
        ]);

        $fixture['engine']->snapshot($fixture['room']['uuid']);

        $storedRoom = (new GameRoomModel())->where('public_uuid', $fixture['room']['uuid'])->first();
        $this->assertSame('FINISHED', $storedRoom['status']);
        $finishedEvent = $this->db->table('game_events')->where('room_id', $storedRoom['id'])->where('type', 'game.finished')->get()->getRowArray();
        $payload = json_decode((string) $finishedEvent['payload_json'], true)['payload'];
        $this->assertSame('QUESTION_LIMIT', $payload['finish_reason']);
        $this->assertSame([$fixture['teams'][1]['public_uuid']], $payload['winner_team_uuids']);
    }

    public function testAdvanceDoesNotRunAfterRoomFinished(): void
    {
        $fixture = $this->startAnswerRace(1);
        (new GameTeamModel())->update($fixture['teams'][0]['id'], ['position' => 23]);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $this->forceQuestionRevealElapsed($fixture['question']['id']);

        $storedRoom = (new GameRoomModel())->where('public_uuid', $fixture['room']['uuid'])->first();
        $this->assertSame('FINISHED', $storedRoom['status']);

        $fixture['engine']->snapshot($fixture['room']['uuid']);

        $question = (new GameRoundQuestionModel())->find($fixture['question']['id']);
        $round = (new GameRoundModel())->find($fixture['round']['id']);
        $this->assertSame('QUESTION_RESOLVED', $question['state']);
        $this->assertSame('ROUND_INTERRUPTED', $round['state']);
        $this->assertSame(0, (new GameRoundModel())->where('room_id', $storedRoom['id'])->where('round_number', 2)->countAllResults());
    }

    public function testSnapshotResolvedQuestionExposesMovementForOwnAndAllTeams(): void
    {
        $fixture = $this->startAnswerRace(2);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][1]['public_uuid'], (int) $correct['id']);

        $snapshot = $fixture['engine']->snapshot($fixture['room']['uuid']);
        $currentQuestion = $snapshot['current_round']['current_question'];

        $this->assertSame('QUESTION_RESOLVED', $currentQuestion['state']);
        $this->assertCount(2, $currentQuestion['movement']);
        $movementTeamUuids = array_column($currentQuestion['movement'], 'team_uuid');
        sort($movementTeamUuids);
        $expected = [$fixture['teams'][0]['public_uuid'], $fixture['teams'][1]['public_uuid']];
        sort($expected);
        $this->assertSame($expected, $movementTeamUuids);
        $this->assertNotNull($currentQuestion['reveal_until_epoch_ms']);
    }

    public function testSnapshotResolvingQuestionExposesNoPartialResult(): void
    {
        $fixture = $this->startAnswerRace(2);
        (new GameRoundQuestionModel())->update($fixture['question']['id'], ['state' => 'QUESTION_RESOLVING']);

        $snapshot = $fixture['engine']->snapshot($fixture['room']['uuid']);
        $currentQuestion = $snapshot['current_round']['current_question'];

        $this->assertSame('QUESTION_RESOLVING', $currentQuestion['state']);
        $this->assertArrayNotHasKey('movement', $currentQuestion);
        $this->assertArrayNotHasKey('fastest_team_uuids', $currentQuestion);
        $this->assertArrayNotHasKey('finisher_team_uuids', $currentQuestion);
        foreach ($currentQuestion['answers'] as $answer) {
            $this->assertSame(['team_uuid', 'answered'], array_keys($answer));
        }
    }

    public function testSnapshotLastResolvedQuestionSurvivesNextQuestionCreation(): void
    {
        $fixture = $this->startAnswerRace(2);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][1]['public_uuid'], (int) $correct['id']);
        $resolvedQuestionUuid = $fixture['question']['public_uuid'];
        $this->forceQuestionRevealElapsed($fixture['question']['id']);
        $fixture['engine']->snapshot($fixture['room']['uuid']);

        $snapshot = $fixture['engine']->snapshot($fixture['room']['uuid']);

        $this->assertSame('QUESTION_ACTIVE', $snapshot['current_round']['current_question']['state']);
        $this->assertSame(2, $snapshot['current_round']['current_question']['question_number']);
        $this->assertNotNull($snapshot['last_resolved_question']);
        $this->assertSame($resolvedQuestionUuid, $snapshot['last_resolved_question']['uuid']);
        $this->assertSame('QUESTION_CLOSED', $snapshot['last_resolved_question']['state']);
    }

    public function testSnapshotLastCompletedRoundSurvivesNextRoundCreation(): void
    {
        $fixture = $this->startAnswerRace(1);
        (new GameRoundModel())->update($fixture['round']['id'], ['question_target_count' => 1]);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);
        $this->forceQuestionRevealElapsed($fixture['question']['id']);
        $fixture['engine']->snapshot($fixture['room']['uuid']);
        $completedRoundUuid = $fixture['round']['public_uuid'];

        $this->forceRoundRevealElapsed($fixture['round']['id']);
        $fixture['engine']->snapshot($fixture['room']['uuid']);

        $snapshot = $fixture['engine']->snapshot($fixture['room']['uuid']);

        $this->assertSame(2, $snapshot['current_round']['round_number']);
        $this->assertNotNull($snapshot['last_completed_round']);
        $this->assertSame($completedRoundUuid, $snapshot['last_completed_round']['uuid']);
        $this->assertSame('ROUND_CLOSED', $snapshot['last_completed_round']['state']);
    }

    public function testSnapshotFinishedRoomExposesFinishReasonAndWinnersAndKeepsExistingKeysIntact(): void
    {
        $fixture = $this->startAnswerRace(1);
        (new GameTeamModel())->update($fixture['teams'][0]['id'], ['position' => 23]);
        $correct = $this->correctOptionForQuestion($fixture['question']);
        $fixture['engine']->raceQuestionAnswer($fixture['room']['uuid'], $fixture['teams'][0]['public_uuid'], (int) $correct['id']);

        $snapshot = $fixture['engine']->snapshot($fixture['room']['uuid']);

        $this->assertSame('FINISHED', $snapshot['room']['status']);
        $finishEvent = null;
        foreach ($snapshot['events'] as $event) {
            if ($event['event'] === 'game.finished') {
                $finishEvent = $event;
            }
        }
        $this->assertNotNull($finishEvent);
        $this->assertSame('TRACK_FINISH', $finishEvent['payload']['finish_reason']);
        $this->assertSame([$fixture['teams'][0]['public_uuid']], $finishEvent['payload']['winner_team_uuids']);

        $this->assertNull($snapshot['current_turn']);
        $this->assertArrayHasKey('mode_state', $snapshot);
        $this->assertArrayHasKey('teams', $snapshot);
        $this->assertArrayHasKey('leaderboard', $snapshot);
    }

    private function forceQuestionRevealElapsed(int $questionId): void
    {
        (new GameRoundQuestionModel())->update($questionId, ['reveal_until_epoch_ms' => 0]);
    }

    private function forceRoundRevealElapsed(int $roundId): void
    {
        (new GameRoundModel())->update($roundId, ['reveal_until_epoch_ms' => 0]);
    }

    public function testRoundPersistenceWritesAndCastsAllFields(): void
    {
        $roomModel = new GameRoomModel();
        $roomId = $roomModel->insert([
            'public_uuid' => '00000000-0000-4000-8000-000000000001',
            'teacher_id' => 1,
            'board_template_id' => 1,
            'pin' => '910001',
            'title' => 'Quiz Race Persistence Test',
            'race_question_limit' => 50,
            'race_round_question_counts_json' => [15, 15, 20],
            'race_round_winner_bonus_points' => 100,
        ], true);
        $storedRoom = $roomModel->find($roomId);

        $this->assertSame(50, $storedRoom['race_question_limit']);
        $this->assertSame([15, 15, 20], $storedRoom['race_round_question_counts_json']);
        $this->assertSame(100, $storedRoom['race_round_winner_bonus_points']);

        $roundModel = new GameRoundModel();
        $roundId = $roundModel->insert([
            'public_uuid' => '10000000-0000-4000-8000-000000000001',
            'room_id' => $roomId,
            'round_number' => 1,
            'state' => 'ROUND_COMPLETED',
            'question_target_count' => 15,
            'question_resolved_count' => 15,
            'difficulty_schedule_json' => ['EASY', 'MEDIUM', 'HARD'],
            'round_winner_team_ids_json' => [1, 2],
            'round_score_summary_json' => ['1' => ['score' => 500]],
            'started_at' => '2026-09-11 08:00:00',
            'completed_at' => '2026-09-11 08:15:00',
            'reveal_until' => '2026-09-11 08:15:05',
            'reveal_until_epoch_ms' => 1789114505000,
            'paused_remaining_ms' => 2500,
        ], true);
        $round = $roundModel->find($roundId);

        $this->assertSame(1, $round['round_number']);
        $this->assertSame(['EASY', 'MEDIUM', 'HARD'], $round['difficulty_schedule_json']);
        $this->assertSame([1, 2], $round['round_winner_team_ids_json']);
        $this->assertSame(['1' => ['score' => 500]], $round['round_score_summary_json']);
        $this->assertSame(1789114505000, $round['reveal_until_epoch_ms']);

        $questionModel = new GameRoundQuestionModel();
        $questionId = $questionModel->insert([
            'public_uuid' => '20000000-0000-4000-8000-000000000001',
            'round_id' => $roundId,
            'question_number' => 1,
            'question_id' => 1,
            'difficulty' => 'EASY',
            'state' => 'QUESTION_RESOLVED',
            'answer_count' => 2,
            'started_at' => '2026-09-11 08:00:00',
            'started_at_epoch_ms' => 1789113600000,
            'deadline_at' => '2026-09-11 08:00:30',
            'deadline_epoch_ms' => 1789113630000,
            'paused_remaining_ms' => 1000,
            'resolved_at' => '2026-09-11 08:00:20',
            'reveal_until' => '2026-09-11 08:00:23',
            'reveal_until_epoch_ms' => 1789113623000,
            'fastest_team_ids_json' => [1],
            'finisher_team_ids_json' => [],
            'movement_summary_json' => ['1' => ['steps' => 3]],
        ], true);
        $question = $questionModel->find($questionId);

        $this->assertSame(2, $question['answer_count']);
        $this->assertSame(1789113600000, $question['started_at_epoch_ms']);
        $this->assertSame([1], $question['fastest_team_ids_json']);
        $this->assertSame([], $question['finisher_team_ids_json']);
        $this->assertSame(['1' => ['steps' => 3]], $question['movement_summary_json']);

        $answerModel = new GameRoundAnswerModel();
        $answerId = $answerModel->insert([
            'public_uuid' => '30000000-0000-4000-8000-000000000001',
            'round_question_id' => $questionId,
            'team_id' => 1,
            'question_id' => 1,
            'option_id' => 1,
            'answer_text' => 'Jawaban A',
            'is_correct' => true,
            'outcome' => 'CORRECT',
            'answered_at' => '2026-09-11 08:00:05',
            'answered_at_epoch_ms' => 1789113605000,
            'response_ms' => 5000,
            'score_delta' => 141,
            'score_breakdown_json' => ['base' => 100, 'speed' => 41],
        ], true);
        $answer = $answerModel->find($answerId);

        $this->assertTrue($answer['is_correct']);
        $this->assertSame(5000, $answer['response_ms']);
        $this->assertSame(141, $answer['score_delta']);
        $this->assertSame(['base' => 100, 'speed' => 41], $answer['score_breakdown_json']);
    }

    public function testRoundDefaultsAreApplied(): void
    {
        $id = (new GameRoundModel())->insert([
            'public_uuid' => '10000000-0000-4000-8000-000000000002',
            'room_id' => 1,
            'round_number' => 1,
            'question_target_count' => 15,
            'difficulty_schedule_json' => ['EASY'],
        ], true);
        $round = (new GameRoundModel())->find($id);

        $this->assertSame('ROUND_ACTIVE', $round['state']);
        $this->assertSame(0, $round['question_resolved_count']);
    }

    public function testQuestionDefaultsAreApplied(): void
    {
        $id = (new GameRoundQuestionModel())->insert([
            'public_uuid' => '20000000-0000-4000-8000-000000000002',
            'round_id' => 1,
            'question_number' => 1,
            'question_id' => 1,
            'difficulty' => 'EASY',
            'started_at' => '2026-09-11 08:00:00',
            'started_at_epoch_ms' => 1789113600000,
            'deadline_at' => '2026-09-11 08:00:30',
            'deadline_epoch_ms' => 1789113630000,
        ], true);
        $question = (new GameRoundQuestionModel())->find($id);

        $this->assertSame('QUESTION_ACTIVE', $question['state']);
        $this->assertSame(0, $question['answer_count']);
    }

    public function testAnswerDefaultsAreApplied(): void
    {
        $id = (new GameRoundAnswerModel())->insert([
            'public_uuid' => '30000000-0000-4000-8000-000000000002',
            'round_question_id' => 1,
            'team_id' => 1,
            'question_id' => 1,
            'outcome' => 'TIMEOUT',
        ], true);
        $answer = (new GameRoundAnswerModel())->find($id);

        $this->assertFalse($answer['is_correct']);
        $this->assertSame(0, $answer['score_delta']);
    }

    public function testDuplicateRoomRoundNumberIsRejected(): void
    {
        $model = new GameRoundModel();
        $model->insert($this->roundData('10000000-0000-4000-8000-000000000010'));

        $this->expectException(DatabaseException::class);
        $model->insert($this->roundData('10000000-0000-4000-8000-000000000011'));
    }

    public function testDuplicateRoundQuestionNumberIsRejected(): void
    {
        $model = new GameRoundQuestionModel();
        $model->insert($this->questionData('20000000-0000-4000-8000-000000000010'));

        $this->expectException(DatabaseException::class);
        $model->insert($this->questionData('20000000-0000-4000-8000-000000000011'));
    }

    public function testDuplicateQuestionTeamAnswerIsRejected(): void
    {
        $model = new GameRoundAnswerModel();
        $model->insert($this->answerData('30000000-0000-4000-8000-000000000010'));

        $this->expectException(DatabaseException::class);
        $model->insert($this->answerData('30000000-0000-4000-8000-000000000011'));
    }

    /** @return array<string, mixed> */
    private function roundData(string $uuid): array
    {
        return [
            'public_uuid' => $uuid,
            'room_id' => 1,
            'round_number' => 1,
            'question_target_count' => 15,
            'difficulty_schedule_json' => ['EASY'],
        ];
    }

    /** @return array<string, mixed> */
    private function questionData(string $uuid): array
    {
        return [
            'public_uuid' => $uuid,
            'round_id' => 1,
            'question_number' => 1,
            'question_id' => 1,
            'difficulty' => 'EASY',
            'started_at' => '2026-09-11 08:00:00',
            'started_at_epoch_ms' => 1789113600000,
            'deadline_at' => '2026-09-11 08:00:30',
            'deadline_epoch_ms' => 1789113630000,
        ];
    }

    /** @return array<string, mixed> */
    private function answerData(string $uuid): array
    {
        return [
            'public_uuid' => $uuid,
            'round_question_id' => 1,
            'team_id' => 1,
            'question_id' => 1,
            'outcome' => 'TIMEOUT',
        ];
    }

    private function seedTopic(string $name): int
    {
        return (int) (new QuestionTopicModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => 1,
            'name' => $name,
        ], true);
    }

    private function seedQuestion(int $topicId, string $stem, string $difficulty): int
    {
        return (int) (new QuestionModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => 1,
            'topic_id' => $topicId,
            'source_type' => 'MASTER',
            'question_type' => 'MULTIPLE_CHOICE',
            'stem' => $stem,
            'difficulty' => $difficulty,
            'status' => 'PUBLISHED',
            'points' => 100,
            'time_limit_seconds' => 30,
        ], true);
    }

    /** @return array<string, mixed> */
    private function startAnswerRace(int $teamCount, array $roomOptions = []): array
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Quiz Race Answer Test ' . Uuid::v4(), [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
        ] + $roomOptions)['room'];
        $teams = [];
        for ($index = 1; $index <= $teamCount; $index++) {
            $teams[] = $engine->joinByPin($room['pin'], 'Tim Jawab ' . $index)['team'];
        }
        $engine->start($room['uuid']);
        $storedRoom = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        $round = (new GameRoundModel())->where('room_id', $storedRoom['id'])->where('state', 'ROUND_ACTIVE')->first();
        $question = (new GameRoundQuestionModel())->where('round_id', $round['id'])->where('state', 'QUESTION_ACTIVE')->first();

        return compact('engine', 'room', 'teams', 'round', 'question') + [
            'stored_room' => $storedRoom,
        ];
    }

    private function optionForQuestion(array $question): array
    {
        return (new QuestionOptionModel())
            ->where('question_id', $question['question_id'])
            ->orderBy('id', 'ASC')
            ->first();
    }

    private function correctOptionForQuestion(array $question): array
    {
        return (new QuestionOptionModel())
            ->where('question_id', $question['question_id'])
            ->where('is_correct', 1)
            ->first();
    }

    private function wrongOptionForQuestion(array $question): array
    {
        return (new QuestionOptionModel())
            ->where('question_id', $question['question_id'])
            ->where('is_correct', 0)
            ->first();
    }

    private function setQuestionWindow(int $questionId, int $startedAtEpochMs, int $deadlineEpochMs): void
    {
        (new GameRoundQuestionModel())->update($questionId, [
            'started_at' => date('Y-m-d H:i:s', intdiv($startedAtEpochMs, 1000)),
            'started_at_epoch_ms' => $startedAtEpochMs,
            'deadline_at' => date('Y-m-d H:i:s', intdiv($deadlineEpochMs, 1000)),
            'deadline_epoch_ms' => $deadlineEpochMs,
        ]);
    }

    /** @param list<int> $timestamps */
    private function engineAt(array $timestamps): GameEngine
    {
        return new class($timestamps) extends GameEngine {
            /** @param list<int> $timestamps */
            public function __construct(private array $timestamps)
            {
                parent::__construct();
            }

            protected function currentEpochMs(): int
            {
                if ($this->timestamps === []) {
                    throw new RuntimeException('Deterministic epoch-ms clock exhausted.');
                }

                return (int) array_shift($this->timestamps);
            }
        };
    }

    private function snapshotAnswerFlag(array $snapshot, string $teamUuid): bool
    {
        foreach ($snapshot['current_round']['current_question']['answers'] as $answer) {
            if ($answer['team_uuid'] === $teamUuid) {
                return (bool) $answer['answered'];
            }
        }

        throw new RuntimeException('Team answer flag not found in snapshot.');
    }

    private function assertDomainFailure(callable $action, string $messageContains): void
    {
        $error = null;
        try {
            $action();
        } catch (\Throwable $caught) {
            $error = $caught;
        }

        $this->assertNotNull($error, 'Expected domain failure was not thrown.');
        $this->assertTrue(
            $error instanceof \DomainException || $error instanceof \CodeIgniter\Exceptions\PageNotFoundException,
            'Expected a domain or not-found failure, got ' . $error::class
        );
        $this->assertStringContainsString($messageContains, $error->getMessage());
    }
}
