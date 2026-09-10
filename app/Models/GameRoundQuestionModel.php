<?php

namespace App\Models;

use CodeIgniter\Model;

class GameRoundQuestionModel extends Model
{
    protected $table = 'game_round_questions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'public_uuid',
        'round_id',
        'question_number',
        'question_id',
        'difficulty',
        'state',
        'answer_count',
        'started_at',
        'started_at_epoch_ms',
        'deadline_at',
        'deadline_epoch_ms',
        'paused_remaining_ms',
        'resolved_at',
        'reveal_until',
        'reveal_until_epoch_ms',
        'fastest_team_ids_json',
        'finisher_team_ids_json',
        'movement_summary_json',
    ];
    protected array $casts = [
        'id' => 'integer',
        'round_id' => 'integer',
        'question_number' => 'integer',
        'question_id' => 'integer',
        'answer_count' => 'integer',
        'started_at_epoch_ms' => 'integer',
        'deadline_epoch_ms' => 'integer',
        'paused_remaining_ms' => '?integer',
        'reveal_until_epoch_ms' => '?integer',
        'fastest_team_ids_json' => '?json-array',
        'finisher_team_ids_json' => '?json-array',
        'movement_summary_json' => '?json-array',
    ];
    protected $useTimestamps = true;
}
