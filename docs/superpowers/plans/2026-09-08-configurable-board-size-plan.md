# Configurable Board Size Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a teacher choose a 50, 70, or 100-tile board when creating a room, instead of every board always being 100 tiles.

**Architecture:** Reuses the exact "clone-on-difference" pattern already proven by `GameEngine::applyMysteryTileCount()`: a new `applyBoardSize()` method clones the `board_templates` row with a hand-designed, collision-free ladder/snake/special-tile layout for the requested size when it differs from the source template's own size, keeping the theme's palette. No schema change and no new cleanup code — `board_templates.tile_count`/`ladders_json`/`snakes_json`/`special_tiles_json` already exist, and `deleteRoom()` already purges any `status='ROOM_INSTANCE'` clone.

**Tech Stack:** PHP 8.2, CodeIgniter 4, SQLite, PHPUnit + `DatabaseTestTrait`.

**Spec:** `docs/superpowers/specs/2026-09-08-configurable-board-size-design.md`

**Working directory for every command below:** `/Users/mbp19/Documents/YAZDAD/APLIKASI PRODUKSI/games/ular-tangga`

---

### Task 1: `applyBoardSize()` engine method + wiring

**Files:**
- Modify: `app/Services/Game/GameEngine.php:38-57` (`createRoom()`), plus a new constant and private method
- Test: `tests/database/GameEngineHardeningTest.php`

- [ ] **Step 1: Write the failing tests**

Add these to the `GameEngineHardeningTest` class:

```php
    public function testCreateRoomAppliesSelectedBoardSize(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Board Size 70 Test', [
            'board_size' => 70,
        ])['room'];

        $usedBoardId = (int) (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $board = (new BoardTemplateModel())->find($usedBoardId);

        $this->assertSame(70, (int) $board['tile_count']);
        $this->assertSame('ROOM_INSTANCE', $board['status']);
        $this->assertCount(6, json_decode((string) $board['ladders_json'], true));
        $this->assertCount(6, json_decode((string) $board['snakes_json'], true));
        $this->assertCount(6, json_decode((string) $board['special_tiles_json'], true));
    }

    public function testCreateRoomKeepsDefaultBoardWhenSizeIs100(): void
    {
        $sourceBoard = (new BoardTemplateModel())->where('status', 'ACTIVE')->orderBy('id', 'ASC')->first();

        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Board Size Default Test', [
            'board_size' => 100,
        ])['room'];

        $usedBoardId = (int) (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $this->assertSame((int) $sourceBoard['id'], $usedBoardId);
    }

    public function testCreateRoomComposesBoardSizeAndMysteryTileCount(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Board Size Plus Mystery Test', [
            'board_size' => 70,
            'mystery_tile_count' => 4,
        ])['room'];

        $usedBoardId = (int) (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $board = (new BoardTemplateModel())->find($usedBoardId);
        $mysteryTiles = array_values(array_filter(
            json_decode((string) $board['special_tiles_json'], true),
            static fn (array $tile): bool => $tile['type'] === 'MYSTERY',
        ));

        $this->assertSame(70, (int) $board['tile_count']);
        $this->assertCount(4, $mysteryTiles);
    }

    public function testDeleteRoomRemovesClonedBoardSizeTemplate(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Board Size Cleanup Test', [
            'board_size' => 50,
        ])['room'];
        $usedBoardId = (int) (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $this->assertSame('ROOM_INSTANCE', (new BoardTemplateModel())->find($usedBoardId)['status']);

        $engine->deleteRoom($room['uuid']);

        $this->assertNull((new BoardTemplateModel())->find($usedBoardId));
    }
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `./vendor/bin/phpunit --filter "testCreateRoomAppliesSelectedBoardSize|testCreateRoomKeepsDefaultBoardWhenSizeIs100|testCreateRoomComposesBoardSizeAndMysteryTileCount|testDeleteRoomRemovesClonedBoardSizeTemplate" tests/database/GameEngineHardeningTest.php`
Expected: FAIL — `createRoom()` doesn't read `board_size` yet, so every test still gets the default 100-tile board.

- [ ] **Step 3: Add the `BOARD_SIZE_LAYOUTS` constant and `applyBoardSize()` method**

In `app/Services/Game/GameEngine.php`, find `private function applyMysteryTileCount(array $board, int $mysteryCount): array` (search for it) and insert this new constant and method directly ABOVE it:

```php
    private const BOARD_SIZE_LAYOUTS = [
        50 => [
            'ladders' => [
                ['from' => 3, 'to' => 9],
                ['from' => 8, 'to' => 18],
                ['from' => 14, 'to' => 28],
                ['from' => 19, 'to' => 34],
                ['from' => 26, 'to' => 39],
            ],
            'snakes' => [
                ['from' => 12, 'to' => 4],
                ['from' => 22, 'to' => 11],
                ['from' => 31, 'to' => 16],
                ['from' => 42, 'to' => 24],
                ['from' => 47, 'to' => 32],
            ],
            'special_tiles' => [
                ['tile' => 6, 'type' => 'BONUS', 'points' => 50, 'label' => 'Bonus 50'],
                ['tile' => 15, 'type' => 'TRAP', 'steps' => 3, 'label' => 'Trap mundur 3'],
                ['tile' => 21, 'type' => 'SAFE', 'label' => 'Perisai aman'],
                ['tile' => 30, 'type' => 'MYSTERY', 'label' => 'Misteri'],
                ['tile' => 37, 'type' => 'BONUS', 'points' => 75, 'label' => 'Bonus 75'],
            ],
        ],
        70 => [
            'ladders' => [
                ['from' => 5, 'to' => 14],
                ['from' => 11, 'to' => 27],
                ['from' => 18, 'to' => 38],
                ['from' => 24, 'to' => 45],
                ['from' => 33, 'to' => 52],
                ['from' => 41, 'to' => 60],
            ],
            'snakes' => [
                ['from' => 16, 'to' => 6],
                ['from' => 30, 'to' => 13],
                ['from' => 47, 'to' => 22],
                ['from' => 55, 'to' => 31],
                ['from' => 64, 'to' => 50],
                ['from' => 68, 'to' => 57],
            ],
            'special_tiles' => [
                ['tile' => 9, 'type' => 'BONUS', 'points' => 50, 'label' => 'Bonus 50'],
                ['tile' => 20, 'type' => 'TRAP', 'steps' => 3, 'label' => 'Trap mundur 3'],
                ['tile' => 36, 'type' => 'SAFE', 'label' => 'Perisai aman'],
                ['tile' => 43, 'type' => 'MYSTERY', 'label' => 'Misteri'],
                ['tile' => 53, 'type' => 'BONUS', 'points' => 75, 'label' => 'Bonus 75'],
                ['tile' => 62, 'type' => 'TRAP', 'steps' => 4, 'label' => 'Trap mundur 4'],
            ],
        ],
    ];

    private function applyBoardSize(array $board, int $tileCount): array
    {
        $layout = self::BOARD_SIZE_LAYOUTS[$tileCount] ?? null;
        if ($layout === null) {
            return $board;
        }

        $newBoardId = (new BoardTemplateModel())->insert([
            'public_uuid' => Uuid::v4(),
            'name' => $board['name'] . ' (' . $tileCount . ' Kotak)',
            'tile_count' => $tileCount,
            'ladders_json' => json_encode($layout['ladders'], JSON_UNESCAPED_SLASHES),
            'snakes_json' => json_encode($layout['snakes'], JSON_UNESCAPED_SLASHES),
            'special_tiles_json' => json_encode($layout['special_tiles'], JSON_UNESCAPED_SLASHES),
            'theme_json' => $board['theme_json'],
            'status' => 'ROOM_INSTANCE',
        ], true);

        return (new BoardTemplateModel())->find($newBoardId);
    }

