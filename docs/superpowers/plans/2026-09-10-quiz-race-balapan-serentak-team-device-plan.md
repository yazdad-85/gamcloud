# Quiz Race - Balapan Serentak Multi-Ronde Device per Tim Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable `QUIZ_RACE + TEAM_DEVICE` as a simultaneous race with multiple rounds, multiple shared question cycles per round, score-only round prizes, and immediate game finish when a question resolution puts a team on the finish line.

**Architecture:** Keep Phase 11 centralized play intact. Add macro rounds (`game_rounds`), question cycles inside rounds (`game_round_questions`), and one answer per team per question cycle (`game_round_answers`). `RaceRoundService` owns allocation/difficulty/round ranking; `RaceQuestionService` owns pure simultaneous movement. Atomic question-state transitions prevent duplicate answer or resolution effects.

**Confirmed product rules:**

- A round contains multiple questions. Default allocation is `[15, 15, 20]`.
- One question cycle presents the same question to all teams.
- Correct movement is `+1`; fastest correct gets another `+2`.
- A completed round awards score only, default `+100`, to its round winner.
- Reaching `max_position` ends the race immediately, even mid-round and before all 50 questions are used.
- A round interrupted by race finish gives no round-completion prize.

**Tech Stack:** PHP 8 / CodeIgniter 4, SQLite/MySQL-compatible migrations, vanilla JS (`public/assets/app.js`), PHPUnit database/unit/feature tests.

---

## Before You Start

- Working directory: `/Users/mbp19/Documents/YAZDAD/APLIKASI PRODUKSI/games/ular-tangga`.
- Read `docs/superpowers/specs/2026-09-10-quiz-race-team-device-design.md` completely.
- Read `docs/superpowers/plans/2026-09-10-quiz-race-sprint-tanpa-dadu-plan.md` for Phase 11 conventions.
- Run `./vendor/bin/phpunit` and keep the baseline green.
- Do not remove or rewrite centralized `selectDifficultyTier()` and Quiz Race `answer()` behavior.
- Every persisted field must be present in the corresponding model `$allowedFields`.
- Use server epoch milliseconds for race timing; do not reuse the current second-resolution `responseMs()` helper.

## Scope

**In scope:**

- Enable `QUIZ_RACE + TEAM_DEVICE` creation and PIN join.
- Configurable 1-5 rounds with a default 15/15/20 question allocation.
- Balanced persisted EASY/MEDIUM/HARD schedule in each round.
- Shared question/deadline and one answer per team per question cycle.
- Per-question movement, Boost, Oil Spill, score, and immediate finish detection.
- Round checkpoint, round standings, and score-only round prize.
- Question-limit fallback winner when nobody reaches finish.
- Atomic submit/resolve/advance behavior.
- Result/reconnect snapshots, team controller, teacher control, and projector.
- Pause/resume, reports, question protection, room deletion, and regression coverage.

**Out of scope:**

- Nitro, Pit Stop, and Duel Susul.
- Tournament or leaderboard across rooms.
- Full custom track/theme editor.
- Reworking centralized Quiz Race mechanics.

---

## Task 1: Schema and Models for Multi-Round Race

**Files:**

- Create: `app/Database/Migrations/2026-09-10-000005_AddQuizRaceRoundConfigToGameRooms.php`
- Create: `app/Database/Migrations/2026-09-10-000006_CreateGameRounds.php`
- Create: `app/Database/Migrations/2026-09-10-000007_CreateGameRoundQuestions.php`
- Create: `app/Database/Migrations/2026-09-10-000008_CreateGameRoundAnswers.php`
- Modify: `app/Models/GameRoomModel.php`
- Create: `app/Models/GameRoundModel.php`
- Create: `app/Models/GameRoundQuestionModel.php`
- Create: `app/Models/GameRoundAnswerModel.php`

- [ ] **Step 1: Add room configuration columns**

Add nullable columns so existing rooms remain valid:

- `race_question_limit` integer.
- `race_round_question_counts_json` text.
- `race_round_winner_bonus_points` integer.

