<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateGameRoundQuestions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'round_id' => ['type' => 'INTEGER'],
            'question_number' => ['type' => 'INTEGER'],
            'question_id' => ['type' => 'INTEGER'],
            'difficulty' => ['type' => 'VARCHAR', 'constraint' => 20],
            'state' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'QUESTION_ACTIVE'],
            'answer_count' => ['type' => 'INTEGER', 'default' => 0],
            'started_at' => ['type' => 'DATETIME'],
            'started_at_epoch_ms' => ['type' => 'BIGINT'],
            'deadline_at' => ['type' => 'DATETIME'],
            'deadline_epoch_ms' => ['type' => 'BIGINT'],
            'paused_remaining_ms' => ['type' => 'INTEGER', 'null' => true],
            'resolved_at' => ['type' => 'DATETIME', 'null' => true],
            'reveal_until' => ['type' => 'DATETIME', 'null' => true],
            'reveal_until_epoch_ms' => ['type' => 'BIGINT', 'null' => true],
            'fastest_team_ids_json' => ['type' => 'TEXT', 'null' => true],
            'finisher_team_ids_json' => ['type' => 'TEXT', 'null' => true],
            'movement_summary_json' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->addUniqueKey(['round_id', 'question_number']);
        $this->forge->addKey(['round_id', 'state']);
        $this->forge->addKey('question_id');
        $this->forge->createTable('game_round_questions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('game_round_questions', true);
    }
}
