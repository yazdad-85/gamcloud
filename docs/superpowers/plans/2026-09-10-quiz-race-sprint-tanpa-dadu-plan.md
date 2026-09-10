# Quiz Race — Sprint Tanpa Dadu Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `QUIZ_RACE` from a `PlannedModeEngine` placeholder into a fully playable second game mode — a dice-free, straight-lane sprint where teams pick their own question difficulty each turn (EASY/MEDIUM/HARD = bigger steps for bigger risk) — for `TEACHER_CENTRALIZED` (Mode Tanpa Device) rooms.

**Architecture:** `GameEngine` gets a new `selectDifficultyTier()` method (a dice-free sibling of `roll()`) and a `game_mode === 'QUIZ_RACE'` branch inside `answer()` that computes movement via a new, pure, DB-free `RaceTrackService` class instead of the existing dice/snake/ladder-specific `movementForCorrectAnswer()`. Board templates gain a `game_mode` column so Quiz Race tracks (no ladders/snakes, procedurally-sized per room) don't get mixed up with Ular Tangga's grid themes. Everything else — team roster, PIN-join rejection for `TEACHER_CENTRALIZED`, manual answer-timer start, idempotency, events, Control Game panel — is reused unchanged.

**Tech Stack:** PHP 8 / CodeIgniter 4, MySQL/MariaDB, vanilla JS (`public/assets/app.js`), PHPUnit with `DatabaseTestTrait` for engine tests and plain `CIUnitTestCase` for the new pure-logic unit tests.

---

## Before You Start

- Working directory for every command: `/Users/mbp19/Documents/YAZDAD/APLIKASI PRODUKSI/games/ular-tangga`.
- Run the whole suite with `vendor/bin/phpunit` (single unnamed testsuite covering `./tests`).
- Design doc: `docs/superpowers/specs/2026-09-10-quiz-race-mode-design.md`. Read it once if anything below feels unmotivated.
- No HTTP/feature tests exist in this repo — engine logic is tested by calling `GameEngine` methods directly (see `tests/database/TeacherCentralizedModeTest.php` for the exact pattern this plan follows). No JS/view tests exist either — UI tasks end with a manual browser verification checklist.
- Every `GameEngine` method that inserts/updates a row only persists fields listed in that model's `$allowedFields` — forgetting a new column there is a common way to silently lose data.

## Scope for this plan (read this before starting Task 1)

The design spec (Bagian D) describes **five** race tiles (Boost, Oil Spill, Pit Stop, Nitro, Duel Susul) and a lap checkpoint reward of "1 Nitro gratis." This plan deliberately ships a smaller, fully-working slice, and defers the rest to a follow-up plan:

**In scope here:**
- `QUIZ_RACE` becomes playable, restricted to `TEACHER_CENTRALIZED` rooms only (Balapan Serentak for `TEAM_DEVICE` is a separate, later plan — see the design spec's "Catatan Teknis" section on why it needs a different data model).
- Dice-free tier-choice turn loop (EASY +1 / MEDIUM +2 / HARD +3, wrong = 0, uniform).
- Two of the five race tiles: **Boost** (instant +2 extra steps) and **Oil Spill** (locks the team to EASY-only on their next turn). Both are single-step effects with no extra turn state, so they're cheap and fully testable now.
- Laps as checkpoints: crossing a lap boundary grants an immediate **+1 bonus step** (a placeholder-free, fully working reward — this is what gets upgraded to "1 Nitro gratis" once the follow-up plan ships Nitro). The lap-based *automatic difficulty zone* from the spec does **not** apply here — per the spec's own fix, that only applies to Balapan Serentak, since Sprint Tanpa Dadu's difficulty is already player-chosen.
- Non-blocking bank-soal-vs-track-length warning on the Create Game form.
- 3-theme track catalog (Stadion Atletik Senja, Arena Kartun Ceria, Arena Neon Digital).

**Explicitly out of scope, deferred to a follow-up plan:**
- **Pit Stop, Nitro, Duel Susul.** Pit Stop's only job is blocking Duel Susul, so it's meaningless without it; Nitro and Duel Susul both need brand-new turn states (mirroring `MYSTERY_CHOICE_PENDING`/`SNAKE_REDEMPTION_ACTIVE`) and are substantial enough to deserve their own focused plan.
- Balapan Serentak (`TEAM_DEVICE` engine) — needs a new "shared round" data model, per the spec.
- A custom visual lane/stadium renderer. `renderBoard()` in `app.js` already has a generic fallback for any `mode_state.renderer` other than `'snakes_ladders_board'` (shows a `.mode-placeholder` box with the mode's label). This plan relies on that existing fallback; a real animated lane view is cosmetic polish for later.

---

## Task 1: Schema — `game_mode` on board templates, `lap_count` on rooms, `selected_tier` on turns

**Files:**
- Create: `app/Database/Migrations/2026-09-10-000002_AddGameModeToBoardTemplates.php`
- Create: `app/Database/Migrations/2026-09-10-000003_AddLapCountToGameRooms.php`
- Create: `app/Database/Migrations/2026-09-10-000004_AddSelectedTierToGameTurns.php`
- Modify: `app/Models/BoardTemplateModel.php`
- Modify: `app/Models/GameRoomModel.php`
- Modify: `app/Models/GameTurnModel.php`

- [ ] **Step 1: Create the `board_templates.game_mode` migration**

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddGameModeToBoardTemplates extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('board_templates', [
            'game_mode' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'SNAKES_LADDERS',
                'after' => 'name',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('board_templates', 'game_mode');
    }
}
```

Save as `app/Database/Migrations/2026-09-10-000002_AddGameModeToBoardTemplates.php`.

- [ ] **Step 2: Create the `game_rooms.lap_count` migration**

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLapCountToGameRooms extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_rooms', [
            'lap_count' => [
                'type' => 'INTEGER',
                'default' => 1,
                'after' => 'max_position',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_rooms', 'lap_count');
    }
}
```

Save as `app/Database/Migrations/2026-09-10-000003_AddLapCountToGameRooms.php`.

- [ ] **Step 3: Create the `game_turns.selected_tier` migration**

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSelectedTierToGameTurns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_turns', [
            'selected_tier' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'null' => true,
                'after' => 'dice_value',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_turns', 'selected_tier');
    }
}
```

Save as `app/Database/Migrations/2026-09-10-000004_AddSelectedTierToGameTurns.php`.

- [ ] **Step 4: Run the migrations**

Run: `php spark migrate`
Expected: three new migrations run with no errors.

- [ ] **Step 5: Add the new fields to each model's `$allowedFields`**

In `app/Models/BoardTemplateModel.php`, add `'game_mode',` right after `'name',`:

```php
    protected $allowedFields = ['public_uuid', 'name', 'game_mode', 'tile_count', 'ladders_json', 'snakes_json', 'special_tiles_json', 'theme_json', 'status'];
```

In `app/Models/GameRoomModel.php`, add `'lap_count',` right after `'max_position',`:

```php
        'max_position',
        'lap_count',
        'game_mode',
```

In `app/Models/GameTurnModel.php`, add `'selected_tier',` right after `'dice_value',`:

```php
        'dice_value',
        'selected_tier',
        'question_id',
```

- [ ] **Step 6: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: all existing tests still pass (these are purely additive nullable/defaulted columns).

- [ ] **Step 7: Commit**

```bash
git add app/Database/Migrations/2026-09-10-000002_AddGameModeToBoardTemplates.php app/Database/Migrations/2026-09-10-000003_AddLapCountToGameRooms.php app/Database/Migrations/2026-09-10-000004_AddSelectedTierToGameTurns.php app/Models/BoardTemplateModel.php app/Models/GameRoomModel.php app/Models/GameTurnModel.php
git commit -m "feat: add schema for Quiz Race board templates, lap count, and tier selection"
```

---

## Task 2: `RaceTrackService` — pure movement/tile-effect/lap logic

This is a standalone, DB-free class so its rules (tier distances, tile effects, lap math) can be unit-tested in isolation before `GameEngine` ever touches it.

**Files:**
- Create: `app/Services/Game/RaceTrackService.php`
- Test: `tests/unit/RaceTrackServiceTest.php` (new file)

- [ ] **Step 1: Write the failing tests**

Create `tests/unit/RaceTrackServiceTest.php`:

```php
<?php

