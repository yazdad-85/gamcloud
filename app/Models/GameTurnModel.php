<?php

namespace App\Models;

use CodeIgniter\Model;

class GameTurnModel extends Model
{
    protected $table = 'game_turns';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'public_uuid',
        'room_id',
        'team_id',
        'state',
        'turn_number',
        'dice_value',
        'question_id',
        'question_started_at',
        'question_deadline_at',
        'answer_is_correct',
    ];
    protected $useTimestamps = true;
}
