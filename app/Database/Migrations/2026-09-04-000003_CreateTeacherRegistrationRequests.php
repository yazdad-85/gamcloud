<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTeacherRegistrationRequests extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'auth_user_id' => ['type' => 'INTEGER', 'null' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 140],
            'email' => ['type' => 'VARCHAR', 'constraint' => 190],
            'school_name' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'PENDING_EMAIL'],
            'verification_code_hash' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'verification_expires_at' => ['type' => 'DATETIME', 'null' => true],
            'verification_attempts' => ['type' => 'INTEGER', 'default' => 0],
            'verified_at' => ['type' => 'DATETIME', 'null' => true],
            'approved_at' => ['type' => 'DATETIME', 'null' => true],
            'rejected_at' => ['type' => 'DATETIME', 'null' => true],
            'reviewed_by_user_id' => ['type' => 'INTEGER', 'null' => true],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'last_sent_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->addKey(['email', 'status']);
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->createTable('teacher_registration_requests', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('teacher_registration_requests', true);
    }
}
