<?php

namespace App\Services\Report;

use App\Models\GameRoundModel;
use Config\Database;

class GameReportService
{
    public function roomReport(array $room, array $pages = []): array
    {
        $soalPage = max(1, (int) ($pages['soal_page'] ?? 1));
        $jawabPage = max(1, (int) ($pages['jawab_page'] ?? 1));

        $db = Database::connect();
        $teams = $db->table('game_teams')
            ->where('room_id', $room['id'])
            ->get()
            ->getResultArray();

        $turnAnswers = $db->table('game_answers ga')
            ->select('ga.*, gt.name AS team_name, gt.color AS team_color, gt.public_uuid AS team_uuid, q.stem AS question_stem, q.difficulty, qo.label AS option_label, gturn.turn_number')
            ->join('game_teams gt', 'gt.id = ga.team_id')
            ->join('game_turns gturn', 'gturn.id = ga.turn_id')
            ->join('questions q', 'q.id = ga.question_id')
            ->join('question_options qo', 'qo.id = ga.option_id', 'left')
            ->where('gt.room_id', $room['id'])
            ->orderBy('ga.id', 'ASC')
            ->get()
            ->getResultArray();
        $raceAnswers = $db->table('game_round_answers gra')
            ->select('gra.*, gt.name AS team_name, gt.color AS team_color, gt.public_uuid AS team_uuid, q.stem AS question_stem, q.difficulty, qo.label AS option_label, gr.round_number, grq.question_number')
            ->join('game_round_questions grq', 'grq.id = gra.round_question_id')
            ->join('game_rounds gr', 'gr.id = grq.round_id')
            ->join('game_teams gt', 'gt.id = gra.team_id')
            ->join('questions q', 'q.id = gra.question_id')
            ->join('question_options qo', 'qo.id = gra.option_id', 'left')
            ->where('gr.room_id', $room['id'])
            ->orderBy('gra.id', 'ASC')
            ->get()
            ->getResultArray();
        $answers = $this->normalizeAnswers($turnAnswers, $raceAnswers);

        $events = $db->table('game_events')
            ->where('room_id', $room['id'])
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $decodedEvents = array_map(
            static fn (array $row): array => json_decode((string) $row['payload_json'], true) ?: [],
            $events
        );

        $questionStats = $this->questionStats($answers);

        $finish = $this->finishMetadata($decodedEvents, $teams, $room);
        $teams = $this->decorateAndSortTeams($teams, $finish['winner_uuids']);
        $teamsByUuid = array_column($teams, null, 'public_uuid');
        $winners = array_values(array_filter(array_map(
            static fn (string $uuid): ?array => $teamsByUuid[$uuid] ?? null,
            $finish['winner_uuids']
        )));
        $winner = $winners[0] ?? null;
        $rounds = $this->rounds($room, $teamsByUuid);

        $charts = $this->buildCharts($questionStats, $answers, $teams);
        $soalPaginated = $this->paginate($questionStats, $soalPage, 10);
        $jawabPaginated = $this->paginate($answers, $jawabPage, 15);

        return [
            'room' => $room,
            'winner' => $winner,
            'winners' => $winners,
            'winner_uuids' => $finish['winner_uuids'],
            'finish_reason' => $finish['finish_reason'],
            'teams' => $teams,
            'answers' => $answers,
            'rounds' => $rounds,
            'events' => $decodedEvents,
            'questionStats' => $questionStats,
            'charts' => $charts,
            'questionStatsPage' => $soalPaginated,
            'answersPage' => $jawabPaginated,
        ];
    }

    public function paginate(array $items, int $page, int $perPage): array
    {
        $total = count($items);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    private function normalizeAnswers(array $turnAnswers, array $raceAnswers): array
    {
        $answers = [];
        foreach ($turnAnswers as $answer) {
            $answer['source'] = 'TURN';
            $answer['round_number'] = null;
            $answer['question_number'] = (int) $answer['turn_number'];
            $answer['outcome'] = (int) $answer['is_correct'] === 1
                ? 'CORRECT'
                : ($answer['option_id'] === null ? 'TIMEOUT' : 'WRONG');
            $answer['response_ms'] = $answer['response_ms'] !== null ? (int) $answer['response_ms'] : null;
            $answer['score_delta'] = null;
            $answer['score_breakdown'] = [];
            $answer['_sort_epoch_ms'] = $this->answerEpochMs($answer);
            $answers[] = $answer;
        }
        foreach ($raceAnswers as $answer) {
            $answer['source'] = 'RACE_ROUND';
            $answer['round_number'] = (int) $answer['round_number'];
            $answer['question_number'] = (int) $answer['question_number'];
            $answer['response_ms'] = $answer['response_ms'] !== null ? (int) $answer['response_ms'] : null;
            $answer['score_delta'] = (int) $answer['score_delta'];
            $answer['score_breakdown'] = $this->decodeArray($answer['score_breakdown_json'] ?? null);
            $answer['_sort_epoch_ms'] = $this->answerEpochMs($answer);
            $answers[] = $answer;
        }

        usort($answers, static function (array $a, array $b): int {
            $time = $a['_sort_epoch_ms'] <=> $b['_sort_epoch_ms'];
            if ($time !== 0) {
                return $time;
            }

            return (int) $a['id'] <=> (int) $b['id'];
        });
        foreach ($answers as &$answer) {
            unset($answer['_sort_epoch_ms']);
        }
        unset($answer);

        return $answers;
    }

    private function answerEpochMs(array $answer): int
    {
        if (isset($answer['answered_at_epoch_ms']) && $answer['answered_at_epoch_ms'] !== null) {
            return (int) $answer['answered_at_epoch_ms'];
        }

        $timestamp = strtotime((string) ($answer['answered_at'] ?? ''));

        return $timestamp === false ? 0 : $timestamp * 1000;
    }

    private function questionStats(array $answers): array
    {
        $stats = [];
        foreach ($answers as $answer) {
            $questionId = (int) $answer['question_id'];
            if (! isset($stats[$questionId])) {
                $stats[$questionId] = [
                    'question_id' => $questionId,
                    'stem' => (string) $answer['question_stem'],
                    'total_answers' => 0,
                    'correct_answers' => 0,
                ];
            }
            $stats[$questionId]['total_answers']++;
            if ((int) $answer['is_correct'] === 1) {
                $stats[$questionId]['correct_answers']++;
            }
        }

        $stats = array_values($stats);
        usort($stats, static fn (array $a, array $b): int => $b['total_answers'] <=> $a['total_answers']);

        return $stats;
    }

    private function finishMetadata(array $events, array $teams, array $room): array
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $event = $events[$i];
            if (($event['event'] ?? '') !== 'game.finished') {
                continue;
            }
            $payload = $event['payload'] ?? [];
            $winnerUuids = array_values(array_filter(
                $payload['winner_team_uuids'] ?? [],
                static fn ($uuid): bool => is_string($uuid) && $uuid !== ''
            ));
            $legacyWinner = $payload['winner_team_uuid'] ?? null;
            if ($winnerUuids === [] && is_string($legacyWinner) && $legacyWinner !== '') {
                $winnerUuids[] = $legacyWinner;
            }

            return [
                'finish_reason' => is_string($payload['finish_reason'] ?? null) ? $payload['finish_reason'] : null,
                'winner_uuids' => array_values(array_unique($winnerUuids)),
            ];
        }