Add all three to `GameRoomModel::$allowedFields`.

- [ ] **Step 2: Create `game_rounds`**

Columns:

- `id`, auto-increment primary key.
- `public_uuid` varchar(36).
- `room_id` integer.
- `round_number` integer.
- `state` varchar(30), default `ROUND_ACTIVE`.
- `question_target_count` integer.
- `question_resolved_count` integer, default 0.
- `difficulty_schedule_json` text.
- `round_winner_team_ids_json` text nullable.
- `round_score_summary_json` text nullable.
- `started_at`, `completed_at`, `reveal_until` datetime nullable.
- `reveal_until_epoch_ms` bigint nullable.
- `paused_remaining_ms` integer nullable.
- `created_at`, `updated_at` datetime nullable.

Indexes:

- Unique `public_uuid`.
- Unique `room_id, round_number`.
- Index `room_id, state`.

- [ ] **Step 3: Create `game_round_questions`**

Columns:

- `id`, `public_uuid`, `round_id`, `question_number`.
- `question_id`, `difficulty`.
- `state` varchar(30), default `QUESTION_ACTIVE`.
- `answer_count` integer, default 0.
- `started_at`, `started_at_epoch_ms`.
- `deadline_at`, `deadline_epoch_ms`.
- `paused_remaining_ms` integer nullable.
- `resolved_at`, `reveal_until`, `reveal_until_epoch_ms` nullable.
- `fastest_team_ids_json`, `finisher_team_ids_json`, `movement_summary_json` nullable.
- `created_at`, `updated_at`.

Indexes:

- Unique `public_uuid`.
- Unique `round_id, question_number`.
- Index `round_id, state`.
- Index `question_id`.

- [ ] **Step 4: Create `game_round_answers`**

Columns:

- `id`, `public_uuid`, `round_question_id`, `team_id`, `question_id`.
- `option_id` integer nullable.
- `answer_text` text nullable.
- `is_correct` integer, default 0.
- `outcome` varchar(20): `CORRECT`, `WRONG`, or `TIMEOUT`.
- `answered_at`, `answered_at_epoch_ms`, `response_ms` nullable.
- `score_delta` integer, default 0.
- `score_breakdown_json` text nullable.
- `created_at`, `updated_at` nullable.

Indexes:

- Unique `public_uuid`.
- Unique `round_question_id, team_id`.
- Index `round_question_id, is_correct, response_ms`.
- Index `team_id` and `question_id`.

Follow the existing schema style: logical relationships and explicit cleanup, not new cascade foreign keys.

- [ ] **Step 5: Create models and migration tests**

Verify all fields are writable and these uniqueness guards fail correctly:

- Duplicate room/round number.
- Duplicate round/question number.
- Duplicate question-cycle/team answer.

- [ ] **Step 6: Run and commit**

```bash
php spark migrate
./vendor/bin/phpunit
git add app/Database/Migrations app/Models/GameRoomModel.php app/Models/GameRoundModel.php app/Models/GameRoundQuestionModel.php app/Models/GameRoundAnswerModel.php
git commit -m "feat: add multi-round Quiz Race schema"
```

---

## Task 2: Pure Round and Question Services

**Files:**

- Create: `app/Services/Game/RaceRoundService.php`
- Create: `app/Services/Game/RaceQuestionService.php`
- Modify: `app/Services/Game/RaceTrackService.php` only for reusable helpers.
- Create: `tests/unit/RaceRoundServiceTest.php`
- Create: `tests/unit/RaceQuestionServiceTest.php`

- [ ] **Step 1: Test `RaceRoundService`**

Cover:

- Default allocation is `[15, 15, 20]`.
- Allocation validation accepts 1-5 positive counts totaling 3-100.
- Difficulty schedules are balanced and persisted-ready: 15 becomes 5/5/5; 20 becomes 7/6/7.
- Schedule shuffle can receive a deterministic randomizer/seed for tests.
- Round standings rank by score earned in round, correct count, then summed correct response time.
- Exact ties return co-winners.
- Round prize adds score only and never movement.
- Race-finish interruption is not eligible for round prize.

