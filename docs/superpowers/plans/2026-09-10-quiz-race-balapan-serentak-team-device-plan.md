# Quiz Race - Balapan Serentak Device per Tim Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable `QUIZ_RACE` for `TEAM_DEVICE` rooms as Balapan Serentak: every team joins with PIN, receives the same question in each shared round, answers once from its own device, and all team movement resolves together.

**Architecture:** Keep Phase 11 intact. Do not reuse `game_turns` for simultaneous play. Add `game_rounds` and `game_round_answers`, a small `RaceRoundService` for pure round-resolution logic, and new `GameEngine` methods for round start/answer/resolve. Shared rounds use atomic state transitions (`ROUND_ACTIVE -> ROUND_RESOLVING -> ROUND_RESOLVED -> ROUND_CLOSED`) so answer, polling, and teacher force-resolve cannot apply movement twice. `TEACHER_CENTRALIZED` keeps using `selectDifficultyTier()` and `answer()` unchanged except for genuinely shared helpers and regression fixes.

**Tech Stack:** PHP 8 / CodeIgniter 4, SQLite/MySQL-compatible migrations, vanilla JS (`public/assets/app.js`), PHPUnit with `DatabaseTestTrait` for engine/database behavior and `CIUnitTestCase` for pure service behavior.

---

## Before You Start

- Working directory: `/Users/mbp19/Documents/YAZDAD/APLIKASI PRODUKSI/games/ular-tangga`.
- Run the full suite with `./vendor/bin/phpunit`.
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
- A short resolved-result phase before the next question becomes answerable.
- Millisecond server timing, concurrency-safe resolution, and duplicate-submit handling.
- Pause/resume behavior for shared deadlines.
- Existing question reuse protection, room deletion, and reports understand round data.
- Co-winner support when multiple teams finish in the same resolution.

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
- `answer_count` integer default 0
- `started_at` datetime nullable
- `started_at_epoch_ms` bigint nullable
- `deadline_at` datetime nullable
- `deadline_epoch_ms` bigint nullable
- `paused_remaining_ms` integer nullable
- `resolved_at` datetime nullable
- `reveal_until` datetime nullable
- `reveal_until_epoch_ms` bigint nullable
- `fastest_team_ids_json` text nullable
- `winner_team_ids_json` text nullable
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
- `outcome` varchar(20), one of `CORRECT`, `WRONG`, `TIMEOUT`
- `answered_at` datetime nullable
- `answered_at_epoch_ms` bigint nullable
- `response_ms` integer nullable
- `created_at` datetime nullable

Indexes:

- Unique `public_uuid`
- Unique `round_id, team_id`
- Index `round_id, is_correct, response_ms`

Use explicit application cleanup rather than adding cascade foreign keys in this phase, matching the existing schema style. Still add ordinary indexes for `room_id`, `question_id`, `round_id`, and `team_id` where the compound indexes do not cover the query direction.

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
    'answer_count',
    'started_at',
    'started_at_epoch_ms',
    'deadline_at',
    'deadline_epoch_ms',
    'paused_remaining_ms',
    'resolved_at',
    'reveal_until',
    'reveal_until_epoch_ms',
    'fastest_team_ids_json',
    'winner_team_ids_json',
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
    'outcome',
    'answered_at',
    'answered_at_epoch_ms',
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

- `difficultyForRound()` returns `EASY` below 30%, `MEDIUM` from 30% through below 70%, and `HARD` from 70% leader progress.
- Correct answer gives base +1.
- Fastest correct answer gets +2 additional steps.
- Tied fastest correct answers both get +2.
- Wrong answer and missing answer stay at the same position.
- Oil Spill lock makes a team ineligible for fastest bonus for one round and clears the lock.
- Boost tile applies after landed position.
- Checkpoint crossing gives +1.
- Finish clamps at `max_position`.
- Exact Oil Spill lifecycle: consume an existing lock for this round, but preserve a newly landed Oil Spill for the next round.
- Movement ordering is base/fastest, landed tile effect, then one checkpoint bonus; checkpoint bonus does not chain into another special tile.
- Resolution returns every team that reaches finish in the same round as co-winners.

