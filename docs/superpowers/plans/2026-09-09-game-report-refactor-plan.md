# Game Report Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor `/teacher/games/{uuid}/report` into a modern laporan with explicit winner banner, position-first leaderboard, paginated soal/jawaban tables, Chart.js graphs, and Dompdf download of winner + question analysis.

**Architecture:** Enrich `GameReportService` to resolve winner, sort teams by board position, build chart datasets, and paginate collections. `ReportController` passes page query params and a new `pdf()` action. HTML view stays separate from a print-simple `report_pdf.php` rendered by Dompdf. Chart.js loads only on the report page via the teacher layout `scripts` section.

**Tech Stack:** PHP 8.2 / CodeIgniter 4, PHPUnit + DatabaseTestTrait, Dompdf (`dompdf/dompdf`), Chart.js CDN, existing `app.css`.

**Testing note:** Service logic is TDD with PHPUnit. UI/PDF verified with `node` N/A for PHP views + manual checklist; PDF content-type can be unit-tested by rendering Dompdf to string without HTTP auth if FeatureTest CSRF is painful.

---

## File map

| File | Responsibility |
|---|---|
| `app/Services/Report/GameReportService.php` | Winner, sort, chart data, pagination |
| `tests/database/GameReportServiceTest.php` | PHPUnit for service |
| `app/Controllers/Teacher/ReportController.php` | `show` + `pdf` |
| `app/Views/teacher/games/report.php` | Modern HTML report |
| `app/Views/teacher/games/report_pdf.php` | Dompdf HTML |
| `app/Config/Routes.php` | PDF route |
| `public/assets/app.css` | Report styles |
| `composer.json` / `composer.lock` | dompdf |

---

### Task 1: Enrich `GameReportService` (winner, sort, charts, pagination) — TDD

**Files:**
- Modify: `app/Services/Report/GameReportService.php`
- Create: `tests/database/GameReportServiceTest.php`

- [ ] **Step 1: Create failing test file**

Create `tests/database/GameReportServiceTest.php`:

