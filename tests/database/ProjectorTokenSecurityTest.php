<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Services\Game\GameEngine;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ProjectorTokenSecurityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed      = DemoGameSeeder::class;

    public function testCreateRoomPersistsProjectorTokenAndHash(): void
    {
        $engine  = new GameEngine();
        $created = $engine->createRoom(1, 'Token Room', ['turn_order_mode' => 'join_order']);
        $room    = $created['room'];

        $this->assertArrayHasKey('pin', $room);
        $this->assertNotSame('', (string) $room['pin']);
        $this->assertArrayHasKey('projector_token', $room);
        $this->assertSame(64, strlen((string) $room['projector_token']));

        $row = (new \App\Models\GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        $this->assertNotNull($row);
        $this->assertSame($room['projector_token'], $row['projector_token']);
        $this->assertTrue(hash_equals((string) $row['projector_token_hash'], hash('sha256', (string) $row['projector_token'])));
    }

    public function testSnapshotWithoutTokenOmitsPin(): void
    {
        $engine  = new GameEngine();
        $created = $engine->createRoom(1, 'Public Snapshot', ['turn_order_mode' => 'join_order']);
        $uuid    = $created['room']['uuid'];

        $snapshot = $engine->snapshot($uuid);

        $this->assertArrayNotHasKey('pin', $snapshot['room']);
        $this->assertArrayNotHasKey('projector_token', $snapshot['room']);
    }

    public function testSnapshotWithValidTokenIncludesPin(): void
    {
        $engine  = new GameEngine();
        $created = $engine->createRoom(1, 'Valid Token Snapshot', ['turn_order_mode' => 'join_order']);
        $token   = (string) $created['room']['projector_token'];
        $uuid    = $created['room']['uuid'];
        $pin     = (string) $created['room']['pin'];

        $snapshot = $engine->snapshot($uuid, $token);

        $this->assertSame($pin, $snapshot['room']['pin'] ?? null);
        $this->assertArrayNotHasKey('projector_token', $snapshot['room']);
    }

    public function testSnapshotWithInvalidTokenOmitsPin(): void
    {
        $engine  = new GameEngine();
        $created = $engine->createRoom(1, 'Invalid Token Snapshot', ['turn_order_mode' => 'join_order']);
        $uuid    = $created['room']['uuid'];

        $snapshot = $engine->snapshot($uuid, str_repeat('a', 64));

        $this->assertArrayNotHasKey('pin', $snapshot['room']);
    }

    public function testOwnerSnapshotIncludesPinAndProjectorToken(): void
    {
        $engine  = new GameEngine();
        $created = $engine->createRoom(1, 'Owner Snapshot', ['turn_order_mode' => 'join_order']);
        $uuid    = $created['room']['uuid'];

        $snapshot = $engine->snapshot($uuid, null, true);

        $this->assertSame($created['room']['pin'], $snapshot['room']['pin']);
        $this->assertSame($created['room']['projector_token'], $snapshot['room']['projector_token']);
    }
}
