# Create Game/Bank Soal Fixes + Mystery Box Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the question-timing/difficulty-zone bugs and UX gaps found on `teacher/games/create` and `teacher/questions`, and redesign the Mystery tile into a choice-based, high-risk/high-reward mechanic that can target the landing team or an opponent.

**Architecture:** All gameplay logic lives in `App\Services\Game\GameEngine` (a single service class backing a small CodeIgniter 4 app). Every change here follows that existing pattern: engine methods mutate DB state inside a transaction, record an event via `recordEvent()`, and return a fresh `snapshot()`. The Mystery redesign adds two new engine methods (`chooseMysteryTarget`, `answerMystery`) and reuses the existing `game_turns.state` machine instead of introducing new tables.

**Tech Stack:** PHP 8.2, CodeIgniter 4, SQLite (`writable/ular_tangga.sqlite`), PHPUnit with `DatabaseTestTrait`, vanilla JS (`public/assets/app.js`, no bundler/test runner).

**Spec:** `docs/superpowers/specs/2026-09-08-create-game-mystery-box-redesign-design.md`

**Working directory for every command below:** `/Users/mbp19/Documents/YAZDAD/APLIKASI PRODUKSI/games/ular-tangga`

---

### Task 1: Bagian A — Soal sesuai kotak tujuan dadu

**Files:**
- Modify: `app/Services/Game/GameEngine.php:310-370` (`roll()`), `app/Services/Game/GameEngine.php:721-751` (`movementForCorrectAnswer()`)
- Test: `tests/database/GameEngineHardeningTest.php:59-77` (`testDifficultyZoneSelectsMediumQuestionForMiddleBoard`)

- [ ] **Step 1: Update the failing assertion first**

Replace the last line of `testDifficultyZoneSelectsMediumQuestionForMiddleBoard` (currently asserting a hardcoded position) so it asserts against the actual dice-landing tile:

```php
    public function testDifficultyZoneSelectsMediumQuestionForMiddleBoard(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Difficulty Zone Medium Test', [
            'turn_order_mode' => 'join_order',
            'question_selection' => ['strategy' => 'difficulty_zone'],
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Zona')['team'];

        $engine->start($room['uuid']);
        (new GameTeamModel())->update($team['id'], ['position' => 40]);
        $snapshot = $engine->roll($room['uuid'], $team['public_uuid']);
        $lastQuestion = $this->lastEvent($this->roomId($room['uuid']), 'question.started');

        $this->assertSame('difficulty_zone', $snapshot['room']['question_selection']['strategy']);
        $this->assertSame('MEDIUM', $snapshot['current_turn']['question']['difficulty']);
        $this->assertSame('MEDIUM', $lastQuestion['payload']['selection']['requested_difficulty']);
        $diceValue = (int) $snapshot['current_turn']['dice_value'];
        $this->assertSame(40 + $diceValue, $lastQuestion['payload']['selection']['based_on_position']);
    }
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `./vendor/bin/phpunit --filter testDifficultyZoneSelectsMediumQuestionForMiddleBoard tests/database/GameEngineHardeningTest.php`
Expected: FAIL — `based_on_position` is currently always `40` regardless of dice, so it won't equal `40 + $diceValue` for any dice roll 1-6.

- [ ] **Step 3: Add a `computeLandedTile()` helper**

In `app/Services/Game/GameEngine.php`, insert this new private method directly above `private function movementForCorrectAnswer(...)` (currently at line 721):

```php
    private function computeLandedTile(int $from, int $dice, array $room): int
    {
        $maxPosition = (int) $room['max_position'];
        $rolledTo = $from + $dice;

        if (($room['finish_rule'] ?? 'clamp_finish') === 'exact_finish' && $rolledTo > $maxPosition) {
            return max(1, $maxPosition - ($rolledTo - $maxPosition));
        }

        return min($maxPosition, $rolledTo);
    }
