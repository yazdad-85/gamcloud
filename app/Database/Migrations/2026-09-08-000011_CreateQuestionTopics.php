<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateQuestionTopics extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'owner_teacher_id' => ['type' => 'INTEGER'],
            'name' => ['type' => 'VARCHAR', 'constraint' => 140],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->addKey(['owner_teacher_id']);
        $this->forge->createTable('question_topics', true);

        $this->forge->addColumn('questions', [
            'topic_id' => [
                'type' => 'INTEGER',
                'null' => true,
                'after' => 'owner_teacher_id',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('questions', 'topic_id');
        $this->forge->dropTable('question_topics', true);
    }
}