use App\Services\Game\RaceTrackService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RaceTrackServiceTest extends CIUnitTestCase
{
    public function testStepsForTierReturnsExpectedDistances(): void
    {
        $race = new RaceTrackService();

        $this->assertSame(1, $race->stepsForTier('EASY'));
        $this->assertSame(2, $race->stepsForTier('MEDIUM'));
        $this->assertSame(3, $race->stepsForTier('HARD'));
    }

    public function testMovementForTierAnswerAdvancesByTierSteps(): void
    {
        $race = new RaceTrackService();
        $room = ['max_position' => 24];
        $board = ['special_tiles_json' => '[]'];

        $movement = $race->movementForTierAnswer(5, 'MEDIUM', $room, $board);

        $this->assertSame(5, $movement['from']);
        $this->assertSame(7, $movement['landed']);
        $this->assertSame(7, $movement['to']);
        $this->assertNull($movement['special']);
    }

    public function testMovementForTierAnswerClampsAtFinishLine(): void
    {
        $race = new RaceTrackService();
        $room = ['max_position' => 24];
        $board = ['special_tiles_json' => '[]'];

        $movement = $race->movementForTierAnswer(23, 'HARD', $room, $board);

        $this->assertSame(24, $movement['to']);
    }

    public function testBoostTileAddsExtraSteps(): void
    {
        $race = new RaceTrackService();
        $room = ['max_position' => 24];
        $board = ['special_tiles_json' => json_encode([
            ['tile' => 7, 'type' => 'BONUS', 'steps' => 2, 'label' => 'Boost'],
        ])];

        $movement = $race->movementForTierAnswer(5, 'MEDIUM', $room, $board);

        $this->assertSame(7, $movement['landed']);
        $this->assertSame(9, $movement['to']);
        $this->assertSame('BOOST', $movement['special']);
    }

    public function testBoostTileClampsAtFinishLine(): void
    {
        $race = new RaceTrackService();
        $room = ['max_position' => 8];
        $board = ['special_tiles_json' => json_encode([
            ['tile' => 7, 'type' => 'BONUS', 'steps' => 5, 'label' => 'Boost'],
        ])];

        $movement = $race->movementForTierAnswer(5, 'MEDIUM', $room, $board);

        $this->assertSame(8, $movement['to']);
    }

    public function testOilSpillTileFlagsSpecialWithoutMovingBack(): void
    {
        $race = new RaceTrackService();
        $room = ['max_position' => 24];
        $board = ['special_tiles_json' => json_encode([
            ['tile' => 7, 'type' => 'TRAP', 'label' => 'Oil Spill'],
        ])];

        $movement = $race->movementForTierAnswer(5, 'MEDIUM', $room, $board);

        $this->assertSame(7, $movement['to']);
        $this->assertSame('OIL_SPILL', $movement['special']);
    }

    public function testLapForPositionDividesTrackEvenly(): void
    {
        $race = new RaceTrackService();

        $this->assertSame(1, $race->lapForPosition(1, 30, 5));
        $this->assertSame(1, $race->lapForPosition(6, 30, 5));
        $this->assertSame(2, $race->lapForPosition(7, 30, 5));
        $this->assertSame(5, $race->lapForPosition(30, 30, 5));
    }

    public function testCheckpointCrossedDetectsLapBoundaryOnly(): void
    {
        $race = new RaceTrackService();

        $this->assertFalse($race->checkpointCrossed(4, 6, 30, 5));
        $this->assertTrue($race->checkpointCrossed(5, 7, 30, 5));
    }

    public function testGenerateTrackTilesCyclesThroughRaceTypes(): void
    {
        $race = new RaceTrackService();

        $tiles = $race->generateTrackTiles(12);

        $this->assertSame([
            ['tile' => 4, 'type' => 'BONUS', 'label' => 'Boost', 'steps' => 2],
            ['tile' => 8, 'type' => 'TRAP', 'label' => 'Oil Spill'],
        ], $tiles);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/unit/RaceTrackServiceTest.php`
Expected: FAIL — `Class "App\Services\Game\RaceTrackService" not found`.

- [ ] **Step 3: Create `RaceTrackService`**

Create `app/Services/Game/RaceTrackService.php`:

```php
<?php

namespace App\Services\Game;

class RaceTrackService
{
    private const TIER_STEPS = [
        'EASY' => 1,
        'MEDIUM' => 2,
        'HARD' => 3,
    ];

    private const RACE_TILE_TYPES = ['BONUS', 'TRAP'];

    public function stepsForTier(string $tier): int
    {
        return self::TIER_STEPS[strtoupper($tier)] ?? 0;
    }

    public function movementForTierAnswer(int $from, string $tier, array $room, array $board): array
    {
        $maxPosition = (int) $room['max_position'];
        $steps = $this->stepsForTier($tier);
        $landed = min($maxPosition, $from + $steps);
        $tileEffect = $this->applyRaceTileEffect($landed, $board, $maxPosition);

        return [
            'from' => $from,
            'landed' => $landed,
            'to' => $tileEffect['to'],
            'special' => $tileEffect['special'],
            'effects' => $tileEffect['effects'],
            'score_delta' => 0,
        ];
    }

    private function applyRaceTileEffect(int $position, array $board, int $maxPosition): array
    {
        $tile = $this->specialTileAt($position, $board);
        if ($tile === null) {
            return ['to' => $position, 'special' => null, 'effects' => []];
        }

        $type = strtoupper((string) ($tile['type'] ?? ''));
        $label = (string) ($tile['label'] ?? $type);

        if ($type === 'BONUS') {
            $bonusSteps = max(1, (int) ($tile['steps'] ?? 2));
            $to = min($maxPosition, $position + $bonusSteps);

            return [
                'to' => $to,
                'special' => 'BOOST',
                'effects' => [[
                    'type' => 'BOOST',
                    'tile' => $position,
                    'steps' => $bonusSteps,
                    'label' => $label,
                ]],
            ];
        }

        if ($type === 'TRAP') {
            return [
                'to' => $position,
                'special' => 'OIL_SPILL',
                'effects' => [[
                    'type' => 'OIL_SPILL',
                    'tile' => $position,
                    'label' => $label,
                ]],
            ];
        }

        return ['to' => $position, 'special' => null, 'effects' => []];
    }

    public function specialTiles(array $board): array
    {
        $tiles = json_decode((string) ($board['special_tiles_json'] ?? ''), true);
        if (! is_array($tiles)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($tile): ?array {
            if (! is_array($tile)) {
                return null;
            }
            $position = (int) ($tile['tile'] ?? 0);
            $type = strtoupper((string) ($tile['type'] ?? ''));
            if ($position < 1 || ! in_array($type, self::RACE_TILE_TYPES, true)) {
                return null;
            }

            return [
                'tile' => $position,
                'type' => $type,
                'label' => (string) ($tile['label'] ?? $type),
                'steps' => isset($tile['steps']) ? (int) $tile['steps'] : null,
            ];
        }, $tiles)));
    }

    public function specialTileAt(int $position, array $board): ?array
    {
        foreach ($this->specialTiles($board) as $tile) {
            if ((int) $tile['tile'] === $position) {
                return $tile;
            }
        }

        return null;
    }

    public function lapForPosition(int $position, int $trackLength, int $lapCount): int
    {
        $lapCount = max(1, $lapCount);
        $trackLength = max(1, $trackLength);
        $segment = $trackLength / $lapCount;

        return (int) min($lapCount, max(1, ceil($position / $segment)));
    }

    public function checkpointCrossed(int $from, int $to, int $trackLength, int $lapCount): bool
    {
        return $this->lapForPosition($to, $trackLength, $lapCount) > $this->lapForPosition($from, $trackLength, $lapCount);
    }

    /**
     * Deterministically lays out Boost/Oil Spill tiles every 4 tiles along a
     * freshly-sized race track, skipping the start tile and never placing a
     * tile past the finish line.
     *
     * @return list<array{tile:int,type:string,label:string,steps?:int}>
     */
    public function generateTrackTiles(int $trackLength): array
    {
        $types = ['BONUS', 'TRAP'];
        $tiles = [];
        $typeIndex = 0;
        for ($position = 4; $position < $trackLength; $position += 4) {
            $type = $types[$typeIndex % count($types)];
            $tile = [
                'tile' => $position,
                'type' => $type,
                'label' => $type === 'BONUS' ? 'Boost' : 'Oil Spill',
            ];
            if ($type === 'BONUS') {
                $tile['steps'] = 2;
            }
            $tiles[] = $tile;
            $typeIndex++;
        }

        return $tiles;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/unit/RaceTrackServiceTest.php`
Expected: `OK (9 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add app/Services/Game/RaceTrackService.php tests/unit/RaceTrackServiceTest.php
git commit -m "feat: add RaceTrackService with tier movement, tile effects, and lap math"
```

---

## Task 3: Make `QuizRaceModeEngine` playable

**Files:**
- Modify: `app/Services/Game/Modes/QuizRaceModeEngine.php`
- Test: `tests/database/QuizRaceModeTest.php` (new file — this file accumulates tests across Tasks 3, 5, 6, 7 of this plan)

- [ ] **Step 1: Write the failing test**

Create `tests/database/QuizRaceModeTest.php`:

```php
<?php

use App\Models\GameTeamModel;
use App\Models\GameTurnModel;
use App\Models\QuestionOptionModel;
use App\Services\Game\GameEngine;
use App\Services\Game\Modes\GameModeCatalog;
use App\Services\Game\Uuid;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use DomainException;

/**
 * @internal
 */
final class QuizRaceModeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace = 'App';
    protected $seed = App\Database\Seeds\DemoGameSeeder::class;

    public function testQuizRaceIsPlayableAndUsesRaceRenderer(): void
    {
        $catalog = new GameModeCatalog();

        $this->assertContains('QUIZ_RACE', $catalog->playableKeys());
        $mode = $catalog->resolve('QUIZ_RACE');
        $this->assertSame('quiz_race_track', $mode->renderer());
        $this->assertTrue($mode->isPlayable());
    }

    private function actingAsTeacherOwner(int $teacherId): void
    {
        $users = model(UserModel::class);
        $suffix = bin2hex(random_bytes(4));
        $user = new User([
            'username' => 'test-teacher-' . $teacherId . '-' . $suffix,
            'email' => 'test-teacher-' . $teacherId . '-' . $suffix . '@example.test',
            'active' => true,
        ]);
        $user->setPassword('TestPassword123!');
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup('teacher');

        (new App\Models\TeacherModel())->update($teacherId, ['auth_user_id' => $user->id]);

        $this->actingAs($user);
    }

    private function roomId(string $roomUuid): int
    {
        $room = (new App\Models\GameRoomModel())->where('public_uuid', $roomUuid)->first();

        return (int) $room['id'];
    }

    private function latestTurn(string $roomUuid): array
    {
        return (new GameTurnModel())->where('room_id', $this->roomId($roomUuid))->orderBy('id', 'DESC')->first();
    }

    private function correctOptionId(int $questionId): int
    {
        $option = (new QuestionOptionModel())->where('question_id', $questionId)->where('is_correct', 1)->first();

        return (int) $option['id'];
    }

    private function teamInSnapshot(array $snapshot, string $teamUuid): array
    {
        foreach ($snapshot['teams'] as $team) {
            if ($team['uuid'] === $teamUuid) {
                return $team;
            }
        }

        throw new DomainException('Team not found in snapshot.');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/database/QuizRaceModeTest.php --filter testQuizRaceIsPlayableAndUsesRaceRenderer`
Expected: FAIL — `assertContains` fails because `QUIZ_RACE` isn't in `playableKeys()` yet.

- [ ] **Step 3: Rewrite `QuizRaceModeEngine`**

Replace the contents of `app/Services/Game/Modes/QuizRaceModeEngine.php`:

```php
<?php

namespace App\Services\Game\Modes;

class QuizRaceModeEngine implements GameModeEngineInterface
{
    public function key(): string
    {
        return 'QUIZ_RACE';
    }

    public function label(): string
    {
        return 'Quiz Race';
    }

    public function isPlayable(): bool
    {
        return true;
    }

    public function renderer(): string
    {
        return 'quiz_race_track';
    }

    public function initialState(array $room, array $board): array
    {
        return [
            'version' => 1,
            'board_model' => 'linear_track',
            'finish_position' => (int) ($room['max_position'] ?? $board['tile_count'] ?? 24),
        ];
    }

    public function publicState(array $room, array $board, ?array $turn, array $teams): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'status' => 'ACTIVE',
            'renderer' => $this->renderer(),
            'actions' => ['select_tier', 'answer'],
            'board_model' => 'linear_track',
            'finish_position' => (int) ($room['max_position'] ?? $board['tile_count'] ?? 24),
            'lap_count' => (int) ($room['lap_count'] ?? 1),
            'current_turn_state' => $turn['state'] ?? null,
            'team_count' => count($teams),
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/database/QuizRaceModeTest.php --filter testQuizRaceIsPlayableAndUsesRaceRenderer`
Expected: `OK (1 test, ...)`

- [ ] **Step 5: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: all tests pass — no existing test asserts an exhaustive/exact list of playable mode keys, only that `SNAKES_LADDERS` itself still resolves correctly.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Game/Modes/QuizRaceModeEngine.php tests/database/QuizRaceModeTest.php
git commit -m "feat: make QuizRaceModeEngine playable with a linear_track board model"
```

---

## Task 4: Seed 3 Quiz Race track themes (+ a HARD demo question)

**Files:**
- Modify: `app/Database/Seeds/DemoGameSeeder.php`

- [ ] **Step 1: Add a HARD-difficulty demo question**

In `app/Database/Seeds/DemoGameSeeder.php`, the `$questions` array currently has no `HARD` entry (`selectQuestion()` falls back to any published question when a difficulty has zero rows, which would make later Quiz Race tests that pick HARD tiers non-deterministic). Add this entry to the `$questions` array (right after the last existing entry, before the closing `];`):

```php
            [
                'stem' => 'Ibu kota Indonesia adalah ...',
                'difficulty' => 'HARD',
                'options' => ['A' => ['Bandung', false], 'B' => ['Jakarta', true], 'C' => ['Surabaya', false], 'D' => ['Medan', false]],
            ],
```

- [ ] **Step 2: Seed the 3 race board templates**

In `app/Database/Seeds/DemoGameSeeder.php`, replace the `foreach ($this->boardTemplates(...))` call and the `boardTemplates()` method's closing so a second seeding pass runs for race tracks. Replace:

```php
        foreach ($this->boardTemplates($ladders, $snakes, $specialTiles) as $template) {
            $this->upsertBoardTemplate($template, $now);
        }
```

with:

```php
        foreach ($this->boardTemplates($ladders, $snakes, $specialTiles) as $template) {
            $this->upsertBoardTemplate($template, $now);
        }

        foreach ($this->raceBoardTemplates() as $template) {
            $this->upsertBoardTemplate($template, $now);
        }
```

Then, in `upsertBoardTemplate()`, add `'game_mode' => $template['game_mode'] ?? 'SNAKES_LADDERS',` to the `$data` array (right after `'name' => $template['name'],`):

```php
    private function upsertBoardTemplate(array $template, string $now): int
    {
        $data = [
            'name' => $template['name'],
            'game_mode' => $template['game_mode'] ?? 'SNAKES_LADDERS',
            'tile_count' => 100,
            'ladders_json' => json_encode($template['ladders'], JSON_UNESCAPED_SLASHES),
            'snakes_json' => json_encode($template['snakes'], JSON_UNESCAPED_SLASHES),
            'special_tiles_json' => json_encode($template['special_tiles'], JSON_UNESCAPED_SLASHES),
            'theme_json' => json_encode($template['theme'], JSON_UNESCAPED_SLASHES),
            'status' => 'ACTIVE',
            'updated_at' => $now,
        ];
```

`tile_count` staying hardcoded at `100` here is fine — Quiz Race rooms always clone their own room-specific track size via `GameEngine::applyRaceTrackLength()` (Task 5), so this seeded row's `tile_count` is never actually used for a race; only its `theme_json` and `game_mode` matter.

Finally, add the new `raceBoardTemplates()` method right after the existing `boardTemplates()` method (before the closing `}` of the class):

```php
    private function raceBoardTemplates(): array
    {
        return [
            [
                'name' => 'Stadion Atletik Senja',
                'game_mode' => 'QUIZ_RACE',
                'ladders' => [],
                'snakes' => [],
                'special_tiles' => [],
                'theme' => [
                    'theme_key' => 'athletic_dusk',
                    'name' => 'Stadion Atletik Senja',
                    'palette' => [
                        'board' => '#7c2d12',
                        'board2' => '#9a3412',
                        'tileA' => '#fdba74',
                        'tileB' => '#fb923c',
                        'accent' => '#facc15',
                        'snake' => '#7c2d12',
                        'ladder' => '#facc15',
                    ],
                ],
            ],
            [
                'name' => 'Arena Kartun Ceria',
                'game_mode' => 'QUIZ_RACE',
                'ladders' => [],
                'snakes' => [],
                'special_tiles' => [],
                'theme' => [
                    'theme_key' => 'cartoon_arena',
                    'name' => 'Arena Kartun Ceria',
                    'palette' => [
                        'board' => '#0ea5e9',
                        'board2' => '#38bdf8',
                        'tileA' => '#ffffff',
                        'tileB' => '#fde68a',
                        'accent' => '#4ade80',
                        'snake' => '#0ea5e9',
                        'ladder' => '#4ade80',
                    ],
                ],
            ],
            [
                'name' => 'Arena Neon Digital',
                'game_mode' => 'QUIZ_RACE',
                'ladders' => [],
                'snakes' => [],
                'special_tiles' => [],
                'theme' => [
                    'theme_key' => 'neon_arena',
                    'name' => 'Arena Neon Digital',
                    'palette' => [
                        'board' => '#0b1020',
                        'board2' => '#0f172a',
                        'tileA' => '#111827',
                        'tileB' => '#1e293b',
                        'accent' => '#22d3ee',
                        'snake' => '#22d3ee',
                        'ladder' => '#f472b6',
                    ],
                ],
            ],
        ];
    }
```

- [ ] **Step 3: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: all tests pass — this only adds rows, it doesn't change any existing behavior.

- [ ] **Step 4: Commit**

```bash
git add app/Database/Seeds/DemoGameSeeder.php
git commit -m "feat: seed 3 Quiz Race track themes and a HARD demo question"
```

---

## Task 5: `GameEngine::createRoom()` support for Quiz Race

Adds: filtering board templates by `game_mode`, generating a room-sized race track (`applyRaceTrackLength()`), `lap_count`, forcing `clamp_finish` + disabling `near_finish_bonus` for races, and rejecting `QUIZ_RACE` + `TEAM_DEVICE` (Balapan Serentak doesn't exist yet — see Scope section above).

**Files:**
- Modify: `app/Services/Game/GameEngine.php`
- Test: `tests/database/QuizRaceModeTest.php`

- [ ] **Step 1: Write the failing tests**

Add these methods to the `QuizRaceModeTest` class body (before the private helper methods):

```php
    public function testCreateRoomWithQuizRaceUsesRaceBoardAndAppliesTrackLength(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Quiz Race Track Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'track_length' => 18,
            'lap_count' => 3,
        ]);

        $this->assertSame('QUIZ_RACE', $snapshot['room']['game_mode']);
        $this->assertSame(18, $snapshot['room']['max_position']);
        $this->assertSame(3, $snapshot['room']['lap_count']);
        $this->assertSame('clamp_finish', $snapshot['room']['finish_rule']);
        $this->assertSame([], $snapshot['board']['ladders']);
        $this->assertSame([], $snapshot['board']['snakes']);
    }

    public function testCreateRoomRejectsQuizRaceWithTeamDeviceParticipation(): void
    {
        $engine = new GameEngine();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tanpa Device');
        $engine->createRoom(1, 'Quiz Race Reject Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
        ]);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/database/QuizRaceModeTest.php --filter "CreateRoomWithQuizRace|CreateRoomRejectsQuizRace"`
Expected: `testCreateRoomWithQuizRaceUsesRaceBoardAndAppliesTrackLength` FAILs (no `lap_count` key in the room payload yet, board is still the 100-tile grid). `testCreateRoomRejectsQuizRaceWithTeamDeviceParticipation` FAILs too (no exception thrown yet).

- [ ] **Step 3: Rewrite `createRoom()`**

In `app/Services/Game/GameEngine.php`, replace the entire body of `createRoom()` (`GameEngine.php:40-131`) with:

```php
    public function createRoom(int $teacherId, string $title, array $options = []): array
    {
        if (empty($options['skip_quota'])) {
            $this->assertTeacherRoomQuota($teacherId);
        }

        $gameModeKey = $this->validOption(strtoupper((string) ($options['game_mode'] ?? 'SNAKES_LADDERS')), $this->modes->playableKeys(), 'SNAKES_LADDERS');
        $gameMode = $this->modes->resolve($gameModeKey);
        $participationMode = $this->validOption(
            strtoupper((string) ($options['participation_mode'] ?? 'TEAM_DEVICE')),
            ['TEAM_DEVICE', 'TEACHER_CENTRALIZED'],
            'TEAM_DEVICE'
        );
        if ($gameModeKey === 'QUIZ_RACE' && $participationMode !== 'TEACHER_CENTRALIZED') {
            throw new DomainException('Quiz Race saat ini hanya tersedia untuk Mode Tanpa Device (Terpusat).');
        }

        $boards = new BoardTemplateModel();
        $boardTemplateId = (int) ($options['board_template_id'] ?? 0);
        $board = null;
        if ($boardTemplateId > 0) {
            $board = $boards->where('id', $boardTemplateId)->where('status', 'ACTIVE')->where('game_mode', $gameModeKey)->first();
        }
        $board ??= (new BoardTemplateModel())->where('status', 'ACTIVE')->where('game_mode', $gameModeKey)->first();
        if ($board === null) {
            throw new DomainException('Board template belum tersedia. Jalankan seeder demo lebih dulu.');
        }

        $finishRule = $this->validOption((string) ($options['finish_rule'] ?? 'clamp_finish'), ['clamp_finish', 'exact_finish'], 'clamp_finish');
        $lapCount = 1;

        if ($gameModeKey === 'QUIZ_RACE') {
            $trackLength = max(6, min(60, (int) ($options['track_length'] ?? 24)));
            $board = $this->applyRaceTrackLength($board, $trackLength);
            $lapCount = max(1, min(10, (int) ($options['lap_count'] ?? 5)));
            $finishRule = 'clamp_finish';
        } else {
            if (isset($options['board_size']) && in_array((int) $options['board_size'], [50, 70], true)) {
                $board = $this->applyBoardSize($board, (int) $options['board_size']);
            }

            if (isset($options['mystery_tile_count'])) {
                $requestedMysteryCount = max(0, min(6, (int) $options['mystery_tile_count']));
                $board = $this->applyMysteryTileCount($board, $requestedMysteryCount);
                if ($this->specialTileCount($board, 'MYSTERY') !== $requestedMysteryCount) {
                    throw new DomainException('Konfigurasi Kotak Mystery gagal diterapkan. Room tidak dibuat.');
                }
            }
        }

        $pin = $this->uniquePin();
        $projectorToken = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $turnOrderMode = $this->validOption((string) ($options['turn_order_mode'] ?? 'random'), ['random', 'join_order'], 'random');
        $scoring = $this->scoringRules($options['scoring'] ?? []);
        if ($gameModeKey === 'QUIZ_RACE') {
            $scoring['near_finish_bonus'] = false;
        }
        $questionSelectionSource = is_array($options['question_selection'] ?? null)
            ? $options['question_selection']
            : [];
        if (array_key_exists('topic_uuids', $questionSelectionSource)) {
            $questionSelectionSource = array_merge(
                $questionSelectionSource,
                $this->resolveQuestionTopics($teacherId, $questionSelectionSource['topic_uuids'])
            );
        }
        $questionSelection = $this->questionSelectionRules($questionSelectionSource, (int) $board['tile_count']);
        $baseRoomState = [
            'max_position' => (int) $board['tile_count'],
        ];
        $roomId = (new GameRoomModel())->insert([
            'public_uuid' => Uuid::v4(),
            'teacher_id' => $teacherId,
            'board_template_id' => $board['id'],
            'pin' => $pin,
            'projector_token' => $projectorToken,
            'projector_token_hash' => hash('sha256', $projectorToken),
            'title' => $title,
            'status' => 'LOBBY',
            'state_version' => 1,
            'question_time_seconds' => $this->config->defaultQuestionTime,
            'redemption_time_seconds' => $this->config->redemptionTime,
            'max_teams' => 6,
            'max_position' => (int) $board['tile_count'],
            'lap_count' => $lapCount,
            'game_mode' => $gameMode->key(),
            'participation_mode' => $participationMode,
            'mode_state_json' => json_encode($gameMode->initialState($baseRoomState, $board), JSON_UNESCAPED_SLASHES),
            'turn_order_mode' => $turnOrderMode,
            'finish_rule' => $finishRule,
            'scoring_json' => json_encode($scoring, JSON_UNESCAPED_SLASHES),
            'question_selection_json' => json_encode($questionSelection, JSON_UNESCAPED_SLASHES),
            'expires_at' => date('Y-m-d H:i:s', time() + ($this->config->pinTtlMinutes * 60)),
            'created_at' => $now,
            'updated_at' => $now,
        ], true);

        $room = $this->roomById((int) $roomId);
        $this->recordEvent($room, 'room.created', [
            'pin' => $pin,
            'title' => $title,
            'game_mode' => $gameMode->key(),
            'question_topics' => $questionSelection['topics'],
            'mystery_tile_count' => $this->specialTileCount($board, 'MYSTERY'),
        ]);

        return $this->snapshot($room['public_uuid'], null, true);
    }
```

- [ ] **Step 4: Add `applyRaceTrackLength()` and the `RaceTrackService` instance**

Add `private RaceTrackService $race;` right after `private GameModeCatalog $modes;` (`GameEngine.php:30`), and initialize it in the constructor right after `$this->modes = new GameModeCatalog();` (`GameEngine.php:37`):

```php
    private BaseConnection $db;
    private GameConfig $config;
    private GameModeCatalog $modes;
    private RaceTrackService $race;

    public function __construct(
        private readonly RealtimeService $realtime = new RealtimeService()
    ) {
        $this->db = Database::connect();
        $this->config = config(GameConfig::class);
        $this->modes = new GameModeCatalog();
        $this->race = new RaceTrackService();
    }
```

`RaceTrackService` lives in the same `App\Services\Game` namespace as `GameEngine`, so no new `use` import is needed.

Then add `applyRaceTrackLength()` right after `applyBoardSize()` (`GameEngine.php:2053-2072`, i.e. right after that method's closing `}`):

```php
    private function applyRaceTrackLength(array $board, int $trackLength): array
    {
        $newBoardId = (new BoardTemplateModel())->insert([
            'public_uuid' => Uuid::v4(),
            'name' => $board['name'] . ' (' . $trackLength . ' Kotak)',
            'game_mode' => 'QUIZ_RACE',
            'tile_count' => $trackLength,
            'ladders_json' => json_encode([], JSON_UNESCAPED_SLASHES),
            'snakes_json' => json_encode([], JSON_UNESCAPED_SLASHES),
            'special_tiles_json' => json_encode($this->race->generateTrackTiles($trackLength), JSON_UNESCAPED_SLASHES),
            'theme_json' => $board['theme_json'],
            'status' => 'ROOM_INSTANCE',
        ], true);

        return (new BoardTemplateModel())->find($newBoardId);
    }
```

This mirrors `applyBoardSize()`'s existing `ROOM_INSTANCE` clone pattern exactly — `deleteRoom()` already cleans up `ROOM_INSTANCE` board rows regardless of game mode, so no changes are needed there.

- [ ] **Step 5: Add `lap_count` to the public room payload**

In `publicRoom()` (`GameEngine.php:2178-2207`), add `'lap_count' => (int) ($room['lap_count'] ?? 1),` right after `'max_position' => (int) $room['max_position'],`:

```php
            'max_position' => (int) $room['max_position'],
            'lap_count' => (int) ($room['lap_count'] ?? 1),
            'game_mode' => $room['game_mode'] ?? 'SNAKES_LADDERS',
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/database/QuizRaceModeTest.php`
Expected: `OK (3 tests, ...)`

- [ ] **Step 7: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: all tests pass — every existing `createRoom()` call in other test files omits `game_mode` or passes `SNAKES_LADDERS`, so the new `where('game_mode', $gameModeKey)` board filter must still find the seeded grid templates (Task 4 tagged them `SNAKES_LADDERS` via the `upsertBoardTemplate()` default).

- [ ] **Step 8: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/QuizRaceModeTest.php
git commit -m "feat: support Quiz Race track sizing and lap count in createRoom()"
```

---

## Task 6: `GameEngine::selectDifficultyTier()` — the dice-free "roll"

**Files:**
- Modify: `app/Services/Game/GameEngine.php`
- Test: `tests/database/QuizRaceModeTest.php`

- [ ] **Step 1: Write the failing tests**

Add these methods to `QuizRaceModeTest` (before the private helpers):

```php
    public function testSelectDifficultyTierRecordsChosenTierAndDrawsQuestion(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Select Tier Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'track_length' => 18,
            'lap_count' => 3,
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Cepat')['team'];
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Lain');
        $engine->start($room['uuid']);

        $snapshot = $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'HARD');

        $this->assertSame('QUESTION_PENDING_START', $snapshot['current_turn']['state']);
        $this->assertNotNull($snapshot['current_turn']['question']);
        $this->assertSame('HARD', $snapshot['current_turn']['question']['difficulty']);
        $turn = $this->latestTurn($room['uuid']);
        $this->assertSame('HARD', $turn['selected_tier']);
    }

    public function testSelectDifficultyTierRejectsInvalidTier(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Select Tier Invalid Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);

        $this->expectException(DomainException::class);
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'IMPOSSIBLE');
    }

    public function testSelectDifficultyTierRejectsForNonQuizRaceRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Non Race Reject Test')['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Biasa')['team'];
        $engine->start($room['uuid']);

        $this->expectException(DomainException::class);
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'EASY');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/database/QuizRaceModeTest.php --filter SelectDifficultyTier`
Expected: FAIL — `Call to undefined method App\Services\Game\GameEngine::selectDifficultyTier()`.

- [ ] **Step 3: Add `selectDifficultyTier()`**

In `app/Services/Game/GameEngine.php`, add this method right after `startAnswerTimer()` (`GameEngine.php:390-415`, i.e. right before `roll()`):

```php
    public function selectDifficultyTier(string $roomUuid, string $teamUuid, string $tier, ?string $idempotencyKey = null): array
    {
        $room = $this->roomByUuid($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa. Permainan tidak bisa dilanjutkan.');
        if (($room['game_mode'] ?? 'SNAKES_LADDERS') !== 'QUIZ_RACE') {
            throw new DomainException('Aksi ini hanya berlaku untuk mode Quiz Race.');
        }

        $team = $this->teamByUuid($teamUuid, (int) $room['id']);
        $scope = 'select-tier:' . $room['public_uuid'] . ':' . $team['public_uuid'];
        if ($existing = $this->idempotentResponse($scope, $idempotencyKey)) {
            return $existing;
        }

        if ($room['status'] !== 'PLAYING') {
            throw new DomainException('Game belum dalam status PLAYING.');
        }
        if ((int) $room['current_team_id'] !== (int) $team['id']) {
            throw new DomainException('Belum giliran tim ini.');
        }

        $turn = $this->activeTurn((int) $room['id']);
        if ($turn === null || $turn['state'] !== 'ROLL_READY') {
            throw new DomainException('Tingkat soal hanya bisa dipilih saat ROLL_READY.');
        }

        $tier = strtoupper($tier);
        if (! in_array($tier, ['EASY', 'MEDIUM', 'HARD'], true)) {
            throw new DomainException('Tingkat soal tidak valid.');
        }

        $activeEffects = $this->teamEffects($team);
        if (! empty($activeEffects['oil_spill_lock'])) {
            if ($tier !== 'EASY') {
                throw new DomainException('Tim terkena Oil Spill — giliran ini hanya bisa memilih tingkat EASY.');
            }
            $activeEffects['oil_spill_lock'] = false;
            (new GameTeamModel())->update($team['id'], [
                'active_effects_json' => json_encode($activeEffects, JSON_UNESCAPED_SLASHES),
            ]);
        }

        $selectionRules = $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position']);
        $poolRecycled = false;
        $question = $this->selectQuestion(
            (int) $room['teacher_id'],
            $tier,
            $selectionRules['topic_ids'],
            (int) $room['id'],
            $poolRecycled
        );

        $now = date('Y-m-d H:i:s');
        $deferTimer = ($room['participation_mode'] ?? 'TEAM_DEVICE') === 'TEACHER_CENTRALIZED';
        $deadline = $deferTimer ? null : date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => $deferTimer ? 'QUESTION_PENDING_START' : 'QUESTION_ACTIVE',
            'selected_tier' => $tier,
            'question_id' => $question['id'],
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
        $this->bumpRoom($room['id']);
        $room = $this->roomById((int) $room['id']);

        if ($poolRecycled) {
            $this->recordEvent($room, 'question.pool_recycled', [
                'room_uuid' => $room['public_uuid'],
                'difficulty' => $tier,
                'topic_ids' => $selectionRules['topic_ids'],
                'reason' => 'exhausted',
            ]);
        }
        $this->recordEvent($room, 'tier.selected', [
            'team_uuid' => $team['public_uuid'],
            'tier' => $tier,
        ]);
        $this->recordEvent($room, 'question.started', [
            'team_uuid' => $team['public_uuid'],
            'turn_uuid' => $turn['public_uuid'],
            'question' => $this->publicQuestion($question),
            'selection' => [
                'strategy' => 'team_choice',
                'requested_difficulty' => $tier,
                'selected_difficulty' => $question['difficulty'],
            ],
            'deadline_at' => $deadline,
        ]);

        $response = $this->snapshot($room['public_uuid']);
        $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

        return $response;
    }
```

Note this method doesn't call `TenantContext::assertRoomOwner()` — same as `roll()`, authorization happens at the HTTP layer via `TeamSessionService::assertTeamSession()` (Task 8), not inside the engine method itself.

- [ ] **Step 4: Extend `teamEffects()` with the Oil Spill lock flag**

In `teamEffects()` (`GameEngine.php:2146-2156`), add the new key:

```php
    private function teamEffects(array $team): array
    {
        $effects = json_decode((string) ($team['active_effects_json'] ?? ''), true);
        if (! is_array($effects)) {
            $effects = [];
        }

        return [
            'safe_shield' => max(0, min(3, (int) ($effects['safe_shield'] ?? 0))),
            'oil_spill_lock' => (bool) ($effects['oil_spill_lock'] ?? false),
        ];
    }
```

This is read by `publicTeam()` already (it calls `teamEffects()` for the `active_effects` field), so the flag is automatically visible to the client too — no separate wiring needed there.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/database/QuizRaceModeTest.php`
Expected: `OK (6 tests, ...)`

- [ ] **Step 6: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: all tests pass — `teamEffects()`'s new key is additive and defaults to `false` for every existing `SNAKES_LADDERS` team.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/QuizRaceModeTest.php
git commit -m "feat: add selectDifficultyTier() as the dice-free roll for Quiz Race"
```

---

## Task 7: `GameEngine::answer()` — race movement, Boost/Oil Spill, lap checkpoint

**Files:**
- Modify: `app/Services/Game/GameEngine.php`
- Test: `tests/database/QuizRaceModeTest.php`

- [ ] **Step 1: Write the failing tests**

Add these methods to `QuizRaceModeTest` (before the private helpers):

```php
    public function testAnswerMovesTeamByTierStepsOnCorrectAnswer(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Race Answer Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'track_length' => 18,
            'lap_count' => 3,
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'MEDIUM');
        $engine->startAnswerTimer($room['uuid']);
        $turn = $this->latestTurn($room['uuid']);

        $snapshot = $engine->answer($room['uuid'], $team['public_uuid'], $this->correctOptionId((int) $turn['question_id']));

        // Track has no special tile at position 3 (procedurally generated
        // tiles land on 4, 8, 12, 16), so this is a clean +2 (MEDIUM) move.
        $this->assertSame(3, $this->teamInSnapshot($snapshot, $team['public_uuid'])['position']);
    }

    public function testAnswerWrongTierMovementStaysPutAtZeroSteps(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Race Wrong Answer Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'track_length' => 18,
            'lap_count' => 3,
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'HARD');
        $engine->startAnswerTimer($room['uuid']);
        $turn = $this->latestTurn($room['uuid']);
        $wrongOptionId = (int) (new QuestionOptionModel())
            ->where('question_id', $turn['question_id'])
            ->where('is_correct', 0)
            ->first()['id'];

        $snapshot = $engine->answer($room['uuid'], $team['public_uuid'], $wrongOptionId);

        $this->assertSame(1, $this->teamInSnapshot($snapshot, $team['public_uuid'])['position']);
    }

    public function testAnswerAppliesBoostTileForExtraSteps(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Race Boost Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'track_length' => 18,
            'lap_count' => 3,
        ])['room'];
        (new App\Models\BoardTemplateModel())->update(
            (new App\Models\GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'],
            ['special_tiles_json' => json_encode([['tile' => 3, 'type' => 'BONUS', 'steps' => 2, 'label' => 'Boost']])]
        );
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);
        // Start position 1, MEDIUM (+2) lands exactly on tile 3 (the Boost tile),
        // which then adds its own +2 -> final position 5.
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'MEDIUM');
        $engine->startAnswerTimer($room['uuid']);
        $turn = $this->latestTurn($room['uuid']);

        $snapshot = $engine->answer($room['uuid'], $team['public_uuid'], $this->correctOptionId((int) $turn['question_id']));

        $this->assertSame(5, $this->teamInSnapshot($snapshot, $team['public_uuid'])['position']);
    }

    public function testAnswerAppliesOilSpillLockRestrictingTeamsNextTierChoice(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Race Oil Spill Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'track_length' => 18,
            'lap_count' => 3,
        ])['room'];
        (new App\Models\BoardTemplateModel())->update(
            (new App\Models\GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'],
            ['special_tiles_json' => json_encode([['tile' => 3, 'type' => 'TRAP', 'label' => 'Oil Spill']])]
        );
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        $team2 = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Dua')['team'];
        $engine->start($room['uuid']);

        // Start position 1, MEDIUM (+2) lands exactly on tile 3 (the Oil Spill tile).
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'MEDIUM');
        $engine->startAnswerTimer($room['uuid']);
        $turn = $this->latestTurn($room['uuid']);
        $snapshot = $engine->answer($room['uuid'], $team['public_uuid'], $this->correctOptionId((int) $turn['question_id']));
        $this->assertTrue($this->teamInSnapshot($snapshot, $team['public_uuid'])['active_effects']['oil_spill_lock']);

        // Tim Dua takes a neutral turn so play comes back around to Tim Satu.
        $engine->selectDifficultyTier($room['uuid'], $team2['public_uuid'], 'EASY');
        $engine->startAnswerTimer($room['uuid']);
        $turn2 = $this->latestTurn($room['uuid']);
        $engine->answer($room['uuid'], $team2['public_uuid'], $this->correctOptionId((int) $turn2['question_id']));

        $this->expectException(DomainException::class);
        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'HARD');
    }

    public function testAnswerAppliesLapCheckpointBonusStep(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Race Checkpoint Test', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'track_length' => 18,
            'lap_count' => 3,
        ])['room'];
        // Empty track: no Boost/Oil Spill tiles to interfere with the checkpoint math.
        (new App\Models\BoardTemplateModel())->update(
            (new App\Models\GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'],
            ['special_tiles_json' => '[]']
        );
        $this->actingAsTeacherOwner(1);
        $team = (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        (new GameEngine())->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);
        (new GameTeamModel())->update($this->teamRowId($team['public_uuid']), ['position' => 5]);

        $engine->selectDifficultyTier($room['uuid'], $team['public_uuid'], 'MEDIUM');
        $engine->startAnswerTimer($room['uuid']);
        $turn = $this->latestTurn($room['uuid']);

        $snapshot = $engine->answer($room['uuid'], $team['public_uuid'], $this->correctOptionId((int) $turn['question_id']));

        // From 5, MEDIUM (+2) lands on 7 — track length 18 / 3 laps = lap boundaries
        // at 6 and 12, so 5 -> 7 crosses one boundary and earns +1 checkpoint bonus.
        $this->assertSame(8, $this->teamInSnapshot($snapshot, $team['public_uuid'])['position']);
    }

    private function teamRowId(string $teamUuid): int
    {
        return (int) (new GameTeamModel())->where('public_uuid', $teamUuid)->first()['id'];
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/database/QuizRaceModeTest.php --filter Answer`
Expected: FAIL — team position stays at the SNAKES_LADDERS dice-based value (or errors), since `answer()` doesn't know about `QUIZ_RACE` yet.

- [ ] **Step 3: Branch `answer()` on `game_mode` for movement**

In `app/Services/Game/GameEngine.php`, in `answer()`, replace this block (`GameEngine.php:539-551`):

```php
        $movement = $isCorrect
            ? $this->movementForCorrectAnswer($from, $dice, $room, $board, $team)
            : [
                'from' => $from,
                'rolled_to' => $from,
                'landed' => $from,
                'to' => $from,
                'special' => null,
                'effects' => [],
                'score_delta' => 0,
                'active_effects' => $this->teamEffects($team),
                'finish_bounced' => false,
            ];
```

with:

```php
        $isQuizRace = ($room['game_mode'] ?? 'SNAKES_LADDERS') === 'QUIZ_RACE';
        $movement = $isCorrect
            ? ($isQuizRace
                ? $this->movementForRaceTierAnswer($from, (string) $turn['selected_tier'], $room, $board, $team)
                : $this->movementForCorrectAnswer($from, $dice, $room, $board, $team))
            : [
                'from' => $from,
                'rolled_to' => $from,
                'landed' => $from,
                'to' => $from,
                'special' => null,
                'effects' => [],
                'score_delta' => 0,
                'active_effects' => $this->teamEffects($team),
                'finish_bounced' => false,
            ];

        if ($isQuizRace && $isCorrect && $this->race->checkpointCrossed($movement['from'], $movement['to'], (int) $room['max_position'], (int) ($room['lap_count'] ?? 1))) {
            $movement['to'] = min((int) $room['max_position'], $movement['to'] + 1);
            $movement['lap_checkpoint'] = true;
        }
```

- [ ] **Step 4: Record a `lap.checkpoint` event**

In `answer()`, right after the existing `foreach ($movement['effects'] ?? [] as $effect) { ... }` block (`GameEngine.php:669-679`), add:

```php
        if (! empty($movement['lap_checkpoint'])) {
            $this->recordEvent($room, 'lap.checkpoint', [
                'team_uuid' => $team['public_uuid'],
                'lap' => $this->race->lapForPosition($movement['to'], (int) $room['max_position'], (int) ($room['lap_count'] ?? 1)),
                'bonus_steps' => 1,
            ]);
        }
```

- [ ] **Step 5: Add `movementForRaceTierAnswer()`**

Add this private method right after `movementForCorrectAnswer()` (`GameEngine.php:1387-1472`, right after that method's closing `}`, before `applySpecialTileEffect()`):

```php
    private function movementForRaceTierAnswer(int $from, string $tier, array $room, array $board, array $team): array
    {
        $result = $this->race->movementForTierAnswer($from, $tier, $room, $board);
        $activeEffects = $this->teamEffects($team);
        if (($result['special'] ?? null) === 'OIL_SPILL') {
            $activeEffects['oil_spill_lock'] = true;
        }

        return $result + [
            'rolled_to' => $result['to'],
            'active_effects' => $activeEffects,
            'finish_bounced' => false,
            'pending_board_challenge' => null,
        ];
    }