```

- [ ] **Step 4: Refactor `movementForCorrectAnswer()` to reuse it**

Replace the current body (`GameEngine.php:721-751`):

```php
    private function movementForCorrectAnswer(int $from, int $dice, array $room, array $board, array $team): array
    {
        $maxPosition = (int) $room['max_position'];
        $rolledTo = $from + $dice;
        $finishBounced = false;
        $activeEffects = $this->teamEffects($team);

        if (($room['finish_rule'] ?? 'clamp_finish') === 'exact_finish' && $rolledTo > $maxPosition) {
            $landed = max(1, $maxPosition - ($rolledTo - $maxPosition));
            $finishBounced = true;
        } else {
            $landed = min($maxPosition, $rolledTo);
        }

        $boardJump = $this->applyBoardJump($landed, $board, $activeEffects);
```

with:

```php
    private function movementForCorrectAnswer(int $from, int $dice, array $room, array $board, array $team): array
    {
        $maxPosition = (int) $room['max_position'];
        $rolledTo = $from + $dice;
        $finishBounced = ($room['finish_rule'] ?? 'clamp_finish') === 'exact_finish' && $rolledTo > $maxPosition;
        $landed = $this->computeLandedTile($from, $dice, $room);
        $activeEffects = $this->teamEffects($team);

        $boardJump = $this->applyBoardJump($landed, $board, $activeEffects);
```

(the rest of the method body is unchanged).

- [ ] **Step 5: Make `roll()` select the question by the landed tile**

In `roll()` (`GameEngine.php:310-370`), replace:

```php
        $dice = random_int(1, 6);
        $targetDifficulty = $this->targetDifficultyForTurn($room, (int) $team['position']);
        $question = $this->selectQuestion((int) $room['teacher_id'], $targetDifficulty);
```

with:

```php
        $dice = random_int(1, 6);
        $landedTile = $this->computeLandedTile((int) $team['position'], $dice, $room);
        $targetDifficulty = $this->targetDifficultyForTurn($room, $landedTile);
        $question = $this->selectQuestion((int) $room['teacher_id'], $targetDifficulty);
```

Then, in the same method's `question.started` event payload, replace:

```php
                'based_on_position' => (int) $team['position'],
```

with:

```php
                'based_on_position' => $landedTile,
```

- [ ] **Step 6: Run the target test again to confirm it passes**

Run: `./vendor/bin/phpunit --filter testDifficultyZoneSelectsMediumQuestionForMiddleBoard tests/database/GameEngineHardeningTest.php`
Expected: PASS

- [ ] **Step 7: Run the full suite to check for regressions**

Run: `./vendor/bin/phpunit`
Expected: all 38 existing tests still PASS (tile-effect tests that force a specific `dice_value` after `roll()` are unaffected because they only read `dice_value` for movement, not for question selection).

- [ ] **Step 8: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/GameEngineHardeningTest.php
git commit -m "fix: select question difficulty from the dice-landing tile, not the pre-roll position"
```

---

### Task 2: Bagian B — Zona difficulty proporsional terhadap ukuran papan

**Files:**
- Modify: `app/Services/Game/GameEngine.php:1129-1149` (`questionSelectionRules()`), `:1151-1163` (`targetDifficultyForTurn()`), and 3 call sites (lines 60, 358, 1318)
- Test: `tests/database/GameEngineHardeningTest.php`

- [ ] **Step 1: Add the top import needed by the new test**

At the top of `tests/database/GameEngineHardeningTest.php`, add:

```php
use App\Services\Game\Uuid;
```

- [ ] **Step 2: Write the failing test**

Add this new test method anywhere in the `GameEngineHardeningTest` class (e.g. right after `testDifficultyZoneFallsBackWhenRequestedDifficultyIsEmpty`):

```php
    public function testDifficultyZoneScalesWithNonStandardBoardSize(): void
    {
        $customBoardId = (new BoardTemplateModel())->insert([
            'public_uuid' => Uuid::v4(),
            'name' => 'Papan 60 Kotak Test',
            'tile_count' => 60,
            'ladders_json' => json_encode([]),
            'snakes_json' => json_encode([]),
            'special_tiles_json' => json_encode([]),
            'theme_json' => json_encode(['name' => 'Test 60']),
            'status' => 'ACTIVE',
        ], true);

        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Small Board Test', [
            'board_template_id' => $customBoardId,
            'question_selection' => ['strategy' => 'difficulty_zone'],
        ])['room'];

        $this->assertSame(60, $room['max_position']);
        $this->assertSame([
            ['from' => 1, 'to' => 18, 'difficulty' => 'EASY'],
            ['from' => 19, 'to' => 42, 'difficulty' => 'MEDIUM'],
            ['from' => 43, 'to' => 60, 'difficulty' => 'HARD'],
        ], $room['question_selection']['zones']);
    }
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `./vendor/bin/phpunit --filter testDifficultyZoneScalesWithNonStandardBoardSize tests/database/GameEngineHardeningTest.php`
Expected: FAIL — current code returns hardcoded `to: 30/70/100` regardless of `tile_count`.

- [ ] **Step 4: Make `questionSelectionRules()` take the board's `max_position`**

Replace `GameEngine.php:1129-1149`:

```php
    private function questionSelectionRules($source): array
    {
        if (is_string($source)) {
            $source = json_decode($source, true) ?: [];
        }
        if (! is_array($source)) {
            $source = [];
        }

        $strategy = $this->validOption((string) ($source['strategy'] ?? 'difficulty_zone'), ['difficulty_zone', 'random'], 'difficulty_zone');

        return [
            'strategy' => $strategy,
            'zones' => [
                ['from' => 1, 'to' => 30, 'difficulty' => 'EASY'],
                ['from' => 31, 'to' => 70, 'difficulty' => 'MEDIUM'],
                ['from' => 71, 'to' => 100, 'difficulty' => 'HARD'],
            ],
            'fallback' => 'any_published_question',
        ];
    }
```

with:

```php
    private function questionSelectionRules($source, int $maxPosition): array
    {
        if (is_string($source)) {
            $source = json_decode($source, true) ?: [];
        }
        if (! is_array($source)) {
            $source = [];
        }

        $strategy = $this->validOption((string) ($source['strategy'] ?? 'difficulty_zone'), ['difficulty_zone', 'random'], 'difficulty_zone');
        $maxPosition = max(1, $maxPosition);
        $easyTo = max(1, (int) round($maxPosition * 0.3));
        $mediumTo = max($easyTo, (int) round($maxPosition * 0.7));

        return [
            'strategy' => $strategy,
            'zones' => [
                ['from' => 1, 'to' => $easyTo, 'difficulty' => 'EASY'],
                ['from' => $easyTo + 1, 'to' => $mediumTo, 'difficulty' => 'MEDIUM'],
                ['from' => $mediumTo + 1, 'to' => $maxPosition, 'difficulty' => 'HARD'],
            ],
            'fallback' => 'any_published_question',
        ];
    }
```

- [ ] **Step 5: Update `targetDifficultyForTurn()` to pass `max_position` through**

Replace `GameEngine.php:1151-1163`:

```php
    private function targetDifficultyForTurn(array $room, int $position): ?string
    {
        $rules = $this->questionSelectionRules($room['question_selection_json'] ?? []);
        if ($rules['strategy'] !== 'difficulty_zone') {
            return null;
        }

        foreach ($rules['zones'] as $zone) {
            if ($position >= (int) $zone['from'] && $position <= (int) $zone['to']) {
                return $zone['difficulty'];
            }
        }

        return 'HARD';
    }
```

with:

```php
    private function targetDifficultyForTurn(array $room, int $position): ?string
    {
        $rules = $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position']);
        if ($rules['strategy'] !== 'difficulty_zone') {
            return null;
        }

        foreach ($rules['zones'] as $zone) {
            if ($position >= (int) $zone['from'] && $position <= (int) $zone['to']) {
                return $zone['difficulty'];
            }
        }

        return $rules['zones'][count($rules['zones']) - 1]['difficulty'];
    }
```

- [ ] **Step 6: Fix the remaining two call sites**

In `createRoom()` (`GameEngine.php:60`), replace:

```php
        $questionSelection = $this->questionSelectionRules($options['question_selection'] ?? []);
```

with:

```php
        $questionSelection = $this->questionSelectionRules($options['question_selection'] ?? [], (int) $board['tile_count']);
```

In `roll()`'s `question.started` event payload (`GameEngine.php:358`), replace:

```php
                'strategy' => $this->questionSelectionRules($room['question_selection_json'] ?? [])['strategy'],
```

with:

```php
                'strategy' => $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position'])['strategy'],
```

In `publicRoom()` (`GameEngine.php:1318`), replace:

```php
            'question_selection' => $this->questionSelectionRules($room['question_selection_json'] ?? []),
```

with:

```php
            'question_selection' => $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position']),
```

- [ ] **Step 7: Run the new test and the full suite**

Run: `./vendor/bin/phpunit --filter testDifficultyZoneScalesWithNonStandardBoardSize tests/database/GameEngineHardeningTest.php`
Expected: PASS

Run: `./vendor/bin/phpunit`
Expected: all tests PASS (for `max_position = 100`, `easyTo = 30`, `mediumTo = 70` — identical to the old hardcoded zones, so every existing board-100 test is unaffected).

- [ ] **Step 8: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/GameEngineHardeningTest.php
git commit -m "fix: scale difficulty zones proportionally to the room's actual board size"
```

---

### Task 3: Bagian E — Sembunyikan kotak DUEL

**Files:**
- Modify: `app/Services/Game/GameEngine.php:47-70` (`boardSpecialTiles()`), `:820-831` (DUEL branch in `applySpecialTileEffect()`)
- Test: `tests/database/GameEngineHardeningTest.php:281-297` (`testDuelTileStructureTriggersWithoutChangingScore`)

- [ ] **Step 1: Replace the DUEL test with one that expects it to be hidden**

Replace `testDuelTileStructureTriggersWithoutChangingScore` (`GameEngineHardeningTest.php:281-297`) with:

```php
    public function testDuelTileIsHiddenAndActsAsNormalTile(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Duel Tile Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Duel')['team'];

        $snapshot = $this->answerCorrectWithForcedMove($engine, $room, $team, 76, 1);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(77, $updatedTeam['position']);
        $this->assertSame(100, $updatedTeam['score']);
        $this->assertSame(0, (new GameEventModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->where('type', 'tile.special_triggered')
            ->countAllResults());
        $this->assertSame([], array_values(array_filter(
            $snapshot['board']['special_tiles'],
            static fn (array $tile): bool => (int) $tile['tile'] === 77,
        )));
    }
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `./vendor/bin/phpunit --filter testDuelTileIsHiddenAndActsAsNormalTile tests/database/GameEngineHardeningTest.php`
Expected: FAIL — tile 77 still triggers a `DUEL` `tile.special_triggered` event today.

- [ ] **Step 3: Remove `DUEL` from the allowed special tile types**

In `boardSpecialTiles()` (`GameEngine.php:47-70`), replace:

```php
            if ($position < 1 || ! in_array($type, ['BONUS', 'TRAP', 'SAFE', 'MYSTERY', 'DUEL'], true)) {
                return null;
            }
```

with:

```php
            if ($position < 1 || ! in_array($type, ['BONUS', 'TRAP', 'SAFE', 'MYSTERY'], true)) {
                return null;
            }
```

- [ ] **Step 4: Remove the now-unreachable DUEL branch**

In `applySpecialTileEffect()` (`GameEngine.php:820-831`), delete this block entirely (it can never be reached once `specialTileAt()` never returns a `DUEL` tile):

```php
        if ($type === 'DUEL') {
            return [
                'to' => $position,
                'special' => 'DUEL',
                'effects' => [[
                    'type' => 'DUEL',
                    'tile' => $position,
                    'label' => $label,
                    'status' => 'PENDING_IMPLEMENTATION',
                ]],
                'score_delta' => 0,
            ];
        }

```

- [ ] **Step 5: Run the test and full suite**

Run: `./vendor/bin/phpunit --filter testDuelTileIsHiddenAndActsAsNormalTile tests/database/GameEngineHardeningTest.php`
Expected: PASS

Run: `./vendor/bin/phpunit`
Expected: all tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/GameEngineHardeningTest.php
git commit -m "fix: hide the unimplemented DUEL tile from gameplay until it is built"
```

---

### Task 4: Bagian F — Peringatan soal tanpa tag difficulty

**Files:**
- Modify: `app/Services/Question/DocxQuestionImportService.php` (`newQuestion()`, the `isDifficultyLine()` call site, `persist()`)
- Modify: `app/Controllers/Teacher/QuestionController.php:74-88` (`importDocx()`)
- Test: `tests/database/DocxQuestionImportServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add this test to `DocxQuestionImportServiceTest`, reusing the existing fixture:

```php
    public function testImportDocxReportsQuestionsMissingExplicitDifficulty(): void
    {
        $path = $this->makeDocxFixture();
        $result = (new DocxQuestionImportService())->import($path, 1);
        $this->pathsToClean[] = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'uploads/question-imports/1/' . $result['batch_uuid'];

        // Fixture has 3 imported questions: #1 has [EASY] (explicit),
        // #2 and #3 only have [TRUE_FALSE] (difficulty defaults to MEDIUM, not explicit).
        $this->assertSame(2, $result['difficulty_unspecified']);
    }
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `./vendor/bin/phpunit --filter testImportDocxReportsQuestionsMissingExplicitDifficulty tests/database/DocxQuestionImportServiceTest.php`
Expected: FAIL with an undefined array key `difficulty_unspecified`.

- [ ] **Step 3: Track explicit difficulty in `newQuestion()`**

In `app/Services/Question/DocxQuestionImportService.php`, replace the `newQuestion()` method:

```php
    private function newQuestion(string $stem, array $images): array
    {
        $type = 'MULTIPLE_CHOICE';
        $difficulty = 'MEDIUM';
        $stem = preg_replace_callback('/\[(EASY|MEDIUM|HARD|MUDAH|SEDANG|SULIT|PILGAN|PG|TRUE_FALSE|TRUE\/FALSE|BENAR\s*SALAH)\]/i', static function (array $match) use (&$type, &$difficulty): string {
            $token = strtoupper(str_replace(' ', '_', $match[1]));
            if (in_array($token, ['EASY', 'MUDAH'], true)) {
                $difficulty = 'EASY';
            } elseif (in_array($token, ['HARD', 'SULIT'], true)) {
                $difficulty = 'HARD';
            } elseif (in_array($token, ['MEDIUM', 'SEDANG'], true)) {
                $difficulty = 'MEDIUM';
            } elseif (in_array($token, ['TRUE_FALSE', 'TRUE/FALSE', 'BENAR_SALAH'], true)) {
                $type = 'TRUE_FALSE';
            }

            return '';
        }, $stem) ?? $stem;

        return [
            'stem' => trim($stem),
            'type' => $type,
            'difficulty' => $difficulty,
            'answer' => null,
            'images' => $images,
            'options' => [],
        ];
    }
```

with:

```php
    private function newQuestion(string $stem, array $images): array
    {
        $type = 'MULTIPLE_CHOICE';
        $difficulty = 'MEDIUM';
        $difficultyExplicit = false;
        $stem = preg_replace_callback('/\[(EASY|MEDIUM|HARD|MUDAH|SEDANG|SULIT|PILGAN|PG|TRUE_FALSE|TRUE\/FALSE|BENAR\s*SALAH)\]/i', static function (array $match) use (&$type, &$difficulty, &$difficultyExplicit): string {
            $token = strtoupper(str_replace(' ', '_', $match[1]));
            if (in_array($token, ['EASY', 'MUDAH'], true)) {
                $difficulty = 'EASY';
                $difficultyExplicit = true;
            } elseif (in_array($token, ['HARD', 'SULIT'], true)) {
                $difficulty = 'HARD';
                $difficultyExplicit = true;
            } elseif (in_array($token, ['MEDIUM', 'SEDANG'], true)) {
                $difficulty = 'MEDIUM';
                $difficultyExplicit = true;
            } elseif (in_array($token, ['TRUE_FALSE', 'TRUE/FALSE', 'BENAR_SALAH'], true)) {
                $type = 'TRUE_FALSE';
            }

            return '';
        }, $stem) ?? $stem;

        return [
            'stem' => trim($stem),
            'type' => $type,
            'difficulty' => $difficulty,
            'difficulty_explicit' => $difficultyExplicit,
            'answer' => null,
            'images' => $images,
            'options' => [],
        ];
    }
```

- [ ] **Step 4: Mark explicit difficulty from the "Level: ..." line format too**

Find this block in the paragraph-parsing loop (search for `isDifficultyLine`):

```php
            if ($this->isDifficultyLine($line, $difficulty)) {
                $current['difficulty'] = $difficulty;
                continue;
            }
```

Replace with:

```php
            if ($this->isDifficultyLine($line, $difficulty)) {
                $current['difficulty'] = $difficulty;
                $current['difficulty_explicit'] = true;
                continue;
            }
```

- [ ] **Step 5: Count unspecified-difficulty questions in `persist()`**

In `persist()`, replace:

```php
    private function persist(array $questions, int $teacherId, string $batchUuid, int $skipped): array
    {
        $questionModel = new QuestionModel();
        $optionModel = new QuestionOptionModel();
        $imported = 0;

        foreach ($questions as $question) {
```

with:

```php
    private function persist(array $questions, int $teacherId, string $batchUuid, int $skipped): array
    {
        $questionModel = new QuestionModel();
        $optionModel = new QuestionOptionModel();
        $imported = 0;
        $difficultyUnspecified = 0;

        foreach ($questions as $question) {
```

Then, right after the existing `$imported++;` line near the end of the same `foreach` loop, add:

```php
            $imported++;
            if (empty($question['difficulty_explicit'])) {
                $difficultyUnspecified++;
            }
```

(replacing the standalone `$imported++;` line with these two lines).

Finally, update the method's `return` statement from:

```php
        return [
            'batch_uuid' => $batchUuid,
            'imported' => $imported,
            'skipped' => $skipped,
        ];
```

to:

```php
        return [
            'batch_uuid' => $batchUuid,
            'imported' => $imported,
            'skipped' => $skipped,
            'difficulty_unspecified' => $difficultyUnspecified,
        ];
```

- [ ] **Step 6: Run the test to confirm it passes**

Run: `./vendor/bin/phpunit --filter testImportDocxReportsQuestionsMissingExplicitDifficulty tests/database/DocxQuestionImportServiceTest.php`
Expected: PASS

- [ ] **Step 7: Surface the warning in the import flash message**

In `app/Controllers/Teacher/QuestionController.php`, replace in `importDocx()`:

```php
        $message = 'Import berhasil: ' . $result['imported'] . ' soal masuk bank soal.';
        if ((int) $result['skipped'] > 0) {
            $message .= ' ' . $result['skipped'] . ' soal dilewati karena format belum valid.';
        }

        return redirect()->to('/teacher/questions')->with('message', $message);
```

with:

```php
        $message = 'Import berhasil: ' . $result['imported'] . ' soal masuk bank soal.';
        if ((int) $result['skipped'] > 0) {
            $message .= ' ' . $result['skipped'] . ' soal dilewati karena format belum valid.';
        }
        if ((int) $result['difficulty_unspecified'] > 0) {
            $message .= ' ' . $result['difficulty_unspecified'] . ' soal tanpa tag difficulty eksplisit otomatis dianggap MEDIUM.';
        }

        return redirect()->to('/teacher/questions')->with('message', $message);
```

- [ ] **Step 8: Run the full DOCX test file and full suite**

Run: `./vendor/bin/phpunit tests/database/DocxQuestionImportServiceTest.php`
Expected: both tests PASS.

Run: `./vendor/bin/phpunit`
Expected: all tests PASS.

- [ ] **Step 9: Manual verification**

Start the dev server (`php spark serve --host 127.0.0.1 --port 8090`), log in as a teacher, go to `/teacher/questions`, import a `.docx` with at least one question missing a difficulty tag, and confirm the flash message mentions "soal tanpa tag difficulty eksplisit".

- [ ] **Step 10: Commit**

```bash
git add app/Services/Question/DocxQuestionImportService.php app/Controllers/Teacher/QuestionController.php tests/database/DocxQuestionImportServiceTest.php
git commit -m "feat: warn teachers when imported questions default to MEDIUM difficulty"
```

---

### Task 5: Bagian C — Preview tema papan di form create game

**Files:**
- Modify: `app/Views/teacher/games/create.php:112-124`
- Modify: `public/assets/app.css` (append new rules)

No controller change is needed: `GameController::create()` already passes `boards` (with `theme_json`, which includes `palette`) to the view, and the view already decodes `theme_json` per board in its loop.

- [ ] **Step 1: Add preview CSS**

Append to `public/assets/app.css`:

```css
.theme-grid {
    display: grid;
    gap: 8px;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
}

.theme-option {
    background: #ffffff;
    border: 1px solid var(--line);
    border-radius: 8px;
    cursor: pointer;
    display: grid;
    gap: 6px;
    padding: 10px;
}

.theme-option input {
    accent-color: var(--primary);
    min-height: auto;
    padding: 0;
    width: auto;
}

.theme-option strong {
    color: var(--text);
    font-size: 13px;
}

.theme-option .muted {
    font-size: 11px;
}

.theme-option:has(input:checked) {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
}

.theme-preview {
    border-radius: 6px;
    display: grid;
    gap: 2px;
    grid-template-columns: repeat(4, 1fr);
    height: 64px;
    overflow: hidden;
    padding: 4px;
    position: relative;
}

.theme-preview-tile {
    border-radius: 2px;
}

.theme-preview-ladder,
.theme-preview-snake {
    border-radius: 2px;
    bottom: 4px;
    height: 4px;
    position: absolute;
    width: 40%;
}

.theme-preview-ladder {
    left: 4px;
}

.theme-preview-snake {
    right: 4px;
}

.theme-preview-auto {
    align-items: center;
    background: #e2e8f0;
    color: #475569;
    display: flex;
    font-size: 11px;
    font-weight: 800;
    height: 64px;
    justify-content: center;
    text-transform: uppercase;
}
```

- [ ] **Step 2: Replace the theme `<select>` with preview cards**

In `app/Views/teacher/games/create.php`, replace lines 112-124:

```php
        <div class="field">
            <label for="board_template_id">Tema Papan</label>
            <select id="board_template_id" name="board_template_id">
                <option value="">Pilih otomatis</option>
                <?php foreach ($boards as $board): ?>
                    <?php $theme = json_decode((string) ($board['theme_json'] ?? ''), true) ?: []; ?>
                    <option value="<?= esc((string) $board['id']) ?>" <?= old('board_template_id') == $board['id'] ? 'selected' : '' ?>>
                        <?= esc($theme['name'] ?? $board['name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <p class="field-help">Tema memengaruhi suasana papan, bukan mengganti soal satu per satu.</p>
        </div>
```

with:

```php
        <div class="field">
            <label>Tema Papan</label>
            <div class="theme-grid">
                <label class="theme-option">
                    <input type="radio" name="board_template_id" value="" <?= (string) old('board_template_id', '') === '' ? 'checked' : '' ?>>
                    <span class="theme-preview theme-preview-auto">Otomatis</span>
                    <strong>Pilih Otomatis</strong>
                    <span class="muted">Sistem pilih tema aktif pertama</span>
                </label>
                <?php foreach ($boards as $board): ?>
                    <?php
                        $theme = json_decode((string) ($board['theme_json'] ?? ''), true) ?: [];
                        $palette = $theme['palette'] ?? [];
                    ?>
                    <label class="theme-option">
                        <input type="radio" name="board_template_id" value="<?= esc((string) $board['id']) ?>" <?= (string) old('board_template_id') === (string) $board['id'] ? 'checked' : '' ?>>
                        <span class="theme-preview" style="background:<?= esc($palette['board'] ?? '#111827') ?>">
                            <?php for ($i = 0; $i < 16; $i++): ?>
                                <span class="theme-preview-tile" style="background:<?= esc($i % 2 === 0 ? ($palette['tileA'] ?? '#f8fafc') : ($palette['tileB'] ?? '#e0f2fe')) ?>"></span>
                            <?php endfor ?>
                            <span class="theme-preview-ladder" style="background:<?= esc($palette['ladder'] ?? '#facc15') ?>"></span>
                            <span class="theme-preview-snake" style="background:<?= esc($palette['snake'] ?? '#22c55e') ?>"></span>
                        </span>
                        <strong><?= esc($theme['name'] ?? $board['name']) ?></strong>
                        <span class="muted"><?= esc((string) $board['tile_count']) ?> kotak</span>
                    </label>
                <?php endforeach ?>
            </div>
            <p class="field-help">Tema memengaruhi suasana papan (warna, ular, tangga), bukan mengganti soal satu per satu.</p>
        </div>
```

- [ ] **Step 3: Manual verification**

Start the dev server, open `/teacher/games/create`, and confirm each theme card shows a distinct mini color swatch with a ladder/snake bar, the tile count, and that clicking a card highlights it (via `:has(input:checked)`, matching the existing `.mode-option` behavior). Submit the form and confirm the created room uses the selected theme (unchanged backend behavior — `board_template_id` is posted exactly as before).

- [ ] **Step 4: Commit**

```bash
git add app/Views/teacher/games/create.php public/assets/app.css
git commit -m "feat: show a visual preview of each board theme on the create-game form"
```

---

### Task 6: Bagian D — Info ukuran papan & penjelasan alur soal

**Files:**
- Modify: `app/Views/teacher/games/create.php` (field-help text near "Zona difficulty")
- Modify: `app/Views/teacher/games/show.php:5-17` (info line)

No controller changes needed — `show()` already passes `snapshot`, which already includes `snapshot['board']['tile_count']`.

- [ ] **Step 1: Add the turn/question flow explanation to the create form**

In `app/Views/teacher/games/create.php`, directly below the existing line:

```php
            <p class="field-help">Zona difficulty: kotak 1-30 EASY, 31-70 MEDIUM, 71-100 HARD. Jika stok zona kosong, game fallback ke soal published lain.</p>
```

add:

```php
            <p class="field-help">Soal muncul di setiap giliran lempar dadu, disesuaikan dengan kotak yang dituju dadu. Kotak BONUS/TRAP/SAFE/MYSTERY adalah efek tambahan yang berlaku setelah jawaban benar, bukan syarat munculnya soal.</p>
```

- [ ] **Step 2: Show board size on the room detail page**

In `app/Views/teacher/games/show.php`, replace:

```php
        <p class="muted">
            Mode:
            <strong><?= esc($snapshot['mode_state']['label'] ?? $room['game_mode'] ?? 'Ular Tangga Kuis') ?></strong>
            /
            Giliran pertama:
```

with:

```php
        <p class="muted">
            Papan: <strong><?= esc((string) $snapshot['board']['tile_count']) ?> kotak</strong>
            /
            Mode:
            <strong><?= esc($snapshot['mode_state']['label'] ?? $room['game_mode'] ?? 'Ular Tangga Kuis') ?></strong>
            /
            Giliran pertama:
```

- [ ] **Step 3: Manual verification**

Open `/teacher/games/create` and confirm the new explanatory sentence appears under "Pengambilan Soal". Open any room's detail page (`/teacher/games/{uuid}`) and confirm it now starts with "Papan: 100 kotak /".

- [ ] **Step 4: Commit**

```bash
git add app/Views/teacher/games/create.php app/Views/teacher/games/show.php
git commit -m "docs(ui): show board size and clarify when questions appear on create-game form"
```

---

### Task 7: Bagian G — Guru atur jumlah kotak Mystery per room

**Files:**
- Modify: `app/Services/Game/GameEngine.php:40-53` (`createRoom()`), plus a new private method
- Modify: `app/Controllers/Teacher/GameController.php:52-119` (`store()`)
- Modify: `app/Views/teacher/games/create.php`
- Test: `tests/database/GameEngineHardeningTest.php`

- [ ] **Step 1: Write the failing tests**

Add these two tests to `GameEngineHardeningTest`:

```php
    public function testCreateRoomPlacesRequestedNumberOfMysteryTiles(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Count Test', [
            'mystery_tile_count' => 4,
        ])['room'];

        $usedBoardId = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $board = (new BoardTemplateModel())->find($usedBoardId);
        $tiles = json_decode((string) $board['special_tiles_json'], true);
        $mysteryTiles = array_values(array_filter($tiles, static fn (array $tile): bool => $tile['type'] === 'MYSTERY'));

        $this->assertCount(4, $mysteryTiles);
        $this->assertSame('ROOM_INSTANCE', $board['status']);

        $positions = array_map(static fn (array $tile): int => (int) $tile['tile'], $tiles);
        $this->assertSame($positions, array_values(array_unique($positions)));
    }

    public function testCreateRoomKeepsOriginalBoardWhenMysteryCountUnchanged(): void
    {
        $sourceBoard = (new BoardTemplateModel())->where('status', 'ACTIVE')->orderBy('id', 'ASC')->first();
        $sourceTiles = json_decode((string) $sourceBoard['special_tiles_json'], true);
        $defaultMysteryCount = count(array_filter($sourceTiles, static fn (array $tile): bool => $tile['type'] === 'MYSTERY'));

        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Default Test', [
            'mystery_tile_count' => $defaultMysteryCount,
        ])['room'];

        $usedBoardId = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first()['board_template_id'];
        $this->assertSame((int) $sourceBoard['id'], (int) $usedBoardId);
    }
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `./vendor/bin/phpunit --filter "testCreateRoomPlacesRequestedNumberOfMysteryTiles|testCreateRoomKeepsOriginalBoardWhenMysteryCountUnchanged" tests/database/GameEngineHardeningTest.php`
Expected: FAIL — `createRoom()` doesn't read `mystery_tile_count` yet, so the first test's board keeps the default 2 mystery tiles instead of 4.

- [ ] **Step 3: Add `applyMysteryTileCount()` and wire it into `createRoom()`**

In `app/Services/Game/GameEngine.php`, replace this block inside `createRoom()` (lines 40-53):

```php
        $boards = new BoardTemplateModel();
        $boardTemplateId = (int) ($options['board_template_id'] ?? 0);
        $board = null;
        if ($boardTemplateId > 0) {
            $board = $boards->where('id', $boardTemplateId)->where('status', 'ACTIVE')->first();
        }
        $board ??= (new BoardTemplateModel())->where('status', 'ACTIVE')->first();
        if ($board === null) {
            throw new DomainException('Board template belum tersedia. Jalankan seeder demo lebih dulu.');
        }

        $pin = $this->uniquePin();
```

with:

```php
        $boards = new BoardTemplateModel();
        $boardTemplateId = (int) ($options['board_template_id'] ?? 0);
        $board = null;
        if ($boardTemplateId > 0) {
            $board = $boards->where('id', $boardTemplateId)->where('status', 'ACTIVE')->first();
        }
        $board ??= (new BoardTemplateModel())->where('status', 'ACTIVE')->first();
        if ($board === null) {
            throw new DomainException('Board template belum tersedia. Jalankan seeder demo lebih dulu.');
        }

        if (isset($options['mystery_tile_count'])) {
            $board = $this->applyMysteryTileCount($board, (int) $options['mystery_tile_count']);
        }

        $pin = $this->uniquePin();
```

Then add this new private method right after `specialTileAt()` (currently ending around line 1270):

```php
    private function applyMysteryTileCount(array $board, int $mysteryCount): array
    {
        $mysteryCount = max(0, min(6, $mysteryCount));
        $allTiles = json_decode((string) ($board['special_tiles_json'] ?? ''), true);
        if (! is_array($allTiles)) {
            $allTiles = [];
        }

        $nonMysteryTiles = array_values(array_filter(
            $allTiles,
            static fn ($tile): bool => is_array($tile) && strtoupper((string) ($tile['type'] ?? '')) !== 'MYSTERY',
        ));
        $currentMysteryCount = count($allTiles) - count($nonMysteryTiles);

        if ($mysteryCount === $currentMysteryCount) {
            return $board;
        }

        $tileCount = (int) $board['tile_count'];
        $occupied = [];
        foreach ($nonMysteryTiles as $tile) {
            $occupied[(int) $tile['tile']] = true;
        }
        foreach (json_decode((string) $board['ladders_json'], true) ?: [] as $ladder) {
            $occupied[(int) $ladder['from']] = true;
            $occupied[(int) $ladder['to']] = true;
        }
        foreach (json_decode((string) $board['snakes_json'], true) ?: [] as $snake) {
            $occupied[(int) $snake['from']] = true;
            $occupied[(int) $snake['to']] = true;
        }

        $pool = [];
        for ($tile = 2; $tile < $tileCount; $tile++) {
            if (! isset($occupied[$tile])) {
                $pool[] = $tile;
            }
        }
        shuffle($pool);
        $newMysteryTiles = array_map(
            static fn (int $tile): array => ['tile' => $tile, 'type' => 'MYSTERY', 'label' => 'Misteri'],
            array_slice($pool, 0, $mysteryCount),
        );

        $newSpecialTiles = array_merge($nonMysteryTiles, $newMysteryTiles);
        $newBoardId = (new BoardTemplateModel())->insert([
            'public_uuid' => Uuid::v4(),
            'name' => $board['name'] . ' (Room)',
            'tile_count' => $tileCount,
            'ladders_json' => $board['ladders_json'],
            'snakes_json' => $board['snakes_json'],
            'special_tiles_json' => json_encode($newSpecialTiles, JSON_UNESCAPED_SLASHES),
            'theme_json' => $board['theme_json'],
            'status' => 'ROOM_INSTANCE',
        ], true);

        return (new BoardTemplateModel())->find($newBoardId);
    }
```

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `./vendor/bin/phpunit --filter "testCreateRoomPlacesRequestedNumberOfMysteryTiles|testCreateRoomKeepsOriginalBoardWhenMysteryCountUnchanged" tests/database/GameEngineHardeningTest.php`
Expected: PASS

- [ ] **Step 5: Wire the option through the controller**

In `app/Controllers/Teacher/GameController.php`, inside `store()`, add right after the `$boardTemplateId` block:

```php
        $mysteryTileCount = $this->request->getPost('mystery_tile_count');
        $mysteryTileCount = $mysteryTileCount === null || $mysteryTileCount === ''
            ? null
            : max(0, min(6, (int) $mysteryTileCount));
```

Then add `'mystery_tile_count' => $mysteryTileCount,` to the options array passed to `$engine->createRoom(...)`, e.g.:

```php
        $snapshot = $engine->createRoom($teacherId, $title, [
            'game_mode' => $gameMode,
            'board_template_id' => $boardTemplateId,
            'turn_order_mode' => $turnOrderMode,
            'finish_rule' => $finishRule,
            'mystery_tile_count' => $mysteryTileCount,
            'skip_quota' => $tenant->isSuperadmin(),
```

- [ ] **Step 6: Add the input field to the create form**

In `app/Views/teacher/games/create.php`, add this new field right after the theme-grid field block (added in Task 5):

```php
        <div class="field">
            <label for="mystery_tile_count">Jumlah Kotak Mystery</label>
            <input type="number" id="mystery_tile_count" name="mystery_tile_count" min="0" max="6" value="<?= esc((string) old('mystery_tile_count', 2)) ?>">
            <p class="field-help">Kotak Mystery ditempatkan acak di papan saat room dibuat, tidak menumpuk dengan kotak spesial lain.</p>
        </div>
```

- [ ] **Step 7: Run the full suite**

Run: `./vendor/bin/phpunit`
Expected: all tests PASS.

- [ ] **Step 8: Manual verification**

Start the dev server, create a room with "Jumlah Kotak Mystery" set to 5, open its detail page, and confirm 5 Mystery tiles are visible on the board (distinct from BONUS/TRAP/SAFE tiles).

- [ ] **Step 9: Commit**

```bash
git add app/Services/Game/GameEngine.php app/Controllers/Teacher/GameController.php app/Views/teacher/games/create.php tests/database/GameEngineHardeningTest.php
git commit -m "feat: let teachers choose how many Mystery tiles appear on the board"
```

---

### Task 8: Bagian H (1/6) — Migration untuk mystery_target_team_id

**Files:**
- Create: `app/Database/Migrations/2026-09-08-000010_AddMysteryTargetToGameTurns.php`
- Modify: `app/Models/GameTurnModel.php`

- [ ] **Step 1: Create the migration**

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMysteryTargetToGameTurns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_turns', [
            'mystery_target_team_id' => [
                'type' => 'INTEGER',
                'null' => true,
                'after' => 'answer_is_correct',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_turns', 'mystery_target_team_id');
    }
}
```

- [ ] **Step 2: Add the field to the model**

In `app/Models/GameTurnModel.php`, add `'mystery_target_team_id',` to `$allowedFields`, right after `'answer_is_correct',`:

```php
    protected $allowedFields = [
        'public_uuid',
        'room_id',
        'team_id',
        'state',
        'turn_number',
        'dice_value',
        'question_id',
        'question_started_at',
        'question_deadline_at',
        'answer_is_correct',
        'mystery_target_team_id',
    ];
```

- [ ] **Step 3: Apply the migration to the local dev database**

Run: `php spark migrate`
Expected: output confirms `AddMysteryTargetToGameTurns` migrated.

- [ ] **Step 4: Run the full test suite**

Run: `./vendor/bin/phpunit`
Expected: all tests PASS (PHPUnit's `DatabaseTestTrait` runs migrations automatically against its own test database, so this also verifies the migration is valid).

- [ ] **Step 5: Commit**

```bash
git add app/Database/Migrations/2026-09-08-000010_AddMysteryTargetToGameTurns.php app/Models/GameTurnModel.php
git commit -m "feat: add mystery_target_team_id column to game_turns"
```

---

### Task 9: Bagian H (2/6) — answer() menunda giliran saat mendarat di Mystery

**Files:**
- Modify: `app/Services/Game/GameEngine.php` (tail of `answer()`, `applySpecialTileEffect()`'s MYSTERY branch, delete `applyMysteryEffect()`)
- Test: `tests/database/GameEngineHardeningTest.php:261-279` (`testMysteryTileTriggersServerChosenEffect`)

- [ ] **Step 1: Replace the old Mystery test**

Replace `testMysteryTileTriggersServerChosenEffect` (`GameEngineHardeningTest.php:261-279`) with:

```php
    public function testMysteryLandingDefersToChoicePendingState(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Pending Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];

        $snapshot = $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(46, $updatedTeam['position']);
        $this->assertSame(100, $updatedTeam['score']);
        $this->assertSame('MYSTERY_CHOICE_PENDING', $snapshot['current_turn']['state']);
        $this->assertSame($team['public_uuid'], $snapshot['current_turn']['team_uuid']);
    }
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `./vendor/bin/phpunit --filter testMysteryLandingDefersToChoicePendingState tests/database/GameEngineHardeningTest.php`
Expected: FAIL — the turn currently completes immediately (`TURN_COMPLETED`) instead of pausing at `MYSTERY_CHOICE_PENDING`.

- [ ] **Step 3: Simplify the MYSTERY branch in `applySpecialTileEffect()`**

Replace:

```php
        if ($type === 'MYSTERY') {
            return $this->applyMysteryEffect($position, $label, $maxPosition, $activeEffects);
        }
```

with:

```php
        if ($type === 'MYSTERY') {
            return [
                'to' => $position,
                'special' => 'MYSTERY',
                'effects' => [],
                'score_delta' => 0,
            ];
        }
```

- [ ] **Step 4: Delete the now-unused `applyMysteryEffect()` method**

Delete the entire `private function applyMysteryEffect(...)` method (the block right after `applySpecialTileEffect()`, roughly 45 lines, ending right before `private function isTurnExpired(...)`).

- [ ] **Step 5: Defer turn completion when landing on Mystery**

In `answer()`, replace:

```php
        $finished = $to >= (int) $room['max_position'];
        $nextTeam = null;
        if ($finished) {
            (new GameRoomModel())->update($room['id'], [
                'status' => 'FINISHED',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'TURN_COMPLETED',
                'answer_is_correct' => $isCorrect ? 1 : 0,
            ]);
        } else {
            $nextTeam = $this->nextTeam((int) $room['id'], (int) $team['id']);
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'TURN_COMPLETED',
                'answer_is_correct' => $isCorrect ? 1 : 0,
            ]);
            (new GameRoomModel())->update($room['id'], [
                'current_team_id' => $nextTeam['id'],
            ]);
            $this->createTurn($room, $nextTeam, ((int) $turn['turn_number']) + 1);
        }
```

with:

```php
        $isMysteryLanding = $isCorrect && ($movement['special'] ?? null) === 'MYSTERY';
        $finished = ! $isMysteryLanding && $to >= (int) $room['max_position'];
        $nextTeam = null;
        if ($isMysteryLanding) {
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'MYSTERY_CHOICE_PENDING',
                'answer_is_correct' => 1,
                'question_started_at' => date('Y-m-d H:i:s'),
                'question_deadline_at' => date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']),
            ]);
        } elseif ($finished) {
            (new GameRoomModel())->update($room['id'], [
                'status' => 'FINISHED',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'TURN_COMPLETED',
                'answer_is_correct' => $isCorrect ? 1 : 0,
            ]);
        } else {
            $nextTeam = $this->nextTeam((int) $room['id'], (int) $team['id']);
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'TURN_COMPLETED',
                'answer_is_correct' => $isCorrect ? 1 : 0,
            ]);
            (new GameRoomModel())->update($room['id'], [
                'current_team_id' => $nextTeam['id'],
            ]);
            $this->createTurn($room, $nextTeam, ((int) $turn['turn_number']) + 1);
        }
