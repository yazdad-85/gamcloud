<?php

namespace App\Models;

use CodeIgniter\Model;

class GameTeamModel extends Model
{
    protected $table = 'game_teams';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'public_uuid',
        'room_id',
        'name',
        'color',
        'avatar',
        'session_token_hash',
        'position',
        'score',
        'streak_count',
        'active_effects_json',
        'is_connected',
        'joined_at',
    ];
    protected $useTimestamps = true;
}
