<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateGameRounds extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'room_id' => ['type' => 'INTEGER'],
            'round_number' => ['type' => 'INTEGER'],
            'state' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'ROUND_ACTIVE'],
            'question_target_count' => ['type' => 'INTEGER'],
            'question_resolved_count' => ['type' => 'INTEGER', 'default' => 0],
            'difficulty_schedule_json' => ['type' => 'TEXT'],
            'round_winner_team_ids_json' => ['type' => 'TEXT', 'null' => true],
            'round_score_summary_json' => ['type' => 'TEXT', 'null' => true],
            'started_at' => ['type' => 'DATETIME', 'null' => true],
            'completed_at' => ['type' => 'DATETIME', 'null' => true],
            'reveal_until' => ['type' => 'DATETIME', 'null' => true],
            'reveal_until_epoch_ms' => ['type' => 'BIGINT', 'null' => true],
            'paused_remaining_ms' => ['type' => 'INTEGER', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->addUniqueKey(['room_id', 'round_number']);
        $this->forge->addKey(['room_id', 'state']);
        $this->forge->createTable('game_rounds', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('game_rounds', true);
    }
}
