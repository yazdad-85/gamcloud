<?php

use App\Services\Game\RaceTrackService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RaceTrackServiceTest extends CIUnitTestCase
{
    public function testStepsForTierReturnsExpectedDistances(): void
    {
        $race = new RaceTrackService();

        $this->assertSame(1, $race->stepsForTier('EASY'));
        $this->assertSame(2, $race->stepsForTier('MEDIUM'));
        $this->assertSame(3, $race->stepsForTier('HARD'));
    }

    public function testMovementForTierAnswerAdvancesByTierSteps(): void
    {
        $race = new RaceTrackService();
        $room = ['max_position' => 24];
        $board = ['special_tiles_json' => '[]'];

        $movement = $race->movementForTierAnswer(5, 'MEDIUM', $room, $board);

        $this->assertSame(5, $movement['from']);
        $this->assertSame(7, $movement['landed']);
        $this->assertSame(7, $movement['to']);
        $this->assertNull($movement['special']);
    }

    public function testMovementForTierAnswerClampsAtFinishLine(): void
    {
        $race = new RaceTrackService();
        $room = ['max_position' => 24];
        $board = ['special_tiles_json' => '[]'];

        $movement = $race->movementForTierAnswer(23, 'HARD', $room, $board);

        $this->assertSame(24, $movement['to']);
    }

    public function testBoostTileAddsExtraSteps(): void
    {
        $race = new RaceTrackService();
        $room = ['max_position' => 24];
        $board = ['special_tiles_json' => json_encode([
            ['tile' => 7, 'type' => 'BONUS', 'steps' => 2, 'label' => 'Boost'],
        ])];

        $movement = $race->movementForTierAnswer(5, 'MEDIUM', $room, $board);

        $this->assertSame(7, $movement['landed']);
        $this->assertSame(9, $movement['to']);
        $this->assertSame('BOOST', $movement['special']);
    }

    public function testBoostTileClampsAtFinishLine(): void
    {
        $race = new RaceTrackService();
        $room = ['max_position' => 8];
        $board = ['special_tiles_json' => json_encode([
            ['tile' => 7, 'type' => 'BONUS', 'steps' => 5, 'label' => 'Boost'],
        ])];

        $movement = $race->movementForTierAnswer(5, 'MEDIUM', $room, $board);

        $this->assertSame(8, $movement['to']);
    }

    public function testOilSpillTileFlagsSpecialWithoutMovingBack(): void
    {
        $race = new RaceTrackService();
        $room = ['max_position' => 24];
        $board = ['special_tiles_json' => json_encode([
            ['tile' => 7, 'type' => 'TRAP', 'label' => 'Oil Spill'],
        ])];

        $movement = $race->movementForTierAnswer(5, 'MEDIUM', $room, $board);

        $this->assertSame(7, $movement['to']);
        $this->assertSame('OIL_SPILL', $movement['special']);
    }

    public function testLapForPositionDividesTrackEvenly(): void
    {
        $race = new RaceTrackService();

        $this->assertSame(1, $race->lapForPosition(1, 30, 5));
        $this->assertSame(1, $race->lapForPosition(6, 30, 5));
        $this->assertSame(2, $race->lapForPosition(7, 30, 5));
        $this->assertSame(5, $race->lapForPosition(30, 30, 5));
    }

    public function testCheckpointCrossedDetectsLapBoundaryOnly(): void
    {
        $race = new RaceTrackService();

        $this->assertFalse($race->checkpointCrossed(4, 6, 30, 5));
        $this->assertTrue($race->checkpointCrossed(5, 7, 30, 5));
    }

    public function testGenerateTrackTilesCyclesThroughRaceTypes(): void
    {
        $race = new RaceTrackService();

        $tiles = $race->generateTrackTiles(12);

        $this->assertSame([
            ['tile' => 4, 'type' => 'BONUS', 'label' => 'Boost', 'steps' => 2],
            ['tile' => 8, 'type' => 'TRAP', 'label' => 'Oil Spill'],
        ], $tiles);
    }
}
