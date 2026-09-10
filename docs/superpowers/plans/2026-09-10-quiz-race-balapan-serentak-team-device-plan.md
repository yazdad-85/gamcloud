# Quiz Race - Balapan Serentak Device per Tim Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable `QUIZ_RACE` for `TEAM_DEVICE` rooms as Balapan Serentak: every team joins with PIN, receives the same question in each shared round, answers once from its own device, and all team movement resolves together.

**Architecture:** Keep Phase 11 intact. Do not reuse `game_turns` for simultaneous play. Add `game_rounds` and `game_round_answers`, a small `RaceRoundService` for pure round-resolution logic, and new `GameEngine` methods for round start/answer/resolve. `TEACHER_CENTRALIZED` keeps using `selectDifficultyTier()` and `answer()` unchanged.

**Tech Stack:** PHP 8 / CodeIgniter 4, SQLite/MySQL-compatible migrations, vanilla JS (`public/assets/app.js`), PHPUnit with `DatabaseTestTrait` for engine/database behavior and `CIUnitTestCase` for pure service behavior.

---

## Before You Start

- Working directory: `/Users/mbp19/Documents/YAZDAD/APLIKASI PRODUKSI/games/ular-tangga`.
- Run the full suite with `vendor/bin/phpunit`.
- Read first:
  - `docs/superpowers/specs/2026-09-10-quiz-race-team-device-design.md`
  - `docs/superpowers/plans/2026-09-10-quiz-race-sprint-tanpa-dadu-plan.md`
- Phase 11 is the baseline. Do not remove or rewrite:
  - `GameEngine::selectDifficultyTier()`
  - `GameEngine::answer()` Quiz Race branch for `TEACHER_CENTRALIZED`
  - `RaceTrackService::movementForTierAnswer()`
  - Create Game race track fields
- Every new persisted field must be in the matching model's `$allowedFields`.

## Scope for This Plan

**In scope:**

- Allow `QUIZ_RACE + TEAM_DEVICE` room creation.
- Reuse Quiz Race track length, lap count, race board templates, Boost/Oil Spill tile generation, and bank soal/topik selection.
- New shared round tables and models.
- Shared question per round.
- Device-team answer once per round.
- Correct +1, fastest correct +2.
- Boost +2, Oil Spill removes speed-bonus eligibility for next round, checkpoint +1.
- Automatic next round creation after resolve if no team finished.
- Teacher Control panel can monitor and force-resolve a round.
- Team controller can answer the active shared round.

**Out of scope:**

- Nitro.
- Pit Stop.
- Duel Susul.
- Custom animated lane renderer.
- Multi-room tournament/season leaderboard.

---

## Task 1: Schema - Shared Race Rounds

**Files:**

- Create: `app/Database/Migrations/2026-09-10-000005_CreateGameRounds.php`
- Create: `app/Database/Migrations/2026-09-10-000006_CreateGameRoundAnswers.php`
- Create: `app/Models/GameRoundModel.php`
- Create: `app/Models/GameRoundAnswerModel.php`

- [ ] **Step 1: Create `game_rounds` migration**

Columns:

- `id` integer primary auto increment
- `public_uuid` varchar(36)
- `room_id` integer
- `round_number` integer
- `state` varchar(30), default `ROUND_ACTIVE`
- `question_id` integer
- `difficulty` varchar(20)
- `started_at` datetime nullable
- `deadline_at` datetime nullable
- `resolved_at` datetime nullable
- `fastest_team_ids_json` text nullable
- `movement_summary_json` text nullable
- `created_at` datetime nullable
- `updated_at` datetime nullable

Indexes:

- Unique `public_uuid`
- Unique `room_id, round_number`
- Index `room_id, state`

- [ ] **Step 2: Create `game_round_answers` migration**

Columns:

- `id` integer primary auto increment
- `public_uuid` varchar(36)
- `round_id` integer
- `team_id` integer
- `question_id` integer
- `option_id` integer nullable
- `answer_text` text nullable
- `is_correct` integer default 0
- `answered_at` datetime nullable
- `response_ms` integer nullable
- `created_at` datetime nullable

Indexes:

- Unique `public_uuid`
- Unique `round_id, team_id`
- Index `round_id, is_correct, response_ms`

- [ ] **Step 3: Create the two models**

`GameRoundModel` allowed fields:

```php
[
    'public_uuid',
    'room_id',
    'round_number',
    'state',
    'question_id',
    'difficulty',
    'started_at',
    'deadline_at',
    'resolved_at',
    'fastest_team_ids_json',
    'movement_summary_json',
]
```

`GameRoundAnswerModel` allowed fields:

```php
[
    'public_uuid',
    'round_id',
    'team_id',
    'question_id',
    'option_id',
    'answer_text',
    'is_correct',
    'answered_at',
    'response_ms',
]
```

- [ ] **Step 4: Run migrations and tests**

Run:

```bash
php spark migrate
vendor/bin/phpunit
```

- [ ] **Step 5: Commit**

```bash
git add app/Database/Migrations/2026-09-10-000005_CreateGameRounds.php app/Database/Migrations/2026-09-10-000006_CreateGameRoundAnswers.php app/Models/GameRoundModel.php app/Models/GameRoundAnswerModel.php
git commit -m "feat: add shared Quiz Race round schema"
```

---

## Task 2: `RaceRoundService` Pure Round Logic

**Files:**

- Create: `app/Services/Game/RaceRoundService.php`
- Create: `tests/unit/RaceRoundServiceTest.php`

- [ ] **Step 1: Write unit tests first**

Cover:

- `difficultyForRound()` returns `EASY`, `MEDIUM`, `HARD` from leader position/lap.
- Correct answer gives base +1.
- Fastest correct answer gets +2 additional steps.
- Tied fastest correct answers both get +2.
- Wrong answer and missing answer stay at the same position.
- Oil Spill lock makes a team ineligible for fastest bonus for one round and clears the lock.
- Boost tile applies after landed position.
- Checkpoint crossing gives +1.
- Finish clamps at `max_position`.

- [ ] **Step 2: Implement service**

Recommended methods:

```php
final class RaceRoundService
{
    public function difficultyForRound(array $teams, array $room): string;

    public function resolveMovements(array $teams, array $answers, array $room, array $board): array;

    public function answeredTeamIds(array $answers): array;
}
```

Return shape for each movement:

```php
[
    'team_id' => 1,
    'from' => 1,
    'landed' => 2,
    'to' => 4,
    'is_correct' => true,
    'fastest_bonus' => true,
    'special' => 'BOOST',
    'effects' => [],
    'lap_checkpoint' => false,
    'active_effects' => [],
]
```

Use `RaceTrackService` internally for special tiles and lap math. Add small helper methods to `RaceTrackService` only if they are genuinely reusable and covered by tests.

- [ ] **Step 3: Run focused tests and full suite**

```bash
vendor/bin/phpunit tests/unit/RaceRoundServiceTest.php
vendor/bin/phpunit
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/Game/RaceRoundService.php tests/unit/RaceRoundServiceTest.php app/Services/Game/RaceTrackService.php
git commit -m "feat: add shared round logic for Quiz Race"
```

---

## Task 3: Allow `QUIZ_RACE + TEAM_DEVICE` Room Creation

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Modify: `app/Services/Game/Modes/QuizRaceModeEngine.php`
- Modify: `tests/database/QuizRaceModeTest.php`

- [ ] **Step 1: Write failing tests**

Add tests proving:

- `createRoom()` accepts `game_mode=QUIZ_RACE` and `participation_mode=TEAM_DEVICE`.
- Room uses race board clone with no ladders/snakes.
- Room still has PIN and accepts `joinByPin()`.
- `addTeamByOwner()` is still rejected for `TEAM_DEVICE`.
- `QUIZ_RACE + TEACHER_CENTRALIZED` still works exactly as Phase 11.

- [ ] **Step 2: Remove the creation guard**

In `GameEngine::createRoom()`, remove the current rejection:

```php
if ($gameModeKey === 'QUIZ_RACE' && $participationMode !== 'TEACHER_CENTRALIZED') {
    throw new DomainException(...);
}
```

Keep all race board generation, `lap_count`, `finish_rule = clamp_finish`, and `near_finish_bonus = false`.

- [ ] **Step 3: Update mode state**

In `QuizRaceModeEngine::publicState()`:

- For `TEACHER_CENTRALIZED`, keep `actions: ['select_tier', 'answer']`.
- For `TEAM_DEVICE`, return `actions: ['race_answer']` and `round_model: 'shared_round'`.

Do not mark the whole mode as planned when only one participation mode has different behavior.

- [ ] **Step 4: Run tests**

```bash
vendor/bin/phpunit tests/database/QuizRaceModeTest.php
vendor/bin/phpunit
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Game/GameEngine.php app/Services/Game/Modes/QuizRaceModeEngine.php tests/database/QuizRaceModeTest.php
git commit -m "feat: allow Quiz Race rooms with team devices"
```

---

## Task 4: GameEngine Round Lifecycle

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Modify: `tests/database/QuizRaceTeamDeviceTest.php` (new)