        $winnerUuids = [];
        if (($room['status'] ?? '') === 'FINISHED') {
            $max = (int) ($room['max_position'] ?? 0);
            foreach ($teams as $team) {
                if ($max > 0 && (int) $team['position'] >= $max) {
                    $winnerUuids[] = (string) $team['public_uuid'];
                }
            }
        }

        return [
            'finish_reason' => $winnerUuids === [] ? null : 'TRACK_FINISH',
            'winner_uuids' => $winnerUuids,
        ];
    }

    private function decorateAndSortTeams(array $teams, array $winnerUuids): array
    {
        foreach ($teams as &$team) {
            $team['is_board_winner'] = in_array((string) $team['public_uuid'], $winnerUuids, true);
        }
        unset($team);

        usort($teams, static function (array $a, array $b): int {
            $pos = (int) $b['position'] <=> (int) $a['position'];
            if ($pos !== 0) {
                return $pos;
            }

            return (int) $b['score'] <=> (int) $a['score'];
        });

        return $teams;
    }

    private function rounds(array $room, array $teamsByUuid): array
    {
        $teamsById = [];
        foreach ($teamsByUuid as $team) {
            $teamsById[(int) $team['id']] = $team;
        }

        $rounds = (new GameRoundModel())
            ->where('room_id', $room['id'])
            ->orderBy('round_number', 'ASC')
            ->findAll();
        foreach ($rounds as &$round) {
            $winnerUuids = [];
            $winnerNames = [];
            foreach ($round['round_winner_team_ids_json'] ?? [] as $teamId) {
                if (! isset($teamsById[(int) $teamId])) {
                    continue;
                }
                $winnerUuids[] = (string) $teamsById[(int) $teamId]['public_uuid'];
                $winnerNames[] = (string) $teamsById[(int) $teamId]['name'];
            }
            $round['winner_team_uuids'] = $winnerUuids;
            $round['winner_team_names'] = $winnerNames;
            $round['score_summary'] = $round['round_score_summary_json'] ?? [];
            $round['prize_points'] = $winnerUuids === [] ? 0 : (int) ($room['race_round_winner_bonus_points'] ?? 0);
        }
        unset($round);

        return $rounds;
    }

    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function buildCharts(array $questionStats, array $answers, array $teams): array
    {
        $accuracyLabels = [];
        $accuracyValues = [];
        foreach ($questionStats as $stat) {
            $stem = (string) $stat['stem'];
            $accuracyLabels[] = mb_strlen($stem) > 40 ? mb_substr($stem, 0, 37) . '...' : $stem;
            $total = (int) $stat['total_answers'];
            $correct = (int) $stat['correct_answers'];
            $accuracyValues[] = $total > 0 ? (int) round(($correct / $total) * 100) : 0;
        }

        $correctByTeam = [];
        $wrongByTeam = [];
        foreach ($teams as $team) {
            $correctByTeam[$team['public_uuid']] = 0;
            $wrongByTeam[$team['public_uuid']] = 0;
        }
        foreach ($answers as $answer) {
            $uuid = (string) ($answer['team_uuid'] ?? '');
            if ($uuid === '' || ! array_key_exists($uuid, $correctByTeam)) {
                continue;
            }
            if ((int) $answer['is_correct'] === 1) {
                $correctByTeam[$uuid]++;
            } else {
                $wrongByTeam[$uuid]++;
            }
        }

        $teamLabels = [];
        $correctSeries = [];
        $wrongSeries = [];
        foreach ($teams as $team) {
            $teamLabels[] = $team['name'];
            $correctSeries[] = $correctByTeam[$team['public_uuid']] ?? 0;
            $wrongSeries[] = $wrongByTeam[$team['public_uuid']] ?? 0;
        }

        return [
            'accuracy' => [
                'labels' => $accuracyLabels,
                'values' => $accuracyValues,
            ],
            'team_results' => [
                'labels' => $teamLabels,
                'correct' => $correctSeries,
                'wrong' => $wrongSeries,
            ],
        ];
    }
}
