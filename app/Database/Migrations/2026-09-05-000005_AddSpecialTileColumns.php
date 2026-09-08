<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSpecialTileColumns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('board_templates', [
            'special_tiles_json' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'snakes_json',
            ],
        ]);

        $this->forge->addColumn('game_teams', [
            'active_effects_json' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'score',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('board_templates', 'special_tiles_json');
        $this->forge->dropColumn('game_teams', 'active_effects_json');
    }
}