```

- [ ] **Step 6: Run the test and the full suite**

Run: `./vendor/bin/phpunit --filter testMysteryLandingDefersToChoicePendingState tests/database/GameEngineHardeningTest.php`
Expected: PASS

Run: `./vendor/bin/phpunit`
Expected: all tests PASS (no other test lands a team on a MYSTERY tile, so `$isMysteryLanding` is `false` everywhere else and behavior is unchanged).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/GameEngineHardeningTest.php
git commit -m "refactor: defer turn completion when a team lands on a Mystery tile"
```

---

### Task 10: Bagian H (3/6) — chooseMysteryTarget()

**Files:**
- Modify: `app/Services/Game/GameEngine.php` (new public method `chooseMysteryTarget()`, new private method `resolveMysteryChoiceTimeout()`)
- Test: `tests/database/GameEngineHardeningTest.php`

- [ ] **Step 1: Write the failing tests**

Add these to `GameEngineHardeningTest`:

```php
    public function testChooseMysteryTargetSelfPreparesHardQuestion(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Choose Self Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);

        $snapshot = $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], 'SELF');

        $this->assertSame('MYSTERY_QUESTION_ACTIVE', $snapshot['current_turn']['state']);
        $this->assertSame('HARD', $snapshot['current_turn']['question']['difficulty']);
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.target_chosen');
        $this->assertSame('SELF', $lastEvent['payload']['target']);
    }

    public function testChooseMysteryTargetRejectsSelfAsOpponent(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Choose Invalid Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);

        $this->expectException(\DomainException::class);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], $team['public_uuid']);
    }

    public function testChooseMysteryTargetOpponentValidatesTeamExistsInRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Choose Opponent Invalid Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);

        $this->expectException(\DomainException::class);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], 'not-a-real-team-uuid');
    }

    public function testMysteryChoiceTimeoutSkipsTurnWithoutEffect(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Choice Timeout Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $first = $engine->joinByPin($room['pin'], 'Tim A')['team'];
        $second = $engine->joinByPin($room['pin'], 'Tim B')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $first, 45, 1);

        $turns = new GameTurnModel();
        $turn = $turns->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $turns->update($turn['id'], [
            'question_deadline_at' => date('Y-m-d H:i:s', time() - 5),
        ]);

        $snapshot = $engine->chooseMysteryTarget($room['uuid'], $first['public_uuid'], 'SELF');
        $updatedTeam = $this->teamFromSnapshot($snapshot, $first['public_uuid']);

        $this->assertSame('QUESTION_TIMEOUT', (new GameTurnModel())->find($turn['id'])['state']);
        $this->assertSame($second['public_uuid'], $snapshot['room']['current_team_uuid']);
        $this->assertSame(100, $updatedTeam['score']);
    }
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `./vendor/bin/phpunit --filter "testChooseMysteryTarget|testMysteryChoiceTimeout" tests/database/GameEngineHardeningTest.php`
Expected: FAIL with "Call to undefined method GameEngine::chooseMysteryTarget()".

- [ ] **Step 3: Implement `chooseMysteryTarget()` and `resolveMysteryChoiceTimeout()`**

Add these two methods to `GameEngine.php`, right after the `answer()` method ends (before `public function snapshot(...)`):

```php
    public function chooseMysteryTarget(string $roomUuid, string $teamUuid, string $target, ?string $idempotencyKey = null): array
    {
        $room = $this->roomByUuid($roomUuid);
        $team = $this->teamByUuid($teamUuid, (int) $room['id']);
        $scope = 'mystery_choose:' . $room['public_uuid'] . ':' . $team['public_uuid'];
        if ($existing = $this->idempotentResponse($scope, $idempotencyKey)) {
            return $existing;
        }

        $turn = $this->activeTurn((int) $room['id']);
        if ($turn === null || $turn['state'] !== 'MYSTERY_CHOICE_PENDING' || (int) $turn['team_id'] !== (int) $team['id']) {
            throw new DomainException('Tidak ada Kotak Misteri yang menunggu pilihan tim ini.');
        }

        if ($this->isTurnExpired($turn)) {
            $response = $this->resolveMysteryChoiceTimeout($room, $team, $turn);
            $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

            return $response;
        }

        $targetTeamId = null;
        if ($target !== 'SELF') {
            $targetTeam = (new GameTeamModel())
                ->where('room_id', $room['id'])
                ->where('public_uuid', $target)
                ->first();
            if ($targetTeam === null || (int) $targetTeam['id'] === (int) $team['id']) {
                throw new DomainException('Target Kotak Misteri tidak valid.');
            }
            $targetTeamId = (int) $targetTeam['id'];
        }

        $question = $this->selectQuestion((int) $room['teacher_id'], 'HARD');
        $now = date('Y-m-d H:i:s');
        $deadline = date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => 'MYSTERY_QUESTION_ACTIVE',
            'mystery_target_team_id' => $targetTeamId,
            'question_id' => $question['id'],
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
        $this->bumpRoom($room['id']);
        $room = $this->roomById((int) $room['id']);

        $this->recordEvent($room, 'mystery.target_chosen', [
            'team_uuid' => $team['public_uuid'],
            'target' => $target,
        ]);

        $response = $this->snapshot($room['public_uuid']);
        $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

        return $response;
    }

    private function resolveMysteryChoiceTimeout(array $room, array $team, array $turn): array
    {
        $nextTeam = $this->nextTeam((int) $room['id'], (int) $team['id']);

        $this->db->transStart();
        (new GameTurnModel())->update($turn['id'], [
            'state' => 'QUESTION_TIMEOUT',
        ]);
        (new GameRoomModel())->update($room['id'], [
            'current_team_id' => $nextTeam['id'],
        ]);
        $this->createTurn($room, $nextTeam, ((int) $turn['turn_number']) + 1);
        $this->bumpRoom($room['id']);
        $this->db->transComplete();

        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'turn.timeout', [
            'team_uuid' => $team['public_uuid'],
            'turn_uuid' => $turn['public_uuid'],
            'next_team_uuid' => $nextTeam['public_uuid'],
            'points' => 0,
            'reason' => 'mystery_choice_expired',
        ]);

        return $this->snapshot($room['public_uuid']);
    }
