<?php

namespace App\Controllers\Teacher;

use App\Controllers\BaseController;
use App\Models\GameRoomModel;
use App\Models\QuestionModel;
use App\Services\Security\TenantContext;

class DashboardController extends BaseController
{
    public function index(): string
    {
        $tenant = new TenantContext();
        $roomQuery = (new GameRoomModel())->orderBy('id', 'DESC')->limit(6);
        $questionQuery = (new QuestionModel())->where('status', 'PUBLISHED');

        if (! $tenant->isSuperadmin()) {
            $teacherId = $tenant->teacherId();
            $roomQuery->where('teacher_id', $teacherId);
            $questionQuery->where('owner_teacher_id', $teacherId);
        }

        $rooms = $roomQuery->findAll();

        return view('teacher/dashboard', [
            'activeRooms' => array_filter($rooms, static fn (array $room): bool => in_array($room['status'], ['LOBBY', 'PLAYING', 'PAUSED'], true)),
            'recentRooms' => $rooms,
            'questionCount' => $questionQuery->countAllResults(),
        ]);
    }
}
