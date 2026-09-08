<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateGameSchema extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'name' => ['type' => 'VARCHAR', 'constraint' => 140],
            'email' => ['type' => 'VARCHAR', 'constraint' => 190],
            'role' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'teacher'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->addUniqueKey('email');
        $this->forge->createTable('teachers', true);

        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'owner_teacher_id' => ['type' => 'INTEGER', 'null' => true],
            'source_type' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PERSONAL'],
            'question_type' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'MULTIPLE_CHOICE'],
            'stem' => ['type' => 'TEXT'],
            'difficulty' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'MEDIUM'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PUBLISHED'],
            'points' => ['type' => 'INTEGER', 'default' => 100],
            'time_limit_seconds' => ['type' => 'INTEGER', 'default' => 30],
            'explanation' => ['type' => 'TEXT', 'null' => true],
            'meta_json' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->addKey(['owner_teacher_id', 'source_type', 'status']);
        $this->forge->createTable('questions', true);

        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'question_id' => ['type' => 'INTEGER'],
            'label' => ['type' => 'VARCHAR', 'constraint' => 8],
            'body' => ['type' => 'TEXT'],
            'is_correct' => ['type' => 'INTEGER', 'default' => 0],
            'sort_order' => ['type' => 'INTEGER', 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('question_id');
        $this->forge->createTable('question_options', true);

        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'name' => ['type' => 'VARCHAR', 'constraint' => 140],
            'tile_count' => ['type' => 'INTEGER', 'default' => 100],
            'ladders_json' => ['type' => 'TEXT'],
            'snakes_json' => ['type' => 'TEXT'],
            'theme_json' => ['type' => 'TEXT', 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'ACTIVE'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->createTable('board_templates', true);

        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'teacher_id' => ['type' => 'INTEGER'],
            'board_template_id' => ['type' => 'INTEGER'],
            'pin' => ['type' => 'VARCHAR', 'constraint' => 8],
            'title' => ['type' => 'VARCHAR', 'constraint' => 180],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'LOBBY'],
            'current_team_id' => ['type' => 'INTEGER', 'null' => true],
            'state_version' => ['type' => 'INTEGER', 'default' => 1],
            'question_time_seconds' => ['type' => 'INTEGER', 'default' => 30],
            'redemption_time_seconds' => ['type' => 'INTEGER', 'default' => 10],
            'max_teams' => ['type' => 'INTEGER', 'default' => 6],
            'max_position' => ['type' => 'INTEGER', 'default' => 100],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'started_at' => ['type' => 'DATETIME', 'null' => true],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
            'expires_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->addUniqueKey('pin');
        $this->forge->addKey(['teacher_id', 'status']);
        $this->forge->createTable('game_rooms', true);

        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'room_id' => ['type' => 'INTEGER'],
            'name' => ['type' => 'VARCHAR', 'constraint' => 80],
            'color' => ['type' => 'VARCHAR', 'constraint' => 20],
            'avatar' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'pawn'],
            'session_token_hash' => ['type' => 'VARCHAR', 'constraint' => 128],
            'position' => ['type' => 'INTEGER', 'default' => 1],
            'score' => ['type' => 'INTEGER', 'default' => 0],
            'is_connected' => ['type' => 'INTEGER', 'default' => 1],
            'joined_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->addKey(['room_id', 'score']);
        $this->forge->createTable('game_teams', true);

        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'room_id' => ['type' => 'INTEGER'],
            'team_id' => ['type' => 'INTEGER'],
            'state' => ['type' => 'VARCHAR', 'constraint' => 40],
            'turn_number' => ['type' => 'INTEGER'],
            'dice_value' => ['type' => 'INTEGER', 'null' => true],
            'question_id' => ['type' => 'INTEGER', 'null' => true],
            'question_started_at' => ['type' => 'DATETIME', 'null' => true],
            'question_deadline_at' => ['type' => 'DATETIME', 'null' => true],
            'answer_is_correct' => ['type' => 'INTEGER', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->addKey(['room_id', 'state']);
        $this->forge->createTable('game_turns', true);

        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'turn_id' => ['type' => 'INTEGER'],
            'team_id' => ['type' => 'INTEGER'],
            'question_id' => ['type' => 'INTEGER'],
            'option_id' => ['type' => 'INTEGER', 'null' => true],
            'answer_text' => ['type' => 'TEXT', 'null' => true],
            'is_correct' => ['type' => 'INTEGER', 'default' => 0],
            'answered_at' => ['type' => 'DATETIME', 'null' => true],
            'response_ms' => ['type' => 'INTEGER', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['turn_id', 'team_id']);
        $this->forge->createTable('game_answers', true);

        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'room_id' => ['type' => 'INTEGER'],
            'team_id' => ['type' => 'INTEGER'],
            'type' => ['type' => 'VARCHAR', 'constraint' => 40],
            'points' => ['type' => 'INTEGER'],
            'reason' => ['type' => 'VARCHAR', 'constraint' => 190],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['room_id', 'team_id']);
        $this->forge->createTable('score_transactions', true);

        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'room_id' => ['type' => 'INTEGER'],
            'type' => ['type' => 'VARCHAR', 'constraint' => 80],
            'state_version' => ['type' => 'INTEGER'],
            'payload_json' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->addKey(['room_id', 'state_version']);
        $this->forge->createTable('game_events', true);

        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'scope' => ['type' => 'VARCHAR', 'constraint' => 120],
            'key_hash' => ['type' => 'VARCHAR', 'constraint' => 128],
            'response_json' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'expires_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['scope', 'key_hash']);
        $this->forge->createTable('idempotency_keys', true);

        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'room_id' => ['type' => 'INTEGER'],
            'channel' => ['type' => 'VARCHAR', 'constraint' => 160],
            'event' => ['type' => 'VARCHAR', 'constraint' => 80],
            'payload_json' => ['type' => 'TEXT'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDING'],
            'attempts' => ['type' => 'INTEGER', 'default' => 0],
            'last_error' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'sent_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->createTable('realtime_outbox', true);
    }

    public function down(): void
    {
        foreach ([
            'realtime_outbox',
            'idempotency_keys',
            'game_events',
            'score_transactions',
            'game_answers',
            'game_turns',
            'game_teams',
            'game_rooms',
            'board_templates',
            'question_options',
            'questions',
            'teachers',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
