<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RenameDefaultSiteNameToEdugame extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('platform_settings')) {
            return;
        }

        $this->db->table('platform_settings')
            ->where('key', 'site_name')
            ->whereIn('value', ['Ular Tangga Edukatif', 'Ular Tangga Edukatif '])
            ->update([
                'value'      => 'Edugame',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('platform_settings')) {
            return;
        }

        $this->db->table('platform_settings')
            ->where('key', 'site_name')
            ->where('value', 'Edugame')
            ->update([
                'value'      => 'Ular Tangga Edukatif',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
