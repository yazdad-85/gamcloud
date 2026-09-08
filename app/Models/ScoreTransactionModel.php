<?php

namespace App\Models;

use CodeIgniter\Model;

class ScoreTransactionModel extends Model
{
    protected $table = 'score_transactions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['room_id', 'team_id', 'type', 'points', 'reason', 'created_at'];
}
