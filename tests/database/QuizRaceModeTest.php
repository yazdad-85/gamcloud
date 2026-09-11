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
        $this->assertSame('Quiz Race Track Test', $snapshot['room']['display_title']);
        $this->assertSame('Quiz Race', $snapshot['room']['mode_label']);
        $this->assertSame(18, $snapshot['room']['max_position']);
        $this->assertSame(3, $snapshot['room']['lap_count']);
        $this->assertSame('clamp_finish', $snapshot['room']['finish_rule']);
        $this->assertSame([], $snapshot['board']['ladders']);
        $this->assertSame([], $snapshot['board']['snakes']);
    }

    public function testQuizRaceSnapshotNormalizesOldDefaultUlarTanggaTitle(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Game Ular Tangga 10/09 23:27', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ]);

        $this->assertSame('Game Ular Tangga 10/09 23:27', $snapshot['room']['title']);
        $this->assertSame('Quiz Race 10/09 23:27', $snapshot['room']['display_title']);
    }

    public function testCreateRoomWithQuizRaceTeamDevicePersistsDefaultRoundConfiguration(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Quiz Race Team Device Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
            'track_length' => 18,
            'lap_count' => 9,
            'race_question_limit' => 999,
        ]);

        $storedRoom = (new App\Models\GameRoomModel())->where('public_uuid', $snapshot['room']['uuid'])->first();

        $this->assertSame('TEAM_DEVICE', $snapshot['room']['participation_mode']);
        $this->assertSame(18, $snapshot['room']['max_position']);
        $this->assertSame(3, $snapshot['room']['lap_count']);
        $this->assertSame('clamp_finish', $snapshot['room']['finish_rule']);
        $this->assertSame([], $snapshot['board']['ladders']);
        $this->assertSame([], $snapshot['board']['snakes']);
        $this->assertSame(['race_question_answer'], $snapshot['mode_state']['actions']);
        $this->assertSame('multi_question_round', $snapshot['mode_state']['round_model']);
        $this->assertSame(50, $storedRoom['race_question_limit']);
        $this->assertSame([15, 15, 20], $storedRoom['race_round_question_counts_json']);
        $this->assertSame(100, $storedRoom['race_round_winner_bonus_points']);
    }

    public function testCreateRoomWithQuizRaceTeamDevicePersistsCustomRoundConfiguration(): void
    {
        $snapshot = (new GameEngine())->createRoom(1, 'Quiz Race Custom Rounds', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
            'race_round_question_counts' => [6, '7', 8, 9],
            'race_round_winner_bonus_points' => 250,
        ]);
        $storedRoom = (new App\Models\GameRoomModel())->where('public_uuid', $snapshot['room']['uuid'])->first();

        $this->assertSame(4, $snapshot['room']['lap_count']);
        $this->assertSame(30, $storedRoom['race_question_limit']);
        $this->assertSame([6, 7, 8, 9], $storedRoom['race_round_question_counts_json']);
        $this->assertSame(250, $storedRoom['race_round_winner_bonus_points']);
    }

    /**
     * @dataProvider invalidTeamDeviceRoundAllocationProvider
     */
    public function testCreateRoomRejectsInvalidQuizRaceTeamDeviceRoundAllocation(mixed $allocation): void
    {
        $this->expectException(DomainException::class);

        (new GameEngine())->createRoom(1, 'Quiz Race Invalid Rounds', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
            'race_round_question_counts' => $allocation,
        ]);
    }

    public static function invalidTeamDeviceRoundAllocationProvider(): array
    {
        return [
            'not a list' => [['round_1' => 15]],
            'zero questions' => [[15, 0, 20]],
            'too many rounds' => [[1, 1, 1, 1, 1, 1]],
            'total below minimum' => [[1]],
            'total above maximum' => [[50, 51]],
            'invalid JSON' => ['not-json'],
        ];
    }

    public function testQuizRaceTeamDeviceAllowsPinJoinAndRejectsOwnerRosterAddition(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Quiz Race Join Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];

        $joined = $engine->joinByPin($room['pin'], 'Tim Device');

        $this->assertSame('Tim Device', $joined['team']['name']);
        $this->assertSame($room['uuid'], $joined['room']['public_uuid']);

        $this->actingAsTeacherOwner(1);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('join PIN');
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Owner');
    }

    public function testCentralizedQuizRaceIgnoresTeamDeviceRoundAllocationAndKeepsPhaseElevenState(): void
    {
        $snapshot = (new GameEngine())->createRoom(1, 'Quiz Race Centralized Regression', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'lap_count' => 7,
            'race_round_question_counts' => [0],
            'race_round_winner_bonus_points' => 999,
        ]);
        $storedRoom = (new App\Models\GameRoomModel())->where('public_uuid', $snapshot['room']['uuid'])->first();

        $this->assertSame(7, $snapshot['room']['lap_count']);
        $this->assertSame(['select_tier', 'answer'], $snapshot['mode_state']['actions']);
        $this->assertArrayNotHasKey('round_model', $snapshot['mode_state']);
        $this->assertNull($storedRoom['race_question_limit']);
        $this->assertNull($storedRoom['race_round_question_counts_json']);
        $this->assertNull($storedRoom['race_round_winner_bonus_points']);

        $legacyState = (new GameModeCatalog())->resolve('QUIZ_RACE')->publicState(
            ['max_position' => 24, 'lap_count' => 5],
            ['tile_count' => 24],
            null,
            []
        );
        $this->assertSame(['select_tier', 'answer'], $legacyState['actions']);
        $this->assertArrayNotHasKey('round_model', $legacyState);
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

    public function testAnswerMovesTeamByTierStepsOnCorrectAnswer(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Race Answer Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'track_length' => 18,
            'lap_count' => 3,
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'MEDIUM');
        $engine->startAnswerTimer($room['uuid']);
        $turn = $this->latestTurn($room['uuid']);

        $snapshot = $engine->answer($room['uuid'], $team['public_uuid'], $this->correctOptionId((int) $turn['question_id']));

        // Track has no special tile at position 3 (procedurally generated
        // tiles land on 4, 8, 12, 16), so this is a clean +2 (MEDIUM) move.
        $this->assertSame(3, $this->teamInSnapshot($snapshot, $team['public_uuid'])['position']);
    }

    public function testAnswerWrongTierMovementStaysPutAtZeroSteps(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Race Wrong Answer Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'track_length' => 18,
            'lap_count' => 3,
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'HARD');
        $engine->startAnswerTimer($room['uuid']);
        $turn = $this->latestTurn($room['uuid']);
        $wrongOptionId = (int) (new QuestionOptionModel())
            ->where('question_id', $turn['question_id'])
            ->where('is_correct', 0)
            ->first()['id'];

        $snapshot = $engine->answer($room['uuid'], $team['public_uuid'], $wrongOptionId);

        $this->assertSame(1, $this->teamInSnapshot($snapshot, $team['public_uuid'])['position']);
    }

    public function testAnswerAppliesBoostTileForExtraSteps(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Race Boost Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'track_length' => 18,
            'lap_count' => 3,
        ])['room'];
        (new App\Models\BoardTemplateModel())->update(
            (new App\Models\GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'],
            ['special_tiles_json' => json_encode([['tile' => 3, 'type' => 'BONUS', 'steps' => 2, 'label' => 'Boost']])]
        );
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);
        // Start position 1, MEDIUM (+2) lands exactly on tile 3 (the Boost tile),
        // which then adds its own +2 -> final position 5.
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'MEDIUM');
        $engine->startAnswerTimer($room['uuid']);
        $turn = $this->latestTurn($room['uuid']);

        $snapshot = $engine->answer($room['uuid'], $team['public_uuid'], $this->correctOptionId((int) $turn['question_id']));

        $this->assertSame(5, $this->teamInSnapshot($snapshot, $team['public_uuid'])['position']);
    }

    public function testAnswerAppliesOilSpillLockRestrictingTeamsNextTierChoice(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Race Oil Spill Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'track_length' => 18,
            'lap_count' => 3,
        ])['room'];
        (new App\Models\BoardTemplateModel())->update(
            (new App\Models\GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'],
            ['special_tiles_json' => json_encode([['tile' => 3, 'type' => 'TRAP', 'label' => 'Oil Spill']])]
        );
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        $team2 = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Dua')['team'];
        $engine->start($room['uuid']);

        // Start position 1, MEDIUM (+2) lands exactly on tile 3 (the Oil Spill tile).
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'MEDIUM');
        $engine->startAnswerTimer($room['uuid']);
        $turn = $this->latestTurn($room['uuid']);
        $snapshot = $engine->answer($room['uuid'], $team['public_uuid'], $this->correctOptionId((int) $turn['question_id']));
        $this->assertTrue($this->teamInSnapshot($snapshot, $team['public_uuid'])['active_effects']['oil_spill_lock']);

        // Tim Dua takes a neutral turn so play comes back around to Tim Satu.
        $engine->selectDifficultyTier($room['uuid'], $team2['public_uuid'], 'EASY');
        $engine->startAnswerTimer($room['uuid']);
        $turn2 = $this->latestTurn($room['uuid']);
        $engine->answer($room['uuid'], $team2['public_uuid'], $this->correctOptionId((int) $turn2['question_id']));

        $this->expectException(DomainException::class);
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'HARD');
    }

    public function testAnswerAppliesLapCheckpointBonusStep(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Race Checkpoint Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'track_length' => 18,
            'lap_count' => 3,
        ])['room'];
        // Empty track: no Boost/Oil Spill tiles to interfere with the checkpoint math.
        (new App\Models\BoardTemplateModel())->update(
            (new App\Models\GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'],
            ['special_tiles_json' => '[]']
        );
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);
        (new GameTeamModel())->update($this->teamRowId($team['public_uuid']), ['position' => 5]);

        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'MEDIUM');
        $engine->startAnswerTimer($room['uuid']);
        $turn = $this->latestTurn($room['uuid']);

        $snapshot = $engine->answer($room['uuid'], $team['public_uuid'], $this->correctOptionId((int) $turn['question_id']));

        // From 5, MEDIUM (+2) lands on 7 — track length 18 / 3 laps = lap boundaries
        // at 6 and 12, so 5 -> 7 crosses one boundary and earns +1 checkpoint bonus.
        $this->assertSame(8, $this->teamInSnapshot($snapshot, $team['public_uuid'])['position']);
    }

    private function teamRowId(string $teamUuid): int
    {
        return (int) (new GameTeamModel())->where('public_uuid', $teamUuid)->first()['id'];
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