- [ ] **Step 2: Implement service**

Recommended methods:

```php
final class RaceRoundService
{
    public function difficultyForRound(array $teams, array $room): string;

    public function resolveMovements(array $teams, array $answers, array $room, array $board): array;

    public function answeredTeamIds(array $answers): array;

    public function winnerTeamIds(array $movements, int $maxPosition): array;
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

Use `RaceTrackService` internally for special tiles and lap math. `difficultyForRound()` uses `(leader_position - 1) / (max_position - 1)`, not rounded lap numbers, so the 30/40/30 split remains deterministic for every allowed lap count. Add small helper methods to `RaceTrackService` only if they are genuinely reusable and covered by tests.

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
- Round deadline uses persisted server epoch milliseconds and the room question duration.

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
- Insert `game_rounds` with `ROUND_ACTIVE`, question, difficulty, `started_at`, `started_at_epoch_ms`, `deadline_at`, and `deadline_epoch_ms`.
- Use one captured server `nowEpochMs` for all round timestamps. Do not reuse `GameEngine::responseMs()` because it currently has one-second resolution.
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
- Two near-simultaneous duplicate submits produce one row and a stable domain/idempotent response, not HTTP 500.
- Team from another room cannot answer.
- Invalid option is rejected.
- Answer arriving at or after `deadline_epoch_ms` is rejected as late and triggers guarded timeout resolution.
- Answer racing against deadline/teacher resolution either commits fully before resolution or is rejected; no answer row appears after its round is resolved.
- After submit, snapshot shows this team answered but does not reveal correctness to other teams during active round.
- When all teams have answered, engine resolves the round automatically.
- Answers 50-100ms apart are ordered correctly; they are not treated as a tie merely because they share a wall-clock second.

- [ ] **Step 2: Add `raceRoundAnswer()`**

Public method:

```php
public function raceRoundAnswer(string $roomUuid, string $teamUuid, int $optionId, ?string $idempotencyKey = null): array
```

Behavior:

- Room must be `PLAYING`, `QUIZ_RACE`, `TEAM_DEVICE`.
- Team must belong to room.
- Active round must exist, room must not be paused, and server epoch milliseconds must be strictly before `deadline_epoch_ms`.
- Option must belong to round question.
- Begin a transaction and conditionally increment `game_rounds.answer_count` only where the round is still `ROUND_ACTIVE` and `deadline_epoch_ms > nowEpochMs`. Require exactly one affected row before inserting the answer. This row update serializes submit against resolver claims on both SQLite and MySQL.
- Capture `answered_at_epoch_ms` with `microtime(true)`, calculate `response_ms` from the persisted round epoch, then insert one `game_round_answers` row in the same transaction.
- Catch the unique `round_id + team_id` collision and convert it to a stable already-answered response. Do not rely on a pre-insert count check.
- Record `race.answer_submitted`.
- After commit, if `answer_count` equals the fixed room team count, call `resolveRaceRound()`.
- Return snapshot.

Use idempotency scope:

```php
race-round-answer:{roomUuid}:{roundUuid}:{teamUuid}
```

`race.answer_submitted` must not include `option_id`, correctness, or response time while the round is active.

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
- Modify: `app/Config/Game.php`
- Modify: `tests/database/QuizRaceTeamDeviceTest.php`

- [ ] **Step 1: Write failing tests**

Cover:

- Correct non-fastest team moves +1.
- Fastest correct team moves +3.
- Fastest comparison uses persisted `response_ms` with millisecond resolution.
- Wrong answer stays put.
- Timeout/no answer stays put.
- Resolution inserts one synthetic `TIMEOUT` answer row for each missing team without increasing `answer_count`.
- Boost adds +2.
- Oil Spill blocks fastest bonus next round and then clears.
- Checkpoint adds +1.
- Finish sets room `FINISHED` and records winner.
- Multiple finishers in one resolution are recorded as co-winners.
- A resolved result remains visible until `reveal_until`; only then is the next round created automatically.
- Answer-last, deadline polling, and teacher resolve racing together update positions exactly once.
- An answer racing with resolve is either included before the resolver claim or rejected without increment/insert drift.
- A failed resolution transaction leaves the round claim recoverable as `ROUND_ACTIVE`.

- [ ] **Step 2: Add `resolveRaceRound()`**

Public or private method:

```php
public function resolveRaceRound(string $roomUuid, bool $force = false, ?string $idempotencyKey = null): array
```

Use idempotency scope:

```text
race-round-resolve:{roomUuid}:{roundUuid}
```

Behavior:

- Only for `QUIZ_RACE + TEAM_DEVICE`.
- Without `force`, resolve active round only if all teams answered or deadline passed. `force=true` is owner-only at the controller boundary and treats missing answers as timeout.
- Begin a transaction and atomically claim the round with a conditional `UPDATE ... WHERE id = ? AND state = 'ROUND_ACTIVE'`. Continue only when exactly one row was affected.
- Load teams, answers, board.
- Insert a synthetic `TIMEOUT` answer for every missing team, with null option/text/response time, so each resolved round has one outcome per team.
- Use `RaceRoundService::resolveMovements()`.
- In one transaction:
  - Update team positions, scores, streaks, active effects.
  - Record score transactions using the exact scoring rules below.
  - Update round to `ROUND_RESOLVED` with fastest ids, co-winner ids, movement summary, `reveal_until`, and `reveal_until_epoch_ms`.
  - If winner exists, update room to `FINISHED`.
  - Do not create the next round yet; the resolved result must remain observable.
  - Bump room state once for the completed aggregate transition.
- Record:
  - `race.round_resolved`
  - `tile.special_triggered`
  - `lap.checkpoint`
  - `game.finished` if applicable

Scoring rules are explicit:

- Correct/wrong base points use the existing room configuration.
- Timeout uses wrong-answer points and resets streak.
- Time bonus uses that answer's `response_ms` against the round duration, never the wall clock at resolve time.
- Streak bonus remains per team.
- `near_finish_bonus` is disabled.
- Fastest movement, Boost, and checkpoint do not add score points.
- Emit matching `score_transactions` rows so reports remain auditable.

Add `public int $raceRoundRevealSeconds = 3;` to `Config\Game`; do not hard-code the reveal duration in the engine.

For simultaneous finish, `game.finished` includes all `winner_team_uuids`; include the first UUID as legacy `winner_team_uuid` until all existing consumers have migrated.

- [ ] **Step 3: Deadline handling**

Add lightweight auto-resolution and advancement:

- `raceRoundAnswer()` resolves immediately when all teams answered.
- `snapshot()` may call `resolveExpiredRaceRoundIfNeeded($room)` before building public state, but that helper must use the same atomic claim.
- While the current round is `ROUND_RESOLVED`, snapshot exposes its result. Once `reveal_until` passes, call `advanceResolvedRaceRoundIfNeeded()`.
- Advancement atomically changes the prior round from `ROUND_RESOLVED` to `ROUND_CLOSED` and creates exactly one next round in the same transaction.
- `forceTimeout()` for `QUIZ_RACE + TEAM_DEVICE` delegates to `resolveRaceRound(..., true, ...)` instead of touching `game_turns`.
- Event/outbox publication occurs only after the state transaction succeeds; never publish a result from a rolled-back transaction.

- [ ] **Step 4: Run tests**

```bash
vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php --filter Resolve
vendor/bin/phpunit
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Game/GameEngine.php app/Config/Game.php tests/database/QuizRaceTeamDeviceTest.php
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
- Resolving round disables answering and does not expose partial movement.
- Resolved round exposes movement summary, fastest team UUIDs, co-winners, and `reveal_until`.
- After the next round starts, `last_resolved_round` still exposes the prior movement summary for refresh/reconnect feedback.
- Projector snapshot works with existing projector token rules.

