<?php

use App\Services\Game\RaceQuestionService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RaceQuestionServiceTest extends CIUnitTestCase
{
    public function testResolveMovementsAwardsCorrectAndFastestStepsOnly(): void
    {
        $result = $this->service()->resolveMovements(
            $this->teams([1 => 2, 2 => 2, 3 => 2, 4 => 2]),
            [
                ['team_id' => 1, 'outcome' => 'CORRECT', 'response_ms' => 1200],
                ['team_id' => 2, 'outcome' => 'CORRECT', 'response_ms' => 1800],
                ['team_id' => 3, 'outcome' => 'WRONG', 'response_ms' => 500],
                ['team_id' => 4, 'outcome' => 'TIMEOUT', 'response_ms' => null],
            ],
            ['max_position' => 24],
            $this->board()
        );

        $this->assertSame([1], $result['fastest_team_ids']);
        $this->assertSame([5, 3, 2, 2], array_column($result['movements'], 'to'));
        $this->assertSame([3, 1, 0, 0], array_column($result['movements'], 'steps'));
    }

    public function testMatchesAnswerWhenTeamRowIdIsStringButAnswerTeamIdIsInt(): void
    {
        // On MySQLi (production), numeric columns come back as PHP strings
        // unless 'numberNative' is enabled — it isn't. GameTeamModel doesn't
        // cast 'id' to int, so $team['id'] here is a string, exactly like a
        // team row read from the real database. GameEngine, meanwhile,
        // explicitly casts submitted answers' team_id to (int) before calling
        // resolveMovements(). Both must resolve to the same team regardless of
        // this type difference — SQLite3 (used locally/in tests) happens to
        // return native ints for both sides, which is why this only ever
        // surfaced in production.
        $teams = [
            ['id' => '1', 'position' => 2, 'active_effects_json' => '{}'],
            ['id' => '2', 'position' => 2, 'active_effects_json' => '{}'],
        ];

        $result = $this->service()->resolveMovements(
            $teams,
            [
                ['team_id' => 1, 'outcome' => 'CORRECT', 'is_correct' => true, 'response_ms' => 7553],
                ['team_id' => 2, 'outcome' => 'WRONG', 'is_correct' => false, 'response_ms' => 8614],
            ],
            ['max_position' => 24],
            $this->board()
        );

        $movementsByTeamId = [];
        foreach ($result['movements'] as $movement) {
            $movementsByTeamId[(string) $movement['team_id']] = $movement;
        }

        // Team 1's sole correct answer also makes it the fastest, so steps
        // include both the base correct-answer step and the +2 fastest bonus.
        // The bug under test isn't this scoring math — it's that team 1's
        // answer used to never be found at all (silently replaced by the
        // TIMEOUT default), which these outcome/steps values reveal either way.
        $this->assertSame('CORRECT', $movementsByTeamId['1']['outcome']);
        $this->assertSame(3, $movementsByTeamId['1']['steps']);
        $this->assertSame('WRONG', $movementsByTeamId['2']['outcome']);
        $this->assertSame(0, $movementsByTeamId['2']['steps']);
    }

    public function testExactFastestTiesAllReceiveBonus(): void
    {
        $result = $this->service()->resolveMovements(
            $this->teams([1 => 1, 2 => 1, 3 => 1]),
            [
                ['team_id' => 1, 'is_correct' => true, 'response_ms' => 900],
                ['team_id' => 2, 'is_correct' => true, 'response_ms' => 900],
                ['team_id' => 3, 'is_correct' => true, 'response_ms' => 901],
            ],
            ['max_position' => 24],
            $this->board()
        );

        $this->assertSame([1, 2], $result['fastest_team_ids']);
        $this->assertSame([4, 4, 2], array_column($result['movements'], 'to'));
    }

    public function testOilSpillLockRemovesFastestEligibilityAndIsConsumed(): void
    {
        $teams = $this->teams([1 => 2, 2 => 2]);
        $teams[0]['active_effects_json'] = json_encode(['safe_shield' => 1, 'oil_spill_lock' => true]);

        $result = $this->service()->resolveMovements(
            $teams,
            [
                ['team_id' => 1, 'is_correct' => true, 'response_ms' => 500],
                ['team_id' => 2, 'is_correct' => true, 'response_ms' => 800],
            ],
            ['max_position' => 24],
            $this->board()
        );

        $this->assertSame([2], $result['fastest_team_ids']);
        $this->assertSame([3, 5], array_column($result['movements'], 'to'));
        $this->assertTrue($result['movements'][0]['oil_spill_consumed']);
        $this->assertFalse($result['movements'][0]['active_effects']['oil_spill_lock']);
        $this->assertSame(1, $result['movements'][0]['active_effects']['safe_shield']);
    }

    public function testLandingOnOilSpillStoresNextQuestionLock(): void
    {
        $result = $this->service()->resolveMovements(
            $this->teams([1 => 4, 2 => 1]),
            [
                ['team_id' => 1, 'is_correct' => true, 'response_ms' => 1000],
                ['team_id' => 2, 'is_correct' => true, 'response_ms' => 500],
            ],
            ['max_position' => 24],
            $this->board([['tile' => 5, 'type' => 'TRAP', 'label' => 'Oil Spill']])
        );

        $this->assertSame('OIL_SPILL', $result['movements'][0]['special']);
        $this->assertTrue($result['movements'][0]['active_effects']['oil_spill_lock']);
    }

    public function testNewOilSpillLockReplacesConsumedLockInSameQuestion(): void
    {
        $teams = $this->teams([1 => 4, 2 => 1]);
        $teams[0]['active_effects_json'] = json_encode(['oil_spill_lock' => true]);

        $result = $this->service()->resolveMovements(
            $teams,
            [
                ['team_id' => 1, 'is_correct' => true, 'response_ms' => 100],
                ['team_id' => 2, 'is_correct' => true, 'response_ms' => 500],
            ],
            ['max_position' => 24],
            $this->board([['tile' => 5, 'type' => 'TRAP', 'label' => 'Oil Spill']])
        );

        $this->assertSame([2], $result['fastest_team_ids']);
        $this->assertTrue($result['movements'][0]['oil_spill_consumed']);
        $this->assertTrue($result['movements'][0]['active_effects']['oil_spill_lock']);
    }

    public function testBoostAddsTwoAndClampsAtFinish(): void
    {
        $result = $this->service()->resolveMovements(
            $this->teams([1 => 5, 2 => 8]),
            [
                ['team_id' => 1, 'is_correct' => true, 'response_ms' => 1000],
                ['team_id' => 2, 'is_correct' => false, 'response_ms' => 900],
            ],
            ['max_position' => 10],
            $this->board([['tile' => 8, 'type' => 'BONUS', 'steps' => 5, 'label' => 'Boost']])
        );

        $this->assertSame(8, $result['movements'][0]['landed']);
        $this->assertSame(10, $result['movements'][0]['to']);
        $this->assertSame('BOOST', $result['movements'][0]['special']);
        $this->assertSame(8, $result['movements'][1]['to']);
        $this->assertNull($result['movements'][1]['special']);
        $this->assertSame([1], $result['finisher_team_ids']);
    }

    public function testAllMovementsAreComputedBeforeFinishersAreReturned(): void
    {
        $result = $this->service()->resolveMovements(
            $this->teams([1 => 8, 2 => 6]),
            [
                ['team_id' => 1, 'is_correct' => true, 'response_ms' => 500],
                ['team_id' => 2, 'is_correct' => true, 'response_ms' => 500],
            ],
            ['max_position' => 10],
            $this->board()
        );

        $this->assertCount(2, $result['movements']);
        $this->assertSame([10, 9], array_column($result['movements'], 'to'));
        $this->assertSame([1], $result['finisher_team_ids']);
    }

    public function testFinisherRankingUsesScoreCorrectCountAndResponseTime(): void
    {
        $ranking = $this->service()->rankFinishers([
            ['team_id' => 1, 'score' => 900, 'correct_count' => 9, 'correct_response_ms' => 9000],
            ['team_id' => 2, 'score' => 1000, 'correct_count' => 8, 'correct_response_ms' => 8000],
            ['team_id' => 3, 'score' => 1000, 'correct_count' => 9, 'correct_response_ms' => 8500],
            ['team_id' => 4, 'score' => 1000, 'correct_count' => 9, 'correct_response_ms' => 8000],
        ]);

        $this->assertSame([4, 3, 2, 1], array_column($ranking['standings'], 'team_id'));
        $this->assertSame([4], $ranking['winner_team_ids']);
    }

    public function testQuestionLimitRankingUsesPositionBeforeOtherCriteria(): void
    {
        $ranking = $this->service()->rankQuestionLimit([
            ['team_id' => 1, 'position' => 20, 'score' => 2000, 'correct_count' => 20, 'correct_response_ms' => 20000],
            ['team_id' => 2, 'position' => 21, 'score' => 1000, 'correct_count' => 10, 'correct_response_ms' => 10000],
            ['team_id' => 3, 'position' => 21, 'score' => 1100, 'correct_count' => 9, 'correct_response_ms' => 9000],
        ]);

        $this->assertSame([3, 2, 1], array_column($ranking['standings'], 'team_id'));
        $this->assertSame([3], $ranking['winner_team_ids']);
    }

    public function testExactFinalTiesReturnCoWinners(): void
    {
        $finishers = $this->service()->rankFinishers([
            ['team_id' => 1, 'score' => 1000, 'correct_count' => 9, 'correct_response_ms' => 8000],
            ['team_id' => 2, 'score' => 1000, 'correct_count' => 9, 'correct_response_ms' => 8000],
        ]);
        $questionLimit = $this->service()->rankQuestionLimit([
            ['team_id' => 1, 'position' => 20, 'score' => 1000, 'correct_count' => 9, 'correct_response_ms' => 8000],
            ['team_id' => 2, 'position' => 20, 'score' => 1000, 'correct_count' => 9, 'correct_response_ms' => 8000],
        ]);

        $this->assertSame([1, 2], $finishers['winner_team_ids']);
        $this->assertSame([1, 2], $questionLimit['winner_team_ids']);
    }

    private function service(): RaceQuestionService
    {
        return new RaceQuestionService();
    }

    private function teams(array $positions): array
    {
        $teams = [];
        foreach ($positions as $id => $position) {
            $teams[] = [
                'id' => $id,
                'position' => $position,
                'active_effects_json' => '{}',
            ];
        }

        return $teams;
    }

    private function board(array $tiles = []): array
    {
        return ['special_tiles_json' => json_encode($tiles)];
    }
}