```

- [ ] **Step 4: Run the tests and full suite**

Run: `./vendor/bin/phpunit --filter "testChooseMysteryTarget|testMysteryChoiceTimeout" tests/database/GameEngineHardeningTest.php`
Expected: all 4 PASS.

Run: `./vendor/bin/phpunit`
Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/GameEngineHardeningTest.php
git commit -m "feat: let a team choose a Mystery Box target before answering the HARD question"
```

---

### Task 11: Bagian H (4/6) — answerMystery()

**Files:**
- Modify: `app/Services/Game/GameEngine.php` (new public method `answerMystery()`, private `resolveMysteryOutcome()` and `applyMysteryDeltaToTeam()`)
- Test: `tests/database/GameEngineHardeningTest.php`

- [ ] **Step 1: Write the failing tests**

Add these to `GameEngineHardeningTest`:

```php
    public function testMysteryRewardSelfOnCorrectAnswer(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Reward Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], 'SELF');

        $turn = (new GameTurnModel())->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $optionId = $this->correctOptionId((int) $turn['question_id']);
        $snapshot = $engine->answerMystery($room['uuid'], $team['public_uuid'], $optionId);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(49, $updatedTeam['position']);
        $this->assertSame(180, $updatedTeam['score']);
        $this->assertSame('TURN_COMPLETED', (new GameTurnModel())->find($turn['id'])['state']);
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.resolved');
        $this->assertSame('REWARD_SELF', $lastEvent['payload']['outcome']);
    }

    public function testMysteryPunishOpponentOnCorrectAnswer(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Punish Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $opponent = $engine->joinByPin($room['pin'], 'Tim Lawan')['team'];
        (new GameTeamModel())->update($opponent['id'], ['position' => 30, 'score' => 200]);

        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], $opponent['public_uuid']);

        $turn = (new GameTurnModel())->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $optionId = $this->correctOptionId((int) $turn['question_id']);
        $snapshot = $engine->answerMystery($room['uuid'], $team['public_uuid'], $optionId);
        $updatedOpponent = $this->teamFromSnapshot($snapshot, $opponent['public_uuid']);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(26, $updatedOpponent['position']);
        $this->assertSame(140, $updatedOpponent['score']);
        $this->assertSame(46, $updatedTeam['position']);
        $this->assertSame(100, $updatedTeam['score']);
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.resolved');
        $this->assertSame('PUNISH_OPPONENT', $lastEvent['payload']['outcome']);
        $this->assertSame($opponent['public_uuid'], $lastEvent['payload']['affected_team_uuid']);
    }

    public function testMysteryBoomerangsToSelfOnWrongAnswer(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Boomerang Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $opponent = $engine->joinByPin($room['pin'], 'Tim Lawan')['team'];

        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], $opponent['public_uuid']);

        $turn = (new GameTurnModel())->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $optionId = $this->wrongOptionId((int) $turn['question_id']);
        $snapshot = $engine->answerMystery($room['uuid'], $team['public_uuid'], $optionId);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);
        $updatedOpponent = $this->teamFromSnapshot($snapshot, $opponent['public_uuid']);

        $this->assertSame(42, $updatedTeam['position']);
        $this->assertSame(40, $updatedTeam['score']);
        $this->assertSame(1, $updatedOpponent['position']);
        $this->assertSame(100, $updatedOpponent['score']);
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.resolved');
        $this->assertSame('BOOMERANG_SELF', $lastEvent['payload']['outcome']);
    }

    public function testMysteryQuestionTimeoutBoomerangsToSelf(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Question Timeout Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];

        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], 'SELF');

        $turns = new GameTurnModel();
        $turn = $turns->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $turns->update($turn['id'], [
            'question_deadline_at' => date('Y-m-d H:i:s', time() - 5),
        ]);

        $optionId = $this->firstOptionId((int) $turn['question_id']);
        $snapshot = $engine->answerMystery($room['uuid'], $team['public_uuid'], $optionId);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(42, $updatedTeam['position']);
        $this->assertSame(40, $updatedTeam['score']);
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.resolved');
        $this->assertSame('BOOMERANG_SELF', $lastEvent['payload']['outcome']);
    }
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `./vendor/bin/phpunit --filter "testMysteryReward|testMysteryPunish|testMysteryBoomerangs|testMysteryQuestionTimeout" tests/database/GameEngineHardeningTest.php`
Expected: FAIL with "Call to undefined method GameEngine::answerMystery()".

- [ ] **Step 3: Implement `answerMystery()`, `resolveMysteryOutcome()`, and `applyMysteryDeltaToTeam()`**

Add these three methods right after `resolveMysteryChoiceTimeout()`:

```php
    public function answerMystery(string $roomUuid, string $teamUuid, int $optionId, ?string $idempotencyKey = null): array
    {
        $room = $this->roomByUuid($roomUuid);
        $team = $this->teamByUuid($teamUuid, (int) $room['id']);
        $scope = 'mystery_answer:' . $room['public_uuid'] . ':' . $team['public_uuid'];
        if ($existing = $this->idempotentResponse($scope, $idempotencyKey)) {
            return $existing;
        }

        $turn = $this->activeTurn((int) $room['id']);
        if ($turn === null || $turn['state'] !== 'MYSTERY_QUESTION_ACTIVE' || (int) $turn['team_id'] !== (int) $team['id']) {
            throw new DomainException('Tidak ada soal Kotak Misteri yang menunggu jawaban tim ini.');
        }

        if ($this->isTurnExpired($turn)) {
            $response = $this->resolveMysteryOutcome($room, $team, $turn, false);
            $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

            return $response;
        }

        $option = (new QuestionOptionModel())
            ->where('question_id', $turn['question_id'])
            ->where('id', $optionId)
            ->first();
        if ($option === null) {
            throw new DomainException('Pilihan jawaban tidak valid.');
        }

        $response = $this->resolveMysteryOutcome($room, $team, $turn, (int) $option['is_correct'] === 1);
        $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

        return $response;
    }

    private function resolveMysteryOutcome(array $room, array $team, array $turn, bool $isCorrect): array
    {
        $maxPosition = (int) $room['max_position'];
        $targetTeamId = $turn['mystery_target_team_id'] !== null ? (int) $turn['mystery_target_team_id'] : null;
        $finished = false;
        $finishedTeamUuid = null;

        if ($isCorrect && $targetTeamId === null) {
            $newPosition = $this->applyMysteryDeltaToTeam($room, $team, 80, 3, $maxPosition);
            $outcome = 'REWARD_SELF';
            $affectedTeamUuid = $team['public_uuid'];
            if ($newPosition >= $maxPosition) {
                $finished = true;
                $finishedTeamUuid = $team['public_uuid'];
            }
        } elseif ($isCorrect && $targetTeamId !== null) {
            $opponent = (new GameTeamModel())->find($targetTeamId);
            $this->applyMysteryDeltaToTeam($room, $opponent, -60, -4, $maxPosition);
            $outcome = 'PUNISH_OPPONENT';
            $affectedTeamUuid = $opponent['public_uuid'];
        } else {
            $this->applyMysteryDeltaToTeam($room, $team, -60, -4, $maxPosition);
            $outcome = 'BOOMERANG_SELF';
            $affectedTeamUuid = $team['public_uuid'];
        }

        $this->db->transStart();
        if ($finished) {
            (new GameRoomModel())->update($room['id'], [
                'status' => 'FINISHED',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'TURN_COMPLETED',
                'answer_is_correct' => $isCorrect ? 1 : 0,
            ]);
        } else {
            $nextTeam = $this->nextTeam((int) $room['id'], (int) $team['id']);
            (new GameTurnModel())->update($turn['id'], [
                'state' => 'TURN_COMPLETED',
                'answer_is_correct' => $isCorrect ? 1 : 0,
            ]);
            (new GameRoomModel())->update($room['id'], [
                'current_team_id' => $nextTeam['id'],
            ]);
            $this->createTurn($room, $nextTeam, ((int) $turn['turn_number']) + 1);
        }
        $this->bumpRoom($room['id']);
        $this->db->transComplete();

        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'mystery.resolved', [
            'team_uuid' => $team['public_uuid'],
            'affected_team_uuid' => $affectedTeamUuid,
            'is_correct' => $isCorrect,
            'outcome' => $outcome,
        ]);
        if ($finished) {
            $this->recordEvent($room, 'game.finished', [
                'winner_team_uuid' => $finishedTeamUuid,
            ]);
        }

        return $this->snapshot($room['public_uuid']);
    }

    private function applyMysteryDeltaToTeam(array $room, array $team, int $pointsDelta, int $stepsDelta, int $maxPosition): int
    {
        $newPosition = max(1, min($maxPosition, (int) $team['position'] + $stepsDelta));
        (new GameTeamModel())->update($team['id'], [
            'position' => $newPosition,
            'score' => (int) $team['score'] + $pointsDelta,
        ]);
        $this->recordScore($room, $team, 'SPECIAL_TILE', $pointsDelta, $pointsDelta >= 0 ? 'Bonus Kotak Misteri' : 'Penalti Kotak Misteri');

        return $newPosition;
    }
