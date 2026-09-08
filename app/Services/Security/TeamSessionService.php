<?php

namespace App\Services\Security;

use App\Models\GameTeamModel;
use DomainException;

class TeamSessionService
{
    public function assertTeamSession(string $roomUuid, string $teamUuid): array
    {
        $session = session()->get($this->sessionKey($roomUuid));

        if (! is_array($session) || ($session['team_uuid'] ?? null) !== $teamUuid) {
            throw new DomainException('Session tim tidak valid. Silakan join ulang dengan PIN.');
        }

        $team = (new GameTeamModel())->where('public_uuid', $teamUuid)->first();
        if ($team === null) {
            throw new DomainException('Tim tidak ditemukan.');
        }

        $token = (string) ($session['token'] ?? '');
        if ($token === '' || ! hash_equals((string) $team['session_token_hash'], hash('sha256', $token))) {
            throw new DomainException('Token tim tidak valid. Silakan join ulang dengan PIN.');
        }

        return $team;
    }

    public function currentTeamUuid(string $roomUuid): ?string
    {
        $session = session()->get($this->sessionKey($roomUuid));

        return is_array($session) ? ($session['team_uuid'] ?? null) : null;
    }

    private function sessionKey(string $roomUuid): string
    {
        return 'team_' . $roomUuid;
    }
}
