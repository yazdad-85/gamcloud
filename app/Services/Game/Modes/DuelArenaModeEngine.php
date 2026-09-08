<?php

namespace App\Services\Game\Modes;

class DuelArenaModeEngine extends PlannedModeEngine
{
    public function __construct()
    {
        parent::__construct('DUEL_ARENA', 'Duel Arena', 'duel_arena_stage', 'Duel cepat antar tim dengan giliran singkat.');
    }
}
