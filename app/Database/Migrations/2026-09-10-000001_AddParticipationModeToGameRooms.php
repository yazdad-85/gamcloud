<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddParticipationModeToGameRooms extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_rooms', [
            'participation_mode' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'TEAM_DEVICE',
                'after' => 'game_mode',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_rooms', ['participation_mode']);
    }
}
