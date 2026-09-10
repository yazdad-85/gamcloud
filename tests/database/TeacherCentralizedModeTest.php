<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Models\GameRoomModel;
use App\Models\GameTeamModel;
use App\Models\TeacherModel;
use App\Services\Game\GameEngine;
use App\Services\Game\Uuid;
use App\Services\Security\TeamSessionService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class TeacherCentralizedModeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace = ['App', 'CodeIgniter\Shield', 'CodeIgniter\Settings'];
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

    public function testRoomOwnerActingAsTeacherBypassesTeamTokenInCentralizedRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Auth Bypass Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Otorisasi')['team'];

        $this->actingAsTeacherOwner(1);

        $asserted = (new TeamSessionService())->assertTeamSession($room['uuid'], $team['public_uuid']);
        $this->assertSame($team['public_uuid'], $asserted['public_uuid']);
    }

    public function testRoomOwnerBypassIsInactiveForTeamDeviceRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Auth Bypass Inactive Test', [
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Device')['team'];

        $this->actingAsTeacherOwner(1);

        $this->expectException(DomainException::class);
        (new TeamSessionService())->assertTeamSession($room['uuid'], $team['public_uuid']);
    }

    public function testNonexistentTeamUuidWithNoSessionStillReportsInvalidSessionForTeamDeviceRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Nonexistent Team Test', [
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Session tim tidak valid. Silakan join ulang dengan PIN.');
        (new TeamSessionService())->assertTeamSession($room['uuid'], Uuid::v4());
    }

    public function testNonOwnerTeacherCannotBypassCentralizedRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Auth Bypass Foreign Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Punya Guru Lain')['team'];

        $otherTeacherId = (new TeacherModel())->insert([
            'public_uuid' => Uuid::v4(),
            'name' => 'Guru Lain',
            'email' => 'guru-lain-' . bin2hex(random_bytes(4)) . '@example.test',
            'role' => 'teacher',
        ], true);
        $this->actingAsTeacherOwner($otherTeacherId);

        $this->expectException(DomainException::class);
        (new TeamSessionService())->assertTeamSession($room['uuid'], $team['public_uuid']);
    }

    public function testOwnerCanAddTeamManuallyToCentralizedRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Manual Roster Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ])['room'];
        $this->actingAsTeacherOwner(1);

        $result = $engine->addTeamByOwner($room['uuid'], 'Tim Rajawali');

        $this->assertSame('Tim Rajawali', $result['team']['name']);
        $this->assertSame(1, (new GameTeamModel())->where('room_id', $result['room']['id'])->countAllResults());
    }

    public function testAddTeamByOwnerRejectsTeamDeviceRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Manual Roster Rejected Test', [
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];
        $this->actingAsTeacherOwner(1);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('join PIN');
        $engine->addTeamByOwner($room['uuid'], 'Tim Tidak Boleh');
    }

    public function testAddTeamByOwnerRejectsAfterRoomStarted(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Manual Roster Locked Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $engine->addTeamByOwner($room['uuid'], 'Tim Satu');
        $engine->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);

        $this->expectException(DomainException::class);
        $engine->addTeamByOwner($room['uuid'], 'Tim Telat');
    }

    public function testOwnerCanRemoveTeamWhileInLobby(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Manual Roster Remove Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = $engine->addTeamByOwner($room['uuid'], 'Tim Dihapus')['team'];

        $engine->removeTeamByOwner($room['uuid'], $team['public_uuid']);

        $roomRow = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        $this->assertSame(0, (new GameTeamModel())->where('room_id', $roomRow['id'])->countAllResults());
    }

    private function actingAsTeacherOwner(int $teacherId): void
    {
        $users = model(UserModel::class);
        $suffix = bin2hex(random_bytes(4));
        $user = new User([
            'username' => 'test-teacher-' . $teacherId . '-' . $suffix,
            'email' => 'test-teacher-' . $teacherId . '-' . $suffix . '@example.test',
            'active' => true,
        ]);
        $user->setPassword('TestPassword123!');
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup('teacher');

        (new TeacherModel())->update($teacherId, ['auth_user_id' => $user->id]);

        $this->actingAs($user);
    }
}
