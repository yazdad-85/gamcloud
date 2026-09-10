<?php

use App\Models\GameTeamModel;
use App\Models\GameTurnModel;
use App\Models\QuestionOptionModel;
use App\Services\Game\GameEngine;
use App\Services\Game\Modes\GameModeCatalog;
use App\Services\Game\Uuid;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class QuizRaceModeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace = ['App', 'CodeIgniter\Shield', 'CodeIgniter\Settings'];
    protected $seed = App\Database\Seeds\DemoGameSeeder::class;

    public function testQuizRaceIsPlayableAndUsesRaceRenderer(): void
    {
        $catalog = new GameModeCatalog();

        $this->assertContains('QUIZ_RACE', $catalog->playableKeys());
        $mode = $catalog->resolve('QUIZ_RACE');
        $this->assertSame('quiz_race_track', $mode->renderer());
        $this->assertTrue($mode->isPlayable());
    }

    public function testCreateRoomWithQuizRaceUsesRaceBoardAndAppliesTrackLength(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Quiz Race Track Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'track_length' => 18,
            'lap_count' => 3,
        ]);

        $this->assertSame('QUIZ_RACE', $snapshot['room']['game_mode']);
        $this->assertSame(18, $snapshot['room']['max_position']);
        $this->assertSame(3, $snapshot['room']['lap_count']);
        $this->assertSame('clamp_finish', $snapshot['room']['finish_rule']);
        $this->assertSame([], $snapshot['board']['ladders']);
        $this->assertSame([], $snapshot['board']['snakes']);
    }

    public function testCreateRoomRejectsQuizRaceWithTeamDeviceParticipation(): void
    {
        $engine = new GameEngine();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tanpa Device');
        $engine->createRoom(1, 'Quiz Race Reject Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
        ]);
    }

    public function testSelectDifficultyTierRecordsChosenTierAndDrawsQuestion(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Select Tier Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'track_length' => 18,
            'lap_count' => 3,
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Cepat')['team'];
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Lain');
        $engine->start($room['uuid']);

        $snapshot = $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'HARD');

        $this->assertSame('QUESTION_PENDING_START', $snapshot['current_turn']['state']);
        $this->assertNotNull($snapshot['current_turn']['question']);
        $this->assertSame('HARD', $snapshot['current_turn']['question']['difficulty']);
        $turn = $this->latestTurn($room['uuid']);
        $this->assertSame('HARD', $turn['selected_tier']);
    }

    public function testSelectDifficultyTierRejectsInvalidTier(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Select Tier Invalid Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);

        $this->expectException(DomainException::class);
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'IMPOSSIBLE');
    }

    public function testSelectDifficultyTierRejectsForNonQuizRaceRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Non Race Reject Test')['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Biasa')['team'];
        $engine->start($room['uuid']);

        $this->expectException(DomainException::class);
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'EASY');
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

        (new App\Models\TeacherModel())->update($teacherId, ['auth_user_id' => $user->id]);

        $this->actingAs($user);
    }

    private function roomId(string $roomUuid): int
    {
        $room = (new App\Models\GameRoomModel())->where('public_uuid', $roomUuid)->first();

        return (int) $room['id'];
    }

    private function latestTurn(string $roomUuid): array
    {
        return (new GameTurnModel())->where('room_id', $this->roomId($roomUuid))->orderBy('id', 'DESC')->first();
    }

    private function correctOptionId(int $questionId): int
    {
        $option = (new QuestionOptionModel())->where('question_id', $questionId)->where('is_correct', 1)->first();

        return (int) $option['id'];
    }

    private function teamInSnapshot(array $snapshot, string $teamUuid): array
    {
        foreach ($snapshot['teams'] as $team) {
            if ($team['uuid'] === $teamUuid) {
                return $team;
            }
        }

        throw new \DomainException('Team not found in snapshot.');
    }
}