```

- [ ] **Step 4: Run the tests and full suite**

Run: `./vendor/bin/phpunit --filter "testMysteryReward|testMysteryPunish|testMysteryBoomerangs|testMysteryQuestionTimeout" tests/database/GameEngineHardeningTest.php`
Expected: all 4 PASS.

Run: `./vendor/bin/phpunit`
Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/GameEngineHardeningTest.php
git commit -m "feat: resolve Mystery Box reward/punish/boomerang outcomes"
```

---

### Task 12: Bagian H (5/6) — API routes dan controller

**Files:**
- Modify: `app/Config/Routes.php:52-53`
- Modify: `app/Controllers/Api/V1/RoomsController.php`

- [ ] **Step 1: Add the two new routes**

In `app/Config/Routes.php`, right after the existing `answer` route:

```php
$routes->post('rooms/(:segment)/answer', 'Api\V1\RoomsController::answer/$1', ['filter' => 'rateLimit:45,60,api-mutation']);
```

add:

```php
$routes->post('rooms/(:segment)/mystery/choose', 'Api\V1\RoomsController::chooseMystery/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
$routes->post('rooms/(:segment)/mystery/answer', 'Api\V1\RoomsController::answerMystery/$1', ['filter' => 'rateLimit:45,60,api-mutation']);
```

