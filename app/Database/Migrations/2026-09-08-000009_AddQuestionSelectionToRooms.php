<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddQuestionSelectionToRooms extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_rooms', [
            'question_selection_json' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'scoring_json',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_rooms', 'question_selection_json');
    }
}
