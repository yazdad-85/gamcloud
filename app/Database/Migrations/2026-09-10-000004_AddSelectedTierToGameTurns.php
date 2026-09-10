<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSelectedTierToGameTurns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_turns', [
            'selected_tier' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'null' => true,
                'after' => 'dice_value',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_turns', 'selected_tier');
    }
}
