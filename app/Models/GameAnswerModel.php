<?php

namespace App\Models;

use CodeIgniter\Model;

class GameAnswerModel extends Model
{
    protected $table = 'game_answers';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['turn_id', 'team_id', 'question_id', 'option_id', 'answer_text', 'is_correct', 'answered_at', 'response_ms'];
}
