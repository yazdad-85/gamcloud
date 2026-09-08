<?php

namespace App\Services\Game\Modes;

class SnakesLaddersModeEngine implements GameModeEngineInterface
{
    public function key(): string
    {
        return 'SNAKES_LADDERS';
    }

    public function label(): string
    {
        return 'Ular Tangga Kuis';
    }

    public function isPlayable(): bool
    {
        return true;
    }

    public function renderer(): string
    {
        return 'snakes_ladders_board';
    }

    public function initialState(array $room, array $board): array
    {
        return [
            'version' => 1,
            'board_model' => 'linear_path',
            'finish_position' => (int) ($room['max_position'] ?? $board['tile_count'] ?? 100),
        ];
    }

    public function publicState(array $room, array $board, ?array $turn, array $teams): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'status' => 'ACTIVE',
            'renderer' => $this->renderer(),
            'actions' => ['roll', 'answer'],
            'board_model' => 'linear_path',
            'finish_position' => (int) ($room['max_position'] ?? $board['tile_count'] ?? 100),
            'current_turn_state' => $turn['state'] ?? null,
            'team_count' => count($teams),
        ];
    }
}
