<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Services\Game\GameEngine;
use App\Services\Security\TeamSessionService;
use App\Services\Security\TenantContext;
use CodeIgniter\Exceptions\PageNotFoundException;
use DomainException;
use Throwable;

class RoomsController extends BaseController
{
    public function state(string $roomUuid)
    {
        $token = trim((string) $this->request->getGet('t'));

        return $this->respond(fn () => (new GameEngine())->snapshot(
            $roomUuid,
            $token !== '' ? $token : null
        ));
    }

    public function start(string $roomUuid)
    {
        return $this->respond(function () use ($roomUuid): array {
            (new TenantContext())->assertRoomOwner($roomUuid);

            return (new GameEngine())->start($roomUuid);
        });
    }

    public function pause(string $roomUuid)
    {
        return $this->respond(function () use ($roomUuid): array {
            (new TenantContext())->assertRoomOwner($roomUuid);

            return (new GameEngine())->pause($roomUuid);
        });
    }

    public function resume(string $roomUuid)
    {
        return $this->respond(function () use ($roomUuid): array {
            (new TenantContext())->assertRoomOwner($roomUuid);

            return (new GameEngine())->resume($roomUuid);
        });
    }

    public function skipTurn(string $roomUuid)
    {
        return $this->respond(function () use ($roomUuid): array {
            (new TenantContext())->assertRoomOwner($roomUuid);

            return (new GameEngine())->skipTurn($roomUuid);
        });
    }

    public function forceTimeout(string $roomUuid)
    {
        return $this->respond(function () use ($roomUuid): array {
            (new TenantContext())->assertRoomOwner($roomUuid);

            return (new GameEngine())->forceTimeout($roomUuid);
        });
    }

    public function startTimer(string $roomUuid)
    {
        return $this->respond(fn () => (new GameEngine())->startAnswerTimer($roomUuid));
    }

    public function addTeam(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamName = (string) ($payload['team_name'] ?? '');
        $avatar = (string) ($payload['avatar'] ?? 'robot');

        return $this->respond(fn () => (new GameEngine())->addTeamByOwner($roomUuid, $teamName, $avatar));
    }

    public function removeTeam(string $roomUuid, string $teamUuid)
    {
        return $this->respond(fn () => (new GameEngine())->removeTeamByOwner($roomUuid, $teamUuid));
    }

    public function roll(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamUuid = (string) ($payload['team_uuid'] ?? $this->request->getGet('team'));

        return $this->respond(function () use ($roomUuid, $teamUuid, $payload): array {
            (new TeamSessionService())->assertTeamSession($roomUuid, $teamUuid);

            return (new GameEngine())->roll(
                $roomUuid,
                $teamUuid,
                $this->request->getHeaderLine('Idempotency-Key') ?: ($payload['idempotency_key'] ?? null)
            );
        });
    }

    public function selectTier(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamUuid = (string) ($payload['team_uuid'] ?? $this->request->getGet('team'));
        $tier = (string) ($payload['tier'] ?? '');

        return $this->respond(function () use ($roomUuid, $teamUuid, $tier, $payload): array {
            (new TeamSessionService())->assertTeamSession($roomUuid, $teamUuid);

            return (new GameEngine())->selectDifficultyTier(
                $roomUuid,
                $teamUuid,
                $tier,
                $this->request->getHeaderLine('Idempotency-Key') ?: ($payload['idempotency_key'] ?? null)
            );
        });
    }

    public function answer(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamUuid = (string) ($payload['team_uuid'] ?? $this->request->getGet('team'));
        $optionId = (int) ($payload['option_id'] ?? 0);

        return $this->respond(function () use ($roomUuid, $teamUuid, $optionId, $payload): array {
            (new TeamSessionService())->assertTeamSession($roomUuid, $teamUuid);

            return (new GameEngine())->answer(
                $roomUuid,
                $teamUuid,
                $optionId,
                $this->request->getHeaderLine('Idempotency-Key') ?: ($payload['idempotency_key'] ?? null)
            );
        });
    }

