<?php

namespace App\Controllers\Game;

use App\Controllers\BaseController;
use App\Services\Game\GameEngine;
use App\Services\Security\TeamSessionService;
use DomainException;

class ControllerController extends BaseController
{
    public function show(string $roomUuid): string
    {
        $teamUuid = (string) ($this->request->getGet('team') ?: (new TeamSessionService())->currentTeamUuid($roomUuid));

        try {
            (new TeamSessionService())->assertTeamSession($roomUuid, $teamUuid);
        } catch (DomainException $error) {
            return view('game/invalid_team', [
                'message' => $error->getMessage(),
                'roomUuid' => $roomUuid,
            ]);
        }

        return view('game/controller', [
            'snapshot' => (new GameEngine())->snapshot($roomUuid),
            'teamUuid' => $teamUuid,
        ]);
    }
}
