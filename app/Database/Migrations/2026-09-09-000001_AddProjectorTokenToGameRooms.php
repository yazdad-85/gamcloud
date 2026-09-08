<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

class AddProjectorTokenToGameRooms extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_rooms', [
            'projector_token' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'pin',
            ],
            'projector_token_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'projector_token',
            ],
        ]);

        $db = Database::connect();
        $rooms = $db->table('game_rooms')->select('id')->get()->getResultArray();
        foreach ($rooms as $room) {
            $plain = bin2hex(random_bytes(32));
            $db->table('game_rooms')->where('id', $room['id'])->update([
                'projector_token'      => $plain,
                'projector_token_hash' => hash('sha256', $plain),
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_rooms', ['projector_token', 'projector_token_hash']);
    }
}
