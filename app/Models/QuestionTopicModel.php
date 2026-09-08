<?php

namespace App\Models;

use CodeIgniter\Model;

class QuestionTopicModel extends Model
{
    protected $table = 'question_topics';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['public_uuid', 'owner_teacher_id', 'name'];
    protected $useTimestamps = true;
}
