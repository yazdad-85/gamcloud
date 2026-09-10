<?php

namespace App\Models;

use CodeIgniter\Model;

class GameRoundModel extends Model
{
    protected $table = 'game_rounds';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'public_uuid',
        'room_id',
        'round_number',
        'state',
        'question_target_count',
        'question_resolved_count',
        'difficulty_schedule_json',
        'round_winner_team_ids_json',
        'round_score_summary_json',
        'started_at',
        'completed_at',
        'reveal_until',
        'reveal_until_epoch_ms',
        'paused_remaining_ms',
    ];
    protected array $casts = [
        'id' => 'integer',
        'room_id' => 'integer',
        'round_number' => 'integer',
        'question_target_count' => 'integer',
        'question_resolved_count' => 'integer',
        'difficulty_schedule_json' => 'json-array',
        'round_winner_team_ids_json' => '?json-array',
        'round_score_summary_json' => '?json-array',
        'reveal_until_epoch_ms' => '?integer',
        'paused_remaining_ms' => '?integer',
    ];
    protected $useTimestamps = true;
}