```

Note: `applyBoardSize()` is only ever called with `50` or `70` (see the wiring below) — a request for `100` never reaches this method, so the `?? null` fallback exists only as a defensive guard, not something the normal flow relies on.

- [ ] **Step 4: Wire it into `createRoom()`**

In `createRoom()`, find:

```php
        if (isset($options['mystery_tile_count'])) {
            $board = $this->applyMysteryTileCount($board, (int) $options['mystery_tile_count']);
        }
```

Replace with:

```php
        if (isset($options['board_size']) && in_array((int) $options['board_size'], [50, 70], true)) {
            $board = $this->applyBoardSize($board, (int) $options['board_size']);
        }

        if (isset($options['mystery_tile_count'])) {
            $board = $this->applyMysteryTileCount($board, (int) $options['mystery_tile_count']);
        }
```

(The new `board_size` block is inserted BEFORE the existing `mystery_tile_count` block, so that if both options are given, the mystery-tile placement operates on the size-appropriate board's ladders/snakes/special tiles, not the original 100-tile ones.)

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `./vendor/bin/phpunit --filter "testCreateRoomAppliesSelectedBoardSize|testCreateRoomKeepsDefaultBoardWhenSizeIs100|testCreateRoomComposesBoardSizeAndMysteryTileCount|testDeleteRoomRemovesClonedBoardSizeTemplate" tests/database/GameEngineHardeningTest.php`
Expected: all 4 PASS.

- [ ] **Step 6: Run the full suite**

Run: `./vendor/bin/phpunit`
Expected: all tests PASS (should be 56 — 52 + 4 new).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/GameEngineHardeningTest.php
git commit -m "feat: let teachers choose a 50/70/100-tile board size"
```

---

### Task 2: Form field + controller wiring

**Files:**
- Modify: `app/Views/teacher/games/create.php` (new "Ukuran Papan" field)
- Modify: `app/Controllers/Teacher/GameController.php` (`store()`)

- [ ] **Step 1: Add the form field**

In `app/Views/teacher/games/create.php`, find the "Jumlah Kotak Mystery" field block:

```php
        <div class="field">
            <label for="mystery_tile_count">Jumlah Kotak Mystery</label>
            <input type="number" id="mystery_tile_count" name="mystery_tile_count" min="0" max="6" value="<?= esc((string) old('mystery_tile_count', 2)) ?>">
            <p class="field-help">Kotak Mystery ditempatkan acak di papan saat room dibuat, tidak menumpuk dengan kotak spesial lain.</p>
        </div>
```

Add this new field directly after it:

```php
        <div class="field">
            <label>Ukuran Papan</label>
            <div class="check-grid">
                <label class="check-option">
                    <input type="radio" name="board_size" value="50" <?= old('board_size') === '50' ? 'checked' : '' ?>>
                    <span>Kecil (50 kotak)</span>
                </label>
                <label class="check-option">
                    <input type="radio" name="board_size" value="70" <?= old('board_size') === '70' ? 'checked' : '' ?>>
                    <span>Sedang (70 kotak)</span>
                </label>
                <label class="check-option">
                    <input type="radio" name="board_size" value="100" <?= old('board_size', '100') === '100' ? 'checked' : '' ?>>
                    <span>Besar (100 kotak)</span>
                </label>
            </div>
            <p class="field-help">Ukuran memengaruhi jumlah kotak dan tata letak ular/tangga/kotak spesial, tidak mengganti tema warna papan.</p>
        </div>
```

- [ ] **Step 2: Verify view syntax**

Run: `php -l app/Views/teacher/games/create.php`
Expected: "No syntax errors detected"

- [ ] **Step 3: Parse and pass the field through in the controller**

In `app/Controllers/Teacher/GameController.php`, inside `store()`, find:

```php
        $mysteryTileCount = $this->request->getPost('mystery_tile_count');
        $mysteryTileCount = $mysteryTileCount === null || $mysteryTileCount === ''
            ? null
            : max(0, min(6, (int) $mysteryTileCount));
```

