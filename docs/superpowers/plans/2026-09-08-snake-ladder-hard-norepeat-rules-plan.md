# Snake/Ladder HARD Challenge, No-Repeat Questions, Join Rules Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement `docs/superpowers/specs/2026-09-08-snake-ladder-hard-norepeat-rules-design.md` — soal tidak berulang per room (+ recycle), tantangan HARD saat mendarat ular/tangga setelah jawaban benar, dan narasi + checkbox aturan wajib di halaman join.

**Architecture:** Backend `GameEngine` tetap authoritative. Pola mengikuti mystery: jawaban benar yang mendarat di kepala ular / pangkal tangga **menunda** `applyBoardJump`, memindahkan pion ke `landed` saja, lalu masuk state `SNAKE_REDEMPTION_ACTIVE` / `LADDER_CHALLENGE_ACTIVE` dengan soal HARD baru. Resolusi akhir lewat `answerBoardChallenge` (endpoint API terpisah). No-repeat di `selectQuestion` memakai ID dari `game_answers` ∪ `game_turns.question_id` di room. Join menambah checkbox `rules_accepted` (client + server).

**Tech Stack:** PHP 8.2 / CodeIgniter 4, PHPUnit (`tests/database/GameEngineHardeningTest.php`), Vanilla JS `public/assets/app.js`, CI4 views.

**Catatan testing:** Backend tasks **wajib TDD** (test gagal → implementasi → hijau). Frontend JS diverifikasi `node --check` + checklist manual (tidak ada Jest).

---

## File map

| File | Tanggung jawab |
|---|---|
| `app/Services/Game/GameEngine.php` | no-repeat, defer snake/ladder, `answerBoardChallenge`, timeout challenge, events |
| `app/Controllers/Api/V1/RoomsController.php` | route handler `board-challenge/answer` |
| `app/Config/Routes.php` | daftar endpoint baru |
| `app/Controllers/Public/JoinController.php` | validasi `rules_accepted` |
| `app/Views/public/join.php` | narasi aturan + checkbox |
| `public/assets/app.js` | controller state HARD + projector events |
| `tests/database/GameEngineHardeningTest.php` | PHPUnit untuk semua perilaku engine |
| `tests/feature/JoinRulesTest.php` (create) | HTTP join tanpa/dengan checkbox |

Tidak ada migration: tujuan ekor ular / ujung tangga dihitung ulang dari posisi pion + `snakes_json` / `ladders_json` saat resolve (sama seperti mystery tidak menyimpan `movement` awal di kolom khusus).

Board demo (dari `DemoGameSeeder`): ular `17→7`, tangga `4→14` — dipakai di test dengan `answerCurrentTurnWithForcedMove(..., position, dice)`.

---

### Task 1: No-repeat `selectQuestion` (+ recycle event)

**Files:**
- Modify: `app/Services/Game/GameEngine.php` (`selectQuestion`, helper baru)
- Test: `tests/database/GameEngineHardeningTest.php`

- [ ] **Step 1: Tulis test gagal — soal tidak diulang dalam room**

Tambahkan di `GameEngineHardeningTest.php`:

```php
    public function testSelectQuestionDoesNotRepeatUntilPoolExhausted(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'No Repeat Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim A')['team'];
        $engine->joinByPin($room['pin'], 'Tim B');
        $engine->start($room['uuid']);

        $seen = [];
        for ($i = 0; $i < 6; $i++) {
            $snapshot = $engine->roll($room['uuid'], $team['public_uuid']);
            $questionId = (int) (new GameTurnModel())
                ->where('room_id', $this->roomId($room['uuid']))
                ->orderBy('id', 'DESC')
                ->first()['question_id'];
            $this->assertNotContains($questionId, $seen, 'Question repeated before pool exhausted');
            $seen[] = $questionId;

            $optionId = $this->wrongOptionId($questionId);
            $engine->answer($room['uuid'], $team['public_uuid'], $optionId);

            $team = $this->teamFromSnapshot(
                $engine->snapshot($room['uuid']),
                $team['public_uuid']
            );
            // After wrong answer, turn advances — ensure we are on this team's turn again
            $roomSnap = $engine->snapshot($room['uuid']);
            if ($roomSnap['room']['current_team_uuid'] !== $team['public_uuid']) {
                $other = $roomSnap['room']['current_team_uuid'];
                $engine->roll($room['uuid'], $other);
                $otherTurn = (new GameTurnModel())
                    ->where('room_id', $this->roomId($room['uuid']))
                    ->orderBy('id', 'DESC')
                    ->first();
                $engine->answer($room['uuid'], $other, $this->wrongOptionId((int) $otherTurn['question_id']));
            }
        }
    }
```

Catatan: demo seed punya ≥6 soal published untuk teacher 1. Jika loop goyang karena giliran, sederhanakan test dengan memanggil reflection/`selectQuestion` lewat skenario roll-only + force skip — **prefer** pendekatan di Step 1b berikut jika Step 1 terlalu fragile.

- [ ] **Step 1b (lebih sederhana, pakai ini jika Step 1 ribet): test lewat used-ids helper behavior**

Ganti/tambah test yang memaksa used set:

```php
    public function testRollDoesNotReuseQuestionIdsAlreadyOnTurnsInRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'No Repeat Simple', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim A')['team'];
        $engine->start($room['uuid']);

        $first = $engine->roll($room['uuid'], $team['public_uuid']);
        $turn = (new GameTurnModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->orderBy('id', 'DESC')
            ->first();
        $firstQuestionId = (int) $turn['question_id'];

        // Complete turn without moving (wrong answer)
        $engine->answer($room['uuid'], $team['public_uuid'], $this->wrongOptionId($firstQuestionId));

        // Same team again (only one team)
        $secondRoll = $engine->roll($room['uuid'], $team['public_uuid']);
        $secondTurn = (new GameTurnModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->orderBy('id', 'DESC')
            ->first();
        $secondQuestionId = (int) $secondTurn['question_id'];

        $this->assertNotSame($firstQuestionId, $secondQuestionId);
    }
```

