<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddScoringTensionColumns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_rooms', [
            'scoring_json' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'finish_rule',
            ],
        ]);

        $this->forge->addColumn('game_teams', [
            'streak_count' => [
                'type' => 'INTEGER',
                'default' => 0,
                'after' => 'score',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_rooms', 'scoring_json');
        $this->forge->dropColumn('game_teams', 'streak_count');
    }
}