Recommended interface:

```php
final class RaceRoundService
{
    public function normalizeAllocation(mixed $source): array;
    public function difficultySchedule(int $questionCount): array;
    public function rankRound(array $teamRoundStats): array;
}
```

- [ ] **Step 2: Test `RaceQuestionService`**

Cover:

- Correct `+1`, fastest correct `+2`, wrong/timeout `+0`.
- Only correct answers compete for fastest.
- Exact millisecond ties all receive the fastest bonus.
- Existing Oil Spill lock removes fastest eligibility for one question and is consumed.
- Landing on a new Oil Spill stores a lock for the next question.
- Boost applies after landed position and clamps at finish.
- Movement is computed for all teams before finishers are selected.
- Finisher ranking uses total score, total correct, then summed correct response time.
- Question-limit fallback ranks by position, score, correct count, then response time.
- Exact final ties return co-winners.

Recommended interface:

```php
final class RaceQuestionService
{
    public function resolveMovements(array $teams, array $answers, array $room, array $board): array;
    public function rankFinishers(array $finisherStats): array;
    public function rankQuestionLimit(array $teamStats): array;
}
```

- [ ] **Step 3: Run and commit**

```bash
./vendor/bin/phpunit tests/unit/RaceRoundServiceTest.php
./vendor/bin/phpunit tests/unit/RaceQuestionServiceTest.php
./vendor/bin/phpunit
git add app/Services/Game/RaceRoundService.php app/Services/Game/RaceQuestionService.php app/Services/Game/RaceTrackService.php tests/unit/RaceRoundServiceTest.php tests/unit/RaceQuestionServiceTest.php
git commit -m "feat: add multi-round Quiz Race domain logic"
```

---

## Task 3: Allow and Configure Team-Device Rooms

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Modify: `app/Services/Game/Modes/QuizRaceModeEngine.php`
- Modify: `tests/database/QuizRaceModeTest.php`

- [ ] **Step 1: Write failing tests**

Prove:

- `createRoom()` accepts `QUIZ_RACE + TEAM_DEVICE`.
- Default allocation persists as `[15,15,20]`, limit 50, lap count 3, and round prize 100.
- Custom valid allocations persist.
- Invalid allocations are rejected, not silently changed.
- Race board remains snake/ladder-free and uses `clamp_finish`.
- PIN join works; owner roster addition remains rejected for Team Device.
- Centralized Quiz Race remains unchanged.

- [ ] **Step 2: Remove the Phase 11 creation guard**

Keep the guard removal scoped to `QUIZ_RACE + TEAM_DEVICE`. Parse allocation only for Team Device and derive `race_question_limit` and `lap_count` from it.

- [ ] **Step 3: Update mode state**

- Centralized: keep `actions: ['select_tier', 'answer']`.
- Team Device: return `actions: ['race_question_answer']` and `round_model: 'multi_question_round'`.

- [ ] **Step 4: Run and commit**

```bash
./vendor/bin/phpunit tests/database/QuizRaceModeTest.php
./vendor/bin/phpunit
git add app/Services/Game/GameEngine.php app/Services/Game/Modes/QuizRaceModeEngine.php tests/database/QuizRaceModeTest.php
git commit -m "feat: configure Quiz Race team-device rooms"
```

---

## Task 4: Start the First Round and Question Cycle

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Create: `tests/database/QuizRaceTeamDeviceTest.php`

- [ ] **Step 1: Test start behavior**

Cover:

- Start creates Ronde 1 with target 15 and a persisted 5/5/5 difficulty schedule.
- Start creates Question 1 using schedule index 0.
- `current_team_id` stays null and no `game_turns` row is created.
- Round question uses selected room topics and excludes used questions.
- Question start/deadline epoch milliseconds use one captured server clock.
- Existing Ular Tangga and centralized Quiz Race start paths remain unchanged.

- [ ] **Step 2: Add engine helpers**

Recommended private helpers:

