<?php

namespace App\Controllers\Superadmin;

use App\Controllers\BaseController;
use App\Models\GameRoomModel;
use App\Models\TeacherRegistrationRequestModel;
use App\Models\TeacherModel;
use App\Services\Auth\TeacherRegistrationService;
use Config\Database;

class DashboardController extends BaseController
{
    public function index(): string
    {
        $db = Database::connect();

        return view('superadmin/dashboard', [
            'teacherCount' => (new TeacherModel())->countAllResults(),
            'roomCount' => (new GameRoomModel())->countAllResults(),
            'activeRoomCount' => (new GameRoomModel())->whereIn('status', ['LOBBY', 'PLAYING', 'PAUSED'])->countAllResults(),
            'pendingRegistrationCount' => (new TeacherRegistrationRequestModel())
                ->where('status', TeacherRegistrationService::STATUS_PENDING_EMAIL)
                ->countAllResults(),
            'recentTeachers' => $db->table('teachers t')
                ->select('t.*, u.username, u.last_active')
                ->join('users u', 'u.id = t.auth_user_id', 'left')
                ->orderBy('t.id', 'DESC')
                ->limit(8)
                ->get()
                ->getResultArray(),
        ]);
    }
}
