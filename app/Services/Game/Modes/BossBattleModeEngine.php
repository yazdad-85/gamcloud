<?php

namespace App\Services\Game\Modes;

class BossBattleModeEngine extends PlannedModeEngine
{
    public function __construct()
    {
        parent::__construct('BOSS_BATTLE', 'Boss Battle', 'boss_battle_stage', 'Kelas bekerja sama menurunkan HP boss dengan jawaban benar.');
    }
}
