<?php

namespace App\Commands;

use App\Services\Game\GameEngine;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class DemoResetPlayCommand extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'demo:reset-play';
    protected $description = 'Expire active rooms and create a fresh LOBBY room for manual testing.';
    protected $usage       = 'demo:reset-play';

    public function run(array $params)
    {
        if (ENVIRONMENT === 'production') {
            CLI::error('Demo commands disabled in production.');

            return EXIT_ERROR;
        }

        $db = Database::connect();
        $db->table('game_rooms')
            ->whereIn('status', ['LOBBY', 'PLAYING', 'PAUSED'])
            ->set([
                'status'     => 'EXPIRED',
                'updated_at' => date('Y-m-d H:i:s'),
            ])
            ->update();

        $expired = (int) $db->affectedRows();

        $engine  = new GameEngine();
        $created = $engine->createRoom(1, 'Uji Coba FX ' . date('H:i'), [
            'turn_order_mode' => 'join_order',
        ]);
        $room = $created['room'];

        CLI::write('Expired old rooms: ' . $expired, 'yellow');
        CLI::write('Fresh LOBBY room ready.', 'green');
        CLI::write('PIN          : ' . $room['pin']);
        CLI::write('Room UUID    : ' . $room['uuid']);
        CLI::write('Join         : http://127.0.0.1:8090/join/' . $room['pin']);
        CLI::write('Teacher login: http://127.0.0.1:8090/login');
        CLI::write('  (gunakan kredensial dari .env SEED_TEACHER_PASSWORD — jangan hardcode)');
        CLI::write('Teacher room : http://127.0.0.1:8090/teacher/games/' . $room['uuid']);
        $token = (string) ($room['projector_token'] ?? '');
        CLI::write('Projector    : http://127.0.0.1:8090/game/' . $room['uuid'] . '/projector?t=' . $token);
        CLI::write('Langkah: buka Join di 2 tab/device (TIM A & TIM B), lalu Start dari halaman guru.');

        return EXIT_SUCCESS;
    }
}
