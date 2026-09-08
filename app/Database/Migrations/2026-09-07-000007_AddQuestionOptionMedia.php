<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddQuestionOptionMedia extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('question_options', [
            'media_json' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'body',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('question_options', 'media_json');
    }
}
