<?php

namespace App\Models;

use CodeIgniter\Model;

class GameRoundAnswerModel extends Model
{
    protected $table = 'game_round_answers';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'public_uuid',
        'round_question_id',
        'team_id',
        'question_id',
        'option_id',
        'answer_text',
        'is_correct',
        'outcome',
        'answered_at',
        'answered_at_epoch_ms',
        'response_ms',
        'score_delta',
        'score_breakdown_json',
    ];
    protected array $casts = [
        'id' => 'integer',
        'round_question_id' => 'integer',
        'team_id' => 'integer',
        'question_id' => 'integer',
        'option_id' => '?integer',
        'is_correct' => 'boolean',
        'answered_at_epoch_ms' => '?integer',
        'response_ms' => '?integer',
        'score_delta' => 'integer',
        'score_breakdown_json' => '?json-array',
    ];
    protected $useTimestamps = true;
}