Add this directly after it:

```php
        $boardSize = (string) $this->request->getPost('board_size');
        if (! in_array($boardSize, ['50', '70', '100'], true)) {
            $boardSize = '100';
        }
```

Then find the options array passed to `$engine->createRoom(...)`:

```php
        $snapshot = $engine->createRoom($teacherId, $title, [
            'game_mode' => $gameMode,
            'board_template_id' => $boardTemplateId,
            'turn_order_mode' => $turnOrderMode,
            'finish_rule' => $finishRule,
            'mystery_tile_count' => $mysteryTileCount,
            'skip_quota' => $tenant->isSuperadmin(),
```

Add `'board_size' => (int) $boardSize,` right after `'mystery_tile_count' => $mysteryTileCount,`:

```php
        $snapshot = $engine->createRoom($teacherId, $title, [
            'game_mode' => $gameMode,
            'board_template_id' => $boardTemplateId,
            'turn_order_mode' => $turnOrderMode,
            'finish_rule' => $finishRule,
            'mystery_tile_count' => $mysteryTileCount,
            'board_size' => (int) $boardSize,
            'skip_quota' => $tenant->isSuperadmin(),
```

(Keep every other key in that array — `question_selection`, `scoring`, and anything else already there — exactly as-is.)

- [ ] **Step 4: Verify controller syntax and run the full suite**

Run: `php -l app/Controllers/Teacher/GameController.php`
Expected: "No syntax errors detected"

Run: `./vendor/bin/phpunit`
Expected: all 56 tests still PASS (this task doesn't add or change any test — `createRoom()`'s `board_size` handling was already fully tested in Task 1; this task only wires the HTTP form to it).

- [ ] **Step 5: Commit**

```bash
git add app/Views/teacher/games/create.php app/Controllers/Teacher/GameController.php
git commit -m "feat: add board size selector to the create-game form"
```

---

### Task 3: Manual verification and final regression

**Files:** none (verification only)

- [ ] **Step 1: Run the full suite one more time**

Run: `./vendor/bin/phpunit`
Expected: 56/56 tests pass.

- [ ] **Step 2: Lint every touched file together**

Run:
```bash
php -l app/Services/Game/GameEngine.php
php -l app/Controllers/Teacher/GameController.php
php -l app/Views/teacher/games/create.php
```
Expected: "No syntax errors detected" for all three.

- [ ] **Step 3: Manual browser check**

Start the dev server if it isn't already running (`php spark serve --host 127.0.0.1 --port 8090`), log in as a teacher, open `/teacher/games/create`, and confirm:
- A new "Ukuran Papan" field appears with 3 options, "Besar (100 kotak)" pre-selected by default.
- Create one room with "Kecil (50 kotak)" selected — open its detail page and confirm the board renders as a 5-row (50-tile) grid, not the usual 10-row grid, with ladders/snakes/special tiles visible and no visually broken/partial row.
- Create another room with "Sedang (70 kotak)" AND a non-default "Jumlah Kotak Mystery" (e.g. 4) — confirm the board is 70 tiles and has 4 Mystery tiles (count the Mystery-labeled tiles on the rendered board).
- Create a third room leaving both fields at their defaults — confirm it's still a normal 100-tile board (no behavior change for teachers who don't touch these new fields).

- [ ] **Step 4: Fix and re-verify if anything from Step 3 looks wrong**

If a rendered board looks broken (e.g. a partial top row, tiles missing, ladder/snake pointing off-board), the most likely cause is a typo in one of the `BOARD_SIZE_LAYOUTS` coordinates from Task 1 — re-check the specific `from`/`to`/`tile` values against `docs/superpowers/specs/2026-09-08-configurable-board-size-design.md`'s Bagian A, fix the constant in `GameEngine.php`, re-run the full suite, and commit:

```bash
git add app/Services/Game/GameEngine.php
git commit -m "fix: correct board size layout coordinates after manual review"
```

(Skip this step entirely if Step 3 found nothing to fix.)