- [ ] **Step 2: Add the controller actions**

In `app/Controllers/Api/V1/RoomsController.php`, add these two methods right after `answer()`:

```php
    public function chooseMystery(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamUuid = (string) ($payload['team_uuid'] ?? $this->request->getGet('team'));
        $target = (string) ($payload['target'] ?? '');

        return $this->respond(function () use ($roomUuid, $teamUuid, $target, $payload): array {
            (new TeamSessionService())->assertTeamSession($roomUuid, $teamUuid);

            return (new GameEngine())->chooseMysteryTarget(
                $roomUuid,
                $teamUuid,
                $target,
                $this->request->getHeaderLine('Idempotency-Key') ?: ($payload['idempotency_key'] ?? null)
            );
        });
    }

    public function answerMystery(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamUuid = (string) ($payload['team_uuid'] ?? $this->request->getGet('team'));
        $optionId = (int) ($payload['option_id'] ?? 0);

        return $this->respond(function () use ($roomUuid, $teamUuid, $optionId, $payload): array {
            (new TeamSessionService())->assertTeamSession($roomUuid, $teamUuid);

            return (new GameEngine())->answerMystery(
                $roomUuid,
                $teamUuid,
                $optionId,
                $this->request->getHeaderLine('Idempotency-Key') ?: ($payload['idempotency_key'] ?? null)
            );
        });
    }
```

