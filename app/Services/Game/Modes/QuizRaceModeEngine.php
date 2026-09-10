<?php

namespace App\Services\Game\Modes;

class QuizRaceModeEngine implements GameModeEngineInterface
{
    public function key(): string
    {
        return 'QUIZ_RACE';
    }

    public function label(): string
    {
        return 'Quiz Race';
    }

    public function isPlayable(): bool
    {
        return true;
    }

    public function renderer(): string
    {
        return 'quiz_race_track';
    }

    public function initialState(array $room, array $board): array
    {
        return [
            'version' => 1,
            'board_model' => 'linear_track',
            'finish_position' => (int) ($room['max_position'] ?? $board['tile_count'] ?? 24),
        ];
    }

    public function publicState(array $room, array $board, ?array $turn, array $teams): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'status' => 'ACTIVE',
            'renderer' => $this->renderer(),
            'actions' => ['select_tier', 'answer'],
            'board_model' => 'linear_track',
            'finish_position' => (int) ($room['max_position'] ?? $board['tile_count'] ?? 24),
            'lap_count' => (int) ($room['lap_count'] ?? 1),
            'current_turn_state' => $turn['state'] ?? null,
            'team_count' => count($teams),
        ];
    }
}