- [ ] **Step 1: Add database tests**

Create `QuizRaceTeamDeviceTest` with `DatabaseTestTrait`. Cover:

- `start()` creates active race round for `QUIZ_RACE + TEAM_DEVICE`.
- Round question is visible in snapshot.
- `current_team_id` stays null for shared-round rooms.
- `start()` still creates `game_turns` for Ular Tangga and Quiz Race Tanpa Device.

- [ ] **Step 2: Add engine properties/models**

Import/use:

- `GameRoundModel`
- `GameRoundAnswerModel`
- `RaceRoundService`

Add `private RaceRoundService $raceRounds;` and initialize it in the constructor.

- [ ] **Step 3: Branch `start()`**

In `GameEngine::start()`:

- If room is `QUIZ_RACE + TEAM_DEVICE`, update room to `PLAYING`, keep `current_team_id` null, then call `createRaceRound($room, 1)`.
- Otherwise keep existing behavior unchanged.

- [ ] **Step 4: Implement private round creation**

`createRaceRound(array $room, int $roundNumber): array`

- Load teams and board.
- Determine difficulty via `RaceRoundService::difficultyForRound()`.
- Select one question using existing `selectQuestion()` and room topic rules.
- Insert `game_rounds` with `ROUND_ACTIVE`, question, difficulty, `started_at`, `deadline_at`.
- Record `race.round_started`.
- Return round row.

- [ ] **Step 5: Run tests**

```bash
vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php --filter Start
vendor/bin/phpunit
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/QuizRaceTeamDeviceTest.php
git commit -m "feat: start shared rounds for Quiz Race team-device rooms"
```

---

## Task 5: Answer Once Per Shared Round

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Modify: `tests/database/QuizRaceTeamDeviceTest.php`

- [ ] **Step 1: Write failing tests**

Cover:

- Current team can submit answer to active round.
- Same team cannot answer twice.
- Team from another room cannot answer.
- Invalid option is rejected.
- After submit, snapshot shows this team answered but does not reveal correctness to other teams during active round.
- When all teams have answered, engine resolves the round automatically.

- [ ] **Step 2: Add `raceRoundAnswer()`**

Public method:

```php
public function raceRoundAnswer(string $roomUuid, string $teamUuid, int $optionId, ?string $idempotencyKey = null): array
```

Behavior:

- Room must be `PLAYING`, `QUIZ_RACE`, `TEAM_DEVICE`.
- Team must belong to room.
- Active round must exist and not be expired.
- Option must belong to round question.
- Insert one `game_round_answers` row.
- Record `race.answer_submitted`.
- If all teams answered, call `resolveRaceRound()`.
- Return snapshot.

Use idempotency scope:

```php
race-round-answer:{roomUuid}:{roundUuid}:{teamUuid}
```

- [ ] **Step 3: Run tests**

```bash
vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php --filter RaceRoundAnswer
vendor/bin/phpunit
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/QuizRaceTeamDeviceTest.php
git commit -m "feat: accept one answer per team in Quiz Race rounds"
```

---

## Task 6: Resolve Round Movement

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Modify: `tests/database/QuizRaceTeamDeviceTest.php`

- [ ] **Step 1: Write failing tests**

Cover:

- Correct non-fastest team moves +1.
- Fastest correct team moves +3.
- Wrong answer stays put.
- Timeout/no answer stays put.
- Boost adds +2.
- Oil Spill blocks fastest bonus next round and then clears.
- Checkpoint adds +1.
- Finish sets room `FINISHED` and records winner.
- If no finish, next round is created automatically.

- [ ] **Step 2: Add `resolveRaceRound()`**

Public or private method:

```php
public function resolveRaceRound(string $roomUuid, ?string $idempotencyKey = null): array
```

Behavior:

- Only for `QUIZ_RACE + TEAM_DEVICE`.
- Resolve active round if either all teams answered or deadline passed.
- Load teams, answers, board.
- Use `RaceRoundService::resolveMovements()`.
- In one transaction:
  - Update team positions, scores, streaks, active effects.
  - Update round to `ROUND_RESOLVED` with fastest ids and movement summary.
  - If winner exists, update room to `FINISHED`.
  - Else create next active round.
- Record:
  - `race.round_resolved`
  - `tile.special_triggered`
  - `lap.checkpoint`
  - `game.finished` if applicable

Scoring should reuse existing answer/time/streak scoring where it fits, but `near_finish_bonus` remains disabled for Quiz Race.

- [ ] **Step 3: Deadline handling**

Add lightweight auto-resolution:

- `raceRoundAnswer()` resolves immediately when all teams answered.
- `snapshot()` may call an internal `resolveExpiredRaceRoundIfNeeded($room)` before building public state, so polling device screens can close an expired round without a separate cron.
- `forceTimeout()` for `QUIZ_RACE + TEAM_DEVICE` should resolve the active round instead of touching `game_turns`.

- [ ] **Step 4: Run tests**

```bash
vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php --filter Resolve
vendor/bin/phpunit
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/QuizRaceTeamDeviceTest.php
git commit -m "feat: resolve simultaneous Quiz Race round movement"
```

---

## Task 7: Snapshot and Public Round Payload

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Modify: `tests/database/QuizRaceTeamDeviceTest.php`

- [ ] **Step 1: Add snapshot tests**

Cover:

- `current_round` is null outside `QUIZ_RACE + TEAM_DEVICE`.
- Active round exposes question/options, deadline, answered flags.
- Active round hides other teams' correctness.
- Resolved round exposes movement summary and fastest team UUIDs.
- Projector snapshot works with existing projector token rules.

- [ ] **Step 2: Implement helpers**

Recommended helpers:

- `activeRaceRound(int $roomId): ?array`
- `latestRaceRound(int $roomId): ?array`
- `roundAnswers(int $roundId): array`
- `publicRound(array $round, array $room, ?string $viewerTeamUuid = null): array`

Add `current_round` to `snapshot()`.

- [ ] **Step 3: Keep existing payload stable**

Do not rename:

- `current_turn`
- `mode_state`
- `teams`
- `leaderboard`
- `events`

Add `current_round` as an additive field only.

- [ ] **Step 4: Run tests**

```bash
vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php --filter Snapshot
vendor/bin/phpunit
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/QuizRaceTeamDeviceTest.php
git commit -m "feat: expose shared Quiz Race rounds in snapshots"
```

---

## Task 8: API Routes and Controller Actions

**Files:**

- Modify: `app/Config/Routes.php`
- Modify: `app/Controllers/Api/V1/RoomsController.php`

- [ ] **Step 1: Add routes**

```php
$routes->post('rooms/(:segment)/race-round/answer', 'Api\V1\RoomsController::raceRoundAnswer/$1', ['filter' => 'rateLimit:45,60,api-mutation']);
$routes->post('rooms/(:segment)/race-round/resolve', 'Api\V1\RoomsController::resolveRaceRound/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
```

- [ ] **Step 2: Add controller methods**

`raceRoundAnswer()`:

- Read `team_uuid` and `option_id`.
- Call `TeamSessionService::assertTeamSession()`.
- Call `GameEngine::raceRoundAnswer()`.

`resolveRaceRound()`:

- Require `TenantContext::assertRoomOwner()`.
- Call `GameEngine::resolveRaceRound()`.

- [ ] **Step 3: Run full suite**

```bash
vendor/bin/phpunit
```

- [ ] **Step 4: Commit**

```bash
git add app/Config/Routes.php app/Controllers/Api/V1/RoomsController.php
git commit -m "feat: add Quiz Race shared round API endpoints"
```

---

## Task 9: Create Game UI - Enable Device per Tim for Quiz Race

**Files:**

- Modify: `app/Views/teacher/games/create.php`
- Modify: `app/Controllers/Teacher/GameController.php` only if needed

- [ ] **Step 1: Write the intended behavior**

Manual expectation:

- Select `Quiz Race`.
- `Device per Tim` remains enabled.
- `Tanpa Device` remains enabled.
- Race fields stay visible for both participation modes.
- Ular Tangga-only fields stay hidden for Quiz Race.

- [ ] **Step 2: Update JS toggle**

In the mode visibility script, remove the behavior that disables `TEAM_DEVICE` when `QUIZ_RACE` is selected.

Keep:

- `near_finish_bonus` disabled for all Quiz Race.
- Race fields visible for all Quiz Race.
- Snakes-only fields hidden for all Quiz Race.

- [ ] **Step 3: Update bank-soal warning**

Use different estimates:

- `TEACHER_CENTRALIZED`: `maxTeams * Math.ceil(trackLength / 2)`
- `TEAM_DEVICE`: `Math.ceil(trackLength / 1.5)`

Listen to both `game_mode` and `participation_mode` radio changes.

- [ ] **Step 4: Manual verification**

Run:

```bash
php spark serve
```

Check `/teacher/games/create`:

- Ular Tangga still defaults to Device per Tim.
- Quiz Race allows both Device per Tim and Tanpa Device.
- Warning text changes when toggling participation mode.

- [ ] **Step 5: Commit**

```bash
git add app/Views/teacher/games/create.php app/Controllers/Teacher/GameController.php
git commit -m "feat: enable team-device selection for Quiz Race rooms"
```

