<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class RoomsCleanupCommand extends BaseCommand
{
    protected $group = 'App';
    protected $name = 'rooms:cleanup';
    protected $description = 'Clean expired idempotency keys and stale realtime outbox rows.';
    protected $usage = 'rooms:cleanup';

    public function run(array $params)
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $outboxBefore = date('Y-m-d H:i:s', time() - 86400);

        $db->table('idempotency_keys')
            ->where('expires_at <', $now)
            ->delete();
        $idempotencyDeleted = $db->affectedRows();

        $db->table('realtime_outbox')
            ->where('status', 'SENT')
            ->where('sent_at <', $outboxBefore)
            ->delete();
        $outboxDeleted = $db->affectedRows();

        CLI::write('Expired idempotency keys deleted: ' . $idempotencyDeleted, 'green');
        CLI::write('Old realtime outbox rows deleted: ' . $outboxDeleted, 'green');

        return EXIT_SUCCESS;
    }
}
