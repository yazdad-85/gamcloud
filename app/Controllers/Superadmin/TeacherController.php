<?php

namespace App\Controllers\Superadmin;

use App\Controllers\BaseController;
use Config\Database;

class TeacherController extends BaseController
{
    public function index(): string
    {
        $teachers = Database::connect()->table('teachers t')
            ->select('t.*, u.username, u.active, u.last_active, COUNT(gr.id) AS room_count')
            ->join('users u', 'u.id = t.auth_user_id', 'left')
            ->join('game_rooms gr', 'gr.teacher_id = t.id', 'left')
            ->groupBy('t.id, u.id')
            ->orderBy('t.name', 'ASC')
            ->get()
            ->getResultArray();

        return view('superadmin/teachers', ['teachers' => $teachers]);
    }
}
