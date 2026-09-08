<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddGameModeColumns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_rooms', [
            'game_mode' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'default' => 'SNAKES_LADDERS',
                'after' => 'max_position',
            ],
            'mode_state_json' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'game_mode',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_rooms', ['game_mode', 'mode_state_json']);
    }
}