```php
<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Models\GameEventModel;
use App\Models\GameTeamModel;
use App\Services\Game\GameEngine;
use App\Services\Game\Uuid;
use App\Services\Report\GameReportService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class GameReportServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed = DemoGameSeeder::class;

    public function testRoomReportResolvesWinnerFromGameFinishedEvent(): void
    {
        $engine = new GameEngine();
        $created = $engine->createRoom(1, 'Report Winner', [
            'turn_order_mode' => 'join_order',
            'scoring' => ['correct' => 0, 'wrong' => 0, 'time_bonus_max' => 0, 'streak_bonus' => 0, 'near_finish_bonus' => 0, 'timeout_penalty' => false],
        ]);
        $room = $created['room'];
        $a = $engine->joinByPin($room['pin'], 'TIM A')['team'];
        $b = $engine->joinByPin($room['pin'], 'TIM B')['team'];
        $engine->start($room['uuid']);

        $roomRow = (new \App\Models\GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        (new GameTeamModel())->update($a['id'], ['position' => 50, 'score' => 100]);
        (new GameTeamModel())->update($b['id'], ['position' => 20, 'score' => 900]);
        (new \App\Models\GameRoomModel())->update($roomRow['id'], [
            'status' => 'FINISHED',
            'max_position' => 50,
        ]);
        $roomRow = (new \App\Models\GameRoomModel())->find($roomRow['id']);

        $payload = [
            'event_id' => Uuid::v4(),
            'event' => 'game.finished',
            'room_uuid' => $room['uuid'],
            'state_version' => (int) $roomRow['state_version'],
            'occurred_at' => date(DATE_ATOM),
            'payload' => ['winner_team_uuid' => $a['public_uuid']],
        ];
        (new GameEventModel())->insert([
            'public_uuid' => $payload['event_id'],
            'room_id' => $roomRow['id'],
            'type' => 'game.finished',
            'state_version' => $payload['state_version'],
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $report = (new GameReportService())->roomReport($roomRow);

        $this->assertNotNull($report['winner']);
        $this->assertSame($a['public_uuid'], $report['winner']['public_uuid']);
        $this->assertSame('TIM A', $report['winner']['name']);
        $this->assertTrue($report['teams'][0]['is_board_winner']);
        $this->assertSame('TIM A', $report['teams'][0]['name']);
        $this->assertSame('TIM B', $report['teams'][1]['name']);
    }

    public function testRoomReportSortsByPositionThenScoreWithoutWinner(): void
    {
        $engine = new GameEngine();
        $created = $engine->createRoom(1, 'Report Sort', [
            'turn_order_mode' => 'join_order',
        ]);
        $room = $created['room'];
        $a = $engine->joinByPin($room['pin'], 'TIM A')['team'];
        $b = $engine->joinByPin($room['pin'], 'TIM B')['team'];
        $roomRow = (new \App\Models\GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        (new GameTeamModel())->update($a['id'], ['position' => 10, 'score' => 500]);
        (new GameTeamModel())->update($b['id'], ['position' => 30, 'score' => 100]);
        $roomRow = (new \App\Models\GameRoomModel())->find($roomRow['id']);

        $report = (new GameReportService())->roomReport($roomRow, [
            'soal_page' => 1,
            'jawab_page' => 1,
        ]);

        $this->assertNull($report['winner']);
        $this->assertSame('TIM B', $report['teams'][0]['name']);
        $this->assertSame('TIM A', $report['teams'][1]['name']);
    }

    public function testPaginationSlicesQuestionStatsAndAnswers(): void
    {
        $service = new GameReportService();
        $items = [];
        for ($i = 1; $i <= 25; $i++) {
            $items[] = ['id' => $i];
        }

        $page1 = $service->paginate($items, 1, 10);
        $page3 = $service->paginate($items, 3, 10);
        $overflow = $service->paginate($items, 99, 10);

        $this->assertSame(10, count($page1['items']));
        $this->assertSame(1, $page1['page']);
        $this->assertSame(3, $page1['total_pages']);
        $this->assertSame(5, count($page3['items']));
        $this->assertSame(3, $overflow['page']);
        $this->assertSame(5, count($overflow['items']));
    }
}
```

If `noScoring` helper is preferred, copy the array from `GameEngineHardeningTest` — or omit custom scoring; not critical for this test.

- [ ] **Step 2: Run tests — expect FAIL**

Run: `./vendor/bin/phpunit --filter GameReportServiceTest`

Expected: FAIL (paginate/winner keys missing).

- [ ] **Step 3: Implement service**

Replace `app/Services/Report/GameReportService.php` with:

```php
<?php

namespace App\Services\Report;

use Config\Database;

class GameReportService
{
    public function roomReport(array $room, array $pages = []): array
    {
        $soalPage = max(1, (int) ($pages['soal_page'] ?? 1));
        $jawabPage = max(1, (int) ($pages['jawab_page'] ?? 1));

        $db = Database::connect();
        $teams = $db->table('game_teams')
            ->where('room_id', $room['id'])
            ->get()
            ->getResultArray();

        $answers = $db->table('game_answers ga')
            ->select('ga.*, gt.name AS team_name, gt.color AS team_color, gt.public_uuid AS team_uuid, q.stem AS question_stem, qo.label AS option_label')
            ->join('game_teams gt', 'gt.id = ga.team_id')
            ->join('questions q', 'q.id = ga.question_id')
            ->join('question_options qo', 'qo.id = ga.option_id', 'left')
            ->where('gt.room_id', $room['id'])
            ->orderBy('ga.id', 'ASC')
            ->get()
            ->getResultArray();

        $events = $db->table('game_events')
            ->where('room_id', $room['id'])
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $decodedEvents = array_map(
            static fn (array $row): array => json_decode((string) $row['payload_json'], true) ?: [],
            $events
        );

        $questionStats = $db->table('game_answers ga')
            ->select('q.id AS question_id, q.stem, COUNT(*) AS total_answers, SUM(CASE WHEN ga.is_correct = 1 THEN 1 ELSE 0 END) AS correct_answers')
            ->join('questions q', 'q.id = ga.question_id')
            ->join('game_teams gt', 'gt.id = ga.team_id')
            ->where('gt.room_id', $room['id'])
            ->groupBy('q.id, q.stem')
            ->orderBy('total_answers', 'DESC')
            ->get()
            ->getResultArray();

        $winnerUuid = $this->winnerTeamUuid($decodedEvents, $teams, $room);
        $teams = $this->decorateAndSortTeams($teams, $winnerUuid);
        $winner = null;
        foreach ($teams as $team) {
            if (! empty($team['is_board_winner'])) {
                $winner = $team;
                break;
            }
        }

        $charts = $this->buildCharts($questionStats, $answers, $teams);
        $soalPaginated = $this->paginate($questionStats, $soalPage, 10);
        $jawabPaginated = $this->paginate($answers, $jawabPage, 15);

        return [
            'room' => $room,
            'winner' => $winner,
            'teams' => $teams,
            'answers' => $answers,
            'events' => $decodedEvents,
            'questionStats' => $questionStats,
            'charts' => $charts,
            'questionStatsPage' => $soalPaginated,
            'answersPage' => $jawabPaginated,
        ];
    }

    public function paginate(array $items, int $page, int $perPage): array
    {
        $total = count($items);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    private function winnerTeamUuid(array $events, array $teams, array $room): ?string
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $event = $events[$i];
            if (($event['event'] ?? '') === 'game.finished') {
                $uuid = $event['payload']['winner_team_uuid'] ?? null;
                if (is_string($uuid) && $uuid !== '') {
                    return $uuid;
                }
            }
        }

        if (($room['status'] ?? '') !== 'FINISHED') {
            return null;
        }

        $max = (int) ($room['max_position'] ?? 0);
        foreach ($teams as $team) {
            if ($max > 0 && (int) $team['position'] >= $max) {
                return (string) $team['public_uuid'];
            }
        }

        return null;
    }

    private function decorateAndSortTeams(array $teams, ?string $winnerUuid): array
    {
        foreach ($teams as &$team) {
            $team['is_board_winner'] = $winnerUuid !== null && (string) $team['public_uuid'] === $winnerUuid;
        }
        unset($team);

        usort($teams, static function (array $a, array $b): int {
            $pos = (int) $b['position'] <=> (int) $a['position'];
            if ($pos !== 0) {
                return $pos;
            }

            return (int) $b['score'] <=> (int) $a['score'];
        });

        return $teams;
    }

    private function buildCharts(array $questionStats, array $answers, array $teams): array
    {
        $accuracyLabels = [];
        $accuracyValues = [];
        foreach ($questionStats as $stat) {
            $stem = (string) $stat['stem'];
            $accuracyLabels[] = mb_strlen($stem) > 40 ? mb_substr($stem, 0, 37) . '...' : $stem;
            $total = (int) $stat['total_answers'];
            $correct = (int) $stat['correct_answers'];
            $accuracyValues[] = $total > 0 ? (int) round(($correct / $total) * 100) : 0;
        }

        $correctByTeam = [];
        $wrongByTeam = [];
        foreach ($teams as $team) {
            $correctByTeam[$team['public_uuid']] = 0;
            $wrongByTeam[$team['public_uuid']] = 0;
        }
        foreach ($answers as $answer) {
            $uuid = (string) ($answer['team_uuid'] ?? '');
            if ($uuid === '' || ! array_key_exists($uuid, $correctByTeam)) {
                continue;
            }
            if ((int) $answer['is_correct'] === 1) {
                $correctByTeam[$uuid]++;
            } else {
                $wrongByTeam[$uuid]++;
            }
        }

        $teamLabels = [];
        $correctSeries = [];
        $wrongSeries = [];
        foreach ($teams as $team) {
            $teamLabels[] = $team['name'];
            $correctSeries[] = $correctByTeam[$team['public_uuid']] ?? 0;
            $wrongSeries[] = $wrongByTeam[$team['public_uuid']] ?? 0;
        }

        return [
            'accuracy' => [
                'labels' => $accuracyLabels,
                'values' => $accuracyValues,
            ],
            'team_results' => [
                'labels' => $teamLabels,
                'correct' => $correctSeries,
                'wrong' => $wrongSeries,
            ],
        ];
    }
}
```