- [ ] **Step 3: Confirm the routes resolve**

Run: `php spark routes | grep mystery`
Expected: both lines are listed, e.g.:
```
POST    | rooms/([^/]+)/mystery/choose  | » Api\V1\RoomsController::chooseMystery
POST    | rooms/([^/]+)/mystery/answer  | » Api\V1\RoomsController::answerMystery
```

Full end-to-end HTTP verification (real room UUID, real team session cookie) happens in Task 13's manual verification once the team controller UI can drive these endpoints through the browser.

- [ ] **Step 4: Commit**

```bash
git add app/Config/Routes.php app/Controllers/Api/V1/RoomsController.php
git commit -m "feat: expose mystery choose/answer endpoints"
```

---

### Task 13: Bagian H (6/6) — Client UI (team controller + projector)

**Files:**
- Modify: `app/Views/game/controller.php`
- Modify: `public/assets/app.js`

- [ ] **Step 1: Add the Mystery choice panel to the team controller view**

In `app/Views/game/controller.php`, add this new panel right after the existing `data-question` panel (after its closing `</div>`, before the "Papan" panel):

```html
    <div class="panel hidden" data-mystery-choice>
        <h2>Kotak Misteri</h2>
        <p class="muted">Pilih niatmu sebelum menjawab soal HARD.</p>
        <button class="button" type="button" data-mystery-self>Untuk Timku</button>
        <div class="answer-list" data-mystery-opponents></div>
    </div>
```

