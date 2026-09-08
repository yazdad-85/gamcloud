<?php

namespace App\Controllers\Teacher;

use App\Controllers\BaseController;
use App\Services\Report\GameReportService;
use App\Services\Security\TenantContext;

class ReportController extends BaseController
{
    public function show(string $roomUuid): string
    {
        $room = (new TenantContext())->assertRoomOwner($roomUuid);

        return view('teacher/games/report', [
            'report' => (new GameReportService())->roomReport($room),
        ]);
    }
}
