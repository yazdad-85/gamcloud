<?php

namespace App\Models;

use CodeIgniter\Model;

class BoardTemplateModel extends Model
{
    protected $table = 'board_templates';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['public_uuid', 'name', 'tile_count', 'ladders_json', 'snakes_json', 'special_tiles_json', 'theme_json', 'status'];
    protected $useTimestamps = true;
}
