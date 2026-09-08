<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Models\GameEventModel;
use App\Models\GameTeamModel;
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

        $roomRow = (new \App\Models\GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        (new GameTeamModel())->update($a['id'], ['position' => 50, 'score' => 100]);
        (new GameTeamModel())->update($b['id'], ['position' => 20, 'score' => 900]);
        (new \App\Models\GameRoomModel())->update($roomRow['id'], [
            'status' => 'FINISHED',
            'max_position' => 50,
        ]);
        $roomRow = (new \App\Models\GameRoomModel())->find($roomRow['id']);

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
        $roomRow = (new \App\Models\GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        (new GameTeamModel())->update($a['id'], ['position' => 10, 'score' => 500]);
        (new GameTeamModel())->update($b['id'], ['position' => 30, 'score' => 100]);
        $roomRow = (new \App\Models\GameRoomModel())->find($roomRow['id']);

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
}
