<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Models\BoardTemplateModel;
use App\Models\GameAnswerModel;
use App\Models\GameEventModel;
use App\Models\GameRoomModel;
use App\Models\GameTeamModel;
use App\Models\GameTurnModel;
use App\Models\QuestionModel;
use App\Models\QuestionOptionModel;
use App\Models\QuestionTopicModel;
use App\Models\ScoreTransactionModel;
use App\Services\Game\GameEngine;
use App\Services\Game\Uuid;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class GameEngineHardeningTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed = DemoGameSeeder::class;

    public function testJoinOrderStartsWithFirstJoinedTeam(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Join Order Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $first = $engine->joinByPin($room['pin'], 'Tim Pertama')['team'];
        $engine->joinByPin($room['pin'], 'Tim Kedua');

        $snapshot = $engine->start($room['uuid']);

        $this->assertSame($first['public_uuid'], $snapshot['room']['current_team_uuid']);
        $this->assertSame('join_order', $snapshot['room']['turn_order_mode']);
    }

    public function testRoomSnapshotIncludesPlayableGameModeState(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Mode Foundation Test', [
            'game_mode' => 'SNAKES_LADDERS',
        ]);

        $room = (new GameRoomModel())->where('public_uuid', $snapshot['room']['uuid'])->first();

        $this->assertSame('SNAKES_LADDERS', $room['game_mode']);
        $this->assertSame('SNAKES_LADDERS', $snapshot['room']['game_mode']);
        $this->assertSame('SNAKES_LADDERS', $snapshot['mode_state']['key']);
        $this->assertSame('snakes_ladders_board', $snapshot['mode_state']['renderer']);
        $this->assertSame(['roll', 'answer'], $snapshot['mode_state']['actions']);
        $this->assertSame(100, $snapshot['mode_state']['finish_position']);
        $this->assertSame(1, $snapshot['mode_state']['stored']['version']);
    }

    public function testDifficultyZoneSelectsMediumQuestionForMiddleBoard(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Difficulty Zone Medium Test', [
            'turn_order_mode' => 'join_order',
            'question_selection' => ['strategy' => 'difficulty_zone'],
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Zona')['team'];

        $engine->start($room['uuid']);
        (new GameTeamModel())->update($team['id'], ['position' => 40]);
        $snapshot = $engine->roll($room['uuid'], $team['public_uuid']);
        $lastQuestion = $this->lastEvent($this->roomId($room['uuid']), 'question.started');

        $this->assertSame('difficulty_zone', $snapshot['room']['question_selection']['strategy']);
        $this->assertSame('MEDIUM', $snapshot['current_turn']['question']['difficulty']);
        $this->assertSame('MEDIUM', $lastQuestion['payload']['selection']['requested_difficulty']);
        $diceValue = (int) $snapshot['current_turn']['dice_value'];
        $this->assertSame(40 + $diceValue, $lastQuestion['payload']['selection']['based_on_position']);
    }

    public function testDifficultyZoneFallsBackWhenRequestedDifficultyIsEmpty(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Difficulty Zone Fallback Test', [
            'turn_order_mode' => 'join_order',
            'question_selection' => ['strategy' => 'difficulty_zone'],
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Hard Kosong')['team'];

        $engine->start($room['uuid']);
        (new GameTeamModel())->update($team['id'], ['position' => 80]);
        $snapshot = $engine->roll($room['uuid'], $team['public_uuid']);
        $lastQuestion = $this->lastEvent($this->roomId($room['uuid']), 'question.started');

        $this->assertSame('HARD', $lastQuestion['payload']['selection']['requested_difficulty']);
        $this->assertContains($snapshot['current_turn']['question']['difficulty'], ['EASY', 'MEDIUM']);
        $this->assertContains($lastQuestion['payload']['selection']['selected_difficulty'], ['EASY', 'MEDIUM']);
    }

    public function testRoomOnlySelectsQuestionsFromChosenTopics(): void
    {
        $selectedTopic = $this->seedTopic(1, 'Topik Terpilih');
        $otherTopic = $this->seedTopic(1, 'Topik Lain');
        $selectedQuestionId = $this->seedTopicQuestion(1, $selectedTopic['id'], 'Soal khusus topik terpilih', 'EASY');
        $this->seedTopicQuestion(1, $otherTopic['id'], 'Soal yang tidak boleh masuk', 'EASY');

        $engine = new GameEngine();
        $catalogItem = array_values(array_filter(
            $engine->questionTopicCatalog(1),
            static fn (array $topic): bool => $topic['uuid'] === $selectedTopic['public_uuid']
        ))[0];
        $this->assertSame(1, $catalogItem['summary']['total']);
        $this->assertSame(1, $catalogItem['summary']['difficulty']['EASY']);

        $room = $engine->createRoom(1, 'Topic Scope Test', [
            'turn_order_mode' => 'join_order',
            'question_selection' => [
                'strategy' => 'random',
                'topic_uuids' => [$selectedTopic['public_uuid']],
            ],
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Topik')['team'];

        $engine->start($room['uuid']);
        $snapshot = $engine->roll($room['uuid'], $team['public_uuid']);

        $this->assertSame('Soal khusus topik terpilih', $snapshot['current_turn']['question']['stem']);
        $this->assertSame($selectedQuestionId, (int) (new GameTurnModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->orderBy('id', 'DESC')
            ->first()['question_id']);
        $this->assertSame([[
            'uuid' => $selectedTopic['public_uuid'],
            'name' => 'Topik Terpilih',
        ]], $snapshot['room']['question_selection']['topics']);
        $this->assertSame(1, $snapshot['question_bank']['total']);
        $this->assertArrayNotHasKey('topic_ids', $snapshot['room']['question_selection']);
    }

    public function testDifficultyFallbackStaysInsideChosenTopic(): void
    {
        $selectedTopic = $this->seedTopic(1, 'Topik Easy Saja');
        $otherTopic = $this->seedTopic(1, 'Topik Hard Lain');
        $this->seedTopicQuestion(1, $selectedTopic['id'], 'Fallback tetap di topik ini', 'EASY');
        $this->seedTopicQuestion(1, $otherTopic['id'], 'Hard dari topik lain', 'HARD');

        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Topic Difficulty Fallback Test', [
            'turn_order_mode' => 'join_order',
            'question_selection' => [
                'strategy' => 'difficulty_zone',
                'topic_uuids' => [$selectedTopic['public_uuid']],
            ],
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Fallback Topik')['team'];

        $engine->start($room['uuid']);
        (new GameTeamModel())->update($team['id'], ['position' => 80]);
        $snapshot = $engine->roll($room['uuid'], $team['public_uuid']);

        $this->assertSame('HARD', $this->lastEvent($this->roomId($room['uuid']), 'question.started')['payload']['selection']['requested_difficulty']);
        $this->assertSame('Fallback tetap di topik ini', $snapshot['current_turn']['question']['stem']);
        $this->assertSame('EASY', $snapshot['current_turn']['question']['difficulty']);
    }

    public function testRoomRejectsTopicOwnedByAnotherTeacher(): void
    {
        $foreignTopic = $this->seedTopic(999, 'Topik Guru Lain');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Pilihan topik tidak valid');

        (new GameEngine())->createRoom(1, 'Foreign Topic Test', [
            'question_selection' => [
                'topic_uuids' => [$foreignTopic['public_uuid']],
            ],
        ]);
    }

    public function testDifficultyZoneScalesWithNonStandardBoardSize(): void
    {
        $customBoardId = (new BoardTemplateModel())->insert([
            'public_uuid' => Uuid::v4(),
            'name' => 'Papan 60 Kotak Test',
            'tile_count' => 60,
            'ladders_json' => json_encode([]),
            'snakes_json' => json_encode([]),
            'special_tiles_json' => json_encode([]),
            'theme_json' => json_encode(['name' => 'Test 60']),
            'status' => 'ACTIVE',
        ], true);

        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Small Board Test', [
            'board_template_id' => $customBoardId,
            'question_selection' => ['strategy' => 'difficulty_zone'],
        ])['room'];

        $this->assertSame(60, $room['max_position']);
        $this->assertSame([
            ['from' => 1, 'to' => 18, 'difficulty' => 'EASY'],
            ['from' => 19, 'to' => 42, 'difficulty' => 'MEDIUM'],
            ['from' => 43, 'to' => 60, 'difficulty' => 'HARD'],
        ], $room['question_selection']['zones']);
    }

    public function testCreateRoomFallsBackWhenGameModeIsNotPlayableYet(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Future Mode Fallback Test', [
            'game_mode' => 'BOSS_BATTLE',
        ]);

        $this->assertSame('SNAKES_LADDERS', $snapshot['room']['game_mode']);
        $this->assertSame('SNAKES_LADDERS', $snapshot['mode_state']['key']);
    }

    public function testExpiredQuestionCompletesTurnAndMovesToNextTeam(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Timeout Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $first = $engine->joinByPin($room['pin'], 'Tim A')['team'];
        $second = $engine->joinByPin($room['pin'], 'Tim B')['team'];

        $engine->start($room['uuid']);
        $engine->roll($room['uuid'], $first['public_uuid']);

        $turns = new GameTurnModel();
        $turn = $turns->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $turns->update($turn['id'], [
            'question_deadline_at' => date('Y-m-d H:i:s', time() - 5),
        ]);

        $optionId = $this->firstOptionId((int) $turn['question_id']);
        $snapshot = $engine->answer($room['uuid'], $first['public_uuid'], $optionId);

        $expiredTurn = $turns->find($turn['id']);
        $this->assertSame('QUESTION_TIMEOUT', $expiredTurn['state']);
        $this->assertSame($second['public_uuid'], $snapshot['room']['current_team_uuid']);
        $this->assertSame('ROLL_READY', $snapshot['current_turn']['state']);
        $this->assertSame(1, (new GameAnswerModel())->where('turn_id', $turn['id'])->where('option_id', null)->countAllResults());
    }

    public function testExactFinishBouncesWhenDiceExceedsFinish(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Exact Finish Test', [
            'turn_order_mode' => 'join_order',
            'finish_rule' => 'exact_finish',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Finish')['team'];

        $engine->start($room['uuid']);
        (new GameTeamModel())->update($team['id'], ['position' => 98]);
        $engine->roll($room['uuid'], $team['public_uuid']);

        $turns = new GameTurnModel();
        $turn = $turns->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $turns->update($turn['id'], ['dice_value' => 5]);

        $snapshot = $engine->answer($room['uuid'], $team['public_uuid'], $this->correctOptionId((int) $turn['question_id']));
        $updatedTeam = array_values(array_filter(
            $snapshot['teams'],
            static fn (array $item): bool => $item['uuid'] === $team['public_uuid'],
        ))[0];
        $lastEvent = end($snapshot['events']);

        $this->assertSame(97, $updatedTeam['position']);
        $this->assertSame('PLAYING', $snapshot['room']['status']);
        $this->assertSame('exact_finish', $snapshot['room']['finish_rule']);
        $this->assertTrue($lastEvent['payload']['movement']['finish_bounced']);
        $this->assertSame(103, $lastEvent['payload']['movement']['rolled_to']);
    }

    public function testCreateRoomUsesSelectedBoardThemeAndJoinStoresAvatar(): void
    {
        $board = (new BoardTemplateModel())->where('name', 'Space Mission')->first();
        $this->assertNotNull($board);

        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Theme Avatar Test', [
            'board_template_id' => (int) $board['id'],
        ])['room'];

        $rocket = $engine->joinByPin($room['pin'], 'Tim Roket', 'rocket')['snapshot'];
        $this->assertSame('space_mission', $rocket['board']['theme']['key']);

        $rocketTeam = array_values(array_filter(
            $rocket['teams'],
            static fn (array $item): bool => $item['name'] === 'Tim Roket',
        ))[0];
        $this->assertSame('rocket', $rocketTeam['avatar']);

        $fallback = $engine->joinByPin($room['pin'], 'Tim Fallback', 'invalid-avatar')['snapshot'];
        $fallbackTeam = array_values(array_filter(
            $fallback['teams'],
            static fn (array $item): bool => $item['name'] === 'Tim Fallback',
        ))[0];
        $this->assertSame('robot', $fallbackTeam['avatar']);
    }

    public function testBonusTileAddsScoreAndEvent(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Bonus Tile Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Bonus')['team'];

        $snapshot = $this->answerCorrectWithForcedMove($engine, $room, $team, 11, 1);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);
        $lastSpecial = $this->lastEvent($this->roomId($room['uuid']), 'tile.special_triggered');

        $this->assertSame(12, $updatedTeam['position']);
        $this->assertSame(150, $updatedTeam['score']);
        $this->assertSame('BONUS', $lastSpecial['payload']['effect']['type']);
        $this->assertSame(50, $lastSpecial['payload']['effect']['points']);
        $this->assertSame(1, (new ScoreTransactionModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->where('team_id', $team['id'])
            ->where('type', 'SPECIAL_TILE')
            ->where('points', 50)
            ->countAllResults());
    }

    public function testTrapTileMovesTeamBackward(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Trap Tile Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Trap')['team'];

        $snapshot = $this->answerCorrectWithForcedMove($engine, $room, $team, 22, 1);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);
        $lastSpecial = $this->lastEvent($this->roomId($room['uuid']), 'tile.special_triggered');

        $this->assertSame(20, $updatedTeam['position']);
        $this->assertSame(100, $updatedTeam['score']);
        $this->assertSame('TRAP', $lastSpecial['payload']['effect']['type']);
        $this->assertSame(3, $lastSpecial['payload']['effect']['steps']);
    }

    public function testSafeShieldBlocksSnakeOnce(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Safe Shield Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Safe')['team'];
        (new GameTeamModel())->update($team['id'], [
            'active_effects_json' => json_encode(['safe_shield' => 1], JSON_UNESCAPED_SLASHES),
        ]);

        $snapshot = $this->answerCorrectWithForcedMove($engine, $room, $team, 16, 1);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);
        $lastSpecial = $this->lastEvent($this->roomId($room['uuid']), 'tile.special_triggered');

        $this->assertSame(17, $updatedTeam['position']);
        $this->assertSame(0, $updatedTeam['active_effects']['safe_shield']);
        $this->assertSame('SAFE_BLOCK', $lastSpecial['payload']['effect']['type']);
        $this->assertSame(7, $lastSpecial['payload']['effect']['blocked_to']);
    }

    public function testMysteryLandingDefersToChoicePendingState(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Pending Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];

        $snapshot = $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(46, $updatedTeam['position']);
        $this->assertSame(100, $updatedTeam['score']);
        $this->assertSame('MYSTERY_CHOICE_PENDING', $snapshot['current_turn']['state']);
        $this->assertSame($team['public_uuid'], $snapshot['current_turn']['team_uuid']);
    }

    public function testChooseMysteryTargetSelfPreparesHardQuestion(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Choose Self Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $this->seedHardQuestion(1);
        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);

        $snapshot = $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], 'SELF');

        $this->assertSame('MYSTERY_QUESTION_ACTIVE', $snapshot['current_turn']['state']);
        $this->assertSame('HARD', $snapshot['current_turn']['question']['difficulty']);
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.target_chosen');
        $this->assertSame('SELF', $lastEvent['payload']['target']);
    }

    public function testChooseMysteryTargetRejectsSelfAsOpponent(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Choose Invalid Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);

        $this->expectException(\DomainException::class);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], $team['public_uuid']);
    }

    public function testChooseMysteryTargetOpponentValidatesTeamExistsInRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Choose Opponent Invalid Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);

        $this->expectException(\DomainException::class);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], 'not-a-real-team-uuid');
    }

    public function testMysteryChoiceTimeoutSkipsTurnWithoutEffect(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Choice Timeout Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $first = $engine->joinByPin($room['pin'], 'Tim A')['team'];
        $second = $engine->joinByPin($room['pin'], 'Tim B')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $first, 45, 1);

        $turns = new GameTurnModel();
        $turn = $turns->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $turns->update($turn['id'], [
            'question_deadline_at' => date('Y-m-d H:i:s', time() - 5),
        ]);

        $snapshot = $engine->chooseMysteryTarget($room['uuid'], $first['public_uuid'], 'SELF');
        $updatedTeam = $this->teamFromSnapshot($snapshot, $first['public_uuid']);

        $this->assertSame('QUESTION_TIMEOUT', (new GameTurnModel())->find($turn['id'])['state']);
        $this->assertSame($second['public_uuid'], $snapshot['room']['current_team_uuid']);
        $this->assertSame(100, $updatedTeam['score']);
    }

    public function testMysteryRewardSelfOnCorrectAnswer(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Reward Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], 'SELF');

        $turn = (new GameTurnModel())->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $optionId = $this->correctOptionId((int) $turn['question_id']);
        $snapshot = $engine->answerMystery($room['uuid'], $team['public_uuid'], $optionId);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(49, $updatedTeam['position']);
        $this->assertSame(180, $updatedTeam['score']);
        $this->assertSame('TURN_COMPLETED', (new GameTurnModel())->find($turn['id'])['state']);
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.resolved');
        $this->assertSame('REWARD_SELF', $lastEvent['payload']['outcome']);
        $this->assertSame(['from' => 46, 'landed' => 46, 'to' => 49], $lastEvent['payload']['movement']);
    }

    public function testMysteryPunishOpponentOnCorrectAnswer(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Punish Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $opponent = $engine->joinByPin($room['pin'], 'Tim Lawan')['team'];
        (new GameTeamModel())->update($opponent['id'], ['position' => 30, 'score' => 200]);

        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], $opponent['public_uuid']);

        $turn = (new GameTurnModel())->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $optionId = $this->correctOptionId((int) $turn['question_id']);
        $snapshot = $engine->answerMystery($room['uuid'], $team['public_uuid'], $optionId);
        $updatedOpponent = $this->teamFromSnapshot($snapshot, $opponent['public_uuid']);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(26, $updatedOpponent['position']);
        $this->assertSame(140, $updatedOpponent['score']);
        $this->assertSame(46, $updatedTeam['position']);
        $this->assertSame(100, $updatedTeam['score']);
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.resolved');
        $this->assertSame('PUNISH_OPPONENT', $lastEvent['payload']['outcome']);
        $this->assertSame($opponent['public_uuid'], $lastEvent['payload']['affected_team_uuid']);
        $this->assertSame(['from' => 30, 'landed' => 30, 'to' => 26], $lastEvent['payload']['movement']);
    }

    public function testMysteryBoomerangsToSelfOnWrongAnswer(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Boomerang Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $opponent = $engine->joinByPin($room['pin'], 'Tim Lawan')['team'];

        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], $opponent['public_uuid']);

        $turn = (new GameTurnModel())->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $optionId = $this->wrongOptionId((int) $turn['question_id']);
        $snapshot = $engine->answerMystery($room['uuid'], $team['public_uuid'], $optionId);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);
        $updatedOpponent = $this->teamFromSnapshot($snapshot, $opponent['public_uuid']);

        $this->assertSame(42, $updatedTeam['position']);
        $this->assertSame(40, $updatedTeam['score']);
        $this->assertSame(1, $updatedOpponent['position']);
        $this->assertSame(0, $updatedOpponent['score']);
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.resolved');
        $this->assertSame('BOOMERANG_SELF', $lastEvent['payload']['outcome']);
        $this->assertSame(['from' => 46, 'landed' => 46, 'to' => 42], $lastEvent['payload']['movement']);
    }

    public function testMysteryQuestionTimeoutBoomerangsToSelf(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Question Timeout Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];

        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], 'SELF');

        $turns = new GameTurnModel();
        $turn = $turns->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $turns->update($turn['id'], [
            'question_deadline_at' => date('Y-m-d H:i:s', time() - 5),
        ]);

        $optionId = $this->firstOptionId((int) $turn['question_id']);
        $snapshot = $engine->answerMystery($room['uuid'], $team['public_uuid'], $optionId);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(42, $updatedTeam['position']);
        $this->assertSame(40, $updatedTeam['score']);
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.resolved');
        $this->assertSame('BOOMERANG_SELF', $lastEvent['payload']['outcome']);
        $this->assertSame(['from' => 46, 'landed' => 46, 'to' => 42], $lastEvent['payload']['movement']);
    }

    public function testDuelTileIsHiddenAndActsAsNormalTile(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Duel Tile Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Duel')['team'];

        $snapshot = $this->answerCorrectWithForcedMove($engine, $room, $team, 76, 1);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(77, $updatedTeam['position']);
        $this->assertSame(100, $updatedTeam['score']);
        $this->assertSame(0, (new GameEventModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->where('type', 'tile.special_triggered')
            ->countAllResults());
        $this->assertSame([], array_values(array_filter(
            $snapshot['board']['special_tiles'],
            static fn (array $tile): bool => (int) $tile['tile'] === 77,
        )));
    }

    public function testFastCorrectAnswerAddsTimeBonusTransaction(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Time Bonus Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Cepat')['team'];

        $snapshot = $this->answerCorrectWithForcedMove($engine, $room, $team, 1, 1);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(150, $updatedTeam['score']);
        $this->assertSame(1, $updatedTeam['streak_count']);
        $this->assertSame(1, (new ScoreTransactionModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->where('team_id', $team['id'])
            ->where('type', 'TIME_BONUS')
            ->where('points', 50)
            ->countAllResults());
    }

    public function testSecondCorrectAnswerAddsStreakBonus(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Streak Bonus Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Streak')['team'];

        $engine->start($room['uuid']);
        $this->answerCurrentTurnWithForcedMove($engine, $room, $team, 1, 1, true);
        $snapshot = $this->answerCurrentTurnWithForcedMove($engine, $room, $team, 2, 1, true);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(325, $updatedTeam['score']);
        $this->assertSame(2, $updatedTeam['streak_count']);
        $this->assertSame(1, (new ScoreTransactionModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->where('team_id', $team['id'])
            ->where('type', 'STREAK_BONUS')
            ->where('points', 25)
            ->countAllResults());
    }

    public function testNearFinishBonusAddsTensionScore(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Near Finish Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Finish Dekat')['team'];

        $snapshot = $this->answerCorrectWithForcedMove($engine, $room, $team, 84, 1);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(175, $updatedTeam['score']);
        $this->assertSame(1, (new ScoreTransactionModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->where('team_id', $team['id'])
            ->where('type', 'NEAR_FINISH_BONUS')
            ->where('points', 25)
            ->countAllResults());
    }

    public function testWrongPenaltyResetsStreakWhenEnabled(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Wrong Penalty Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => [
                'wrong_penalty' => true,
            ],
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Salah')['team'];

        $engine->start($room['uuid']);
        $this->answerCurrentTurnWithForcedMove($engine, $room, $team, 1, 1, true);
        $snapshot = $this->answerCurrentTurnWithForcedMove($engine, $room, $team, 2, 1, false);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(125, $updatedTeam['score']);
        $this->assertSame(0, $updatedTeam['streak_count']);
        $this->assertSame(1, (new ScoreTransactionModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->where('team_id', $team['id'])
            ->where('type', 'ANSWER_WRONG')
            ->where('points', -25)
            ->countAllResults());
    }

    public function testTimeoutPenaltyResetsStreakWhenEnabled(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Timeout Penalty Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => [
                'timeout_penalty' => true,
            ],
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Timeout')['team'];

        $engine->start($room['uuid']);
        $this->answerCurrentTurnWithForcedMove($engine, $room, $team, 1, 1, true);
        (new GameTeamModel())->update($team['id'], ['position' => 2]);
        $engine->roll($room['uuid'], $team['public_uuid']);

        $turns = new GameTurnModel();
        $turn = $turns->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $turns->update($turn['id'], [
            'question_deadline_at' => date('Y-m-d H:i:s', time() - 5),
        ]);
        $snapshot = $engine->answer($room['uuid'], $team['public_uuid'], $this->firstOptionId((int) $turn['question_id']));
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(125, $updatedTeam['score']);
        $this->assertSame(0, $updatedTeam['streak_count']);
        $this->assertSame(1, (new ScoreTransactionModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->where('team_id', $team['id'])
            ->where('type', 'TIMEOUT_PENALTY')
            ->where('points', -25)
            ->countAllResults());
    }

    public function testPauseAndResumeControlFlow(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Pause Resume Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Pause')['team'];

        $engine->start($room['uuid']);
        $engine->roll($room['uuid'], $team['public_uuid']);
        $paused = $engine->pause($room['uuid']);
        $this->assertSame('PAUSED', $paused['room']['status']);

        $resumed = $engine->resume($room['uuid']);
        $this->assertSame('PLAYING', $resumed['room']['status']);
        $this->assertSame('QUESTION_ACTIVE', $resumed['current_turn']['state']);
        $this->assertGreaterThan(time() * 1000, $resumed['current_turn']['deadline_epoch_ms']);
        $this->assertNotNull($this->lastEvent($this->roomId($room['uuid']), 'teacher.override'));
    }

    public function testPausedGameBlocksTeamRoll(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Pause Blocks Roll Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Pause Roll')['team'];

        $engine->start($room['uuid']);
        $engine->pause($room['uuid']);

        $this->expectException(\DomainException::class);
        $engine->roll($room['uuid'], $team['public_uuid']);
    }

    public function testTeacherCanSkipTurnToNextTeam(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Skip Turn Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $first = $engine->joinByPin($room['pin'], 'Tim Satu')['team'];
        $second = $engine->joinByPin($room['pin'], 'Tim Dua')['team'];

        $engine->start($room['uuid']);
        $snapshot = $engine->skipTurn($room['uuid']);
        $skippedTurn = (new GameTurnModel())->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'ASC')->first();
        $lastSkip = $this->lastEvent($this->roomId($room['uuid']), 'turn.skipped');

        $this->assertSame($second['public_uuid'], $snapshot['room']['current_team_uuid']);
        $this->assertSame('TURN_SKIPPED', $skippedTurn['state']);
        $this->assertSame($first['public_uuid'], $lastSkip['payload']['team_uuid']);
        $this->assertSame($second['public_uuid'], $lastSkip['payload']['next_team_uuid']);
    }

    public function testTeacherCanForceTimeoutActiveQuestion(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Force Timeout Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $first = $engine->joinByPin($room['pin'], 'Tim Aktif')['team'];
        $second = $engine->joinByPin($room['pin'], 'Tim Berikut')['team'];

        $engine->start($room['uuid']);
        $engine->roll($room['uuid'], $first['public_uuid']);
        $snapshot = $engine->forceTimeout($room['uuid']);
        $timeoutTurn = (new GameTurnModel())->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'ASC')->first();

        $this->assertSame($second['public_uuid'], $snapshot['room']['current_team_uuid']);
        $this->assertSame('ROLL_READY', $snapshot['current_turn']['state']);
        $this->assertSame('QUESTION_TIMEOUT', $timeoutTurn['state']);
        $this->assertSame('force_timeout', $this->lastEvent($this->roomId($room['uuid']), 'teacher.override')['payload']['action']);
    }

    public function testFreeActiveRoomQuotaBlocksFourthActiveRoom(): void
    {
        $engine = new GameEngine();
        $engine->createRoom(1, 'Quota 1');
        $engine->createRoom(1, 'Quota 2');
        $engine->createRoom(1, 'Quota 3');

        $this->expectException(\DomainException::class);
        $engine->createRoom(1, 'Quota 4');
    }

    public function testSuperadminCanBypassRoomQuota(): void
    {
        $engine = new GameEngine();
        $engine->createRoom(1, 'Quota Bypass 1', ['skip_quota' => true]);
        $engine->createRoom(1, 'Quota Bypass 2', ['skip_quota' => true]);
        $engine->createRoom(1, 'Quota Bypass 3', ['skip_quota' => true]);
        $snapshot = $engine->createRoom(1, 'Quota Bypass 4', ['skip_quota' => true]);

        $this->assertSame('LOBBY', $snapshot['room']['status']);
    }

    public function testJoinRejectsExpiredRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Expired Join Test')['room'];
        (new GameRoomModel())->update($this->roomId($room['uuid']), [
            'expires_at' => date('Y-m-d H:i:s', time() - 5),
        ]);

        $this->expectException(\DomainException::class);
        $engine->joinByPin($room['pin'], 'Tim Terlambat');
    }

    public function testJoinRejectsLongTeamName(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Long Team Name Test')['room'];

        $this->expectException(\DomainException::class);
        $engine->joinByPin($room['pin'], str_repeat('A', 81));
    }

    public function testRollRejectsExpiredRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Expired Roll Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Expired Roll')['team'];
        $engine->start($room['uuid']);
        (new GameRoomModel())->update($this->roomId($room['uuid']), [
            'expires_at' => date('Y-m-d H:i:s', time() - 5),
        ]);

        $this->expectException(\DomainException::class);
        $engine->roll($room['uuid'], $team['public_uuid']);
    }

    public function testAnswerRejectsExpiredRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Expired Answer Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Expired Answer')['team'];
        $engine->start($room['uuid']);
        $engine->roll($room['uuid'], $team['public_uuid']);
        $turn = (new GameTurnModel())->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        (new GameRoomModel())->update($this->roomId($room['uuid']), [
            'expires_at' => date('Y-m-d H:i:s', time() - 5),
        ]);

        $this->expectException(\DomainException::class);
        $engine->answer($room['uuid'], $team['public_uuid'], $this->correctOptionId((int) $turn['question_id']));
    }

    public function testMaxTeamLimitIsEnforcedByBackend(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Max Team Test')['room'];
        for ($index = 1; $index <= 6; $index++) {
            $engine->joinByPin($room['pin'], 'Tim ' . $index);
        }

        $this->expectException(\DomainException::class);
        $engine->joinByPin($room['pin'], 'Tim 7');
    }

    public function testDeleteLobbyRoomRemovesRoomAndRelatedRows(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Delete Lobby Test')['room'];
        $engine->joinByPin($room['pin'], 'Tim Hapus');
        $roomId = $this->roomId($room['uuid']);

        $this->assertSame(1, (new GameTeamModel())->where('room_id', $roomId)->countAllResults());
        $this->assertGreaterThan(0, (new GameEventModel())->where('room_id', $roomId)->countAllResults());

        $engine->deleteRoom($room['uuid']);

        $this->assertNull((new GameRoomModel())->where('public_uuid', $room['uuid'])->first());
        $this->assertSame(0, (new GameTeamModel())->where('room_id', $roomId)->countAllResults());
        $this->assertSame(0, (new GameEventModel())->where('room_id', $roomId)->countAllResults());
        $this->assertSame(0, $this->db->table('realtime_outbox')->where('room_id', $roomId)->countAllResults());
    }

    public function testDeletePlayingRoomIsRejected(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Delete Playing Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $engine->joinByPin($room['pin'], 'Tim Aktif');
        $engine->start($room['uuid']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('sedang berjalan');

        $engine->deleteRoom($room['uuid']);
    }

    public function testCreateRoomPlacesRequestedNumberOfMysteryTiles(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Mystery Count Test', [
            'mystery_tile_count' => 4,
        ]);
        $room = $snapshot['room'];

        $usedBoardId = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $board = (new BoardTemplateModel())->find($usedBoardId);
        $tiles = json_decode((string) $board['special_tiles_json'], true);
        $mysteryTiles = array_values(array_filter($tiles, static fn (array $tile): bool => $tile['type'] === 'MYSTERY'));

        $this->assertCount(4, $mysteryTiles);
        $this->assertSame(4, $snapshot['board']['mystery_tile_count']);
        $this->assertSame('ROOM_INSTANCE', $board['status']);

        $positions = array_map(static fn (array $tile): int => (int) $tile['tile'], $tiles);
        $this->assertSame($positions, array_values(array_unique($positions)));
    }

    public function testCreateRoomKeepsOriginalBoardWhenMysteryCountUnchanged(): void
    {
        $sourceBoard = (new BoardTemplateModel())->where('status', 'ACTIVE')->orderBy('id', 'ASC')->first();
        $sourceTiles = json_decode((string) $sourceBoard['special_tiles_json'], true);
        $defaultMysteryCount = count(array_filter($sourceTiles, static fn (array $tile): bool => $tile['type'] === 'MYSTERY'));

        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Default Test', [
            'mystery_tile_count' => $defaultMysteryCount,
        ])['room'];

        $usedBoardId = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $this->assertSame((int) $sourceBoard['id'], (int) $usedBoardId);
    }

    public function testDeleteRoomRemovesItsClonedMysteryBoardTemplate(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Cleanup Test', [
            'mystery_tile_count' => 4,
        ])['room'];
        $usedBoardId = (int) (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $this->assertSame('ROOM_INSTANCE', (new BoardTemplateModel())->find($usedBoardId)['status']);

        $engine->deleteRoom($room['uuid']);

        $this->assertNull((new BoardTemplateModel())->find($usedBoardId));
    }

    public function testDeleteRoomKeepsSharedActiveBoardTemplate(): void
    {
        $sourceBoard = (new BoardTemplateModel())->where('status', 'ACTIVE')->orderBy('id', 'ASC')->first();

        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Shared Board Cleanup Test')['room'];
        $usedBoardId = (int) (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $this->assertSame((int) $sourceBoard['id'], $usedBoardId);

        $engine->deleteRoom($room['uuid']);

        $this->assertNotNull((new BoardTemplateModel())->find($usedBoardId));
        $this->assertSame('ACTIVE', (new BoardTemplateModel())->find($usedBoardId)['status']);
    }

    public function testCreateRoomAppliesSelectedBoardSize(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Board Size 70 Test', [
            'board_size' => 70,
        ])['room'];

        $usedBoardId = (int) (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $board = (new BoardTemplateModel())->find($usedBoardId);

        $this->assertSame(70, (int) $board['tile_count']);
        $this->assertSame('ROOM_INSTANCE', $board['status']);
        $this->assertCount(6, json_decode((string) $board['ladders_json'], true));
        $this->assertCount(6, json_decode((string) $board['snakes_json'], true));
        $this->assertCount(6, json_decode((string) $board['special_tiles_json'], true));
    }

    public function testCreateRoomKeepsDefaultBoardWhenSizeIs100(): void
    {
        $sourceBoard = (new BoardTemplateModel())->where('status', 'ACTIVE')->orderBy('id', 'ASC')->first();

        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Board Size Default Test', [
            'board_size' => 100,
        ])['room'];

        $usedBoardId = (int) (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $this->assertSame((int) $sourceBoard['id'], $usedBoardId);
    }

    public function testCreateRoomComposesBoardSizeAndMysteryTileCount(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Board Size Plus Mystery Test', [
            'board_size' => 70,
            'mystery_tile_count' => 4,
        ])['room'];

        $usedBoardId = (int) (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $board = (new BoardTemplateModel())->find($usedBoardId);
        $mysteryTiles = array_values(array_filter(
            json_decode((string) $board['special_tiles_json'], true),
            static fn (array $tile): bool => $tile['type'] === 'MYSTERY',
        ));

        $this->assertSame(70, (int) $board['tile_count']);
        $this->assertCount(4, $mysteryTiles);
    }

    public function testCreateRoomComposingBoardSizeAndMysteryTileCountLeavesNoOrphanedClone(): void
    {
        $beforeCount = (new BoardTemplateModel())->countAllResults();

        (new GameEngine())->createRoom(1, 'Board Size Plus Mystery No Orphan Test', [
            'board_size' => 70,
            'mystery_tile_count' => 4,
        ]);

        $afterCount = (new BoardTemplateModel())->countAllResults();

        $this->assertSame($beforeCount + 1, $afterCount);
    }

    public function testDeleteRoomRemovesClonedBoardSizeTemplate(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Board Size Cleanup Test', [
            'board_size' => 50,
        ])['room'];
        $usedBoardId = (int) (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $this->assertSame('ROOM_INSTANCE', (new BoardTemplateModel())->find($usedBoardId)['status']);

        $engine->deleteRoom($room['uuid']);

        $this->assertNull((new BoardTemplateModel())->find($usedBoardId));
    }

    private function roomId(string $roomUuid): int
    {
        $room = (new GameRoomModel())->where('public_uuid', $roomUuid)->first();

        return (int) $room['id'];
    }

    private function firstOptionId(int $questionId): int
    {
        $option = (new QuestionOptionModel())->where('question_id', $questionId)->first();

        return (int) $option['id'];
    }

    private function correctOptionId(int $questionId): int
    {
        $option = (new QuestionOptionModel())
            ->where('question_id', $questionId)
            ->where('is_correct', 1)
            ->first();

        return (int) $option['id'];
    }

    private function wrongOptionId(int $questionId): int
    {
        $option = (new QuestionOptionModel())
            ->where('question_id', $questionId)
            ->where('is_correct', 0)
            ->first();

        return (int) $option['id'];
    }

    private function answerCorrectWithForcedMove(GameEngine $engine, array $room, array $team, int $position, int $dice): array
    {
        $engine->start($room['uuid']);

        return $this->answerCurrentTurnWithForcedMove($engine, $room, $team, $position, $dice, true);
    }

    private function answerCurrentTurnWithForcedMove(GameEngine $engine, array $room, array $team, int $position, int $dice, bool $correct): array
    {
        (new GameTeamModel())->update($team['id'], ['position' => $position]);
        $engine->roll($room['uuid'], $team['public_uuid']);

        $turns = new GameTurnModel();
        $turn = $turns->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $turns->update($turn['id'], [
            'dice_value' => $dice,
            'question_started_at' => date('Y-m-d H:i:s', time() + 30),
            'question_deadline_at' => date('Y-m-d H:i:s', time() + 60),
        ]);

        $optionId = $correct
            ? $this->correctOptionId((int) $turn['question_id'])
            : $this->wrongOptionId((int) $turn['question_id']);

        return $engine->answer($room['uuid'], $team['public_uuid'], $optionId);
    }

    private function seedHardQuestion(int $teacherId): void
    {
        $now = date('Y-m-d H:i:s');
        $questionId = (new QuestionModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => $teacherId,
            'source_type' => 'MASTER',
            'question_type' => 'MULTIPLE_CHOICE',
            'stem' => 'Berapa hasil dari akar kuadrat 144? (soal HARD uji)',
            'difficulty' => 'HARD',
            'status' => 'PUBLISHED',
            'points' => 100,
            'time_limit_seconds' => 30,
            'explanation' => null,
            'meta_json' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sort = 1;
        foreach (['A' => ['10', false], 'B' => ['12', true], 'C' => ['14', false], 'D' => ['16', false]] as $label => [$body, $isCorrect]) {
            (new QuestionOptionModel())->insert([
                'question_id' => $questionId,
                'label' => $label,
                'body' => $body,
                'is_correct' => $isCorrect ? 1 : 0,
                'sort_order' => $sort++,
            ]);
        }
    }

    private function seedTopic(int $teacherId, string $name): array
    {
        $topicId = (new QuestionTopicModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => $teacherId,
            'name' => $name,
        ], true);

        return (new QuestionTopicModel())->find($topicId);
    }

    private function seedTopicQuestion(int $teacherId, int $topicId, string $stem, string $difficulty): int
    {
        $now = date('Y-m-d H:i:s');
        $questionId = (int) (new QuestionModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => $teacherId,
            'topic_id' => $topicId,
            'source_type' => 'MASTER',
            'question_type' => 'MULTIPLE_CHOICE',
            'stem' => $stem,
            'difficulty' => $difficulty,
            'status' => 'PUBLISHED',
            'points' => 100,
            'time_limit_seconds' => 30,
            'explanation' => null,
            'meta_json' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], true);

        foreach (['A' => true, 'B' => false] as $label => $isCorrect) {
            (new QuestionOptionModel())->insert([
                'question_id' => $questionId,
                'label' => $label,
                'body' => $isCorrect ? 'Benar' : 'Salah',
                'is_correct' => $isCorrect ? 1 : 0,
                'sort_order' => $label === 'A' ? 1 : 2,
            ]);
        }

        return $questionId;
    }

    private function noScoring(): array
    {
        return [
            'time_bonus' => false,
            'streak_bonus' => false,
            'near_finish_bonus' => false,
            'wrong_penalty' => false,
            'timeout_penalty' => false,
        ];
    }

    private function teamFromSnapshot(array $snapshot, string $teamUuid): array
    {
        return array_values(array_filter(
            $snapshot['teams'],
            static fn (array $item): bool => $item['uuid'] === $teamUuid,
        ))[0];
    }

    private function lastEvent(int $roomId, string $type): array
    {
        $event = (new GameEventModel())
            ->where('room_id', $roomId)
            ->where('type', $type)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($event);

        return json_decode((string) $event['payload_json'], true);
    }
}
