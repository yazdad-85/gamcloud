<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Services\Game\GameEngine;
use App\Services\Report\GameReportService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * @internal
 */
final class GameReportPdfRenderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed = DemoGameSeeder::class;

    public function testReportPdfHtmlRendersWithDompdf(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'PDF Report', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $engine->joinByPin($room['pin'], 'TIM A');
        $roomRow = (new \App\Models\GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        $report = (new GameReportService())->roomReport($roomRow);
        $html = view('teacher/games/report_pdf', ['report' => $report]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        $output = $dompdf->output();

        $this->assertNotSame('', $output);
        $this->assertStringContainsString('%PDF', substr($output, 0, 8));
    }
}
