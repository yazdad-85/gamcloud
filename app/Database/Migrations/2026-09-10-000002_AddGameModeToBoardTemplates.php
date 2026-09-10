<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddGameModeToBoardTemplates extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('board_templates', [
            'game_mode' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'SNAKES_LADDERS',
                'after' => 'name',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('board_templates', 'game_mode');
    }
}
