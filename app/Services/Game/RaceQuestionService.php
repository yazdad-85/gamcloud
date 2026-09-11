<?php

namespace App\Services\Game;

use DomainException;

final class RaceQuestionService
{
    public function __construct(private ?RaceTrackService $track = null)
    {
        $this->track ??= new RaceTrackService();
    }

    /**
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $answers
     * @return array{movements:list<array<string, mixed>>,fastest_team_ids:list<int|string>,finisher_team_ids:list<int|string>}
     */
    public function resolveMovements(array $teams, array $answers, array $room, array $board): array
    {
        $maxPosition = max(1, (int) ($room['max_position'] ?? 1));
        $answersByTeam = $this->answersByTeam($answers);
        $teamContexts = [];
        $fastestResponseMs = null;

        foreach (array_values($teams) as $team) {
            $teamId = $this->teamId($team);
            $answer = $answersByTeam[$this->teamKey($teamId)] ?? [
                'team_id' => $teamId,
                'outcome' => 'TIMEOUT',
                'is_correct' => false,
                'response_ms' => null,
            ];
            $effects = $this->teamEffects($team);
            $oilSpillConsumed = ! empty($effects['oil_spill_lock']);
            $isCorrect = $this->isCorrect($answer);
            $responseMs = $this->responseMs($answer);
            $fastestEligible = $isCorrect && ! $oilSpillConsumed && $responseMs !== null;

            if ($fastestEligible && ($fastestResponseMs === null || $responseMs < $fastestResponseMs)) {
                $fastestResponseMs = $responseMs;
            }

            $teamContexts[] = compact(
                'team',
                'teamId',
                'answer',
                'effects',
                'oilSpillConsumed',
                'isCorrect',
                'responseMs',
                'fastestEligible'
            );
        }

        $fastestTeamIds = [];
        foreach ($teamContexts as $context) {
            if ($context['fastestEligible'] && $context['responseMs'] === $fastestResponseMs) {
                $fastestTeamIds[] = $context['teamId'];
            }
        }

        $fastestKeys = array_fill_keys(array_map([$this, 'teamKey'], $fastestTeamIds), true);
        $movements = [];
        foreach ($teamContexts as $context) {
            $team = $context['team'];
            $teamId = $context['teamId'];
            $effects = $context['effects'];
            $effects['oil_spill_lock'] = false;

            $baseSteps = $context['isCorrect'] ? 1 : 0;
            $fastestBonusSteps = isset($fastestKeys[$this->teamKey($teamId)]) ? 2 : 0;
            $steps = $baseSteps + $fastestBonusSteps;
            $from = max(1, min($maxPosition, (int) ($team['position'] ?? 1)));
            $movement = $this->track->movementForSteps($from, $steps, $room, $board, 2);

            if (($movement['special'] ?? null) === 'OIL_SPILL') {
                $effects['oil_spill_lock'] = true;
            }

            $movements[] = $movement + [
                'team_id' => $teamId,
                'outcome' => $context['isCorrect'] ? 'CORRECT' : strtoupper((string) ($context['answer']['outcome'] ?? 'WRONG')),
                'response_ms' => $context['responseMs'],
                'base_steps' => $baseSteps,
                'fastest_bonus_steps' => $fastestBonusSteps,
                'steps' => $steps,
                'oil_spill_consumed' => $context['oilSpillConsumed'],
                'active_effects' => $effects,
                'finished' => $movement['to'] >= $maxPosition,
            ];
        }

        $finisherTeamIds = [];
        foreach ($movements as $movement) {
            if ($movement['finished']) {
                $finisherTeamIds[] = $movement['team_id'];
            }
        }

        return [
            'movements' => $movements,
            'fastest_team_ids' => $fastestTeamIds,
            'finisher_team_ids' => $finisherTeamIds,
        ];
    }