(Dengan 1 tim, `nextTeam` kembali ke tim yang sama.)

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `./vendor/bin/phpunit --filter testRollDoesNotReuseQuestionIdsAlreadyOnTurnsInRoom`

Expected: FAIL (kadang pass secara kebetulan karena `array_rand` — jika PASS secara acak, ulangi 5× atau assert lebih kuat dengan menandai hampir semua soal sebagai used lewat insert `game_answers` palsu; lihat Step 2b).

- [ ] **Step 2b: Test recycle setelah habis**

```php
    public function testQuestionPoolRecyclesWhenExhausted(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Recycle Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Recycle')['team'];
        $engine->start($room['uuid']);
        $roomId = $this->roomId($room['uuid']);

        $allIds = array_map(
            static fn (array $q): int => (int) $q['id'],
            (new QuestionModel())
                ->where('owner_teacher_id', 1)
                ->where('status', 'PUBLISHED')
                ->findAll()
        );
        $this->assertNotEmpty($allIds);

        // Mark every published question as used via answers on a dummy completed turn pattern:
        $turn = (new GameTurnModel())
            ->where('room_id', $roomId)
            ->orderBy('id', 'DESC')
            ->first();
        foreach ($allIds as $qid) {
            (new GameAnswerModel())->insert([
                'turn_id' => $turn['id'],
                'team_id' => $team['id'],
                'question_id' => $qid,
                'option_id' => null,
                'answer_text' => 'seed-used',
                'is_correct' => 0,
                'answered_at' => date('Y-m-d H:i:s'),
                'response_ms' => 1,
            ]);
        }

        $engine->roll($room['uuid'], $team['public_uuid']);
        $event = $this->lastEvent($roomId, 'question.pool_recycled');
        $this->assertSame('exhausted', $event['payload']['reason']);
        $this->assertContains(
            (int) (new GameTurnModel())->where('room_id', $roomId)->orderBy('id', 'DESC')->first()['question_id'],
            $allIds
        );
    }
```

Run: `./vendor/bin/phpunit --filter testQuestionPoolRecyclesWhenExhausted`  
Expected: FAIL (`lastEvent` tidak menemukan `question.pool_recycled`).

- [ ] **Step 3: Implementasi no-repeat + recycle**

Di `GameEngine.php`, ganti `selectQuestion` dan tambahkan helper:

```php
    private function usedQuestionIdsForRoom(int $roomId): array
    {
        $fromAnswers = array_map(
            static fn (array $row): int => (int) $row['question_id'],
            $this->db->table('game_answers')
                ->select('game_answers.question_id')
                ->join('game_turns', 'game_turns.id = game_answers.turn_id')
                ->where('game_turns.room_id', $roomId)
                ->where('game_answers.question_id IS NOT NULL', null, false)
                ->get()
                ->getResultArray()
        );

        $fromTurns = array_map(
            static fn (array $row): int => (int) $row['question_id'],
            $this->db->table('game_turns')
                ->select('question_id')
                ->where('room_id', $roomId)
                ->where('question_id IS NOT NULL', null, false)
                ->get()
                ->getResultArray()
        );

        return array_values(array_unique(array_filter(array_merge($fromAnswers, $fromTurns))));
    }

    private function selectQuestion(int $teacherId, ?string $difficulty = null, array $topicIds = [], ?int $roomId = null): array
    {
        $usedIds = $roomId !== null ? $this->usedQuestionIdsForRoom($roomId) : [];

        $pick = function (bool $excludeUsed) use ($teacherId, $difficulty, $topicIds, $usedIds): array {
            $query = (new QuestionModel())
                ->where('owner_teacher_id', $teacherId)
                ->where('status', 'PUBLISHED');
            if ($topicIds !== []) {
                $query->whereIn('topic_id', $topicIds);
            }
            if ($difficulty !== null) {
                $query->where('difficulty', $difficulty);
            }
            if ($excludeUsed && $usedIds !== []) {
                $query->whereNotIn('id', $usedIds);
            }
            $questions = $query->findAll();
            if ($questions === [] && $difficulty !== null) {
                $fallbackQuery = (new QuestionModel())
                    ->where('owner_teacher_id', $teacherId)
                    ->where('status', 'PUBLISHED');
                if ($topicIds !== []) {
                    $fallbackQuery->whereIn('topic_id', $topicIds);
                }
                if ($excludeUsed && $usedIds !== []) {
                    $fallbackQuery->whereNotIn('id', $usedIds);
                }
                $questions = $fallbackQuery->findAll();
            }

            return $questions;
        };

        $questions = $pick(true);
        $recycled = false;
        if ($questions === []) {
            $questions = $pick(false);
            $recycled = $roomId !== null && $usedIds !== [];
        }

        if ($questions === []) {
            throw new DomainException('Bank soal masih kosong.');
        }

        $selected = $questions[array_rand($questions)];

        if ($recycled && $roomId !== null) {
            $room = $this->roomById($roomId);
            $this->recordEvent($room, 'question.pool_recycled', [
                'room_uuid' => $room['public_uuid'],
                'difficulty' => $difficulty,
                'topic_ids' => $topicIds,
                'reason' => 'exhausted',
            ]);
        }

        return $selected;
    }
```

