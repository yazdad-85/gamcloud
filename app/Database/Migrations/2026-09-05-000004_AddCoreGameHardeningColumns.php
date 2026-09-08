<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCoreGameHardeningColumns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_rooms', [
            'turn_order_mode' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'random',
                'after' => 'max_position',
            ],
            'finish_rule' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'clamp_finish',
                'after' => 'turn_order_mode',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_rooms', ['turn_order_mode', 'finish_rule']);
    }
}
