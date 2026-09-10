<?php

use App\Models\GameRoomModel;
use App\Models\GameRoundAnswerModel;
use App\Models\GameRoundModel;
use App\Models\GameRoundQuestionModel;
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
}
