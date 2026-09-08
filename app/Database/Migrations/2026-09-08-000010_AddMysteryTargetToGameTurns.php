<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMysteryTargetToGameTurns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_turns', [
            'mystery_target_team_id' => [
                'type' => 'INTEGER',
                'null' => true,
                'after' => 'answer_is_correct',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_turns', 'mystery_target_team_id');
    }
}
