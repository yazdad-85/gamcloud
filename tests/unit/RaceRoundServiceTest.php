<?php

use App\Services\Game\RaceRoundService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RaceRoundServiceTest extends CIUnitTestCase
{
    public function testNormalizeAllocationUsesDefaultAndAcceptsValidInput(): void
    {
        $service = new RaceRoundService();

        $this->assertSame([15, 15, 20], $service->normalizeAllocation(null));
        $this->assertSame([15, 15, 20], $service->normalizeAllocation(''));
        $this->assertSame([10, 20, 30], $service->normalizeAllocation('[10, 20, 30]'));
        $this->assertSame([3], $service->normalizeAllocation(['3']));
        $this->assertSame([1, 1, 1, 1, 1], $service->normalizeAllocation([1, 1, 1, 1, 1]));
    }

    /**
     * @dataProvider invalidAllocationProvider
     */
    public function testNormalizeAllocationRejectsInvalidInput(mixed $allocation): void
    {
        $this->expectException(DomainException::class);

        (new RaceRoundService())->normalizeAllocation($allocation);
    }

    public static function invalidAllocationProvider(): array
    {
        return [
            'invalid JSON' => ['not-json'],
            'not a list' => [['round_one' => 15, 'round_two' => 15]],
            'too many rounds' => [[1, 1, 1, 1, 1, 1]],
            'zero count' => [[3, 0]],
            'fractional count' => [[2.5, 2.5]],
            'total below minimum' => [[1, 1]],
            'total above maximum' => [[50, 51]],
        ];
    }

    public function testDifficultyScheduleIsBalancedAndSeededDeterministically(): void
    {
        $service = new RaceRoundService();

        $fifteen = $service->difficultySchedule(15, 1357);
        $twenty = $service->difficultySchedule(20, 2468);
        $fifteenCounts = array_count_values($fifteen);
        $twentyCounts = array_count_values($twenty);
        ksort($fifteenCounts);
        ksort($twentyCounts);

        $this->assertCount(15, $fifteen);
        $this->assertSame(['EASY' => 5, 'HARD' => 5, 'MEDIUM' => 5], $fifteenCounts);
        $this->assertSame(['EASY' => 7, 'HARD' => 7, 'MEDIUM' => 6], $twentyCounts);
        $this->assertSame($fifteen, $service->difficultySchedule(15, 1357));
        $this->assertNotSame($fifteen, $service->difficultySchedule(15, 9753));
    }

    public function testRankRoundUsesScoreCorrectCountAndCorrectResponseTime(): void
    {
        $ranking = (new RaceRoundService())->rankRound([
            ['team_id' => 1, 'score_delta' => 400, 'correct_count' => 4, 'correct_response_ms' => 9000],
            ['team_id' => 2, 'score_delta' => 500, 'correct_count' => 3, 'correct_response_ms' => 5000],
            ['team_id' => 3, 'score_delta' => 500, 'correct_count' => 4, 'correct_response_ms' => 8000],
            ['team_id' => 4, 'score_delta' => 500, 'correct_count' => 4, 'correct_response_ms' => 7000],
        ]);

        $this->assertSame([4, 3, 2, 1], array_column($ranking['standings'], 'team_id'));
        $this->assertSame([4], $ranking['winner_team_ids']);
        $this->assertSame([1, 2, 3, 4], array_column($ranking['standings'], 'rank'));
    }

    public function testRankRoundReturnsExactCoWinners(): void
    {
        $ranking = (new RaceRoundService())->rankRound([
            ['team_id' => 10, 'score_delta' => 700, 'correct_count' => 6, 'correct_response_ms' => 12345],
            ['team_id' => 11, 'score_delta' => 700, 'correct_count' => 6, 'correct_response_ms' => 12345],
            ['team_id' => 12, 'score_delta' => 700, 'correct_count' => 6, 'correct_response_ms' => 12346],
        ]);

        $this->assertSame([10, 11], $ranking['winner_team_ids']);
        $this->assertSame([1, 1, 3], array_column($ranking['standings'], 'rank'));
    }

    public function testRoundPrizeProducesScoreOnlyAndSkipsInterruptedRound(): void
    {
        $service = new RaceRoundService();

        $this->assertSame([
            ['team_id' => 10, 'score_delta' => 100, 'movement_delta' => 0],
            ['team_id' => 11, 'score_delta' => 100, 'movement_delta' => 0],
        ], $service->roundPrizeDeltas([10, 11], 100));
        $this->assertSame([], $service->roundPrizeDeltas([10, 11], 100, true));
    }
}
