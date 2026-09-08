<?php

namespace App\Models;

use CodeIgniter\Model;

class IdempotencyKeyModel extends Model
{
    protected $table = 'idempotency_keys';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['scope', 'key_hash', 'response_json', 'created_at', 'expires_at'];
}