```php
private function createRaceRound(array $room, int $roundNumber): array;
private function createRaceQuestion(array $room, array $round, int $questionNumber): array;
private function activeRaceRound(int $roomId): ?array;
private function activeRaceQuestion(int $roundId): ?array;
```

`createRaceRound()` reads the allocation at `roundNumber - 1`, persists the full difficulty schedule, resets team streaks for fair round scoring, and records `race.round_started`.

`createRaceQuestion()` selects one question for the scheduled difficulty, stores millisecond timing, and records `race.question_started`.

- [ ] **Step 3: Run and commit**

```bash
./vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php --filter Start
./vendor/bin/phpunit
git add app/Services/Game/GameEngine.php tests/database/QuizRaceTeamDeviceTest.php
git commit -m "feat: start multi-round Quiz Race questions"
```

---

## Task 5: Submit One Answer per Team per Question

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Modify: `tests/database/QuizRaceTeamDeviceTest.php`

- [ ] **Step 1: Write answer tests**

Cover:

- Valid team session can answer the current active question.
- Same team cannot answer the same question twice but can answer the next question.
- Team from another room and invalid option are rejected.
- Answer at/after deadline is late and cannot be inserted.
- Two submissions 50-100ms apart retain their true order.
- Answer racing with resolve either commits before the resolver claim or is rejected entirely.
- Active snapshot reveals only answered flags, not option/correctness/response time.

- [ ] **Step 2: Implement atomic `raceQuestionAnswer()`**

```php
public function raceQuestionAnswer(
    string $roomUuid,
    string $teamUuid,
    int $optionId,
    ?string $idempotencyKey = null
): array;
```

Within one transaction:

1. Validate room `PLAYING`, mode, participation mode, team ownership, question state, and deadline.
2. Conditional increment `game_round_questions.answer_count` where state is `QUESTION_ACTIVE` and deadline is still future.
3. Require one affected row.
4. Capture `answered_at_epoch_ms` with `microtime(true)` and derive `response_ms` from persisted question start.
5. Insert answer with `CORRECT` or `WRONG` outcome.
6. Roll back increment if unique answer insertion fails.

After commit, resolve when answer count equals the fixed room team count.

Idempotency scope:

```text
race-question-answer:{roomUuid}:{roundQuestionUuid}:{teamUuid}
```

`race.answer_submitted` exposes team identity/answered status only while active.

- [ ] **Step 3: Run and commit**

```bash
./vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php --filter Answer
./vendor/bin/phpunit
git add app/Services/Game/GameEngine.php tests/database/QuizRaceTeamDeviceTest.php
git commit -m "feat: accept Quiz Race question-cycle answers"
```

---

## Task 6: Resolve a Question, Score, Move, and Finish Immediately

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Modify: `app/Config/Game.php`
- Modify: `tests/database/QuizRaceTeamDeviceTest.php`

- [ ] **Step 1: Write resolution tests**

Cover movement, score breakdown, timeout rows, Boost/Oil Spill, exact fastest ties, and transaction rollback. Specifically prove:

- Correct non-fastest moves +1; fastest correct moves +3.
- Wrong/timeout stays put.
- Missing teams receive synthetic `TIMEOUT` answer rows.
- Time bonus uses each persisted `response_ms`, not resolve wall-clock time.
- Fastest/Boost/Oil Spill do not directly add score.
- Every team movement is calculated before finisher selection.
- One finisher ends the room immediately.
- A finish on any question, including the round's last allocated question, marks the round `ROUND_INTERRUPTED`, creates no next question, and awards no round prize.
- Simultaneous finish uses score/correct/time tie-breaks and supports exact co-winner.
- Concurrent last-answer, polling, and teacher-force requests apply movement and score once.

- [ ] **Step 2: Implement `resolveRaceQuestion()`**

```php
public function resolveRaceQuestion(
    string $roomUuid,
    bool $force = false,
    ?string $idempotencyKey = null
): array;
```

