<?php

namespace App\Models;

use CodeIgniter\Model;

class GameEventModel extends Model
{
    protected $table = 'game_events';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['public_uuid', 'room_id', 'type', 'state_version', 'payload_json', 'created_at'];
}
