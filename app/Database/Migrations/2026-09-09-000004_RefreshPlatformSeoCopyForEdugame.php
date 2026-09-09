<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Refresh stored SEO defaults so public meta no longer frames the platform as only snakes-and-ladders.
 */
class RefreshPlatformSeoCopyForEdugame extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('platform_settings')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $replacements = [
            'tagline' => [
                'from' => [
                    'Kuis kelas interaktif berbasis papan permainan',
                ],
                'to' => 'Platform game kuis untuk kelas yang hidup',
            ],
            'seo_description' => [
                'from' => [
                    'Jalankan kuis ular tangga di kelas: bank soal guru, tim siswa, proyektor, dan laporan hasil bermain.',
                ],
                'to' => 'Platform game edukatif untuk kelas: bank soal guru, tim siswa, proyektor, dan laporan hasil bermain.',
            ],
        ];

        foreach ($replacements as $key => $pair) {
            $this->db->table('platform_settings')
                ->where('key', $key)
                ->whereIn('value', $pair['from'])
                ->update([
                    'value'      => $pair['to'],
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: SEO copy refresh is one-way.
    }
}
