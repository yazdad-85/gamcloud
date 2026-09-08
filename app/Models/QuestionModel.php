<?php

namespace App\Models;

use CodeIgniter\Model;

class QuestionModel extends Model
{
    protected $table = 'questions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'public_uuid',
        'owner_teacher_id',
        'topic_id',
        'source_type',
        'question_type',
        'stem',
        'difficulty',
        'status',
        'points',
        'time_limit_seconds',
        'explanation',
        'meta_json',
    ];
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;
}
