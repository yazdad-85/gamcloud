<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformSettingModel extends Model
{
    protected $table         = 'platform_settings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['key', 'value', 'updated_at'];
    protected $useTimestamps = false;
}