---

## Task 10: Team Controller UI for Shared Rounds

**Files:**

- Modify: `public/assets/app.js`
- Modify: `public/assets/app.css`
- Modify: `app/Views/game/controller.php` if markup needs a dedicated status area

- [ ] **Step 1: Update `controller(config)` state drawing**

For `snapshot.mode_state.key === 'QUIZ_RACE'` and `snapshot.mode_state.round_model === 'shared_round'`:

- Hide roll button.
- Hide tier buttons.
- Show active round question.
- Enable answer buttons only if current team has not answered and room is `PLAYING`.
- After submit, disable all options and show "Jawaban terkirim, menunggu ronde selesai".
- Keep countdown from `current_round.deadline_epoch_ms`.

- [ ] **Step 2: Add answer handler branch**

When answering shared round:

```js
jsonFetch('/api/v1/rooms/' + config.roomUuid + '/race-round/answer', {
    method: 'POST',
    body: JSON.stringify({team_uuid: activeTeamUuid(), option_id: optionId}),
})
```

Do not call `/answer` for shared-round Quiz Race.

- [ ] **Step 3: Render round result feedback**

After `ROUND_RESOLVED`, show movement summary for the active team:

- Correct/wrong/timeout.
- Fastest bonus if received.
- Boost/Oil Spill/checkpoint effects.
- Current position.

- [ ] **Step 4: Manual verification**

With two browser sessions:

- Join two teams by PIN.
- Start Quiz Race Device per Tim.
- Confirm both see the same question.
- Submit one correct and one wrong.
- Confirm answered team waits, unresolved team can still answer.
- Confirm next round appears after both answer or teacher resolves.

- [ ] **Step 5: Commit**

```bash
git add public/assets/app.js public/assets/app.css app/Views/game/controller.php
git commit -m "feat: support shared Quiz Race rounds on team controllers"
```

---

## Task 11: Teacher Control UI for Shared Rounds

**Files:**

- Modify: `public/assets/app.js`
- Modify: `app/Views/teacher/games/control.php`
- Modify: `public/assets/app.css`

- [ ] **Step 1: Add shared-round status display**

For `QUIZ_RACE + TEAM_DEVICE`, Control Game should show:

- Round number.
- Difficulty.
- Countdown.
- Answered count / total teams.
- Fastest team(s) after resolve.
- Button "Tutup Ronde" while round active.

- [ ] **Step 2: Wire force resolve**

Button calls:

```js
POST /api/v1/rooms/{roomUuid}/race-round/resolve
```

Keep existing `Force Timeout` behavior for Ular Tangga and Tanpa Device; for shared rounds it can call the same resolve endpoint.

- [ ] **Step 3: Keep roster behavior separate**

For `TEAM_DEVICE`, roster manual panel stays hidden. Teams join through PIN, same as existing Ular Tangga.

- [ ] **Step 4: Manual verification**

Check:

- Control page updates answered count as device teams submit.
- Force resolve closes active round.
- New round appears automatically if no winner.
- Status becomes `FINISHED` when a team crosses finish.

- [ ] **Step 5: Commit**

```bash
git add public/assets/app.js public/assets/app.css app/Views/teacher/games/control.php
git commit -m "feat: add teacher controls for shared Quiz Race rounds"
```

---

## Task 12: Regression and End-to-End Smoke

- [ ] **Step 1: Full automated suite**

```bash
vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 2: Manual smoke - Tanpa Device remains stable**

Create `Quiz Race + Tanpa Device`:

- Add teams from roster.
- Start.
- Pick EASY/MEDIUM/HARD.
- Start timer.
- Answer.
- Confirm movement and finish still work.

- [ ] **Step 3: Manual smoke - Device per Tim**

Create `Quiz Race + Device per Tim`:

- Join 2+ teams via PIN.
- Start from teacher page.
- Confirm all team devices see same question.
- Submit answers.
- Confirm fastest correct gets +3 total.
- Confirm wrong/timeout gets +0.
- Play until one team reaches finish.
- Confirm room status becomes `FINISHED`.

- [ ] **Step 4: Manual smoke - Ular Tangga unaffected**

Create normal `Ular Tangga Kuis + Device per Tim`:

- Join team.
- Roll dice.
- Answer.
- Confirm old turn flow works.

- [ ] **Step 5: Commit only if fixes were needed**

If Task 12 surfaced code changes:

```bash
git add <changed files>
git commit -m "fix: stabilize Quiz Race team-device smoke flow"
```

If no fixes were needed, do not create an empty checkpoint commit.