Without `force`, allow resolution only after all teams answer or deadline passes. `force=true` is authorized at the controller boundary and turns missing answers into timeout.

Transaction:

1. Conditional claim `QUESTION_ACTIVE -> QUESTION_RESOLVING`; continue only for one affected row.
2. Insert synthetic timeout outcomes.
3. Resolve movement for all teams.
4. Calculate base/time/streak score and score transactions per team.
5. Update all teams.
6. Increment the round's `question_resolved_count` exactly once.
7. Determine finishers after all movement updates are prepared.
8. Persist question as `QUESTION_RESOLVED`, movement summary, fastest ids, finisher ids, and reveal deadline.
9. If finishers exist, set room `FINISHED`, set round `ROUND_INTERRUPTED`, and persist `finish_reason=TRACK_FINISH` in the event payload. This finish branch takes priority even when `question_resolved_count === question_target_count`; do not calculate or award a round prize.
10. Bump room state once and commit.
11. Publish events only after successful commit.

Add `raceQuestionRevealSeconds = 3` and `raceRoundRevealSeconds = 5` to `Config\Game`.

Idempotency scope:

```text
race-question-resolve:{roomUuid}:{roundQuestionUuid}
```

- [ ] **Step 3: Emit events**

- `race.question_resolved` with movement summary.
- `tile.special_triggered` as applicable.
- `race.round_interrupted` if finish occurred before round-completion processing, including on the last allocated question.
- `game.finished` with `TRACK_FINISH`, winner UUID list, and legacy first UUID.

- [ ] **Step 4: Run and commit**

```bash
./vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php --filter Resolve
./vendor/bin/phpunit
git add app/Services/Game/GameEngine.php app/Config/Game.php tests/database/QuizRaceTeamDeviceTest.php
git commit -m "feat: resolve Quiz Race questions and finish immediately"
```

---

## Task 7: Advance Questions, Complete Rounds, and Enforce Question Limit

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Modify: `tests/database/QuizRaceTeamDeviceTest.php`

- [ ] **Step 1: Write advancement tests**

Cover:

- Resolved question remains visible until reveal ends.
- Exactly one next question is created when the round still has quota.
- Question 15 completes a 15-question round instead of creating Question 16.
- A finish on Question 15 takes priority over round completion, marks the round interrupted, and awards no round prize.
- Round winner is based on score earned in that round before prize.
- Round prize adds default 100 score and zero movement.
- Exact round ties award all co-winners.
- Completed round remains visible for checkpoint reveal, then exactly one next round is created.
- Round 3 uses target 20 in the default allocation.
- If all 50 questions resolve without a finisher, game finishes with `QUESTION_LIMIT` ranking.
- No question/round advancement occurs after room `FINISHED`.

- [ ] **Step 2: Implement guarded question advancement**

`advanceResolvedRaceQuestionIfNeeded()` conditionally closes one resolved question after reveal.

- If race is finished: stop.
- If `question_resolved_count < question_target_count`: create the next question.
- If quota is complete: call `completeRaceRound()`.

The close transition and next-row insertion belong to one transaction. Unique round/question numbering is a secondary guard.

- [ ] **Step 3: Implement round completion**

`completeRaceRound()`:

1. Aggregate per-team score delta, correctness, and correct response time for only that round.
2. Rank with `RaceRoundService` before applying prize.
3. Add `race_round_winner_bonus_points` to each exact winner's score.
4. Insert `RACE_ROUND_WINNER` score transactions.
5. Set `ROUND_COMPLETED`, summary, winner ids, and checkpoint reveal deadline.
6. Record `race.round_completed` after commit.

- [ ] **Step 4: Implement guarded round advancement**

After checkpoint reveal, conditionally set `ROUND_COMPLETED -> ROUND_CLOSED`.

- If another allocation exists: create the next round and its first question.
- If allocation is exhausted: rank all teams using position, score, correctness, and response time; finish with reason `QUESTION_LIMIT`.

- [ ] **Step 5: Run and commit**

