<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePlatformSettingsAndTeacherSchool extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'value' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('key');
        $this->forge->createTable('platform_settings', true);

        $this->forge->addColumn('teachers', [
            'school_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
                'after'      => 'email',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('teachers', 'school_name');
        $this->forge->dropTable('platform_settings', true);
    }
}