Update **semua** pemanggilan `selectQuestion(...)` agar mengirim `$roomId`:

```php
// di roll(), chooseMystery mystery question, dan nanti board challenge:
$question = $this->selectQuestion(
    (int) $room['teacher_id'],
    $targetDifficulty,
    $selectionRules['topic_ids'],
    (int) $room['id']
);
```

Cari semua `selectQuestion(` di file yang sama dan tambahkan argumen room id.

- [ ] **Step 4: Jalankan test — harus PASS**

Run: `./vendor/bin/phpunit --filter 'testRollDoesNotReuseQuestionIdsAlreadyOnTurnsInRoom|testQuestionPoolRecyclesWhenExhausted'`

Expected: OK.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/GameEngineHardeningTest.php
git commit -m "feat: avoid repeating questions in a room until the pool is exhausted"
```

---

### Task 2: Tunda lompatan ular/tangga saat jawaban benar + masuk state challenge

**Files:**
- Modify: `app/Services/Game/GameEngine.php` (`movementForCorrectAnswer`, `answer`)
- Test: `tests/database/GameEngineHardeningTest.php`

- [ ] **Step 1: Tulis test gagal — benar + mendarat ular → state redemption, posisi di kepala**

```php
    public function testCorrectAnswerOnSnakeHeadStartsHardRedemption(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Snake Redemption Start', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Ular')['team'];
        // Demo board: snake 17 → 7. From 16 + dice 1 = 17.
        $snapshot = $this->answerCorrectWithForcedMove($engine, $room, $team, 16, 1);
        $updated = $this->teamFromSnapshot($snapshot, $team['public_uuid']);
        $turn = (new GameTurnModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertSame(17, $updated['position']);
        $this->assertSame('SNAKE_REDEMPTION_ACTIVE', $turn['state']);
        $this->assertNotNull($turn['question_id']);
        $question = (new QuestionModel())->find($turn['question_id']);
        $this->assertSame('HARD', $question['difficulty']);
        $started = $this->lastEvent($this->roomId($room['uuid']), 'snake.redemption_started');
        $this->assertSame($team['public_uuid'], $started['payload']['team_uuid']);
    }

    public function testCorrectAnswerOnLadderBaseStartsHardChallenge(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Ladder Challenge Start', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Tangga')['team'];
        // Demo board: ladder 4 → 14. From 3 + dice 1 = 4.
        $snapshot = $this->answerCorrectWithForcedMove($engine, $room, $team, 3, 1);
        $updated = $this->teamFromSnapshot($snapshot, $team['public_uuid']);
        $turn = (new GameTurnModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertSame(4, $updated['position']);
        $this->assertSame('LADDER_CHALLENGE_ACTIVE', $turn['state']);
        $question = (new QuestionModel())->find($turn['question_id']);
        $this->assertSame('HARD', $question['difficulty']);
        $started = $this->lastEvent($this->roomId($room['uuid']), 'ladder.challenge_started');
        $this->assertSame($team['public_uuid'], $started['payload']['team_uuid']);
    }
```

Jika seed tidak punya soal HARD, panggil `$this->seedHardQuestion(1);` di awal kedua test (helper sudah ada di file test).

- [ ] **Step 2: Jalankan — harus GAGAL**

Run: `./vendor/bin/phpunit --filter 'testCorrectAnswerOnSnakeHeadStartsHardRedemption|testCorrectAnswerOnLadderBaseStartsHardChallenge'`

Expected: FAIL (posisi langsung 7 / 14, atau state `TURN_COMPLETED`).

- [ ] **Step 3: Ubah `movementForCorrectAnswer` agar menunda SNAKE/LADDER**

Ganti isi setelah `$landed = ...` menjadi:

```php
        $activeEffects = $this->teamEffects($team);

        // Peek snake/ladder at landed. Safe shield still consumes and blocks without redemption.
        foreach (json_decode((string) $board['snakes_json'], true) ?: [] as $snake) {
            if ((int) $snake['from'] === $landed) {
                if (($activeEffects['safe_shield'] ?? 0) > 0) {
                    $activeEffects['safe_shield']--;

                    return [
                        'from' => $from,
                        'rolled_to' => $rolledTo,
                        'landed' => $landed,
                        'to' => $landed,
                        'special' => 'SAFE_BLOCK',
                        'effects' => [[
                            'type' => 'SAFE_BLOCK',
                            'tile' => $landed,
                            'blocked_type' => 'SNAKE',
                            'blocked_to' => (int) $snake['to'],
                            'label' => 'Perisai menahan ular',
                        ]],
                        'score_delta' => 0,
                        'active_effects' => $activeEffects,
                        'finish_bounced' => $finishBounced,
                        'pending_board_challenge' => null,
                    ];
                }

                return [
                    'from' => $from,
                    'rolled_to' => $rolledTo,
                    'landed' => $landed,
                    'to' => $landed,
                    'special' => 'SNAKE',
                    'effects' => [],
                    'score_delta' => 0,
                    'active_effects' => $activeEffects,
                    'finish_bounced' => $finishBounced,
                    'pending_board_challenge' => 'SNAKE',
                    'challenge_to' => (int) $snake['to'],
                ];
            }
        }

        foreach (json_decode((string) $board['ladders_json'], true) ?: [] as $ladder) {
            if ((int) $ladder['from'] === $landed) {
                return [
                    'from' => $from,
                    'rolled_to' => $rolledTo,
                    'landed' => $landed,
                    'to' => $landed,
                    'special' => 'LADDER',
                    'effects' => [],
                    'score_delta' => 0,
                    'active_effects' => $activeEffects,
                    'finish_bounced' => $finishBounced,
                    'pending_board_challenge' => 'LADDER',
                    'challenge_to' => (int) $ladder['to'],
                ];
            }
        }

        $boardJump = $this->applyBoardJump($landed, $board, $activeEffects);
        $tileEffect = $this->applySpecialTileEffect($boardJump['to'], $board, $activeEffects);
        $effects = array_merge($boardJump['effects'], $tileEffect['effects']);
        $special = $tileEffect['special'] ?? $boardJump['type'];

        return [
            'from' => $from,
            'rolled_to' => $rolledTo,
            'landed' => $landed,
            'to' => $tileEffect['to'],
            'special' => $special,
            'effects' => $effects,
            'score_delta' => (int) $tileEffect['score_delta'],
            'active_effects' => $activeEffects,
            'finish_bounced' => $finishBounced,
            'pending_board_challenge' => null,
        ];
