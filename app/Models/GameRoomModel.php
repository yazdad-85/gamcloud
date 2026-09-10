<?php

namespace App\Models;

use CodeIgniter\Model;

class GameRoomModel extends Model
{
    protected $table = 'game_rooms';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'public_uuid',
        'teacher_id',
        'board_template_id',
        'pin',
        'projector_token',
        'projector_token_hash',
        'title',
        'status',
        'current_team_id',
        'state_version',
        'question_time_seconds',
        'redemption_time_seconds',
        'max_teams',
        'max_position',
        'lap_count',
        'game_mode',
        'participation_mode',
        'mode_state_json',
        'turn_order_mode',
        'finish_rule',
        'scoring_json',
        'question_selection_json',
        'race_question_limit',
        'race_round_question_counts_json',
        'race_round_winner_bonus_points',
        'started_at',
        'finished_at',
        'expires_at',
    ];
    protected array $casts = [
        'race_question_limit' => '?integer',
        'race_round_question_counts_json' => '?json-array',
        'race_round_winner_bonus_points' => '?integer',
    ];
    protected $useTimestamps = true;
}
