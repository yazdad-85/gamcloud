<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Services\Game\GameEngine;
use App\Services\Security\TeamSessionService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Game;

/**
 * @internal
 */
final class TeamSessionTtlTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed      = DemoGameSeeder::class;

    public function testExpiredTeamSessionIsRejected(): void
    {
        $engine  = new GameEngine();
        $created = $engine->createRoom(1, 'TTL Room', ['turn_order_mode' => 'join_order']);
        $room    = $created['room'];
        $join    = $engine->joinByPin($room['pin'], 'TIM TTL');
        $team    = $join['team'];

        $ttlMinutes = (int) config(Game::class)->teamSessionTtlMinutes;
        session()->set('team_' . $room['uuid'], [
            'team_uuid' => $team['public_uuid'],
            'token'     => $join['token'],
            'issued_at' => time() - (($ttlMinutes + 1) * 60),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('kedaluwarsa');

        (new TeamSessionService())->assertTeamSession($room['uuid'], $team['public_uuid']);
    }

    public function testFreshTeamSessionIsAccepted(): void
    {
        $engine  = new GameEngine();
        $created = $engine->createRoom(1, 'TTL Fresh', ['turn_order_mode' => 'join_order']);
        $room    = $created['room'];
        $join    = $engine->joinByPin($room['pin'], 'TIM OK');
        $team    = $join['team'];

        session()->set('team_' . $room['uuid'], [
            'team_uuid' => $team['public_uuid'],
            'token'     => $join['token'],
            'issued_at' => time(),
        ]);

        $asserted = (new TeamSessionService())->assertTeamSession($room['uuid'], $team['public_uuid']);
        $this->assertSame($team['public_uuid'], $asserted['public_uuid']);
    }
}