```

Catatan: cabang snake/ladder di `applyBoardJump` tetap dipakai untuk path non-pending (atau bisa dibiarkan; peeks di atas sudah menangani landed snake/ladder).

- [ ] **Step 4: Di `answer()`, cabang seperti mystery**

Setelah hitung `$movement` / `$to` / skor reguler, ganti blok `$isMysteryLanding` menjadi:

```php
        $pendingChallenge = $isCorrect ? ($movement['pending_board_challenge'] ?? null) : null;
        $isMysteryLanding = $isCorrect && ($movement['special'] ?? null) === 'MYSTERY' && $pendingChallenge === null;
        $finished = ! $isMysteryLanding && $pendingChallenge === null && $to >= (int) $room['max_position'];
        $nextTeam = null;

        // ... keep answer insert + team update to $to (landed for pending snake/ladder) ...

        if ($pendingChallenge === 'SNAKE' || $pendingChallenge === 'LADDER') {
            $selectionRules = $this->questionSelectionRules($room['question_selection_json'] ?? [], (int) $room['max_position']);
            $hardQuestion = $this->selectQuestion((int) $room['teacher_id'], 'HARD', $selectionRules['topic_ids'], (int) $room['id']);
            $deadlineSeconds = $pendingChallenge === 'SNAKE'
                ? (int) ($room['redemption_time_seconds'] ?: $room['question_time_seconds'])
                : (int) $room['question_time_seconds'];
            $state = $pendingChallenge === 'SNAKE' ? 'SNAKE_REDEMPTION_ACTIVE' : 'LADDER_CHALLENGE_ACTIVE';
            (new GameTurnModel())->update($turn['id'], [
                'state' => $state,
                'answer_is_correct' => 1,
                'question_id' => $hardQuestion['id'],
                'question_started_at' => date('Y-m-d H:i:s'),
                'question_deadline_at' => date('Y-m-d H:i:s', time() + $deadlineSeconds),
            ]);
        } elseif ($isMysteryLanding) {
            // existing mystery branch
```

Setelah `transComplete` + `answer.resolved`, jika pending:

```php
        if ($pendingChallenge === 'SNAKE' || $pendingChallenge === 'LADDER') {
            $room = $this->roomById((int) $room['id']);
            $eventName = $pendingChallenge === 'SNAKE' ? 'snake.redemption_started' : 'ladder.challenge_started';
            $this->recordEvent($room, $eventName, [
                'team_uuid' => $team['public_uuid'],
                'from' => $movement['from'],
                'landed' => $movement['landed'],
                'challenge_to' => $movement['challenge_to'],
                'question' => $this->publicQuestion($hardQuestion),
            ]);
        }
```

Pastikan `$hardQuestion` masih in scope (deklarasikan sebelum `transStart` jika perlu).

Jangan emit `tile.special_triggered` untuk lompatan ular/tangga yang masih pending (effects kosong).

- [ ] **Step 5: Test PASS + commit**

Run: `./vendor/bin/phpunit --filter 'testCorrectAnswerOnSnakeHeadStartsHardRedemption|testCorrectAnswerOnLadderBaseStartsHardChallenge'`

```bash
git add app/Services/Game/GameEngine.php tests/database/GameEngineHardeningTest.php
git commit -m "feat: defer snake and ladder jumps into HARD challenge states"
```

---

### Task 3: `answerBoardChallenge` — resolve ular/tangga

**Files:**
- Modify: `app/Services/Game/GameEngine.php`
- Test: `tests/database/GameEngineHardeningTest.php`

- [ ] **Step 1: Tulis test gagal**

```php
    public function testSnakeRedemptionCorrectStaysOnHead(): void
    {
        $this->seedHardQuestion(1);
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Snake Save', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Selamat')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $team, 16, 1);

        $turn = (new GameTurnModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->orderBy('id', 'DESC')
            ->first();
        $snapshot = $engine->answerBoardChallenge(
            $room['uuid'],
            $team['public_uuid'],
            $this->correctOptionId((int) $turn['question_id'])
        );
        $updated = $this->teamFromSnapshot($snapshot, $team['public_uuid']);
        $this->assertSame(17, $updated['position']);
        $this->assertSame(75, $updated['score']); // +75 redemption with noScoring base? 
        // If noScoring zeroes answer points, set expectation to match scoringRules in test helpers.
        $resolved = $this->lastEvent($this->roomId($room['uuid']), 'snake.redemption_resolved');
        $this->assertTrue($resolved['payload']['is_correct']);
        $this->assertSame(['from' => 17, 'landed' => 17, 'to' => 17], $resolved['payload']['movement']);
    }

    public function testSnakeRedemptionWrongSlidesToTail(): void
    {
        $this->seedHardQuestion(1);
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Snake Fail', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Turun')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $team, 16, 1);
        $turn = (new GameTurnModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->orderBy('id', 'DESC')
            ->first();
        $snapshot = $engine->answerBoardChallenge(
            $room['uuid'],
            $team['public_uuid'],
            $this->wrongOptionId((int) $turn['question_id'])
        );
        $updated = $this->teamFromSnapshot($snapshot, $team['public_uuid']);
        $this->assertSame(7, $updated['position']);
        $resolved = $this->lastEvent($this->roomId($room['uuid']), 'snake.redemption_resolved');
        $this->assertFalse($resolved['payload']['is_correct']);
        $this->assertSame(['from' => 17, 'landed' => 17, 'to' => 7], $resolved['payload']['movement']);
    }

    public function testLadderChallengeCorrectClimbs(): void
    {
        $this->seedHardQuestion(1);
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Ladder Up', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Naik')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $team, 3, 1);
        $turn = (new GameTurnModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->orderBy('id', 'DESC')
            ->first();
        $snapshot = $engine->answerBoardChallenge(
            $room['uuid'],
            $team['public_uuid'],
            $this->correctOptionId((int) $turn['question_id'])
        );
        $updated = $this->teamFromSnapshot($snapshot, $team['public_uuid']);
        $this->assertSame(14, $updated['position']);
        $resolved = $this->lastEvent($this->roomId($room['uuid']), 'ladder.challenge_resolved');
        $this->assertTrue($resolved['payload']['is_correct']);
        $this->assertSame(['from' => 4, 'landed' => 4, 'to' => 14], $resolved['payload']['movement']);
    }

    public function testLadderChallengeWrongStaysAtBase(): void
    {
        $this->seedHardQuestion(1);
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Ladder Fail', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Gagal')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $team, 3, 1);
        $turn = (new GameTurnModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->orderBy('id', 'DESC')
            ->first();
        $snapshot = $engine->answerBoardChallenge(
            $room['uuid'],
            $team['public_uuid'],
            $this->wrongOptionId((int) $turn['question_id'])
        );
        $updated = $this->teamFromSnapshot($snapshot, $team['public_uuid']);
        $this->assertSame(4, $updated['position']);
        $resolved = $this->lastEvent($this->roomId($room['uuid']), 'ladder.challenge_resolved');
        $this->assertFalse($resolved['payload']['is_correct']);
    }
