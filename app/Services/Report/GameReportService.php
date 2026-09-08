<?php

namespace App\Services\Report;

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

        $answers = $db->table('game_answers ga')
            ->select('ga.*, gt.name AS team_name, gt.color AS team_color, gt.public_uuid AS team_uuid, q.stem AS question_stem, qo.label AS option_label')
            ->join('game_teams gt', 'gt.id = ga.team_id')
            ->join('questions q', 'q.id = ga.question_id')
            ->join('question_options qo', 'qo.id = ga.option_id', 'left')
            ->where('gt.room_id', $room['id'])
            ->orderBy('ga.id', 'ASC')
            ->get()
            ->getResultArray();

        $events = $db->table('game_events')
            ->where('room_id', $room['id'])
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $decodedEvents = array_map(
            static fn (array $row): array => json_decode((string) $row['payload_json'], true) ?: [],
            $events
        );

        $questionStats = $db->table('game_answers ga')
            ->select('q.id AS question_id, q.stem, COUNT(*) AS total_answers, SUM(CASE WHEN ga.is_correct = 1 THEN 1 ELSE 0 END) AS correct_answers')
            ->join('questions q', 'q.id = ga.question_id')
            ->join('game_teams gt', 'gt.id = ga.team_id')
            ->where('gt.room_id', $room['id'])
            ->groupBy('q.id, q.stem')
            ->orderBy('total_answers', 'DESC')
            ->get()
            ->getResultArray();

        $winnerUuid = $this->winnerTeamUuid($decodedEvents, $teams, $room);
        $teams = $this->decorateAndSortTeams($teams, $winnerUuid);
        $winner = null;
        foreach ($teams as $team) {
            if (! empty($team['is_board_winner'])) {
                $winner = $team;
                break;
            }
        }

        $charts = $this->buildCharts($questionStats, $answers, $teams);
        $soalPaginated = $this->paginate($questionStats, $soalPage, 10);
        $jawabPaginated = $this->paginate($answers, $jawabPage, 15);

        return [
            'room' => $room,
            'winner' => $winner,
            'teams' => $teams,
            'answers' => $answers,
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

    private function winnerTeamUuid(array $events, array $teams, array $room): ?string
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $event = $events[$i];
            if (($event['event'] ?? '') === 'game.finished') {
                $uuid = $event['payload']['winner_team_uuid'] ?? null;
                if (is_string($uuid) && $uuid !== '') {
                    return $uuid;
                }
            }
        }

        if (($room['status'] ?? '') !== 'FINISHED') {
            return null;
        }

        $max = (int) ($room['max_position'] ?? 0);
        foreach ($teams as $team) {
            if ($max > 0 && (int) $team['position'] >= $max) {
                return (string) $team['public_uuid'];
            }
        }

        return null;
    }

    private function decorateAndSortTeams(array $teams, ?string $winnerUuid): array
    {
        foreach ($teams as &$team) {
            $team['is_board_winner'] = $winnerUuid !== null && (string) $team['public_uuid'] === $winnerUuid;
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
