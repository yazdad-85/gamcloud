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
        'title',
        'status',
        'current_team_id',
        'state_version',
        'question_time_seconds',
        'redemption_time_seconds',
        'max_teams',
        'max_position',
        'game_mode',
        'mode_state_json',
        'turn_order_mode',
        'finish_rule',
        'scoring_json',
        'question_selection_json',
        'started_at',
        'finished_at',
        'expires_at',
    ];
    protected $useTimestamps = true;
}