```

Sesuaikan assertion skor dengan `noScoring()` di test (baca helper `noScoring()` — jika semua poin 0, assert score delta lewat `score_transactions` atau set scoring eksplisit untuk redemption/hots). Prefer assert posisi + event dulu; skor bisa:

```php
        $this->assertGreaterThanOrEqual(75, $updated['score']); // if base scoring still applies
```

atau nonaktifkan assert skor ketat dan cek ledger:

```php
        $tx = (new ScoreTransactionModel())
            ->where('room_id', $this->roomId($room['uuid']))
            ->where('reason_code', 'SNAKE_REDEMPTION')
            ->first();
        $this->assertNotNull($tx);
        $this->assertSame(75, (int) $tx['delta']);
```

- [ ] **Step 2: Run — FAIL** (`answerBoardChallenge` undefined).

- [ ] **Step 3: Implementasikan `answerBoardChallenge`**

```php
    public function answerBoardChallenge(string $roomUuid, string $teamUuid, int $optionId, ?string $idempotencyKey = null): array
    {
        $room = $this->roomByUuid($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa. Permainan tidak bisa dilanjutkan.');
        $team = $this->teamByUuid($teamUuid, (int) $room['id']);
        $scope = 'board-challenge:' . $room['public_uuid'] . ':' . $team['public_uuid'];
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
        $allowed = ['SNAKE_REDEMPTION_ACTIVE', 'LADDER_CHALLENGE_ACTIVE'];
        if ($turn === null || ! in_array($turn['state'], $allowed, true)) {
            throw new DomainException('Tidak ada tantangan ular/tangga aktif.');
        }

        if ($this->isTurnExpired($turn)) {
            $response = $this->resolveBoardChallengeTimeout($room, $team, $turn);
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

        $isCorrect = (int) $option['is_correct'] === 1;
        $kind = $turn['state'] === 'SNAKE_REDEMPTION_ACTIVE' ? 'SNAKE' : 'LADDER';
        $board = (new BoardTemplateModel())->find($room['board_template_id']);
        $from = (int) $team['position'];
        $challengeTo = $this->boardChallengeTarget($from, $kind, $board);
        $to = $isCorrect
            ? ($kind === 'LADDER' ? $challengeTo : $from)
            : ($kind === 'SNAKE' ? $challengeTo : $from);
        $points = $isCorrect ? ($kind === 'SNAKE' ? 75 : 150) : 0;

        $this->db->transStart();
        (new GameAnswerModel())->insert([
            'turn_id' => $turn['id'],
            'team_id' => $team['id'],
            'question_id' => $turn['question_id'],
            'option_id' => $optionId,
            'answer_text' => $option['body'],
            'is_correct' => $isCorrect ? 1 : 0,
            'answered_at' => date('Y-m-d H:i:s'),
            'response_ms' => $this->responseMs($turn),
        ]);
        (new GameTeamModel())->update($team['id'], [
            'position' => $to,
            'score' => (int) $team['score'] + $points,
        ]);
        if ($points !== 0) {
            $this->recordScore(
                $room,
                $team,
                $kind === 'SNAKE' ? 'SNAKE_REDEMPTION' : 'LADDER_CHALLENGE',
                $points,
                $kind === 'SNAKE' ? 'Lolos ular' : 'Naik tangga'
            );
        }

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
            (new GameRoomModel())->update($room['id'], ['current_team_id' => $nextTeam['id']]);
            $this->createTurn($room, $nextTeam, ((int) $turn['turn_number']) + 1);
        }
        $this->bumpRoom($room['id']);
        $this->db->transComplete();

        $room = $this->roomById((int) $room['id']);
        $eventName = $kind === 'SNAKE' ? 'snake.redemption_resolved' : 'ladder.challenge_resolved';
        $movement = ['from' => $from, 'landed' => $from, 'to' => $to];
        $this->recordEvent($room, $eventName, [
            'team_uuid' => $team['public_uuid'],
            'is_correct' => $isCorrect,
            'points' => $points,
            'movement' => $movement,
        ]);
        if ($kind === 'SNAKE' && ! $isCorrect) {
            $this->recordEvent($room, 'tile.special_triggered', [
                'team_uuid' => $team['public_uuid'],
                'effect' => [
                    'type' => 'SNAKE',
                    'tile' => $from,
                    'to' => $to,
                    'label' => 'Ular',
                ],
                'movement' => $movement,
            ]);
        }
        if ($kind === 'LADDER' && $isCorrect) {
            $this->recordEvent($room, 'tile.special_triggered', [
                'team_uuid' => $team['public_uuid'],
                'effect' => [
                    'type' => 'LADDER',
                    'tile' => $from,
                    'to' => $to,
                    'label' => 'Tangga',
                ],
                'movement' => $movement,
            ]);
        }
        if ($finished) {
            $this->recordEvent($room, 'game.finished', [
                'winner_team_uuid' => $team['public_uuid'],
            ]);
        }

        $response = $this->snapshot($room['public_uuid']);
        $this->saveIdempotentResponse($scope, $idempotencyKey, $response);

        return $response;
    }

    private function boardChallengeTarget(int $from, string $kind, array $board): int
    {
        $key = $kind === 'SNAKE' ? 'snakes_json' : 'ladders_json';
        foreach (json_decode((string) $board[$key], true) ?: [] as $item) {
            if ((int) $item['from'] === $from) {
                return (int) $item['to'];
            }
        }
        throw new DomainException('Target ular/tangga tidak ditemukan di papan.');
    }

    private function resolveBoardChallengeTimeout(array $room, array $team, array $turn): array
    {
        // Treat timeout as wrong answer without option_id
        return $this->answerBoardChallengeAsWrong($room, $team, $turn, timedOut: true);
    }
```

Untuk timeout, ekstrak inti resolve ke private `finalizeBoardChallenge(...)` agar tidak rekursif aneh — atau dari `resolveBoardChallengeTimeout` duplikasi singkat path “salah” (YAGNI: panggil internal dengan `$optionId = 0` dan flag timeout insert answer null). Implementasi konkret: copy cabang wrong dari `answerBoardChallenge` ke `resolveBoardChallengeTimeout` tanpa validasi option (ikuti pola `resolveTimedOutTurn` + gerakan challenge).

Update `forceTimeout` / cabang di `answer` yang cek state: jika turn state adalah challenge, panggil `resolveBoardChallengeTimeout`.

Di `forceTimeout` existing, baca state turn; jika `SNAKE_REDEMPTION_ACTIVE` / `LADDER_CHALLENGE_ACTIVE`, delegasikan ke `resolveBoardChallengeTimeout`.

- [ ] **Step 4: PASS + commit**

Run: `./vendor/bin/phpunit --filter 'testSnakeRedemption|testLadderChallenge'`

```bash
git add app/Services/Game/GameEngine.php tests/database/GameEngineHardeningTest.php
git commit -m "feat: resolve snake redemption and ladder HARD challenges"
```

---

### Task 4: API route + controller

**Files:**
- Modify: `app/Config/Routes.php`
- Modify: `app/Controllers/Api/V1/RoomsController.php`

- [ ] **Step 1: Tambah route**

Di grup `api/v1`:

```php
    $routes->post('rooms/(:segment)/board-challenge/answer', 'Api\V1\RoomsController::answerBoardChallenge/$1', ['filter' => 'rateLimit:45,60,api-mutation']);
```

- [ ] **Step 2: Tambah method controller** (mirror `answerMystery`):

```php
    public function answerBoardChallenge(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamUuid = (string) ($payload['team_uuid'] ?? $this->request->getGet('team'));
        $optionId = (int) ($payload['option_id'] ?? 0);

        return $this->respond(function () use ($roomUuid, $teamUuid, $optionId, $payload): array {
            (new TeamSessionService())->assertTeamSession($roomUuid, $teamUuid);

            return (new GameEngine())->answerBoardChallenge(
                $roomUuid,
                $teamUuid,
                $optionId,
                $this->request->getHeaderLine('Idempotency-Key') ?: ($payload['idempotency_key'] ?? null)
            );
        });
    }
```

- [ ] **Step 3: Commit**

```bash
git add app/Config/Routes.php app/Controllers/Api/V1/RoomsController.php
git commit -m "feat: expose board-challenge answer API endpoint"
```

---

### Task 5: Join — narasi aturan + checkbox

**Files:**
- Modify: `app/Views/public/join.php`
- Modify: `app/Controllers/Public/JoinController.php`
- Create: `tests/feature/JoinRulesTest.php` (atau unit tipis jika feature harness sulit — prefer feature)

- [ ] **Step 1: Update view**

```php
<?= $this->extend('layouts/public') ?>

<?= $this->section('content') ?>
<section class="panel join-panel">
    <h1 class="page-title">Join Ular Tangga</h1>
    <p class="muted">Masukkan PIN room dan nama tim.</p>
    <?php if ($error): ?>
        <div class="alert"><?= esc($error) ?></div>
    <?php endif ?>

    <div class="rules-box" data-game-rules>
        <h2>Aturan Permainan</h2>
        <ul>
            <li>Ini permainan <strong>ular tangga kuis</strong>. Pemenang utama: tim yang <strong>pertama sampai kotak finish</strong>.</li>
            <li><strong>Skor</strong> mengukur prestasi menjawab; skor tinggi tidak menggantikan juara papan.</li>
            <li>Lempar dadu → jawab soal. <strong>Jawaban salah atau waktu habis: pion menetap.</strong> Jawaban benar: pion maju sesuai dadu.</li>
            <li>Mendarat di <strong>ular</strong>: soal <strong>sulit (HARD)</strong> untuk menyelamatkan diri. Benar = bertahan; salah = turun.</li>
            <li>Mendarat di <strong>tangga</strong>: soal <strong>sulit (HARD)</strong> untuk naik. Benar = naik; salah = tetap di pangkal.</li>
            <li>Jawablah jujur sesuai pengetahuan — tipu-tipu merugikan belajar dan semangat fair play.</li>
            <li>Ikuti arahan guru di layar projector.</li>
        </ul>
    </div>

    <form class="form" method="post" action="/join" data-join-form>
        <?= csrf_field() ?>
        <div class="field">
            <label for="pin">PIN</label>
            <input id="pin" name="pin" value="<?= esc($pin ?? old('pin')) ?>" inputmode="numeric" autocomplete="off" required>
        </div>
        <div class="field">
            <label for="team_name">Nama Tim</label>
            <input id="team_name" name="team_name" value="<?= esc(old('team_name')) ?>" maxlength="80" required>
        </div>
        <div class="field">
            <label for="avatar">Avatar Tim</label>
            <select id="avatar" name="avatar">
                <?php foreach (['robot' => 'Robot', 'explorer' => 'Explorer', 'rocket' => 'Rocket', 'knight' => 'Knight', 'scientist' => 'Scientist', 'runner' => 'Runner'] as $value => $label): ?>
                    <option value="<?= esc($value) ?>" <?= old('avatar', 'robot') === $value ? 'selected' : '' ?>>
                        <?= esc($label) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label>
                <input type="checkbox" name="rules_accepted" value="1" data-rules-accepted required>
                Saya sudah membaca dan memahami aturan permainan.
            </label>
        </div>
        <button class="button" type="submit" data-join-submit disabled>Masuk</button>
    </form>
</section>
<script>
(function () {
    var box = document.querySelector('[data-rules-accepted]');
    var button = document.querySelector('[data-join-submit]');
    if (!box || !button) return;
    function sync() { button.disabled = !box.checked; }
    box.addEventListener('change', sync);
    sync();
})();
</script>
<?= $this->endSection() ?>
```

Tambah CSS minimal di `public/assets/app.css` (atau stylesheet public yang dipakai layout):

```css
.rules-box {
    background: rgba(15, 23, 42, .04);
    border-radius: 12px;
    margin: 0 0 1rem;
    padding: 12px 14px;
}
.rules-box h2 { font-size: 1rem; margin: 0 0 .5rem; }
.rules-box ul { margin: 0; padding-left: 1.1rem; }
.rules-box li { margin: 0 0 .35rem; }
```

- [ ] **Step 2: Validasi server di `JoinController::join`**

Di awal method, setelah baca post:

```php
        if ((string) $this->request->getPost('rules_accepted') !== '1') {
            return redirect()->back()->withInput()->with('error', 'Centang persetujuan aturan permainan sebelum masuk.');
        }
```

- [ ] **Step 3: Feature test (jika `CIDatabaseTestTrait` / feature tersedia)**

```php
<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use App\Database\Seeds\DemoGameSeeder;
use App\Services\Game\GameEngine;

final class JoinRulesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $seed = DemoGameSeeder::class;

    public function testJoinWithoutRulesAcceptedIsRejected(): void
    {
        $room = (new GameEngine())->createRoom(1, 'Join Rules', [
            'turn_order_mode' => 'join_order',
        ])['room'];

        $result = $this->withSession([])->post('/join', [
            'pin' => $room['pin'],
            'team_name' => 'Tim Tanpa Centang',
            'avatar' => 'robot',
        ]);

        $result->assertRedirect();
        $this->assertNotEmpty(session()->getFlashdata('error'));
    }
}
```

Jika FeatureTestTrait tidak terpasang / flaky CSRF, skip file feature dan verifikasi manual + andalkan assert di controller lewat unit yang memanggil method dengan request mock — **minimal** pastikan baris validasi ada dan checklist manual Task 8 mencakup join.

- [ ] **Step 4: Commit**

```bash
git add app/Views/public/join.php app/Controllers/Public/JoinController.php public/assets/app.css tests/feature/JoinRulesTest.php
git commit -m "feat: require reading game rules before joining a team"
```

---

### Task 6: Controller frontend — state HARD ular/tangga

**Files:**
- Modify: `public/assets/app.js`

- [ ] **Step 1: Perluas `canAnswer` / `showQuestion`**

Cari pengecekan `QUESTION_ACTIVE || MYSTERY_QUESTION_ACTIVE` dan tambahkan:

```js
turn.state === 'SNAKE_REDEMPTION_ACTIVE' || turn.state === 'LADDER_CHALLENGE_ACTIVE'
```

- [ ] **Step 2: Endpoint submit jawaban**

Cari:

```js
const endpoint = activeTurn && activeTurn.state === 'MYSTERY_QUESTION_ACTIVE' ? '/mystery/answer' : '/answer';
```

Ganti:

```js
                let endpoint = '/answer';
                if (activeTurn && activeTurn.state === 'MYSTERY_QUESTION_ACTIVE') {
                    endpoint = '/mystery/answer';
                } else if (activeTurn && (activeTurn.state === 'SNAKE_REDEMPTION_ACTIVE' || activeTurn.state === 'LADDER_CHALLENGE_ACTIVE')) {
                    endpoint = '/board-challenge/answer';
                }