Note: join select adds `gt.public_uuid AS team_uuid` for chart aggregation — required.

- [ ] **Step 4: Tests PASS**

Run: `./vendor/bin/phpunit --filter GameReportServiceTest`

Expected: OK.

If `scoring` key fails createRoom, drop the scoring option from the first test.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Report/GameReportService.php tests/database/GameReportServiceTest.php
git commit -m "feat: enrich game report service with winner, sort, charts, and pagination"
```

---

### Task 2: Modern report HTML (winner, leaderboard, pagination)

**Files:**
- Modify: `app/Controllers/Teacher/ReportController.php`
- Modify: `app/Views/teacher/games/report.php`
- Modify: `public/assets/app.css`

- [ ] **Step 1: Update controller `show`**

```php
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
```

- [ ] **Step 2: Rewrite `report.php` content structure**

Keep `extend('layouts/teacher')`. Replace body with:

```php
<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<?php
$room = $report['room'];
$winner = $report['winner'];
$soalPage = $report['questionStatsPage'];
$jawabPage = $report['answersPage'];
$baseUrl = '/teacher/games/' . $room['public_uuid'] . '/report';
?>
<div class="topbar">
    <div>
        <h1 class="page-title">Laporan Game</h1>
        <p class="muted"><?= esc($room['title']) ?> / PIN <strong><?= esc($room['pin']) ?></strong> / Status <strong><?= esc($room['status']) ?></strong></p>
    </div>
    <div class="topbar-actions">
        <a class="button secondary" href="/teacher/games/<?= esc($room['public_uuid']) ?>">Kembali</a>
        <a class="button" href="/teacher/games/<?= esc($room['public_uuid']) ?>/report/pdf">Export PDF Analisis Soal</a>
    </div>
</div>

<section class="grid cols-3">
    <div class="card metric"><span class="muted">Tim</span><strong><?= count($report['teams']) ?></strong></div>
    <div class="card metric"><span class="muted">Jawaban</span><strong><?= count($report['answers']) ?></strong></div>
    <div class="card metric"><span class="muted">Event</span><strong><?= count($report['events']) ?></strong></div>
</section>

<section class="panel report-winner <?= $winner ? 'is-winner' : 'is-pending' ?>" style="margin-top:16px">
    <?php if ($winner): ?>
        <p class="muted">Pemenang (Juara Papan)</p>
        <h2><?= esc($winner['name']) ?></h2>
        <p>Kotak <strong><?= esc((string) $winner['position']) ?></strong> / <?= esc((string) ($room['max_position'] ?? '-')) ?> · Skor <strong><?= esc((string) $winner['score']) ?></strong></p>
    <?php else: ?>
        <p class="muted">Pemenang</p>
        <h2>Belum ada pemenang</h2>
        <p class="muted">Status room: <?= esc($room['status']) ?>. Juara utama = tim pertama sampai finish.</p>
    <?php endif ?>
</section>

