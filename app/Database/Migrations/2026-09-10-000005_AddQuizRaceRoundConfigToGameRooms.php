<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddQuizRaceRoundConfigToGameRooms extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_rooms', [
            'race_question_limit' => [
                'type' => 'INTEGER',
                'null' => true,
            ],
            'race_round_question_counts_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'race_round_winner_bonus_points' => [
                'type' => 'INTEGER',
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_rooms', [
            'race_question_limit',
            'race_round_question_counts_json',
            'race_round_winner_bonus_points',
        ]);
    }
}
