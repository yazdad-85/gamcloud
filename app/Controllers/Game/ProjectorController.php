<?php

namespace App\Controllers\Game;

use App\Controllers\BaseController;
use App\Models\GameRoomModel;
use App\Services\Game\GameEngine;
use CodeIgniter\Exceptions\PageNotFoundException;

class ProjectorController extends BaseController
{
    public function show(string $roomUuid): string
    {
        $token = trim((string) $this->request->getGet('t'));
        $room  = (new GameRoomModel())->where('public_uuid', $roomUuid)->first();
        $engine = new GameEngine();

        if ($room === null || ! $engine->isValidProjectorToken($room, $token)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('game/projector', [
            'snapshot' => $engine->snapshot($roomUuid, $token),
            'projectorToken' => $token,
        ]);
    }
}