```

Everything downstream in `answer()` (idempotency, scoring, the `GameTeamModel` update, `finished`/`pendingChallenge` detection, event recording) already reads these exact same keys generically and needs no further changes — `pending_board_challenge` stays `null` and `special` is never `'MYSTERY'` for a race turn, so the existing snake/ladder/mystery branches simply never trigger.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/database/QuizRaceModeTest.php`
Expected: `OK (10 tests, ...)`

- [ ] **Step 7: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: all tests pass — every existing `answer()`-touching test runs against `SNAKES_LADDERS` rooms (the default), where `$isQuizRace` is always `false` and the code takes the exact same path as before.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/QuizRaceModeTest.php
git commit -m "feat: wire tier-based movement, Boost/Oil Spill tiles, and lap checkpoints into answer()"
```

---

## Task 8: Route + controller action for tier selection

**Files:**
- Modify: `app/Config/Routes.php`
- Modify: `app/Controllers/Api/V1/RoomsController.php`

- [ ] **Step 1: Add the route**

In `app/Config/Routes.php`, add this line right after the `roll` route (`Routes.php:80`):

```php
    $routes->post('rooms/(:segment)/select-tier', 'Api\V1\RoomsController::selectTier/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
```

- [ ] **Step 2: Add the controller action**

In `app/Controllers/Api/V1/RoomsController.php`, add this method right after `roll()` (before `answer()`):

```php
    public function selectTier(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamUuid = (string) ($payload['team_uuid'] ?? $this->request->getGet('team'));
        $tier = (string) ($payload['tier'] ?? '');

        return $this->respond(function () use ($roomUuid, $teamUuid, $tier, $payload): array {
            (new TeamSessionService())->assertTeamSession($roomUuid, $teamUuid);

            return (new GameEngine())->selectDifficultyTier(
                $roomUuid,
                $teamUuid,
                $tier,
                $this->request->getHeaderLine('Idempotency-Key') ?: ($payload['idempotency_key'] ?? null)
            );
        });
    }
```

This mirrors `roll()` exactly, including the `TeamSessionService::assertTeamSession()` call — which already has a `TEACHER_CENTRALIZED` bypass for the room-owning teacher, so no changes are needed there for the teacher to act on a team's behalf.

- [ ] **Step 3: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: all tests pass (this is wiring with no covering HTTP test, matching this repo's existing convention of testing `GameEngine` directly and controller/route wiring manually).

- [ ] **Step 4: Commit**

```bash
git add app/Config/Routes.php app/Controllers/Api/V1/RoomsController.php
git commit -m "feat: add /select-tier route and controller action for Quiz Race"
```

---

## Task 9: Create Game form — track length, lap count, race theme picker, bank-soal warning

**Files:**
- Modify: `app/Controllers/Teacher/GameController.php`
- Modify: `app/Views/teacher/games/create.php`

- [ ] **Step 1: Split board templates by game mode and pass `track_length`/`lap_count` through**

In `app/Controllers/Teacher/GameController.php`, in `create()`, replace:

```php
            'boards' => (new BoardTemplateModel())->where('status', 'ACTIVE')->orderBy('name', 'ASC')->findAll(),
```

with:

```php
            'boards' => (new BoardTemplateModel())->where('status', 'ACTIVE')->where('game_mode', 'SNAKES_LADDERS')->orderBy('name', 'ASC')->findAll(),
            'raceBoards' => (new BoardTemplateModel())->where('status', 'ACTIVE')->where('game_mode', 'QUIZ_RACE')->orderBy('name', 'ASC')->findAll(),
```

In `store()`, right after the `$participationMode` block, add:

```php
        $trackLength = (int) $this->request->getPost('track_length');
        if ($trackLength < 6 || $trackLength > 60) {
            $trackLength = 24;
        }

        $lapCount = (int) $this->request->getPost('lap_count');
        if ($lapCount < 1 || $lapCount > 10) {
            $lapCount = 5;
        }
```

Then add `'track_length' => $trackLength, 'lap_count' => $lapCount,` to the `$engine->createRoom(...)` options array, right after `'participation_mode' => $participationMode,`:

```php
            $snapshot = $engine->createRoom($teacherId, $title, [
                'game_mode' => $gameMode,
                'participation_mode' => $participationMode,
                'track_length' => $trackLength,
                'lap_count' => $lapCount,
                'board_template_id' => $boardTemplateId,
```

These two fields are silently ignored by `createRoom()` for `SNAKES_LADDERS` rooms (Task 5's branch only reads them inside the `game_mode === 'QUIZ_RACE'` block), so this is safe to always send.

- [ ] **Step 2: Mark the Ular-Tangga-only fields for JS toggling**

In `app/Views/teacher/games/create.php`, add `data-snakes-only` to the opening `<div class="field">` tag of three existing blocks:

Line 168 (`Tema Papan`):
```html
        <div class="field" data-snakes-only>
            <label>Tema Papan</label>
```

Line 210 (`Jumlah Kotak Mystery`):
```html
        <div class="field" data-snakes-only>
            <label for="mystery_tile_count">Jumlah Kotak Mystery</label>
```

Line 215 (`Ukuran Papan`):
```html
        <div class="field" data-snakes-only>
            <label>Ukuran Papan</label>
```

Line 241 (`Aturan Finish` — Quiz Race always forces `clamp_finish` server-side, so this setting doesn't apply):
```html
        <div class="field" data-snakes-only>
            <label for="finish_rule">Aturan Finish</label>
```

- [ ] **Step 3: Add the race-only fields**

In `app/Views/teacher/games/create.php`, right after the `Aturan Finish` field's closing `</div>` (right before the `Scoring Tension` field), add:

```html
        <div class="field hidden" data-race-only>
            <label>Tema Lintasan</label>
            <div class="theme-grid">
                <label class="theme-option">
                    <input type="radio" name="board_template_id" value="" <?= (string) old('board_template_id', '') === '' ? 'checked' : '' ?>>
                    <span class="theme-preview theme-preview-auto">Otomatis</span>
                    <strong>Pilih Otomatis</strong>
                    <span class="muted">Sistem pilih tema lintasan pertama</span>
                </label>
                <?php foreach ($raceBoards as $board): ?>
                    <?php
                        $raceTheme = json_decode((string) ($board['theme_json'] ?? ''), true) ?: [];
                        $racePalette = $raceTheme['palette'] ?? [];
                    ?>
                    <label class="theme-option">
                        <input type="radio" name="board_template_id" value="<?= esc((string) $board['id']) ?>" <?= (string) old('board_template_id') === (string) $board['id'] ? 'checked' : '' ?>>
                        <span class="theme-preview theme-preview-track" style="background:<?= esc($racePalette['board'] ?? '#111827') ?>">
                            <span class="theme-preview-lane" style="background:<?= esc($racePalette['tileA'] ?? '#f8fafc') ?>"></span>
                            <span class="theme-preview-lane" style="background:<?= esc($racePalette['tileB'] ?? '#e0f2fe') ?>"></span>
                            <span class="theme-preview-finish" style="background:<?= esc($racePalette['accent'] ?? '#f97316') ?>"></span>
                        </span>
                        <strong><?= esc($raceTheme['name'] ?? $board['name']) ?></strong>
                        <span class="muted">Tema lintasan balap</span>
                    </label>
                <?php endforeach ?>
            </div>
            <p class="field-help">Tema memengaruhi suasana lintasan (warna &amp; nuansa), bukan mengganti soal.</p>
        </div>
        <div class="field hidden" data-race-only>
            <label for="track_length">Panjang Lintasan</label>
            <input type="number" id="track_length" name="track_length" min="6" max="60" step="1" value="<?= esc((string) old('track_length', 24)) ?>" required>
            <p class="field-help">Jumlah kotak dari garis start ke garis finish.</p>
        </div>
        <div class="field hidden" data-race-only>
            <label for="lap_count">Jumlah Lap</label>
            <input type="number" id="lap_count" name="lap_count" min="1" max="10" step="1" value="<?= esc((string) old('lap_count', 5)) ?>" required>
            <p class="field-help">Lintasan dibagi rata jadi beberapa lap; melewati batas lap memberi bonus 1 langkah instan.</p>
            <p class="field-help" data-race-bank-note></p>
        </div>
```

- [ ] **Step 4: Add the mode-visibility + bank-soal-warning JS**

In `app/Views/teacher/games/create.php`, right after the existing `</script>` that closes the topic-selection script block (at the end of the `scripts` section, before `<?= $this->endSection() ?>`), add a new script block:

```html
<script>
(function () {
    const raceOnlyFields = document.querySelectorAll('[data-race-only]');
    const snakesOnlyFields = document.querySelectorAll('[data-snakes-only]');
    const nearFinishCheckbox = document.querySelector('input[name="near_finish_bonus"]');
    const teamDeviceRadio = document.querySelector('input[name="participation_mode"][value="TEAM_DEVICE"]');
    const centralizedRadio = document.querySelector('input[name="participation_mode"][value="TEACHER_CENTRALIZED"]');
    const trackLengthInput = document.querySelector('#track_length');
    const raceBankNote = document.querySelector('[data-race-bank-note]');

    function currentGameMode() {
        const checked = document.querySelector('input[name="game_mode"]:checked');
        return checked ? checked.value : 'SNAKES_LADDERS';
    }

    function updateRaceBankNote() {
        if (!raceBankNote) {
            return;
        }
        if (currentGameMode() !== 'QUIZ_RACE') {
            raceBankNote.textContent = '';
            return;
        }
        const trackLength = Number(trackLengthInput ? trackLengthInput.value : 0) || 0;
        const totalEl = document.querySelector('[data-bank-total]');
        const totalAvailable = Number(totalEl ? totalEl.textContent : 0) || 0;
        const maxTeams = 6;
        const estimatedNeeded = maxTeams * Math.ceil(trackLength / 2);
        raceBankNote.textContent = totalAvailable < estimatedNeeded
            ? 'Bank soal topik ini diperkirakan kurang untuk lintasan sepanjang ini (perkiraan butuh ~' + estimatedNeeded + ' soal untuk ' + maxTeams + ' tim) — soal kemungkinan akan berulang sebelum tim mencapai finish.'
            : '';
    }

    function applyModeVisibility() {
        const isRace = currentGameMode() === 'QUIZ_RACE';
        raceOnlyFields.forEach((field) => field.classList.toggle('hidden', !isRace));
        snakesOnlyFields.forEach((field) => field.classList.toggle('hidden', isRace));
        if (nearFinishCheckbox) {
            nearFinishCheckbox.disabled = isRace;
            if (isRace) {
                nearFinishCheckbox.checked = false;
            }
        }
        if (teamDeviceRadio) {
            teamDeviceRadio.disabled = isRace;
            if (isRace && teamDeviceRadio.checked && centralizedRadio) {
                centralizedRadio.checked = true;
            }
        }
        updateRaceBankNote();
    }

    document.querySelectorAll('input[name="game_mode"]').forEach((radio) => radio.addEventListener('change', applyModeVisibility));
    if (trackLengthInput) {
        trackLengthInput.addEventListener('input', updateRaceBankNote);
    }
    document.addEventListener('change', function (event) {
        if (event.target && event.target.name === 'question_topic_uuids[]') {
            updateRaceBankNote();
        }
    });
    applyModeVisibility();
})();
</script>
```

`updateRaceBankNote()` reads `[data-bank-total]`'s live `textContent`, which the existing topic-selection script (defined earlier in the same `scripts` section) already keeps up to date via `drawSummary()` — since that script's `change` listener sits on an ancestor of the topic checkboxes, it always runs before this new `document`-level listener sees the same bubbled event, so the total is already fresh by the time `updateRaceBankNote()` reads it.

- [ ] **Step 5: Manual verification**

Run: `php spark serve`

In a browser:
1. Go to `/teacher/games/create`. Confirm "Tema Papan", "Jumlah Kotak Mystery", "Ukuran Papan", and "Aturan Finish" are visible, and "Tema Lintasan"/"Panjang Lintasan"/"Jumlah Lap" are hidden.
2. Select "Quiz Race" under Mode Game. Confirm the fields swap visibility, "Device per Tim" becomes disabled and unchecked (if it was checked), and "Bonus dekat finish" becomes disabled/unchecked.
3. Pick a small topic with few published questions, set "Panjang Lintasan" to something large (e.g. 60). Confirm the warning note appears under "Jumlah Lap".
4. Submit the form. Confirm the room is created and its Control Game page loads without errors.
5. Switch back to "Ular Tangga Kuis" mode on a fresh form load — confirm the original fields and behavior are unaffected.

- [ ] **Step 6: Commit**

```bash
git add app/Controllers/Teacher/GameController.php app/Views/teacher/games/create.php
git commit -m "feat: add Quiz Race track/lap/theme fields and bank-soal warning to Create Game"
```

---

## Task 10: Control Game panel — tier buttons instead of the dice

**Files:**
- Modify: `app/Views/teacher/games/control.php`
- Modify: `app/Views/game/controller.php`
- Modify: `public/assets/app.js`
- Modify: `public/assets/app.css`

- [ ] **Step 1: Add the tier-button markup to both gameplay panels**

In `app/Views/teacher/games/control.php`, inside the `.dice-panel` block, right after the existing `<button class="button roll-button" data-roll type="button">Lempar Dadu</button>` line, add:

```html
        <div class="tier-select hidden" data-tier-select>
            <button class="button tier-button" data-tier="EASY" type="button">EASY <span>+1</span></button>
            <button class="button tier-button" data-tier="MEDIUM" type="button">MEDIUM <span>+2</span></button>
            <button class="button tier-button" data-tier="HARD" type="button">HARD <span>+3</span></button>
        </div>
```

Do the exact same insertion (same markup, same placement right after the roll button) in `app/Views/game/controller.php`'s `.dice-panel` block.

- [ ] **Step 2: Wire up `controller()` in `app.js`**

In `public/assets/app.js`, inside `controller(config)`, add `const tierSelect = document.querySelector('[data-tier-select]');` right after the existing `const rollButton = document.querySelector('[data-roll]');` lookup.

In `drawController()`, right after the line that computes `const canRoll = ...` (the line reading `turn.state === 'ROLL_READY'`), add:

```js
            const isQuizRace = Boolean(snapshot.mode_state && snapshot.mode_state.key === 'QUIZ_RACE');
            const canSelectTier = modeCan(snapshot, 'select_tier') && snapshot.room.status === 'PLAYING' && isMyTurn && turn && turn.state === 'ROLL_READY';
            const oilSpillLocked = Boolean(team && team.active_effects && team.active_effects.oil_spill_lock);
```

Then, right after the existing `if (rollButton) { ... }` block (the one that sets `rollButton.disabled`/`rollButton.textContent`), add:

```js
            if (rollButton) {
                rollButton.classList.toggle('hidden', isQuizRace);
            }

            if (tierSelect) {
                tierSelect.classList.toggle('hidden', !isQuizRace);
                tierSelect.querySelectorAll('[data-tier]').forEach((button) => {
                    const tier = button.dataset.tier;
                    button.disabled = !canSelectTier || isRolling || isAnswering || (oilSpillLocked && tier !== 'EASY');
                });
            }
```

Finally, right after the existing `if (rollButton) { rollButton.addEventListener('click', ...) }` block, add the tier-selection click handler:

```js
        if (tierSelect) {
            tierSelect.addEventListener('click', function (event) {
                const button = event.target.closest('[data-tier]');
                if (!button || button.disabled || isRolling) {
                    return;
                }

                isRolling = true;
                drawController();
                runtime.setError('');

                jsonFetch('/api/v1/rooms/' + config.roomUuid + '/select-tier', {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: activeTeamUuid(), tier: button.dataset.tier}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message))
                    .finally(() => {
                        isRolling = false;
                        drawController();
                    });
            });
        }
```

Reusing the existing `isRolling` flag (rather than a new one) keeps this action gated by the same in-flight guard as the dice roll, and — since the periodic-polling fix earlier this session made `createRuntime()`'s interval always call the *current* `runtime.refresh`, including this handler's `.then(runtime.refresh)` — the panel keeps updating live exactly like the dice-roll path does.

- [ ] **Step 3: Add tier button CSS**

In `public/assets/app.css`, right after the existing `.roll-button { ... }` rule (the one with `font-size: 18px; min-height: 54px; width: 100%;`), add:

```css
.tier-select {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

.tier-button {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    min-height: 54px;
}

.tier-button span {
    font-size: 0.75rem;
    opacity: 0.8;
}
```

- [ ] **Step 4: Manual verification**

Run: `php spark serve`

In a browser:
1. Create a Quiz Race room (Mode Tanpa Device), add 2 teams from the roster panel, click Start.
2. On Control Game, confirm the dice button/dice-face are hidden and the three EASY/MEDIUM/HARD buttons show instead.
3. Click HARD. Confirm a question appears (panel shows "Menunggu Waktu Jawab" per the existing Mode Tanpa Device flow), click "Mulai Waktu Jawab", answer.
4. Confirm the team's "Kotak" position increased and turn passed to the other team.
5. Manually trigger an Oil Spill (temporarily set a board's `special_tiles_json` via a DB tool, or trust Task 7's automated coverage) and confirm the MEDIUM/HARD buttons show disabled on that team's next turn while EASY stays clickable.

- [ ] **Step 5: Commit**

```bash
git add app/Views/teacher/games/control.php app/Views/game/controller.php public/assets/app.js public/assets/app.css
git commit -m "feat: replace dice roll with EASY/MEDIUM/HARD tier buttons for Quiz Race"
```

---

## Task 11: Full regression pass

- [ ] **Step 1: Run the complete suite**

Run: `vendor/bin/phpunit`
Expected: `OK` — every test from Tasks 1-10 plus every pre-existing test passes.

- [ ] **Step 2: Manual smoke test end-to-end**

Run: `php spark serve`, then in a browser: create a Quiz Race + Mode Tanpa Device room, add 2+ teams, play a full race from Start to one team crossing the finish line (`max_position`), confirming the room transitions to `FINISHED` and the Control Game "Sedang Bermain" status updates accordingly.

- [ ] **Step 3: Commit (only if Steps 1-2 surfaced fixes)**

If everything passed clean, there's nothing to commit here — this task is a checkpoint, not a code change.
