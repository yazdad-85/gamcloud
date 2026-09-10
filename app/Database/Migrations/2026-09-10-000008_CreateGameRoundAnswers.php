<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateGameRoundAnswers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'round_question_id' => ['type' => 'INTEGER'],
            'team_id' => ['type' => 'INTEGER'],
            'question_id' => ['type' => 'INTEGER'],
            'option_id' => ['type' => 'INTEGER', 'null' => true],
            'answer_text' => ['type' => 'TEXT', 'null' => true],
            'is_correct' => ['type' => 'INTEGER', 'default' => 0],
            'outcome' => ['type' => 'VARCHAR', 'constraint' => 20],
            'answered_at' => ['type' => 'DATETIME', 'null' => true],
            'answered_at_epoch_ms' => ['type' => 'BIGINT', 'null' => true],
            'response_ms' => ['type' => 'INTEGER', 'null' => true],
            'score_delta' => ['type' => 'INTEGER', 'default' => 0],
            'score_breakdown_json' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->addUniqueKey(['round_question_id', 'team_id']);
        $this->forge->addKey(['round_question_id', 'is_correct', 'response_ms']);
        $this->forge->addKey('team_id');
        $this->forge->addKey('question_id');
        $this->forge->createTable('game_round_answers', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('game_round_answers', true);
    }
}
