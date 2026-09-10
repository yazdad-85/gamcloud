<?php

namespace App\Services\Game;

class GameRoomPresenter
{
    public static function modeLabel(?string $gameMode): string
    {
        return match (strtoupper((string) $gameMode)) {
            'QUIZ_RACE' => 'Quiz Race',
            'BOSS_BATTLE' => 'Boss Battle',
            'TREASURE_HUNT' => 'Treasure Hunt',
            'DUEL_ARENA' => 'Duel Arena',
            default => 'Ular Tangga Kuis',
        };
    }

    public static function defaultTitle(?string $gameMode, ?int $timestamp = null): string
    {
        $prefix = strtoupper((string) $gameMode) === 'QUIZ_RACE'
            ? 'Quiz Race'
            : 'Game Ular Tangga';

        return $prefix . ' ' . date('d/m H:i', $timestamp ?? time());
    }

    /**
     * Keeps custom titles untouched, but normalizes old default titles when
     * a room is actually a Quiz Race room.
     */
    public static function displayTitle(array $room): string
    {
        $title = trim((string) ($room['title'] ?? ''));
        $gameMode = strtoupper((string) ($room['game_mode'] ?? 'SNAKES_LADDERS'));

        if ($title === '') {
            return self::defaultTitle($gameMode);
        }

        if ($gameMode === 'QUIZ_RACE' && str_starts_with($title, 'Game Ular Tangga')) {
            return 'Quiz Race' . substr($title, strlen('Game Ular Tangga'));
        }

        return $title;
    }
}