```bash
./vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php --filter Advance
./vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php --filter Round
./vendor/bin/phpunit
git add app/Services/Game/GameEngine.php tests/database/QuizRaceTeamDeviceTest.php
git commit -m "feat: advance Quiz Race questions and round checkpoints"
```

---

## Task 8: Snapshot Contract for Multi-Round Race

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Modify: `tests/database/QuizRaceTeamDeviceTest.php`

- [ ] **Step 1: Test additive snapshot fields**

Cover:

- `current_round` contains round number, target, resolved count, and `current_question`.
- Active question exposes question/options/deadline and answered flags only.
- Resolving state exposes no partial result.
- Resolved question exposes movement and own/all result according to viewer context.
- `last_resolved_question` survives creation of the next question.
- `last_completed_round` survives creation of the next round.
- Finished snapshot contains finish reason and winner list.
- Existing `current_turn`, `mode_state`, `teams`, `leaderboard`, and `events` keys are unchanged.

- [ ] **Step 2: Add helpers**

Recommended helpers:

- `latestRaceRound(int $roomId)`.
- `latestRaceQuestion(int $roundId)`.
- `raceQuestionAnswers(int $roundQuestionId)`.
- `publicRaceRound(...)`.
- `publicRaceQuestion(...)`.
- `latestResolvedRaceQuestion(...)`.
- `latestCompletedRaceRound(...)`.

Before building state, snapshot may call guarded timeout/question/round advancement. No polling-triggered mutation may bypass an atomic state claim.

- [ ] **Step 3: Run and commit**

```bash
./vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php --filter Snapshot
./vendor/bin/phpunit
git add app/Services/Game/GameEngine.php tests/database/QuizRaceTeamDeviceTest.php
git commit -m "feat: expose multi-round Quiz Race snapshots"
```

---

## Task 9: API Routes, Authorization, and Contracts

**Files:**

- Modify: `app/Config/Routes.php`
- Modify: `app/Controllers/Api/V1/RoomsController.php`
- Create: `tests/feature/QuizRaceTeamDeviceApiTest.php`

- [ ] **Step 1: Write HTTP tests**

Cover valid team answer, cross-team/cross-room rejection, owner-only force resolve, anonymous rejection, duplicate submit response, idempotency headers, rate-limit route registration, and standard `{ok,data,meta}` response envelope.

- [ ] **Step 2: Add routes**

```php
$routes->post('rooms/(:segment)/race-question/answer', 'Api\V1\RoomsController::raceQuestionAnswer/$1', ['filter' => 'rateLimit:45,60,api-mutation']);
$routes->post('rooms/(:segment)/race-question/resolve', 'Api\V1\RoomsController::resolveRaceQuestion/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
```

- [ ] **Step 3: Add controller actions**

- `raceQuestionAnswer()` validates `TeamSessionService::assertTeamSession()` and forwards `team_uuid`, `option_id`, and idempotency key.
- `resolveRaceQuestion()` validates `TenantContext::assertRoomOwner()`, requires `force: true`, and forwards idempotency key.
- Existing endpoint behavior remains unchanged.

- [ ] **Step 4: Run and commit**

```bash
./vendor/bin/phpunit tests/feature/QuizRaceTeamDeviceApiTest.php
./vendor/bin/phpunit
git add app/Config/Routes.php app/Controllers/Api/V1/RoomsController.php tests/feature/QuizRaceTeamDeviceApiTest.php
git commit -m "feat: add Quiz Race question-cycle API"
```

---

## Task 10: Create Game UI for Round Allocation

**Files:**

- Modify: `app/Views/teacher/games/create.php`
- Modify: `app/Controllers/Teacher/GameController.php`
- Modify: `public/assets/app.css` if needed.

- [ ] **Step 1: Enable Team Device for Quiz Race**

Remove the UI disable/forced-centralized behavior while retaining both participation choices.

- [ ] **Step 2: Add allocation editor**

For Team Device Quiz Race:

