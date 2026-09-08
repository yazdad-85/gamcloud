<?php

namespace App\Models;

use CodeIgniter\Model;

class RealtimeOutboxModel extends Model
{
    protected $table = 'realtime_outbox';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['room_id', 'channel', 'event', 'payload_json', 'status', 'attempts', 'last_error', 'created_at', 'sent_at'];
}
