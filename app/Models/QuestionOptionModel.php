<?php

namespace App\Models;

use CodeIgniter\Model;

class QuestionOptionModel extends Model
{
    protected $table = 'question_options';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['question_id', 'label', 'body', 'media_json', 'is_correct', 'sort_order'];
}
