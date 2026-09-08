<?php

namespace App\Services\Game\Modes;

interface GameModeEngineInterface
{
    public function key(): string;

    public function label(): string;

    public function isPlayable(): bool;

    public function renderer(): string;

    public function initialState(array $room, array $board): array;

    public function publicState(array $room, array $board, ?array $turn, array $teams): array;
}
