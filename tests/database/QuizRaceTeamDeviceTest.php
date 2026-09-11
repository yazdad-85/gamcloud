<?php

use App\Models\GameRoomModel;
use App\Models\GameRoundAnswerModel;
use App\Models\GameRoundModel;
use App\Models\GameRoundQuestionModel;
use App\Models\GameTeamModel;
use App\Models\GameTurnModel;
use App\Models\QuestionModel;
use App\Models\QuestionTopicModel;
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
}
