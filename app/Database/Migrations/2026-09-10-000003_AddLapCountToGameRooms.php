<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLapCountToGameRooms extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_rooms', [
            'lap_count' => [
                'type' => 'INTEGER',
                'default' => 1,
                'after' => 'max_position',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_rooms', 'lap_count');
    }
}
