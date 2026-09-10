<?php

namespace App\Services\Security;

use App\Models\GameRoomModel;
use App\Models\GameTeamModel;
use Config\Game;
use DomainException;
use Throwable;

class TeamSessionService
{
    public function assertTeamSession(string $roomUuid, string $teamUuid): array
    {
        $team = (new GameTeamModel())->where('public_uuid', $teamUuid)->first();

        if ($team !== null && $this->teacherCentralizedAccessAllowed($roomUuid, $team)) {
            return $team;
        }

        $session = session()->get($this->sessionKey($roomUuid));

        if (! is_array($session) || ($session['team_uuid'] ?? null) !== $teamUuid) {
            throw new DomainException('Session tim tidak valid. Silakan join ulang dengan PIN.');
        }

        $issuedAt = (int) ($session['issued_at'] ?? 0);
        $ttlMinutes = (int) config(Game::class)->teamSessionTtlMinutes;
        if ($issuedAt < 1 || (time() - $issuedAt) > ($ttlMinutes * 60)) {
            session()->remove($this->sessionKey($roomUuid));
            throw new DomainException('Session tim kedaluwarsa. Silakan join ulang dengan PIN.');
        }

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

    private function teacherCentralizedAccessAllowed(string $roomUuid, array $team): bool
    {
        if (! auth()->loggedIn()) {
            return false;
        }

        $room = (new GameRoomModel())->where('public_uuid', $roomUuid)->first();
        if ($room === null || ($room['participation_mode'] ?? 'TEAM_DEVICE') !== 'TEACHER_CENTRALIZED') {
            return false;
        }

        if ((int) $team['room_id'] !== (int) $room['id']) {
            return false;
        }

        try {
            (new TenantContext())->assertRoomOwner($roomUuid);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function sessionKey(string $roomUuid): string
    {
        return 'team_' . $roomUuid;
    }
}
