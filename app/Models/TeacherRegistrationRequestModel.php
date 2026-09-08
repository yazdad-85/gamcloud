<?php

namespace App\Models;

use CodeIgniter\Model;

class TeacherRegistrationRequestModel extends Model
{
    protected $table = 'teacher_registration_requests';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'public_uuid',
        'auth_user_id',
        'name',
        'email',
        'school_name',
        'status',
        'verification_code_hash',
        'verification_expires_at',
        'verification_attempts',
        'verified_at',
        'approved_at',
        'rejected_at',
        'reviewed_by_user_id',
        'ip_address',
        'user_agent',
        'last_sent_at',
    ];
    protected $useTimestamps = true;
}
