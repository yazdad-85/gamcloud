<?php

namespace App\Controllers\Teacher;

use App\Controllers\BaseController;
use App\Models\BoardTemplateModel;
use App\Models\GameRoomModel;
use App\Models\TeacherModel;
use App\Services\Game\GameEngine;
use App\Services\Game\Modes\GameModeCatalog;
use App\Services\Security\TenantContext;
use DomainException;

class GameController extends BaseController
{
    public function index(): string
    {
        $tenant = new TenantContext();
        $roomQuery = (new GameRoomModel())->orderBy('id', 'DESC');

        if (! $tenant->isSuperadmin()) {
            $roomQuery->where('teacher_id', $tenant->teacherId());
        }

        return view('teacher/games/index', [
            'rooms' => $roomQuery->findAll(),
        ]);
    }

    public function create(): string
    {
        $tenant = new TenantContext();
        $engine = new GameEngine();
        $teachers = $tenant->isSuperadmin() ? (new TeacherModel())->orderBy('name', 'ASC')->findAll() : [];
        $questionTopicCatalogs = [];
        if ($tenant->isSuperadmin()) {
            foreach ($teachers as $teacher) {
                $questionTopicCatalogs[(int) $teacher['id']] = $engine->questionTopicCatalog((int) $teacher['id']);
            }
        }

        return view('teacher/games/create', [
            'isSuperadmin' => $tenant->isSuperadmin(),
            'teachers' => $teachers,
            'boards' => (new BoardTemplateModel())->where('status', 'ACTIVE')->orderBy('name', 'ASC')->findAll(),
            'gameModes' => (new GameModeCatalog())->options(),
            'questionTopicCatalog' => $tenant->isSuperadmin() ? [] : $engine->questionTopicCatalog($tenant->teacherId()),
            'questionTopicCatalogs' => $questionTopicCatalogs,
        ]);
    }

    public function store()
    {
        $title = trim((string) $this->request->getPost('title'));
        if ($title === '') {
            $title = 'Game Ular Tangga ' . date('d/m H:i');
        }

        $tenant = new TenantContext();
        $teacherId = $tenant->isSuperadmin()
            ? (int) $this->request->getPost('teacher_id')
            : $tenant->teacherId();

        if ($teacherId < 1 || (new TeacherModel())->find($teacherId) === null) {
            return redirect()->back()->withInput()->with('error', 'Pilih guru pemilik room yang valid.');
        }

        $turnOrderMode = (string) $this->request->getPost('turn_order_mode');
        if (! in_array($turnOrderMode, ['random', 'join_order'], true)) {
            $turnOrderMode = 'random';
        }

        $finishRule = (string) $this->request->getPost('finish_rule');
        if (! in_array($finishRule, ['clamp_finish', 'exact_finish'], true)) {
            $finishRule = 'clamp_finish';
        }

        $boardTemplateId = (int) $this->request->getPost('board_template_id');
        if ($boardTemplateId < 1) {
            $boardTemplateId = null;
        }

        $mysteryTileCount = $this->request->getPost('mystery_tile_count');
        if (! is_scalar($mysteryTileCount) || preg_match('/^[0-6]$/', (string) $mysteryTileCount) !== 1) {
            return redirect()->back()->withInput()->with('error', 'Jumlah Kotak Mystery wajib dipilih antara 0 sampai 6.');
        }
        $mysteryTileCount = (int) $mysteryTileCount;

        $boardSize = (string) $this->request->getPost('board_size');
        if (! in_array($boardSize, ['50', '70', '100'], true)) {
            $boardSize = '100';
        }

        $modeCatalog = new GameModeCatalog();
        $gameMode = strtoupper((string) $this->request->getPost('game_mode'));
        if (! in_array($gameMode, $modeCatalog->playableKeys(), true)) {
            $gameMode = 'SNAKES_LADDERS';
        }

        $engine = new GameEngine();
        $questionBankSummary = $engine->questionBankSummary($teacherId);
        if ((int) $questionBankSummary['total'] < 1) {
            return redirect()->back()->withInput()->with('error', 'Bank soal guru ini masih kosong. Import atau tambahkan soal sebelum membuat game.');
        }

        $questionStrategy = (string) $this->request->getPost('question_selection_strategy');
        if (! in_array($questionStrategy, ['difficulty_zone', 'random'], true)) {
            $questionStrategy = 'difficulty_zone';
        }

        $questionTopicUuids = $this->request->getPost('question_topic_uuids');
        $questionTopicUuids = is_array($questionTopicUuids) ? $questionTopicUuids : [];

        try {
            $snapshot = $engine->createRoom($teacherId, $title, [
                'game_mode' => $gameMode,
                'board_template_id' => $boardTemplateId,
                'turn_order_mode' => $turnOrderMode,
                'finish_rule' => $finishRule,
                'mystery_tile_count' => $mysteryTileCount,
                'board_size' => (int) $boardSize,
                'skip_quota' => $tenant->isSuperadmin(),
                'question_selection' => [
                    'strategy' => $questionStrategy,
                    'topic_uuids' => $questionTopicUuids,
                ],
                'scoring' => [
                    'time_bonus' => $this->request->getPost('time_bonus') === '1',
                    'streak_bonus' => $this->request->getPost('streak_bonus') === '1',
                    'near_finish_bonus' => $this->request->getPost('near_finish_bonus') === '1',
                    'wrong_penalty' => $this->request->getPost('wrong_penalty') === '1',
                    'timeout_penalty' => $this->request->getPost('timeout_penalty') === '1',
                ],
            ]);
        } catch (DomainException $error) {
            return redirect()->back()->withInput()->with('error', $error->getMessage());
        }

        return redirect()->to('/teacher/games/' . $snapshot['room']['uuid']);
    }

    public function show(string $roomUuid): string
    {
        (new TenantContext())->assertRoomOwner($roomUuid);

        return view('teacher/games/show', [
            'snapshot' => (new GameEngine())->snapshot($roomUuid),
        ]);
    }

    public function control(string $roomUuid): string
    {
        (new TenantContext())->assertRoomOwner($roomUuid);

        return view('teacher/games/control', [
            'snapshot' => (new GameEngine())->snapshot($roomUuid),
        ]);
    }

    public function delete(string $roomUuid)
    {
        (new TenantContext())->assertRoomOwner($roomUuid);

        try {
            (new GameEngine())->deleteRoom($roomUuid);
        } catch (DomainException $error) {
            return redirect()->back()->with('error', $error->getMessage());
        }

        return redirect()->to('/teacher/games')->with('message', 'Room game berhasil dihapus.');
    }
}
