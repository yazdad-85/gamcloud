<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UpdateDefaultQuestionTimeTo20 extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('game_rooms', [
            'question_time_seconds' => [
                'name' => 'question_time_seconds',
                'type' => 'INTEGER',
                'default' => 20,
            ],
        ]);

        $this->db->table('game_rooms')
            ->where('question_time_seconds', 30)
            ->update(['question_time_seconds' => 20]);
    }

    public function down(): void
    {
        $this->forge->modifyColumn('game_rooms', [
            'question_time_seconds' => [
                'name' => 'question_time_seconds',
                'type' => 'INTEGER',
                'default' => 30,
            ],
        ]);

        $this->db->table('game_rooms')
            ->where('question_time_seconds', 20)
            ->update(['question_time_seconds' => 30]);
    }
}