    /**
     * @param list<array<string, mixed>> $finisherStats
     * @return array{standings:list<array<string, mixed>>,winner_team_ids:list<int|string>}
     */
    public function rankFinishers(array $finisherStats): array
    {
        return $this->rank($finisherStats, [
            ['field' => 'score', 'aliases' => [], 'direction' => 'desc'],
            ['field' => 'correct_count', 'aliases' => [], 'direction' => 'desc'],
            ['field' => 'correct_response_ms', 'aliases' => ['correct_response_ms_sum'], 'direction' => 'asc'],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $teamStats
     * @return array{standings:list<array<string, mixed>>,winner_team_ids:list<int|string>}
     */
    public function rankQuestionLimit(array $teamStats): array
    {
        return $this->rank($teamStats, [
            ['field' => 'position', 'aliases' => [], 'direction' => 'desc'],
            ['field' => 'score', 'aliases' => [], 'direction' => 'desc'],
            ['field' => 'correct_count', 'aliases' => [], 'direction' => 'desc'],
            ['field' => 'correct_response_ms', 'aliases' => ['correct_response_ms_sum'], 'direction' => 'asc'],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $answers
     * @return array<string, array<string, mixed>>
     */
    private function answersByTeam(array $answers): array
    {
        $indexed = [];
        foreach ($answers as $answer) {
            if (! is_array($answer) || ! array_key_exists('team_id', $answer)) {
                throw new DomainException('Setiap jawaban Quiz Race harus memiliki team_id.');
            }
            $key = $this->teamKey($answer['team_id']);
            if (isset($indexed[$key])) {
                throw new DomainException('Setiap tim hanya boleh memiliki satu jawaban per soal.');
            }
            $indexed[$key] = $answer;
        }

        return $indexed;
    }

    private function teamId(array $team): int|string
    {
        $teamId = $team['team_id'] ?? $team['id'] ?? null;
        if (! is_int($teamId) && ! is_string($teamId)) {
            throw new DomainException('Data tim Quiz Race harus memiliki id atau team_id.');
        }

        return $teamId;
    }

    private function teamKey(int|string $teamId): string
    {
        return gettype($teamId) . ':' . (string) $teamId;
    }

    private function isCorrect(array $answer): bool
    {
        if (array_key_exists('is_correct', $answer)) {
            return filter_var($answer['is_correct'], FILTER_VALIDATE_BOOLEAN);
        }

        return strtoupper((string) ($answer['outcome'] ?? '')) === 'CORRECT';
    }

    private function responseMs(array $answer): ?int
    {
        if (! array_key_exists('response_ms', $answer) || $answer['response_ms'] === null) {
            return null;
        }

        return max(0, (int) $answer['response_ms']);
    }

    /**
     * Preserve existing Phase 11 effects while consuming only Oil Spill here.
     */
    private function teamEffects(array $team): array
    {
        if (is_array($team['active_effects'] ?? null)) {
            $effects = $team['active_effects'];
        } else {
            $effects = json_decode((string) ($team['active_effects_json'] ?? ''), true);
        }
        if (! is_array($effects)) {
            $effects = [];
        }
        $effects['oil_spill_lock'] = (bool) ($effects['oil_spill_lock'] ?? false);

        return $effects;
    }

    /**
     * @param list<array<string, mixed>> $stats
     * @param list<array{field:string,aliases:list<string>,direction:string}> $criteria
     * @return array{standings:list<array<string, mixed>>,winner_team_ids:list<int|string>}
     */
    private function rank(array $stats, array $criteria): array
    {
        $standings = [];
        foreach (array_values($stats) as $inputOrder => $entry) {
            if (! array_key_exists('team_id', $entry)) {
                throw new DomainException('Statistik Quiz Race harus memiliki team_id.');
            }
            foreach ($criteria as $criterion) {
                $entry[$criterion['field']] = $this->metric($entry, $criterion['field'], $criterion['aliases']);
            }
            $entry['_input_order'] = $inputOrder;
            $standings[] = $entry;
        }

        usort($standings, function (array $left, array $right) use ($criteria): int {
            foreach ($criteria as $criterion) {
                $comparison = $left[$criterion['field']] <=> $right[$criterion['field']];
                if ($comparison !== 0) {
                    return $criterion['direction'] === 'desc' ? -$comparison : $comparison;
                }
            }

            return $left['_input_order'] <=> $right['_input_order'];
        });

        $winnerTeamIds = [];
        $previous = null;
        foreach ($standings as $index => &$entry) {
            $isTie = $previous !== null && $this->sameMetrics($entry, $previous, $criteria);
            $entry['rank'] = $isTie ? $previous['rank'] : $index + 1;
            unset($entry['_input_order']);
            if ($entry['rank'] === 1) {
                $winnerTeamIds[] = $entry['team_id'];
            }
            $previous = $entry;
        }
        unset($entry);

        return ['standings' => $standings, 'winner_team_ids' => $winnerTeamIds];
    }

    private function metric(array $entry, string $field, array $aliases): int
    {
        foreach (array_merge([$field], $aliases) as $candidate) {
            if (array_key_exists($candidate, $entry)) {
                return (int) $entry[$candidate];
            }
        }

        return 0;
    }

    private function sameMetrics(array $left, array $right, array $criteria): bool
    {
        foreach ($criteria as $criterion) {
            if ($left[$criterion['field']] !== $right[$criterion['field']]) {
                return false;
            }
        }

        return true;
    }
}
