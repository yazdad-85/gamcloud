<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Models\GameRoomModel;
use App\Services\Game\GameEngine;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class TeacherCentralizedModeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed = DemoGameSeeder::class;

    public function testCreateRoomDefaultsToTeamDeviceParticipationMode(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Default Participation Test');

        $this->assertSame('TEAM_DEVICE', $snapshot['room']['participation_mode']);
        $row = (new GameRoomModel())->where('public_uuid', $snapshot['room']['uuid'])->first();
        $this->assertSame('TEAM_DEVICE', $row['participation_mode']);
    }

    public function testCreateRoomPersistsTeacherCentralizedParticipationMode(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Centralized Participation Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ]);

        $this->assertSame('TEACHER_CENTRALIZED', $snapshot['room']['participation_mode']);
        $row = (new GameRoomModel())->where('public_uuid', $snapshot['room']['uuid'])->first();
        $this->assertSame('TEACHER_CENTRALIZED', $row['participation_mode']);
    }

    public function testCreateRoomRejectsUnknownParticipationModeValue(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Invalid Participation Test', [
            'participation_mode' => 'SOMETHING_ELSE',
        ]);

        $this->assertSame('TEAM_DEVICE', $snapshot['room']['participation_mode']);
    }
}