<section class="panel" style="margin-top:16px">
    <h2>Leaderboard Final</h2>
    <table class="table">
        <thead>
        <tr><th>#</th><th>Tim</th><th>Skor</th><th>Posisi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($report['teams'] as $index => $team): ?>
            <tr class="<?= ! empty($team['is_board_winner']) ? 'is-board-winner' : '' ?>">
                <td><?= $index + 1 ?></td>
                <td>
                    <span class="pawn" style="background:<?= esc($team['color']) ?>"></span>
                    <?= esc($team['name']) ?>
                    <?php if (! empty($team['is_board_winner'])): ?>
                        <span class="badge-winner">Juara Papan</span>
                    <?php endif ?>
                </td>
                <td><?= esc((string) $team['score']) ?></td>
                <td>Kotak <?= esc((string) $team['position']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</section>

<section class="panel" style="margin-top:16px">
    <h2>Grafik</h2>
    <?php if ($report['charts']['accuracy']['labels'] === [] && $report['charts']['team_results']['labels'] === []): ?>
        <p class="muted">Belum ada data untuk grafik.</p>
    <?php else: ?>
        <div class="report-charts">
            <div class="report-chart-card">
                <h3>Akurasi per Soal (%)</h3>
                <canvas id="chart-accuracy" height="160"></canvas>
            </div>
            <div class="report-chart-card">
                <h3>Benar vs Salah per Tim</h3>
                <canvas id="chart-teams" height="160"></canvas>
            </div>
        </div>
    <?php endif ?>
</section>

<section class="panel" style="margin-top:16px" id="analisis-soal">
    <div class="panel-head">
        <h2>Analisis Soal</h2>
        <a class="button secondary" href="/teacher/games/<?= esc($room['public_uuid']) ?>/report/pdf">Export PDF</a>
    </div>
    <table class="table">
        <thead>
        <tr><th>Soal</th><th>Jawaban</th><th>Benar</th><th>Akurasi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($soalPage['items'] as $stat): ?>
            <?php
                $total = (int) $stat['total_answers'];
                $correct = (int) $stat['correct_answers'];
                $accuracy = $total > 0 ? round(($correct / $total) * 100) : 0;
            ?>
            <tr>
                <td><?= esc($stat['stem']) ?></td>
                <td><?= esc((string) $total) ?></td>
                <td><?= esc((string) $correct) ?></td>
                <td><?= esc((string) $accuracy) ?>%</td>
            </tr>
        <?php endforeach ?>
        <?php if ($soalPage['items'] === []): ?>
            <tr><td colspan="4" class="muted">Belum ada jawaban.</td></tr>
        <?php endif ?>
        </tbody>
    </table>
    <?php if ($soalPage['total_pages'] > 1): ?>
        <nav class="pagination">
            <?php for ($p = 1; $p <= $soalPage['total_pages']; $p++): ?>
                <a class="<?= $p === $soalPage['page'] ? 'active' : '' ?>"
                   href="<?= esc($baseUrl . '?soal_page=' . $p . '&jawab_page=' . $jawabPage['page']) ?>#analisis-soal"><?= $p ?></a>
            <?php endfor ?>
        </nav>
    <?php endif ?>
</section>

<section class="panel" style="margin-top:16px" id="jawaban-tim">
    <h2>Jawaban Tim</h2>
    <table class="table">
        <thead>
        <tr><th>Tim</th><th>Soal</th><th>Jawaban</th><th>Status</th><th>Waktu</th></tr>
        </thead>
        <tbody>
        <?php foreach ($jawabPage['items'] as $answer): ?>
            <tr>
                <td><?= esc($answer['team_name']) ?></td>
                <td><?= esc($answer['question_stem']) ?></td>
                <td><?= esc($answer['option_label'] ?? '-') ?>. <?= esc($answer['answer_text']) ?></td>
                <td><?= (int) $answer['is_correct'] === 1 ? 'Benar' : 'Belum tepat' ?></td>
                <td><?= esc($answer['answered_at'] ?? '-') ?></td>
            </tr>
        <?php endforeach ?>
        <?php if ($jawabPage['items'] === []): ?>
            <tr><td colspan="5" class="muted">Belum ada jawaban.</td></tr>
        <?php endif ?>
        </tbody>
    </table>
    <?php if ($jawabPage['total_pages'] > 1): ?>
        <nav class="pagination">
            <?php for ($p = 1; $p <= $jawabPage['total_pages']; $p++): ?>
                <a class="<?= $p === $jawabPage['page'] ? 'active' : '' ?>"
                   href="<?= esc($baseUrl . '?soal_page=' . $soalPage['page'] . '&jawab_page=' . $p) ?>#jawaban-tim"><?= $p ?></a>
            <?php endfor ?>
        </nav>
    <?php endif ?>
</section>
<?= $this->endSection() ?>
```

Charts script section comes in Task 3 — leave canvas mounts in place now.

- [ ] **Step 3: Add CSS** to `public/assets/app.css`:

```css
.topbar-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.report-winner { border-left: 4px solid #2563eb; }
.report-winner.is-winner { background: linear-gradient(90deg, rgba(37,99,235,.08), transparent); }
.report-winner.is-pending { border-left-color: #94a3b8; }
.badge-winner {
    display: inline-block;
    margin-left: 8px;
    padding: 2px 8px;
    border-radius: 999px;
    background: #2563eb;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
}
.table tr.is-board-winner { background: rgba(37, 99, 235, .06); }
.panel-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; flex-wrap: wrap; }
.panel-head h2 { margin: 0; }
.report-charts { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
.report-chart-card h3 { margin: 0 0 8px; font-size: 14px; }
.pagination { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
.pagination a {
    display: inline-flex;
    min-width: 32px;
    height: 32px;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    text-decoration: none;
    color: inherit;
    padding: 0 8px;
}
.pagination a.active { background: #2563eb; border-color: #2563eb; color: #fff; }
```

- [ ] **Step 4: Manual smoke** — open a finished room report; winner banner + pagination links render (PDF 404 until Task 4 — hide PDF buttons temporarily OR accept 404 until Task 4). Prefer leaving PDF hrefs; Task 4 follows immediately.

- [ ] **Step 5: Commit**

```bash
git add app/Controllers/Teacher/ReportController.php app/Views/teacher/games/report.php public/assets/app.css
git commit -m "feat: modernize game report with winner banner and pagination"
```

---

### Task 3: Chart.js on report page

**Files:**
- Modify: `app/Views/teacher/games/report.php` (scripts section)

- [ ] **Step 1: Append scripts section** after content section:

```php
<?= $this->section('scripts') ?>
<?php if (($report['charts']['accuracy']['labels'] ?? []) !== [] || ($report['charts']['team_results']['labels'] ?? []) !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    var charts = <?= json_encode($report['charts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var acc = document.getElementById('chart-accuracy');
    if (acc && charts.accuracy && charts.accuracy.labels.length) {
        new Chart(acc, {
            type: 'bar',
            data: {
                labels: charts.accuracy.labels,
                datasets: [{ label: 'Akurasi %', data: charts.accuracy.values, backgroundColor: '#2563eb' }]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, max: 100 } },
                plugins: { legend: { display: false } }
            }
        });
    }
    var teams = document.getElementById('chart-teams');
    if (teams && charts.team_results && charts.team_results.labels.length) {
        new Chart(teams, {
            type: 'bar',
            data: {
                labels: charts.team_results.labels,
                datasets: [
                    { label: 'Benar', data: charts.team_results.correct, backgroundColor: '#16a34a' },
                    { label: 'Belum tepat', data: charts.team_results.wrong, backgroundColor: '#dc2626' }
                ]
            },
            options: {
                responsive: true,
                scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }
            }
        });
    }
})();
</script>
<?php endif ?>
<?= $this->endSection() ?>
```

- [ ] **Step 2: Manual** — finished room with answers shows both charts.

- [ ] **Step 3: Commit**

```bash
git add app/Views/teacher/games/report.php
git commit -m "feat: add Chart.js accuracy and team result graphs to game report"
```

---

### Task 4: Dompdf dependency + PDF export

**Files:**
- Modify: `composer.json` / `composer.lock` (via composer require)
- Modify: `app/Config/Routes.php`
- Modify: `app/Controllers/Teacher/ReportController.php`
- Create: `app/Views/teacher/games/report_pdf.php`
- Create: `tests/database/GameReportPdfRenderTest.php` (optional but recommended)

- [ ] **Step 1: Install Dompdf**

Run from project root:

```bash
composer require dompdf/dompdf
```

Expected: package in `composer.lock`, `vendor/dompdf/dompdf` present.

- [ ] **Step 2: Add route**

In `app/Config/Routes.php` after the report route:

```php
$routes->get('teacher/games/(:segment)/report/pdf', 'Teacher\ReportController::pdf/$1', ['filter' => 'teacherAccess']);
```

- [ ] **Step 3: Create PDF view** `app/Views/teacher/games/report_pdf.php`:

```php
<?php
$room = $report['room'];
$winner = $report['winner'];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        h2 { font-size: 14px; margin: 18px 0 8px; }
        .muted { color: #555; }
        .box { border: 1px solid #ccc; padding: 10px; margin: 12px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f3f3f3; }
    </style>
</head>
<body>
    <h1>Laporan Analisis Soal</h1>
    <p class="muted"><?= esc($room['title']) ?> · PIN <?= esc($room['pin']) ?> · <?= esc(date('Y-m-d H:i')) ?></p>

    <div class="box">
        <?php if ($winner): ?>
            <strong>Pemenang (Juara Papan):</strong> <?= esc($winner['name']) ?><br>
            Kotak <?= esc((string) $winner['position']) ?> / <?= esc((string) ($room['max_position'] ?? '-')) ?> ·
            Skor <?= esc((string) $winner['score']) ?>
        <?php else: ?>
            <strong>Pemenang:</strong> Belum ada pemenang (status <?= esc($room['status']) ?>)
        <?php endif ?>
    </div>

    <h2>Analisis Soal</h2>
    <table>
        <thead>
        <tr><th>Soal</th><th>Jawaban</th><th>Benar</th><th>Akurasi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($report['questionStats'] as $stat): ?>
            <?php
                $total = (int) $stat['total_answers'];
                $correct = (int) $stat['correct_answers'];
                $accuracy = $total > 0 ? round(($correct / $total) * 100) : 0;
            ?>
            <tr>
                <td><?= esc($stat['stem']) ?></td>
                <td><?= esc((string) $total) ?></td>
                <td><?= esc((string) $correct) ?></td>
                <td><?= esc((string) $accuracy) ?>%</td>
            </tr>
        <?php endforeach ?>
        <?php if ($report['questionStats'] === []): ?>
            <tr><td colspan="4">Belum ada jawaban.</td></tr>
        <?php endif ?>
        </tbody>
    </table>
</body>
</html>
```

- [ ] **Step 4: Add `pdf` method to controller**

```php
use Dompdf\Dompdf;
use Dompdf\Options;

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
```

- [ ] **Step 5: PHPUnit — Dompdf renders non-empty PDF bytes**

Create `tests/database/GameReportPdfRenderTest.php`:

```php
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
```

Run: `./vendor/bin/phpunit --filter GameReportPdfRenderTest`  
Expected: OK.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock app/Config/Routes.php app/Controllers/Teacher/ReportController.php app/Views/teacher/games/report_pdf.php tests/database/GameReportPdfRenderTest.php
git commit -m "feat: export game question analysis report as PDF via Dompdf"
```

---

### Task 5: Full regression + manual QA

- [ ] **Step 1:** `./vendor/bin/phpunit` — all green.

- [ ] **Step 2: Manual checklist**

- [ ] Room FINISHED: banner menampilkan nama pemenang + badge Juara Papan di leaderboard.
- [ ] Room LOBBY/PLAYING: “Belum ada pemenang”.
- [ ] Pagination soal & jawaban menjaga query param satu sama lain.
- [ ] Grafik tampil bila ada data; empty state bila kosong.
- [ ] Tombol Export PDF mengunduh `laporan-{pin}-analisis-soal.pdf` berisi pemenang + semua soal.
- [ ] Guru lain / tanpa login tidak bisa akses PDF (ownership).

- [ ] **Step 3:** Fix issues if any; commit `fix: polish game report QA findings` only when needed.

---

## Self-review vs spec

| Spec | Task |
|---|---|
| Banner pemenang | Task 2 |
| Leaderboard + badge | Task 1–2 |
| Pagination 10/15 | Task 1–2 |
| Chart.js 2 grafik | Task 1 data + Task 3 |
| Dompdf PDF pemenang+analisis | Task 4 |
| Ownership | existing TenantContext on show/pdf |

No TBD placeholders. Method names: `roomReport`, `paginate`, `pdf`, keys `winner`, `charts`, `questionStatsPage`, `answersPage`.

---

## Execution handoff

Plan saved. Choose:

1. **Subagent-Driven (recommended)** — fresh subagent per task + reviews  
2. **Inline Execution** — execute in this session with checkpoints
