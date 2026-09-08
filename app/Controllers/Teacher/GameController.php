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
        $questionBankSummaries = [];
        if ($tenant->isSuperadmin()) {
            foreach ($teachers as $teacher) {
                $questionBankSummaries[(int) $teacher['id']] = $engine->questionBankSummary((int) $teacher['id']);
            }
        }

        return view('teacher/games/create', [
            'isSuperadmin' => $tenant->isSuperadmin(),
            'teachers' => $teachers,
            'boards' => (new BoardTemplateModel())->where('status', 'ACTIVE')->orderBy('name', 'ASC')->findAll(),
            'gameModes' => (new GameModeCatalog())->options(),
            'questionBankSummary' => $tenant->isSuperadmin() ? null : $engine->questionBankSummary($tenant->teacherId()),
            'questionBankSummaries' => $questionBankSummaries,
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

        $snapshot = $engine->createRoom($teacherId, $title, [
            'game_mode' => $gameMode,
            'board_template_id' => $boardTemplateId,
            'turn_order_mode' => $turnOrderMode,
            'finish_rule' => $finishRule,
            'skip_quota' => $tenant->isSuperadmin(),
            'question_selection' => [
                'strategy' => $questionStrategy,
            ],
            'scoring' => [
                'time_bonus' => $this->request->getPost('time_bonus') === '1',
                'streak_bonus' => $this->request->getPost('streak_bonus') === '1',
                'near_finish_bonus' => $this->request->getPost('near_finish_bonus') === '1',
                'wrong_penalty' => $this->request->getPost('wrong_penalty') === '1',
                'timeout_penalty' => $this->request->getPost('timeout_penalty') === '1',
            ],
        ]);

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
