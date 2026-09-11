<?php

namespace App\Services\Game;

class RaceTrackService
{
    private const TIER_STEPS = [
        'EASY' => 1,
        'MEDIUM' => 2,
        'HARD' => 3,
    ];

    private const RACE_TILE_TYPES = ['BONUS', 'TRAP'];

    public function stepsForTier(string $tier): int
    {
        return self::TIER_STEPS[strtoupper($tier)] ?? 0;
    }

    public function movementForTierAnswer(int $from, string $tier, array $room, array $board): array
    {
        return $this->movementForSteps($from, $this->stepsForTier($tier), $room, $board);
    }

    public function movementForSteps(
        int $from,
        int $steps,
        array $room,
        array $board,
        ?int $boostStepsOverride = null
    ): array
    {
        $maxPosition = max(1, (int) ($room['max_position'] ?? 1));
        $steps = max(0, $steps);
        $landed = min($maxPosition, $from + $steps);
        $tileEffect = $steps === 0
            ? ['to' => $landed, 'special' => null, 'effects' => []]
            : $this->applyRaceTileEffect($landed, $board, $maxPosition, $boostStepsOverride);

        return [
            'from' => $from,
            'landed' => $landed,
            'to' => $tileEffect['to'],
            'special' => $tileEffect['special'],
            'effects' => $tileEffect['effects'],
            'score_delta' => 0,
        ];
    }

    private function applyRaceTileEffect(
        int $position,
        array $board,
        int $maxPosition,
        ?int $boostStepsOverride = null
    ): array
    {
        $tile = $this->specialTileAt($position, $board);
        if ($tile === null) {
            return ['to' => $position, 'special' => null, 'effects' => []];
        }

        $type = strtoupper((string) ($tile['type'] ?? ''));
        $label = (string) ($tile['label'] ?? $type);

        if ($type === 'BONUS') {
            $bonusSteps = $boostStepsOverride ?? max(1, (int) ($tile['steps'] ?? 2));
            $to = min($maxPosition, $position + $bonusSteps);

            return [
                'to' => $to,
                'special' => 'BOOST',
                'effects' => [[
                    'type' => 'BOOST',
                    'tile' => $position,
                    'steps' => $bonusSteps,
                    'label' => $label,
                ]],
            ];
        }

        if ($type === 'TRAP') {
            return [
                'to' => $position,
                'special' => 'OIL_SPILL',
                'effects' => [[
                    'type' => 'OIL_SPILL',
                    'tile' => $position,
                    'label' => $label,
                ]],
            ];
        }

        return ['to' => $position, 'special' => null, 'effects' => []];
    }

    public function specialTiles(array $board): array
    {
        $tiles = json_decode((string) ($board['special_tiles_json'] ?? ''), true);
        if (! is_array($tiles)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($tile): ?array {
            if (! is_array($tile)) {
                return null;
            }
            $position = (int) ($tile['tile'] ?? 0);
            $type = strtoupper((string) ($tile['type'] ?? ''));
            if ($position < 1 || ! in_array($type, self::RACE_TILE_TYPES, true)) {
                return null;
            }

            return [
                'tile' => $position,
                'type' => $type,
                'label' => (string) ($tile['label'] ?? $type),
                'steps' => isset($tile['steps']) ? (int) $tile['steps'] : null,
            ];
        }, $tiles)));
    }

    public function specialTileAt(int $position, array $board): ?array
    {
        foreach ($this->specialTiles($board) as $tile) {
            if ((int) $tile['tile'] === $position) {
                return $tile;
            }
        }

        return null;
    }

    public function lapForPosition(int $position, int $trackLength, int $lapCount): int
    {
        $lapCount = max(1, $lapCount);
        $trackLength = max(1, $trackLength);
        $segment = $trackLength / $lapCount;

        return (int) min($lapCount, max(1, ceil($position / $segment)));
    }

    public function checkpointCrossed(int $from, int $to, int $trackLength, int $lapCount): bool
    {
        return $this->lapForPosition($to, $trackLength, $lapCount) > $this->lapForPosition($from, $trackLength, $lapCount);
    }

    /**
     * Deterministically lays out Boost/Oil Spill tiles every 4 tiles along a
     * freshly-sized race track, skipping the start tile and never placing a
     * tile past the finish line.
     *
     * @return list<array{tile:int,type:string,label:string,steps?:int}>
     */
    public function generateTrackTiles(int $trackLength): array
    {
        $types = ['BONUS', 'TRAP'];
        $tiles = [];
        $typeIndex = 0;
        for ($position = 4; $position < $trackLength; $position += 4) {
            $type = $types[$typeIndex % count($types)];
            $tile = [
                'tile' => $position,
                'type' => $type,
                'label' => $type === 'BONUS' ? 'Boost' : 'Oil Spill',
            ];
            if ($type === 'BONUS') {
                $tile['steps'] = 2;
            }
            $tiles[] = $tile;
            $typeIndex++;
        }

        return $tiles;
    }
}
