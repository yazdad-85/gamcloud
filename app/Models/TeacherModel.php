<?php

namespace App\Models;

use CodeIgniter\Model;

class TeacherModel extends Model
{
    protected $table = 'teachers';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['public_uuid', 'auth_user_id', 'name', 'email', 'role', 'last_login_at'];
    protected $useTimestamps = true;
}
