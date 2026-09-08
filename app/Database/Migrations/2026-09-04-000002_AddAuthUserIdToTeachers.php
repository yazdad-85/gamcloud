<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAuthUserIdToTeachers extends Migration
{
    public function up(): void
    {
        $fields = [
            'auth_user_id' => [
                'type' => 'INTEGER',
                'null' => true,
                'after' => 'public_uuid',
            ],
            'last_login_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'role',
            ],
        ];

        $this->forge->addColumn('teachers', $fields);
        $this->forge->addKey('auth_user_id');
    }

    public function down(): void
    {
        $this->forge->dropColumn('teachers', ['auth_user_id', 'last_login_at']);
    }
}
