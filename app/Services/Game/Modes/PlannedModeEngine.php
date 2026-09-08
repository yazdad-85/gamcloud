<?php

namespace App\Services\Game\Modes;

class PlannedModeEngine implements GameModeEngineInterface
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $renderer,
        private readonly string $description,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isPlayable(): bool
    {
        return false;
    }

    public function renderer(): string
    {
        return $this->renderer;
    }

    public function initialState(array $room, array $board): array
    {
        return [
            'version' => 1,
            'status' => 'PLANNED',
            'description' => $this->description,
        ];
    }

    public function publicState(array $room, array $board, ?array $turn, array $teams): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'status' => 'PLANNED',
            'renderer' => $this->renderer(),
            'actions' => [],
            'description' => $this->description,
            'team_count' => count($teams),
        ];
    }
}