    public function chooseMystery(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamUuid = (string) ($payload['team_uuid'] ?? $this->request->getGet('team'));
        $target = (string) ($payload['target'] ?? '');

        return $this->respond(function () use ($roomUuid, $teamUuid, $target, $payload): array {
            (new TeamSessionService())->assertTeamSession($roomUuid, $teamUuid);

            return (new GameEngine())->chooseMysteryTarget(
                $roomUuid,
                $teamUuid,
                $target,
                $this->request->getHeaderLine('Idempotency-Key') ?: ($payload['idempotency_key'] ?? null)
            );
        });
    }

    public function answerMystery(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamUuid = (string) ($payload['team_uuid'] ?? $this->request->getGet('team'));
        $optionId = (int) ($payload['option_id'] ?? 0);

        return $this->respond(function () use ($roomUuid, $teamUuid, $optionId, $payload): array {
            (new TeamSessionService())->assertTeamSession($roomUuid, $teamUuid);

            return (new GameEngine())->answerMystery(
                $roomUuid,
                $teamUuid,
                $optionId,
                $this->request->getHeaderLine('Idempotency-Key') ?: ($payload['idempotency_key'] ?? null)
            );
        });
    }

    public function answerBoardChallenge(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamUuid = (string) ($payload['team_uuid'] ?? $this->request->getGet('team'));
        $optionId = (int) ($payload['option_id'] ?? 0);

        return $this->respond(function () use ($roomUuid, $teamUuid, $optionId, $payload): array {
            (new TeamSessionService())->assertTeamSession($roomUuid, $teamUuid);

            return (new GameEngine())->answerBoardChallenge(
                $roomUuid,
                $teamUuid,
                $optionId,
                $this->request->getHeaderLine('Idempotency-Key') ?: ($payload['idempotency_key'] ?? null)
            );
        });
    }

    public function raceQuestionAnswer(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamUuid = (string) ($payload['team_uuid'] ?? $this->request->getGet('team'));
        $optionId = (int) ($payload['option_id'] ?? 0);

        return $this->respond(function () use ($roomUuid, $teamUuid, $optionId, $payload): array {
            (new TeamSessionService())->assertTeamSession($roomUuid, $teamUuid);

            return (new GameEngine())->raceQuestionAnswer(
                $roomUuid,
                $teamUuid,
                $optionId,
                $this->request->getHeaderLine('Idempotency-Key') ?: ($payload['idempotency_key'] ?? null)
            );
        });
    }

    public function resolveRaceQuestion(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $force = filter_var($payload['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $this->respond(function () use ($roomUuid, $force, $payload): array {
            (new TenantContext())->assertRoomOwner($roomUuid);
            if (! $force) {
                throw new DomainException('Force resolve wajib menyertakan { "force": true }.');
            }

            return (new GameEngine())->resolveRaceQuestion(
                $roomUuid,
                true,
                $this->request->getHeaderLine('Idempotency-Key') ?: ($payload['idempotency_key'] ?? null)
            );
        });
    }

    private function respond(callable $callback)
    {
        try {
            return $this->response->setJSON([
                'ok' => true,
                'data' => $callback(),
                'meta' => ['request_id' => service('request')->getHeaderLine('X-Request-ID') ?: bin2hex(random_bytes(6))],
            ]);
        } catch (DomainException $error) {
            return $this->response->setStatusCode(422)->setJSON([
                'ok' => false,
                'error' => [
                    'code' => 'DOMAIN_RULE_FAILED',
                    'message' => $error->getMessage(),
                ],
            ]);
        } catch (PageNotFoundException $error) {
            return $this->response->setStatusCode(404)->setJSON([
                'ok' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Data tidak ditemukan.',
                ],
            ]);
        } catch (Throwable $error) {
            log_message('error', $error->getMessage());

            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'error' => [
                    'code' => 'SERVER_ERROR',
                    'message' => 'Terjadi kesalahan server.',
                ],
            ]);
        }
    }
}
