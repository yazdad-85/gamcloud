<?php

namespace App\Services\Game\Modes;

class GameModeCatalog
{
    /**
     * @return array<string, GameModeEngineInterface>
     */
    public function all(): array
    {
        $modes = [
            new SnakesLaddersModeEngine(),
            new QuizRaceModeEngine(),
            new BossBattleModeEngine(),
            new TreasureHuntModeEngine(),
            new DuelArenaModeEngine(),
        ];

        $indexed = [];
        foreach ($modes as $mode) {
            $indexed[$mode->key()] = $mode;
        }

        return $indexed;
    }

    /**
     * @return array<int, array{key:string,label:string,playable:bool,renderer:string}>
     */
    public function options(): array
    {
        return array_map(static fn (GameModeEngineInterface $mode): array => [
            'key' => $mode->key(),
            'label' => $mode->label(),
            'playable' => $mode->isPlayable(),
            'renderer' => $mode->renderer(),
        ], array_values($this->all()));
    }

    /**
     * @return list<string>
     */
    public function playableKeys(): array
    {
        return array_values(array_map(
            static fn (GameModeEngineInterface $mode): string => $mode->key(),
            array_filter($this->all(), static fn (GameModeEngineInterface $mode): bool => $mode->isPlayable()),
        ));
    }

    public function resolve(?string $key): GameModeEngineInterface
    {
        $key = strtoupper((string) $key);
        $modes = $this->all();

        return $modes[$key] ?? $modes['SNAKES_LADDERS'];
    }
}