- [ ] **Step 2: Implement helpers**

Recommended helpers:

- `activeRaceRound(int $roomId): ?array`
- `latestRaceRound(int $roomId): ?array`
- `roundAnswers(int $roundId): array`
- `publicRound(array $round, array $room, ?string $viewerTeamUuid = null): array`

Add `current_round` and `last_resolved_round` to `snapshot()`. `current_round` is the latest non-closed round; `last_resolved_round` is the latest resolved/closed round with its movement summary. Keep the question/options hidden once they are no longer needed if exposing them would reveal answer correctness.

- [ ] **Step 3: Keep existing payload stable**

Do not rename:

- `current_turn`
- `mode_state`
- `teams`
- `leaderboard`
- `events`

Add `current_round` and `last_resolved_round` as additive fields only.

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
- Create: `tests/feature/QuizRaceTeamDeviceApiTest.php`

- [ ] **Step 1: Write HTTP-level authorization and contract tests**

Cover:

- Team with a valid room session can answer only for its own `team_uuid`.
- Team from another browser/session or room is rejected.
- Teacher owner can force-resolve; another teacher and anonymous request are rejected.
- Duplicate answer returns a stable domain/idempotent response rather than 500.
- Route response preserves the standard `{ok,data,meta}` envelope.

- [ ] **Step 2: Add routes**