- [ ] **Step 2: Query the new elements in `controller()`**

In `public/assets/app.js`, inside `function controller(config) {`, add these three lines right after the existing `const optionList = document.querySelector('[data-options]');` line:

```javascript
        const mysteryChoiceBox = document.querySelector('[data-mystery-choice]');
        const mysterySelfButton = document.querySelector('[data-mystery-self]');
        const mysteryOpponents = document.querySelector('[data-mystery-opponents]');
```

- [ ] **Step 3: Extend `canAnswer` to also cover the Mystery HARD question, and render the choice panel**

Replace:

```javascript
            const canAnswer = modeCan(snapshot, 'answer') && isMyTurn && turn && turn.state === 'QUESTION_ACTIVE' && turn.question && !timeExpired;
```

with:

```javascript
            const isMysteryChoice = Boolean(isMyTurn && turn && turn.state === 'MYSTERY_CHOICE_PENDING');
            const canAnswer = modeCan(snapshot, 'answer') && isMyTurn && turn
                && (turn.state === 'QUESTION_ACTIVE' || turn.state === 'MYSTERY_QUESTION_ACTIVE')
                && turn.question && !timeExpired;
```

Then, right after the existing `if (questionBox && optionList) { ... }` block, add:

```javascript
            if (mysteryChoiceBox) {
                mysteryChoiceBox.classList.toggle('hidden', !isMysteryChoice);
                if (mysterySelfButton) {
                    mysterySelfButton.disabled = !isMysteryChoice;
                }
                if (isMysteryChoice && mysteryOpponents) {
                    mysteryOpponents.innerHTML = (snapshot.teams || [])
                        .filter((item) => item.uuid !== config.teamUuid)
                        .map((item) => '<button class="answer-button" type="button" data-mystery-target="' + item.uuid + '"><strong>Serang</strong><span>' + escapeHtml(item.name) + '</span></button>')
                        .join('');
                }
            }
```

- [ ] **Step 4: Fix the `questionBox`/`optionList` visibility condition to also cover the Mystery HARD question**

Replace:

```javascript
            if (questionBox && optionList) {
                questionBox.classList.toggle('hidden', !(canAnswer || (isMyTurn && turn && turn.state === 'QUESTION_ACTIVE' && turn.question)));
                optionList.innerHTML = (canAnswer || (isMyTurn && turn && turn.state === 'QUESTION_ACTIVE' && turn.question)) ? turn.question.options.map((option) => (
```

with:

```javascript
            const showQuestion = canAnswer || (isMyTurn && turn && (turn.state === 'QUESTION_ACTIVE' || turn.state === 'MYSTERY_QUESTION_ACTIVE') && turn.question);
            if (questionBox && optionList) {
                questionBox.classList.toggle('hidden', !showQuestion);
                optionList.innerHTML = showQuestion ? turn.question.options.map((option) => (
```

- [ ] **Step 5: Route the answer submit to the right endpoint**

Replace the `optionList` click handler:

```javascript
        if (optionList) {
            optionList.addEventListener('click', function (event) {
                const button = event.target.closest('[data-option-id]');
                if (!button || button.disabled || isAnswering) {
                    return;
                }
                isAnswering = true;
                drawController();
                runtime.setError('');
                jsonFetch('/api/v1/rooms/' + config.roomUuid + '/answer', {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid, option_id: button.dataset.optionId}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message))
                    .finally(() => {
                        isAnswering = false;
                        drawController();
                    });
            });
        }
```

with:

```javascript
        if (optionList) {
            optionList.addEventListener('click', function (event) {
                const button = event.target.closest('[data-option-id]');
                if (!button || button.disabled || isAnswering) {
                    return;
                }
                isAnswering = true;
                drawController();
                runtime.setError('');
                const activeTurn = runtime.getSnapshot().current_turn;
                const endpoint = activeTurn && activeTurn.state === 'MYSTERY_QUESTION_ACTIVE' ? '/mystery/answer' : '/answer';
                jsonFetch('/api/v1/rooms/' + config.roomUuid + endpoint, {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid, option_id: button.dataset.optionId}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message))
                    .finally(() => {
                        isAnswering = false;
                        drawController();
                    });
            });
        }

        if (mysterySelfButton) {
            mysterySelfButton.addEventListener('click', function () {
                if (mysterySelfButton.disabled) {
                    return;
                }
                runtime.setError('');
                jsonFetch('/api/v1/rooms/' + config.roomUuid + '/mystery/choose', {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid, target: 'SELF'}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message));
            });
        }

        if (mysteryOpponents) {
            mysteryOpponents.addEventListener('click', function (event) {
                const button = event.target.closest('[data-mystery-target]');
                if (!button) {
                    return;
                }
                runtime.setError('');
                jsonFetch('/api/v1/rooms/' + config.roomUuid + '/mystery/choose', {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid, target: button.dataset.mysteryTarget}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message));
            });
        }
```

- [ ] **Step 6: Add projector overlays for the two new events**

In `overlayForEvent()`, add these two cases right after the existing `case 'tile.special_triggered':` case:

```javascript
            case 'mystery.target_chosen':
                return {
                    tone: 'dice',
                    title: 'Kotak Misteri',
                    body: teamNameByUuid(payload.team_uuid, snapshot) + ' memilih ' + (payload.target === 'SELF' ? 'hadiah untuk timnya' : 'menyerang ' + teamNameByUuid(payload.target, snapshot)),
                };
            case 'mystery.resolved':
                return {
                    tone: payload.outcome === 'REWARD_SELF' ? 'success' : 'danger',
                    title: payload.outcome === 'REWARD_SELF' ? 'Misteri: Hadiah!' : (payload.outcome === 'PUNISH_OPPONENT' ? 'Misteri: Kena Serang!' : 'Misteri: Boomerang!'),
                    body: teamNameByUuid(payload.affected_team_uuid, snapshot) + (payload.outcome === 'REWARD_SELF' ? ' dapat efek positif' : ' kena efek negatif'),
                };
```

- [ ] **Step 7: Remove the now-unreachable MYSTERY case from `specialOverlay()`**

In `specialOverlay()`, delete this block (Mystery no longer emits a `tile.special_triggered` event, so this branch can never run):

```javascript
        if (type === 'MYSTERY') {
            return {tone: 'dice', title: 'Tile Misteri', body: effect.label || team};
        }
```

- [ ] **Step 8: Manual verification**

Start the dev server and, with two browser tabs (one team controller, one projector), play through: join two teams, start the game, force a team to land on a Mystery tile (or temporarily lower `mystery_tile_count` isn't needed — just play normally until a team lands on one), and confirm:
- The team controller shows "Untuk Timku" and one "Serang ..." button per opponent.
- Clicking either shows a HARD question.
- Answering correctly/incorrectly updates score/position as expected and the projector shows the new overlays.

- [ ] **Step 9: Commit**

```bash
git add app/Views/game/controller.php public/assets/app.js
git commit -m "feat: add Mystery Box choice UI and projector overlays"
```

---

### Task 14: Regresi penuh dan penutupan

**Files:** none (verification only)

- [ ] **Step 1: Run the full PHPUnit suite one more time**

Run: `./vendor/bin/phpunit`
Expected: all tests PASS (should be 38 original + ~15 new = ~53 tests).

- [ ] **Step 2: Lint-check every touched PHP file**

Run:
```bash
php -l app/Services/Game/GameEngine.php
php -l app/Controllers/Teacher/GameController.php
php -l app/Controllers/Teacher/QuestionController.php
php -l app/Controllers/Api/V1/RoomsController.php
php -l app/Config/Routes.php
php -l app/Models/GameTurnModel.php
php -l app/Database/Migrations/2026-09-08-000010_AddMysteryTargetToGameTurns.php
php -l app/Services/Question/DocxQuestionImportService.php
php -l app/Views/teacher/games/create.php
php -l app/Views/teacher/games/show.php
php -l app/Views/game/controller.php
```
Expected: "No syntax errors detected" for every file.

- [ ] **Step 3: Update the hardening todolist**

In `docs/superpowers/plans/2026-09-05-game-hardening-todolist.md`, under the "Priority 10" section added on 2026-09-08, check off every item in "Rencana Perbaikan" now that it is implemented.

- [ ] **Step 4: Final commit**

```bash
git add docs/superpowers/plans/2026-09-05-game-hardening-todolist.md
git commit -m "docs: mark Priority 10 create-game/mystery-box fixes as done"
```
