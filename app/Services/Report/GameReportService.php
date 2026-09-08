<?php

namespace App\Services\Report;

use Config\Database;

class GameReportService
{
    public function roomReport(array $room): array
    {
        $db = Database::connect();
        $teams = $db->table('game_teams')
            ->where('room_id', $room['id'])
            ->orderBy('score', 'DESC')
            ->orderBy('position', 'DESC')
            ->get()
            ->getResultArray();

        $answers = $db->table('game_answers ga')
            ->select('ga.*, gt.name AS team_name, gt.color AS team_color, q.stem AS question_stem, qo.label AS option_label')
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

        $questionStats = $db->table('game_answers ga')
            ->select('q.stem, COUNT(*) AS total_answers, SUM(CASE WHEN ga.is_correct = 1 THEN 1 ELSE 0 END) AS correct_answers')
            ->join('questions q', 'q.id = ga.question_id')
            ->join('game_teams gt', 'gt.id = ga.team_id')
            ->where('gt.room_id', $room['id'])
            ->groupBy('q.id, q.stem')
            ->orderBy('total_answers', 'DESC')
            ->get()
            ->getResultArray();

        return [
            'room' => $room,
            'teams' => $teams,
            'answers' => $answers,
            'events' => array_map(static fn (array $row): array => json_decode((string) $row['payload_json'], true) ?: [], $events),
            'questionStats' => $questionStats,
        ];
    }
}