```php
$routes->post('rooms/(:segment)/race-round/answer', 'Api\V1\RoomsController::raceRoundAnswer/$1', ['filter' => 'rateLimit:45,60,api-mutation']);
$routes->post('rooms/(:segment)/race-round/resolve', 'Api\V1\RoomsController::resolveRaceRound/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
```

- [ ] **Step 3: Add controller methods**

`raceRoundAnswer()`:

- Read `team_uuid` and `option_id`.
- Call `TeamSessionService::assertTeamSession()`.
- Call `GameEngine::raceRoundAnswer()`.

`resolveRaceRound()`:

- Require `TenantContext::assertRoomOwner()`.
- Read `force` and require it to be true for the teacher's early-close action.
- Call `GameEngine::resolveRaceRound($roomUuid, true, ...)`.
- Forward `Idempotency-Key` for both mutations.

- [ ] **Step 4: Run focused and full suites**

```bash
./vendor/bin/phpunit tests/feature/QuizRaceTeamDeviceApiTest.php
vendor/bin/phpunit
```

- [ ] **Step 5: Commit**

```bash
git add app/Config/Routes.php app/Controllers/Api/V1/RoomsController.php tests/feature/QuizRaceTeamDeviceApiTest.php
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
- During `ROUND_RESOLVING`, keep controls disabled and show a short calculating state.
- During `ROUND_RESOLVED`, show the result panel and do not make the next question answerable until the reveal phase closes.

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

After `ROUND_RESOLVED`, show movement summary for the active team. Continue to use `last_resolved_round` after the next round starts so a refresh/reconnect does not erase feedback:

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
- Confirm all devices see the resolved result before the next question becomes answerable.
- Refresh one device immediately after a round transition and confirm its previous result remains available.
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
- All co-winners if multiple teams finish in the same round.
- Button "Tutup Ronde" while round active.
- A resolved-result state before the next active question.

- [ ] **Step 2: Wire force resolve**

Button calls:

```js
POST /api/v1/rooms/{roomUuid}/race-round/resolve {"force": true}
```

Keep existing `Force Timeout` behavior for Ular Tangga and Tanpa Device. For shared rounds, relabel that command to `Tutup Ronde`, require confirmation that unanswered teams become timeout, and call the round resolve endpoint.

- [ ] **Step 3: Keep roster behavior separate**

For `TEAM_DEVICE`, roster manual panel stays hidden. Teams join through PIN, same as existing Ular Tangga.

For shared-round Quiz Race, also hide/disable `Skip Turn` and `Start Timer`. Pause and Resume remain available but use the shared-round deadline behavior from Task 12.

- [ ] **Step 4: Manual verification**

Check:

- Control page updates answered count as device teams submit.
- Force resolve closes active round.
- Force resolve before the deadline treats unanswered teams as timeout.
- New round appears automatically if no winner.
- Status becomes `FINISHED` when a team crosses finish.
- Projector finish overlay and event feed show every co-winner from `winner_team_uuids`, while retaining support for legacy `winner_team_uuid` events.

- [ ] **Step 5: Commit**

```bash
git add public/assets/app.js public/assets/app.css app/Views/teacher/games/control.php
git commit -m "feat: add teacher controls for shared Quiz Race rounds"
```

---

## Task 12: Platform Integration, Pause/Resume, and Cleanup

**Files:**

- Modify: `app/Services/Game/GameEngine.php`
- Modify: `app/Services/Question/QuestionBankService.php`
- Modify: `app/Services/Report/GameReportService.php`
- Modify: `app/Views/teacher/games/report.php`
- Modify: `app/Views/teacher/games/report_pdf.php`
- Modify: `public/assets/app.js`
- Modify: `tests/database/QuizRaceTeamDeviceTest.php`
- Modify: `tests/database/GameReportServiceTest.php`
- Modify: `tests/database/GameEngineHardeningTest.php`

- [ ] **Step 1: Write integration tests first**

Cover:

- `usedQuestionIdsForRoom()` includes `game_rounds.question_id`; no question repeats until the eligible pool is exhausted.
- An active/resolving round prevents its question and options from being edited/deleted through `QuestionBankService`.
- Deleting a finished Team Device race deletes round answers, rounds, race idempotency keys, score rows, events, teams, room, and its room-instance board.
- Report answer history and question statistics include `game_round_answers` in the same normalized shape as turn answers and distinguish `WRONG` from `TIMEOUT`.
- Report/projector can mark every co-winner while retaining the legacy first-winner field.
- Pause freezes an active round's remaining milliseconds and rejects answers while paused.
- Resume creates a new deadline from the stored remaining milliseconds.
- Repeated pause/resume does not increase or lose substantial time beyond a small test tolerance.

- [ ] **Step 2: Integrate question history and protection**

Update `GameEngine::usedQuestionIdsForRoom()` to union question ids from `game_turns` and `game_rounds`. Update `QuestionBankService::assertNotUsedByActiveRoom()` and option-usage checks so active/resolving race questions cannot be mutated and historical race answers preserve referential integrity.

- [ ] **Step 3: Integrate room deletion**

In `GameEngine::deleteRoom()`:

- Resolve all round ids for the room.
- Delete `game_round_answers` before `game_rounds`.
- Delete idempotency rows whose scope starts with the canonical race answer/resolve prefixes.
- Keep existing deletion ordering and room-instance board cleanup.
- Add a rollback assertion; partial cleanup must not be reported as success.

- [ ] **Step 4: Normalize round answers into reports**

Extend `GameReportService` with a normalized union of turn answers and round answers. Preserve the current report payload keys so the HTML/PDF views and chart builder do not need separate modes. Include round number/source metadata additively when useful.

Update winner extraction to prefer `winner_team_uuids`, fall back to `winner_team_uuid`, and return/mark a list of winners. Return additive `winners` while keeping legacy `winner` as the first item. Update HTML/PDF views to render all winners. Keep single-winner Ular Tangga reports unchanged.

- [ ] **Step 5: Implement shared-round pause/resume**

Branch `GameEngine::pause()` and `resume()` for `QUIZ_RACE + TEAM_DEVICE`:

- On pause during `ROUND_ACTIVE`, atomically store `max(0, deadline_epoch_ms - nowEpochMs)` in `paused_remaining_ms` and clear deadline fields.
- Reject race answers while room status is `PAUSED`.
- On resume, rebuild deadline fields from `paused_remaining_ms` and clear that field.
- If pause happens during `ROUND_RESOLVED`, freeze and restore the reveal duration by the same principle.
- Never use `game_turns` or `current_team_id` in this branch.

- [ ] **Step 6: Verify event and realtime commit ordering**

Do not call a realtime publisher from inside a transaction that can still roll back. Persist state and outbox/event rows transactionally where practical; publish only after successful commit. Add a failure-path test proving no `race.round_resolved`/`game.finished` event survives a rolled-back movement transaction.

- [ ] **Step 7: Run focused and full suites**

```bash
./vendor/bin/phpunit tests/database/QuizRaceTeamDeviceTest.php
./vendor/bin/phpunit tests/database/GameReportServiceTest.php
./vendor/bin/phpunit tests/database/GameEngineHardeningTest.php --filter Delete
./vendor/bin/phpunit
```

- [ ] **Step 8: Commit**

```bash
git add app/Services/Game/GameEngine.php app/Services/Question/QuestionBankService.php app/Services/Report/GameReportService.php app/Views/teacher/games/report.php app/Views/teacher/games/report_pdf.php public/assets/app.js tests/database/QuizRaceTeamDeviceTest.php tests/database/GameReportServiceTest.php tests/database/GameEngineHardeningTest.php
git commit -m "feat: integrate shared Quiz Race rounds with platform lifecycle"
```

---

## Task 13: Concurrency, Regression, and End-to-End Smoke

- [ ] **Step 1: Full automated suite**

```bash
vendor/bin/phpunit
```

Expected: all tests pass.

- [ ] **Step 2: Concurrency smoke**

Exercise the same room with parallel requests:

- Submit the last two team answers nearly simultaneously while polling state and pressing `Tutup Ronde`.
- Assert each team moves once, one `race.round_resolved` event exists, and only one next round is created.
- Send duplicate answer requests with and without the same idempotency key; assert one answer row and no HTTP 500.
- Poll multiple clients across `reveal_until`; assert exactly one next round number is created.

- [ ] **Step 3: Manual smoke - Tanpa Device remains stable**

Create `Quiz Race + Tanpa Device`:

- Add teams from roster.
- Start.
- Pick EASY/MEDIUM/HARD.
- Start timer.
- Answer.
- Confirm movement and finish still work.

- [ ] **Step 4: Manual smoke - Device per Tim**

Create `Quiz Race + Device per Tim`:

- Join 2+ teams via PIN.
- Start from teacher page.
- Confirm all team devices see same question.
- Submit answers.
- Confirm fastest correct gets +3 total.
- Confirm wrong/timeout gets +0.
- Confirm round result remains visible before the next question.
- Pause during an active question, wait beyond the old deadline, resume, and confirm the remaining time continues correctly.
- Force-close a round early and confirm unanswered teams are recorded as timeout.
- Play until one team reaches finish.
- Confirm room status becomes `FINISHED`.
- If two teams finish in the same round, confirm both are shown as winners.
- Open the HTML/PDF report and confirm round answers and question statistics are populated.
- Delete a disposable finished room and confirm no round rows remain.

- [ ] **Step 5: Manual smoke - Ular Tangga unaffected**

Create normal `Ular Tangga Kuis + Device per Tim`:

- Join team.
- Roll dice.
- Answer.
- Confirm old turn flow works.

- [ ] **Step 6: Commit only if fixes were needed**

If Task 13 surfaced code changes:

```bash
git add <changed files>
git commit -m "fix: stabilize Quiz Race team-device smoke flow"
```

If no fixes were needed, do not create an empty checkpoint commit.
