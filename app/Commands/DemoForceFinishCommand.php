<?php

namespace App\Commands;

use App\Models\GameRoomModel;
use App\Models\GameTeamModel;
use App\Models\GameTurnModel;
use App\Models\QuestionOptionModel;
use App\Services\Game\GameEngine;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class DemoForceFinishCommand extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'demo:force-finish';
    protected $description = 'Place current team one tile before finish and force a winning move.';
    protected $usage       = 'demo:force-finish <roomUuid>';

    public function run(array $params)
    {
        $roomUuid = (string) ($params[0] ?? '');
        if ($roomUuid === '') {
            CLI::error('Usage: php spark demo:force-finish <roomUuid>');

            return EXIT_ERROR;
        }

        $rooms = new GameRoomModel();
        $room  = $rooms->where('public_uuid', $roomUuid)->first();
        if ($room === null) {
            CLI::error('Room not found.');

            return EXIT_ERROR;
        }

        $max = (int) $room['max_position'];
        $rooms->update($room['id'], [
            'expires_at' => date('Y-m-d H:i:s', time() + 6 * 3600),
            'status'     => 'PLAYING',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $team = (new GameTeamModel())->find((int) $room['current_team_id']);
        if ($team === null) {
            CLI::error('Current team missing.');

            return EXIT_ERROR;
        }

        $from = max(1, $max - 1);
        (new GameTeamModel())->update($team['id'], ['position' => $from]);

        $turn = (new GameTurnModel())
            ->where('room_id', $room['id'])
            ->orderBy('id', 'DESC')
            ->first();
        if ($turn === null || $turn['state'] !== 'ROLL_READY') {
            CLI::error('Need an active ROLL_READY turn. Current state: ' . ($turn['state'] ?? 'none'));

            return EXIT_ERROR;
        }

        $engine = new GameEngine();
        $engine->roll($roomUuid, $team['public_uuid']);

        $turn = (new GameTurnModel())
            ->where('room_id', $room['id'])
            ->orderBy('id', 'DESC')
            ->first();
        (new GameTurnModel())->update($turn['id'], [
            'dice_value'            => 1,
            'question_started_at'   => date('Y-m-d H:i:s', time() + 30),
            'question_deadline_at'  => date('Y-m-d H:i:s', time() + 120),
        ]);

        $option = (new QuestionOptionModel())
            ->where('question_id', $turn['question_id'])
            ->where('is_correct', 1)
            ->first();
        if ($option === null) {
            CLI::error('No correct option for current question.');

            return EXIT_ERROR;
        }

        $snapshot = $engine->answer($roomUuid, $team['public_uuid'], (int) $option['id']);
        $winner   = null;
        foreach ($snapshot['teams'] ?? [] as $row) {
            if ((int) $row['position'] >= $max) {
                $winner = $row;
            }
        }

        CLI::write('Room extended + forced finish attempt.', 'green');
        CLI::write('Finish tile: ' . $max . ' (rule: ' . ($room['finish_rule'] ?? 'clamp_finish') . ')');
        CLI::write('Team: ' . $team['name'] . ' moved from ' . $from . ' with dice 1');
        CLI::write('Room status: ' . ($snapshot['room']['status'] ?? '?'));
        if ($winner !== null) {
            CLI::write('Winner: ' . $winner['name'] . ' at tile ' . $winner['position'], 'green');
        } else {
            CLI::write('No winner yet — check pending snake/ladder/mystery on finish tile.', 'yellow');
            CLI::write('Current turn state: ' . (($snapshot['current_turn']['state'] ?? null) ?: 'n/a'));
        }
        CLI::write('Projector: http://127.0.0.1:8090/game/' . $roomUuid . '/projector');

        return EXIT_SUCCESS;
    }
}
