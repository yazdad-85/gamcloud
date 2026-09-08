<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class RoomsExpireCommand extends BaseCommand
{
    protected $group = 'App';
    protected $name = 'rooms:expire';
    protected $description = 'Mark stale active game rooms as EXPIRED.';
    protected $usage = 'rooms:expire';

    public function run(array $params)
    {
        $db = Database::connect();
        $builder = $db->table('game_rooms');
        $builder
            ->whereIn('status', ['LOBBY', 'PLAYING', 'PAUSED'])
            ->where('expires_at <', date('Y-m-d H:i:s'))
            ->set([
                'status' => 'EXPIRED',
                'updated_at' => date('Y-m-d H:i:s'),
            ])
            ->update();
        $count = $db->affectedRows();

        CLI::write('Expired rooms updated: ' . (int) $count, 'green');

        return EXIT_SUCCESS;
    }
}