```

- [ ] **Step 3: Label UI singkat (opsional di controller view text)**

Jika ada heading soal, set teks saat state challenge:

```js
            const questionTitle = document.querySelector('[data-question-title]');
            if (questionTitle && turn) {
                if (turn.state === 'SNAKE_REDEMPTION_ACTIVE') {
                    questionTitle.textContent = 'Soal penyelamat ular (HARD)';
                } else if (turn.state === 'LADDER_CHALLENGE_ACTIVE') {
                    questionTitle.textContent = 'Soal klaim tangga (HARD)';
                } else {
                    questionTitle.textContent = 'Pertanyaan';
                }
            }
```

Tambah `<h2 data-question-title>Pertanyaan</h2>` di `app/Views/game/controller.php` jika belum ada elemen setara.

- [ ] **Step 4: `node --check` + commit**

```bash
node --check public/assets/app.js
git add public/assets/app.js app/Views/game/controller.php
git commit -m "feat: let team controller answer snake/ladder HARD challenges"
```

---

### Task 7: Projector sequencer — event ular/tangga

**Files:**
- Modify: `public/assets/app.js` (`SEQUENCED_EVENTS`, `runSequencedEvent`, overlay helpers)

- [ ] **Step 1: Tambah event ke set**

```js
    const SEQUENCED_EVENTS = new Set([
        'dice.rolled',
        'answer.resolved',
        'tile.special_triggered',
        'mystery.resolved',
        'snake.redemption_started',
        'snake.redemption_resolved',
        'ladder.challenge_started',
        'ladder.challenge_resolved',
        'game.finished',
    ]);
