<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Models\GameAnswerModel;
use App\Models\GameEventModel;
use App\Models\GameRoomModel;
use App\Models\GameRoundModel;
use App\Models\GameRoundQuestionModel;
use App\Models\GameTeamModel;
use App\Models\GameTurnModel;
use App\Models\QuestionModel;
use App\Models\QuestionOptionModel;
use App\Services\Game\GameEngine;
use App\Services\Game\Uuid;
use App\Services\Report\GameReportService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class GameReportServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed = DemoGameSeeder::class;

    public function testRoomReportResolvesWinnerFromGameFinishedEvent(): void
    {
        $engine = new GameEngine();
        $created = $engine->createRoom(1, 'Report Winner', [
            'turn_order_mode' => 'join_order',
            'scoring' => ['correct' => 0, 'wrong' => 0, 'time_bonus_max' => 0, 'streak_bonus' => 0, 'near_finish_bonus' => 0, 'timeout_penalty' => false],
        ]);
        $room = $created['room'];
        $a = $engine->joinByPin($room['pin'], 'TIM A')['team'];
        $b = $engine->joinByPin($room['pin'], 'TIM B')['team'];
        $engine->start($room['uuid']);

        $roomRow = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        (new GameTeamModel())->update($a['id'], ['position' => 50, 'score' => 100]);
        (new GameTeamModel())->update($b['id'], ['position' => 20, 'score' => 900]);
        (new GameRoomModel())->update($roomRow['id'], [
            'status' => 'FINISHED',
            'max_position' => 50,
        ]);
        $roomRow = (new GameRoomModel())->find($roomRow['id']);

        $payload = [
            'event_id' => Uuid::v4(),
            'event' => 'game.finished',
            'room_uuid' => $room['uuid'],
            'state_version' => (int) $roomRow['state_version'],
            'occurred_at' => date(DATE_ATOM),
            'payload' => ['winner_team_uuid' => $a['public_uuid']],
        ];
        (new GameEventModel())->insert([
            'public_uuid' => $payload['event_id'],
            'room_id' => $roomRow['id'],
            'type' => 'game.finished',
            'state_version' => $payload['state_version'],
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $report = (new GameReportService())->roomReport($roomRow);

        $this->assertNotNull($report['winner']);
        $this->assertSame($a['public_uuid'], $report['winner']['public_uuid']);
        $this->assertSame('TIM A', $report['winner']['name']);
        $this->assertTrue($report['teams'][0]['is_board_winner']);
        $this->assertSame('TIM A', $report['teams'][0]['name']);
        $this->assertSame('TIM B', $report['teams'][1]['name']);
    }

    public function testRoomReportSortsByPositionThenScoreWithoutWinner(): void
    {
        $engine = new GameEngine();
        $created = $engine->createRoom(1, 'Report Sort', [
            'turn_order_mode' => 'join_order',
        ]);
        $room = $created['room'];
        $a = $engine->joinByPin($room['pin'], 'TIM A')['team'];
        $b = $engine->joinByPin($room['pin'], 'TIM B')['team'];
        $roomRow = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        (new GameTeamModel())->update($a['id'], ['position' => 10, 'score' => 500]);
        (new GameTeamModel())->update($b['id'], ['position' => 30, 'score' => 100]);
        $roomRow = (new GameRoomModel())->find($roomRow['id']);

        $report = (new GameReportService())->roomReport($roomRow, [
            'soal_page' => 1,
            'jawab_page' => 1,
        ]);

        $this->assertNull($report['winner']);
        $this->assertSame('TIM B', $report['teams'][0]['name']);
        $this->assertSame('TIM A', $report['teams'][1]['name']);
    }

    public function testPaginationSlicesQuestionStatsAndAnswers(): void
    {
        $service = new GameReportService();
        $items = [];
        for ($i = 1; $i <= 25; $i++) {
            $items[] = ['id' => $i];
        }

        $page1 = $service->paginate($items, 1, 10);
        $page3 = $service->paginate($items, 3, 10);
        $overflow = $service->paginate($items, 99, 10);

        $this->assertSame(10, count($page1['items']));
        $this->assertSame(1, $page1['page']);
        $this->assertSame(3, $page1['total_pages']);
        $this->assertSame(5, count($page3['items']));
        $this->assertSame(3, $overflow['page']);
        $this->assertSame(5, count($overflow['items']));
    }

    public function testRoomReportBuildsChartDatasetsFromAnswers(): void
    {
        $engine = new GameEngine();
        $created = $engine->createRoom(1, 'Report Charts', [
            'turn_order_mode' => 'join_order',
        ]);
        $room = $created['room'];
        $a = $engine->joinByPin($room['pin'], 'TIM A')['team'];
        $b = $engine->joinByPin($room['pin'], 'TIM B')['team'];
        $engine->start($room['uuid']);

        $roomRow = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        $turn = (new GameTurnModel())
            ->where('room_id', $roomRow['id'])
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertNotNull($turn);

        $questions = (new QuestionModel())
            ->where('owner_teacher_id', 1)
            ->where('status', 'PUBLISHED')
            ->orderBy('id', 'ASC')
            ->findAll(2);
        $this->assertCount(2, $questions);

        $q1Id = (int) $questions[0]['id'];
        $q2Id = (int) $questions[1]['id'];
        $turnId = (int) $turn['id'];

        // TIM A: 2 correct + 1 wrong; TIM B: 1 correct + 1 wrong
        $this->seedAnswer($turnId, (int) $a['id'], $q1Id, true);
        $this->seedAnswer($turnId, (int) $a['id'], $q2Id, true);
        $this->seedAnswer($turnId, (int) $a['id'], $q1Id, false);
        $this->seedAnswer($turnId, (int) $b['id'], $q1Id, false);
        $this->seedAnswer($turnId, (int) $b['id'], $q2Id, true);

        (new GameTeamModel())->update($a['id'], ['position' => 40, 'score' => 200]);
        (new GameTeamModel())->update($b['id'], ['position' => 10, 'score' => 50]);
        $roomRow = (new GameRoomModel())->find($roomRow['id']);

        $report = (new GameReportService())->roomReport($roomRow);

        $this->assertArrayHasKey('charts', $report);
        $this->assertArrayHasKey('accuracy', $report['charts']);
        $this->assertArrayHasKey('team_results', $report['charts']);

        $accuracy = $report['charts']['accuracy'];
        $this->assertCount(2, $accuracy['labels']);
        $this->assertCount(2, $accuracy['values']);
        $this->assertContains((string) $questions[0]['stem'], $accuracy['labels']);
        $this->assertContains((string) $questions[1]['stem'], $accuracy['labels']);

        // q1: 3 answers / 1 correct => 33%; q2: 2 answers / 2 correct => 100%
        $byStem = array_combine($accuracy['labels'], $accuracy['values']);
        $this->assertSame(33, $byStem[(string) $questions[0]['stem']]);
        $this->assertSame(100, $byStem[(string) $questions[1]['stem']]);

        $teamResults = $report['charts']['team_results'];
        $this->assertSame(['TIM A', 'TIM B'], $teamResults['labels']);
        $this->assertSame([2, 1], $teamResults['correct']);
        $this->assertSame([1, 1], $teamResults['wrong']);
    }

    public function testRoomReportWinnerFallsBackToFinishedMaxPosition(): void
    {
        $engine = new GameEngine();
        $created = $engine->createRoom(1, 'Report Fallback', [
            'turn_order_mode' => 'join_order',
        ]);
        $room = $created['room'];
        $a = $engine->joinByPin($room['pin'], 'TIM A')['team'];
        $b = $engine->joinByPin($room['pin'], 'TIM B')['team'];
        $engine->start($room['uuid']);

        $roomRow = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        (new GameTeamModel())->update($a['id'], ['position' => 50, 'score' => 10]);
        (new GameTeamModel())->update($b['id'], ['position' => 20, 'score' => 999]);
        (new GameRoomModel())->update($roomRow['id'], [
            'status' => 'FINISHED',
            'max_position' => 50,
        ]);
        $roomRow = (new GameRoomModel())->find($roomRow['id']);

        $finishedEvents = (new GameEventModel())
            ->where('room_id', $roomRow['id'])
            ->where('type', 'game.finished')
            ->countAllResults();
        $this->assertSame(0, $finishedEvents);

        $report = (new GameReportService())->roomReport($roomRow);

        $this->assertNotNull($report['winner']);
        $this->assertSame($a['public_uuid'], $report['winner']['public_uuid']);
        $this->assertSame('TIM A', $report['winner']['name']);
        $this->assertTrue($report['teams'][0]['is_board_winner']);
    }

    public function testQuizRaceReportNormalizesAnswersRoundsAndAllWinners(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Report Quiz Race', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
            'scoring' => ['time_bonus' => false, 'streak_bonus' => false],
        ])['room'];
        $firstTeam = $engine->joinByPin($room['pin'], 'TIM RACE A')['team'];
        $secondTeam = $engine->joinByPin($room['pin'], 'TIM RACE B')['team'];
        $engine->start($room['uuid']);

        $roomRow = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        $round = (new GameRoundModel())->where('room_id', $roomRow['id'])->first();
        (new GameRoundModel())->update($round['id'], ['question_target_count' => 1]);
        $roundQuestion = (new GameRoundQuestionModel())->where('round_id', $round['id'])->first();
        $correctOption = (new QuestionOptionModel())
            ->where('question_id', $roundQuestion['question_id'])
            ->where('is_correct', 1)
            ->first();
        $wrongOption = (new QuestionOptionModel())
            ->where('question_id', $roundQuestion['question_id'])
            ->where('is_correct', 0)
            ->first();
        $engine->raceQuestionAnswer($room['uuid'], $firstTeam['public_uuid'], (int) $correctOption['id']);
        $engine->raceQuestionAnswer($room['uuid'], $secondTeam['public_uuid'], (int) $wrongOption['id']);
        (new GameRoundQuestionModel())->update($roundQuestion['id'], ['reveal_until_epoch_ms' => 0]);
        $engine->snapshot($room['uuid']);

        $roomRow = (new GameRoomModel())->find($roomRow['id']);
        $finishPayload = [
            'event_id' => Uuid::v4(),
            'event' => 'game.finished',
            'room_uuid' => $room['uuid'],
            'state_version' => (int) $roomRow['state_version'],
            'occurred_at' => date(DATE_ATOM),
            'payload' => [
                'finish_reason' => 'QUESTION_LIMIT',
                'winner_team_uuids' => [$firstTeam['public_uuid'], $secondTeam['public_uuid']],
                'winner_team_uuid' => $firstTeam['public_uuid'],
            ],
        ];
        (new GameEventModel())->insert([
            'public_uuid' => $finishPayload['event_id'],
            'room_id' => $roomRow['id'],
            'type' => 'game.finished',
            'state_version' => $finishPayload['state_version'],
            'payload_json' => json_encode($finishPayload, JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $report = (new GameReportService())->roomReport($roomRow);

        $this->assertCount(2, $report['answers']);
        $this->assertSame(['CORRECT', 'WRONG'], array_column($report['answers'], 'outcome'));
        foreach ($report['answers'] as $answer) {
            $this->assertSame('RACE_ROUND', $answer['source']);
            $this->assertSame(1, $answer['round_number']);
            $this->assertSame(1, $answer['question_number']);
            $this->assertArrayHasKey('response_ms', $answer);
            $this->assertArrayHasKey('score_delta', $answer);
            $this->assertIsArray($answer['score_breakdown']);
        }
        $this->assertCount(1, $report['rounds']);
        $this->assertSame('ROUND_COMPLETED', $report['rounds'][0]['state']);
        $this->assertSame([$firstTeam['public_uuid']], $report['rounds'][0]['winner_team_uuids']);
        $this->assertSame(100, $report['rounds'][0]['prize_points']);
        $this->assertSame('QUESTION_LIMIT', $report['finish_reason']);
        $this->assertSame([$firstTeam['public_uuid'], $secondTeam['public_uuid']], $report['winner_uuids']);
        $this->assertCount(2, $report['winners']);
        $this->assertSame($firstTeam['public_uuid'], $report['winner']['public_uuid']);
    }

    public function testRoomReportPaginationMetadataAndSlices(): void
    {
        $engine = new GameEngine();
        $created = $engine->createRoom(1, 'Report Pages', [
            'turn_order_mode' => 'join_order',
        ]);
        $room = $created['room'];
        $a = $engine->joinByPin($room['pin'], 'TIM A')['team'];
        $b = $engine->joinByPin($room['pin'], 'TIM B')['team'];
        $engine->start($room['uuid']);

        $roomRow = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        $turn = (new GameTurnModel())
            ->where('room_id', $roomRow['id'])
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertNotNull($turn);

        $questions = (new QuestionModel())
            ->where('owner_teacher_id', 1)
            ->where('status', 'PUBLISHED')
            ->orderBy('id', 'ASC')
            ->findAll();
        $this->assertGreaterThanOrEqual(2, count($questions));

        $turnId = (int) $turn['id'];
        // 16 answers => answersPage per_page 15 yields 2 pages
        for ($i = 0; $i < 16; $i++) {
            $q = $questions[$i % count($questions)];
            $teamId = $i % 2 === 0 ? (int) $a['id'] : (int) $b['id'];
            $this->seedAnswer($turnId, $teamId, (int) $q['id'], $i % 3 !== 0);
        }

        $roomRow = (new GameRoomModel())->find($roomRow['id']);
        $report = (new GameReportService())->roomReport($roomRow, [
            'soal_page' => 1,
            'jawab_page' => 2,
        ]);

        $soal = $report['questionStatsPage'];
        $this->assertSame(1, $soal['page']);
        $this->assertSame(10, $soal['per_page']);
        $this->assertArrayHasKey('total_pages', $soal);
        $this->assertIsArray($soal['items']);
        $this->assertSame(count($report['questionStats']), $soal['total']);
        $this->assertLessThanOrEqual(10, count($soal['items']));

        $jawab = $report['answersPage'];
        $this->assertSame(2, $jawab['page']);
        $this->assertSame(15, $jawab['per_page']);
        $this->assertSame(2, $jawab['total_pages']);
        $this->assertSame(16, $jawab['total']);
        $this->assertCount(1, $jawab['items']);
        $this->assertIsArray($jawab['items']);
    }

    private function seedAnswer(int $turnId, int $teamId, int $questionId, bool $correct): void
    {
        (new GameAnswerModel())->insert([
            'turn_id' => $turnId,
            'team_id' => $teamId,
            'question_id' => $questionId,
            'option_id' => null,
            'answer_text' => $correct ? 'ok' : 'no',
            'is_correct' => $correct ? 1 : 0,
            'answered_at' => date('Y-m-d H:i:s'),
            'response_ms' => 1,
        ]);
    }
}
