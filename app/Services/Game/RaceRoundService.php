<?php

namespace App\Services\Game;

use DomainException;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class RaceRoundService
{
    private const DEFAULT_ALLOCATION = [15, 15, 20];
    private const DIFFICULTIES = ['EASY', 'MEDIUM', 'HARD'];
    private const REMAINDER_ORDER = ['EASY', 'HARD', 'MEDIUM'];

    /**
     * @return list<int>
     */
    public function normalizeAllocation(mixed $source): array
    {
        if ($source === null || $source === '') {
            return self::DEFAULT_ALLOCATION;
        }

        if (is_string($source)) {
            $source = json_decode($source, true);
        }

        if (! is_array($source) || ! array_is_list($source) || count($source) < 1 || count($source) > 5) {
            throw new DomainException('Alokasi Quiz Race harus terdiri dari 1 sampai 5 ronde.');
        }

        $allocation = [];
        foreach ($source as $questionCount) {
            if (is_string($questionCount) && preg_match('/^[0-9]+$/', $questionCount) === 1) {
                $questionCount = (int) $questionCount;
            }
            if (! is_int($questionCount) || $questionCount < 1) {
                throw new DomainException('Setiap ronde Quiz Race harus memiliki minimal 1 soal.');
            }
            $allocation[] = $questionCount;
        }

        $total = array_sum($allocation);
        if ($total < 3 || $total > 100) {
            throw new DomainException('Total alokasi Quiz Race harus antara 3 dan 100 soal.');
        }

        return $allocation;
    }

    /**
     * @return list<string>
     */
    public function difficultySchedule(int $questionCount, ?int $seed = null): array
    {
        if ($questionCount < 1 || $questionCount > 100) {
            throw new DomainException('Jumlah soal ronde harus antara 1 dan 100.');
        }

        $counts = array_fill_keys(self::DIFFICULTIES, intdiv($questionCount, count(self::DIFFICULTIES)));
        $remainder = $questionCount % count(self::DIFFICULTIES);
        for ($index = 0; $index < $remainder; $index++) {
            $counts[self::REMAINDER_ORDER[$index]]++;
        }

        $schedule = [];
        foreach (self::DIFFICULTIES as $difficulty) {
            $schedule = array_merge($schedule, array_fill(0, $counts[$difficulty], $difficulty));
        }

        $randomizer = $seed === null
            ? new Randomizer()
            : new Randomizer(new Mt19937($seed));

        return $randomizer->shuffleArray($schedule);
    }

    /**
     * @param list<array<string, mixed>> $teamRoundStats
     * @return array{standings:list<array<string, mixed>>,winner_team_ids:list<int|string>}
     */
    public function rankRound(array $teamRoundStats): array
    {
        return $this->rank($teamRoundStats, [
            ['field' => 'score_delta', 'aliases' => ['round_score', 'score_earned'], 'direction' => 'desc'],
            ['field' => 'correct_count', 'aliases' => [], 'direction' => 'desc'],
            ['field' => 'correct_response_ms', 'aliases' => ['correct_response_ms_sum'], 'direction' => 'asc'],
        ]);
    }

    /**
     * @param list<int|string> $winnerTeamIds
     * @return list<array{team_id:int|string,score_delta:int,movement_delta:int}>
     */
    public function roundPrizeDeltas(array $winnerTeamIds, int $bonusPoints, bool $interrupted = false): array
    {
        if ($interrupted || $bonusPoints <= 0) {
            return [];
        }

        $prizes = [];
        $seen = [];
        foreach ($winnerTeamIds as $teamId) {
            $key = gettype($teamId) . ':' . (string) $teamId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $prizes[] = [
                'team_id' => $teamId,
                'score_delta' => $bonusPoints,
                'movement_delta' => 0,
            ];
        }

        return $prizes;
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
                throw new DomainException('Statistik ronde harus memiliki team_id.');
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

    /**
     * @param list<string> $aliases
     */
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