- Default three numeric round inputs: 15, 15, 20.
- Add/remove round controls, minimum 1 and maximum 5.
- Each count minimum 1; total maximum 100.
- Display computed total questions.
- Derive lap count from number of rounds; do not show a conflicting independent lap input.
- Round winner bonus defaults to 100 and may remain hidden/system-configured in this phase.

For centralized Quiz Race, keep existing lap and track fields unchanged.

- [ ] **Step 3: Update bank warning**

For Team Device, estimated required questions equals the allocation sum because one question is shared by all teams. Warn, do not block, if the selected published pool is smaller.

- [ ] **Step 4: Validate server input**

Never trust generated hidden totals. Parse each allocation server-side, validate count/range/sum, derive total and lap count, then pass canonical values to `createRoom()`.

- [ ] **Step 5: Manual check and commit**

Verify mode/participation toggles restore prior values cleanly and do not alter centralized defaults.

```bash
git add app/Views/teacher/games/create.php app/Controllers/Teacher/GameController.php public/assets/app.css
git commit -m "feat: configure Quiz Race round allocations"
```

---

## Task 11: Team Controller for Multi-Question Rounds

**Files:**

- Modify: `public/assets/app.js`
- Modify: `public/assets/app.css`
- Modify: `app/Views/game/controller.php` if dedicated result markup is needed.

- [ ] **Step 1: Branch shared race rendering**

For `round_model === 'multi_question_round'`:

- Hide dice and difficulty controls.
- Show `Ronde X/Y` and `Soal A/B`.
- Render `current_round.current_question`.
- Enable options only for an unanswered team during `QUESTION_ACTIVE` and room `PLAYING`.
- Show sent/waiting, resolving, and resolved-result states.
- Use `last_resolved_question` after transition/reconnect.

- [ ] **Step 2: Submit to the new endpoint**

```js
POST /api/v1/rooms/{roomUuid}/race-question/answer
```

Generate/reuse one idempotency key per team/question submission. Do not call `/answer` or the old draft `/race-round/answer` endpoint.

- [ ] **Step 3: Render feedback**

Show outcome, fastest bonus, movement, tile effect, current position, answer score delta, and score breakdown. At checkpoint, show round winner(s) and score-only prize without movement animation.

- [ ] **Step 4: Manual multi-session check and commit**

Use separate browser profiles/incognito sessions for two teams. Verify one answer per team per question, next-question progression, checkpoint after configured quota, and immediate finish mid-round.

```bash
git add public/assets/app.js public/assets/app.css app/Views/game/controller.php
git commit -m "feat: support multi-round Quiz Race controllers"
```

---

## Task 12: Teacher Control and Projector

**Files:**

- Modify: `public/assets/app.js`
- Modify: `public/assets/app.css`
- Modify: `app/Views/teacher/games/control.php`
- Modify: `app/Views/game/projector.php` only if markup is needed.

- [ ] **Step 1: Add teacher question status**

Show round number, question number, target/resolved counts, difficulty, deadline, answered count, and current leaders.

- [ ] **Step 2: Add `Tutup Soal`**

Relabel the shared-race force action contextually. Confirm that unanswered teams become timeout, then send:

```text
POST /api/v1/rooms/{roomUuid}/race-question/resolve {"force":true}
```

Hide `Skip Turn` and `Start Timer`. Keep pause/resume.

- [ ] **Step 3: Project question and checkpoint events**

- Animate all team movements from one resolved question as one sequence/batch.
- Show round checkpoint standings and score prize.
- On `TRACK_FINISH`, stop all queues and show the race winner immediately.
- Support simultaneous-finisher tie-break/co-winner payloads.
- Do not show Ular Tangga wording in Quiz Race views.

- [ ] **Step 4: Manual check and commit**

```bash
git add public/assets/app.js public/assets/app.css app/Views/teacher/games/control.php app/Views/game/projector.php
git commit -m "feat: add multi-round Quiz Race control and projector"
```

---

