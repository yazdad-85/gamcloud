<?php

namespace App\Controllers\Game;

use App\Controllers\BaseController;
use App\Services\Game\GameEngine;

class ProjectorController extends BaseController
{
    public function show(string $roomUuid): string
    {
        return view('game/projector', [
            'snapshot' => (new GameEngine())->snapshot($roomUuid),
        ]);
    }
}