```

- [ ] **Step 2: Handler**

```js
            case 'snake.redemption_started':
                return GameFx.banner({
                    tone: 'trap',
                    icon: '🐍',
                    title: 'Ular! Soal penyelamat',
                    body: teamNameByUuid(event.payload.team_uuid, snapshot),
                    durationMs: 1800,
                });
            case 'ladder.challenge_started':
                return GameFx.banner({
                    tone: 'bonus',
                    icon: '🪜',
                    title: 'Tangga! Soal klaim',
                    body: teamNameByUuid(event.payload.team_uuid, snapshot),
                    durationMs: 1800,
                });
            case 'snake.redemption_resolved':
            case 'ladder.challenge_resolved':
                return runMovementSequence(event, snapshot, event.payload.team_uuid, event.payload.is_correct);
```

Pastikan `runMovementSequence` untuk challenge resolved: saat `is_correct` pada snake stay, `movement.from === movement.to` → skip walk; saat wrong snake, animasi turun; ladder correct animasi naik.

- [ ] **Step 3: `node --check` + commit**

```bash
node --check public/assets/app.js
git add public/assets/app.js
git commit -m "feat: sequence snake/ladder challenge banners on projector"
```

---

### Task 8: Regresi + QA manual

- [ ] **Step 1: Full PHPUnit**

Run: `./vendor/bin/phpunit`

Expected: semua hijau (termasuk test baru).

- [ ] **Step 2: JS syntax**

Run: `node --check public/assets/app.js && echo OK`

- [ ] **Step 3: Checklist manual**

- [ ] `/join` menampilkan aturan; tombol Masuk disabled sampai centang; submit tanpa centang (devtools hapus disabled) ditolak server.
- [ ] Room topik terbatas: tidak ada soal kembar sampai pool habis; setelah habis boleh ulang + (opsional) event recycle di log.
- [ ] Jawab salah reguler → pion diam.
- [ ] Benar mendarat ular → soal HARD → benar bertahan / salah turun + animasi.
- [ ] Benar mendarat tangga → soal HARD → benar naik / salah diam di pangkal.
- [ ] Mystery / bonus / trap tidak rusak.

- [ ] **Step 4: Commit perbaikan QA jika ada**

```bash
git add -A
git commit -m "fix: address issues found during snake/ladder rules QA"
```

(Lewati jika bersih.)

---

## Self-review vs spec

| Spec | Task |
|---|---|
| Salah reguler = menetap (existing) | tidak diubah; dicakup QA Task 8 |
| Ular HARD bertahan/turun | Task 2–3, 6–7 |
| Tangga HARD naik/tetap | Task 2–3, 6–7 |
| No-repeat A+C + recycle event | Task 1 |
| Aturan + checkbox join | Task 5 |
| API challenge terpisah | Task 4 |
| FX/projector events | Task 7 |
| PHPUnit | Task 1–3, 8 |

**Tidak ada placeholder TBD.** Nama method konsisten: `answerBoardChallenge`, states `SNAKE_REDEMPTION_ACTIVE` / `LADDER_CHALLENGE_ACTIVE`, events `snake.*` / `ladder.*` / `question.pool_recycled`.

---

## Execution handoff

Setelah plan disimpan, pilih cara eksekusi:

1. **Subagent-Driven (recommended)** — satu subagent per task + review antar task  
2. **Inline Execution** — `executing-plans` di sesi ini dengan checkpoint
