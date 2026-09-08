<?php

namespace App\Controllers\Teacher;

use App\Controllers\BaseController;
use App\Services\Report\GameReportService;
use App\Services\Security\TenantContext;
use Dompdf\Dompdf;
use Dompdf\Options;

class ReportController extends BaseController
{
    public function show(string $roomUuid): string
    {
        $room = (new TenantContext())->assertRoomOwner($roomUuid);
        $soalPage = (int) ($this->request->getGet('soal_page') ?? 1);
        $jawabPage = (int) ($this->request->getGet('jawab_page') ?? 1);

        return view('teacher/games/report', [
            'report' => (new GameReportService())->roomReport($room, [
                'soal_page' => $soalPage,
                'jawab_page' => $jawabPage,
            ]),
        ]);
    }

    public function pdf(string $roomUuid)
    {
        $room = (new TenantContext())->assertRoomOwner($roomUuid);
        $report = (new GameReportService())->roomReport($room);

        $html = view('teacher/games/report_pdf', ['report' => $report]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'laporan-' . preg_replace('/[^A-Za-z0-9_-]+/', '', (string) $room['pin']) . '-analisis-soal.pdf';

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($dompdf->output());
    }
}
