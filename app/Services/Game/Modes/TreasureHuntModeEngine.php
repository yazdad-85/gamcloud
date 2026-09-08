<?php

namespace App\Services\Game\Modes;

class TreasureHuntModeEngine extends PlannedModeEngine
{
    public function __construct()
    {
        parent::__construct('TREASURE_HUNT', 'Treasure Hunt', 'treasure_hunt_map', 'Tim mencari item dan tantangan pada peta kuis.');
    }
}