## Task 13: Pause, Reports, Cleanup, and Question Integrity

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Modify: `app/Services/Question/QuestionBankService.php`
- Modify: `app/Services/Report/GameReportService.php`
- Modify: `app/Views/teacher/games/report.php`
- Modify: `app/Views/teacher/games/report_pdf.php`
- Modify: `tests/database/QuizRaceTeamDeviceTest.php`
- Modify: `tests/database/GameReportServiceTest.php`
- Modify: `tests/database/GameEngineHardeningTest.php`

- [ ] **Step 1: Pause/resume tests and implementation**

- Pause active question by storing remaining milliseconds and clearing deadline.
- Pause resolved question or completed-round reveal by preserving remaining reveal time.
- Reject answer while paused.
- Resume rebuilds the appropriate deadline without granting material extra time.
- Repeated pause/resume remains stable.

- [ ] **Step 2: Question selection and mutation protection**

- Union `game_round_questions.question_id` into `usedQuestionIdsForRoom()`.
- Prevent edit/delete of questions or options used by active/resolving race questions.
- Include historical round answers in option usage checks.

- [ ] **Step 3: Reports**

Normalize turn answers and round answers into the current report payload. Add round/question number, outcome, response time, score breakdown, round prizes, finish reason, and all winner UUIDs. Preserve legacy `winner` while adding `winners`.

- [ ] **Step 4: Explicit room cleanup**

Delete in safe order:

1. `game_round_answers` for the room's question ids.
2. `game_round_questions`.
3. `game_rounds`.
4. Race question answer/resolve idempotency scopes.
5. Existing score/event/outbox/team/room rows and room-instance board.

Assert transaction success and add a deletion regression test.

- [ ] **Step 5: Event commit ordering**

Do not publish realtime state before its database transaction commits. Add a failure-path test proving rolled-back resolution leaves no resolved/finished event or outbox payload.

- [ ] **Step 6: Run and commit**

```bash
./vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php
./vendor/bin/phpunit tests/database/GameReportServiceTest.php
./vendor/bin/phpunit tests/database/GameEngineHardeningTest.php --filter Delete
./vendor/bin/phpunit
git add app/Services/Game/GameEngine.php app/Services/Question/QuestionBankService.php app/Services/Report/GameReportService.php app/Views/teacher/games/report.php app/Views/teacher/games/report_pdf.php tests/database/QuizRaceTeamDeviceTest.php tests/database/GameReportServiceTest.php tests/database/GameEngineHardeningTest.php
git commit -m "feat: integrate multi-round Quiz Race lifecycle"
```

---

## Task 14: Concurrency and End-to-End Regression

- [ ] **Step 1: Full suite**

```bash
./vendor/bin/phpunit
```

- [ ] **Step 2: Concurrency smoke**

On one room, submit the last answers while polling and pressing `Tutup Soal`. Assert:

- One answer row per team/question.
- One movement and score update per team.
- One `race.question_resolved` event.
- At most one next question/round.
- No advancement after finish.

Repeat around question reveal and round checkpoint reveal boundaries.

- [ ] **Step 3: Manual default-allocation smoke**

- Create Team Device room with 15/15/20.
- Join at least two teams in separate sessions.
- Verify mixed difficulty and question numbering.
- Complete 15 questions without finish; verify Ronde 1 checkpoint and score-only prize.
- Confirm positions do not change when the prize is awarded.
- Continue into Ronde 2.
- Arrange a finish before Ronde 2 quota ends; verify immediate `FINISHED`, `ROUND_INTERRUPTED`, no round prize, and no next question.
- Verify report contents and delete a disposable room.

- [ ] **Step 4: Question-limit fallback smoke**

Use a long track so nobody finishes after all allocated questions. Verify `QUESTION_LIMIT` ranking and finish event.

- [ ] **Step 5: Regression smoke**

- Centralized Quiz Race: tier selection, timer, movement, finish.
- Ular Tangga Team Device: PIN join, dice, answer, special tiles, finish.
- Pause/resume both legacy flows.

- [ ] **Step 6: Commit only if fixes were required**

```bash
git add <changed-files>
git commit -m "fix: stabilize multi-round Quiz Race flow"
```

Do not create an empty checkpoint commit.
