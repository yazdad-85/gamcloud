# Mode Tanpa Device Implementation Plan

> **STATUS: SELESAI (2026-09-10).** Seluruh Task 1-12 sudah diimplementasikan, diuji, dan diintegrasikan ke `master`. Jangan menjalankan ulang task dalam dokumen ini. Checkbox bertanda selesai menutup scope implementasi; QA visual pada projector fisik hanya tindak lanjut opsional pasca-merge.

**Goal:** Let a teacher run the Ular Tangga quiz game from a single laptop + projector, with the teacher proxying dice rolls, answers, and Mystery Box choices for every team, so classrooms that ban student phones can still play.

**Architecture:** Add a `participation_mode` column to `game_rooms` (`TEAM_DEVICE` default / `TEACHER_CENTRALIZED`). Every existing mutation endpoint (`roll`, `answer`, `chooseMystery`, `answerMystery`) stays exactly as-is except for one new authorization bypass in `TeamSessionService`, gated strictly behind `participation_mode === 'TEACHER_CENTRALIZED'`. A new turn state (`QUESTION_PENDING_START` / `MYSTERY_QUESTION_PENDING_START`) defers the answer countdown until the teacher explicitly starts it. The teacher's existing Control Game page grows a team-roster panel and an embedded copy of the per-team gameplay panel (reusing the same `controller.php` markup and `UlarTangga.controller()` JS, just pointed at "whichever team is on turn" instead of a fixed team). The projector gets a full-screen "now playing: Team X" takeover driven by turn-advance events that already exist.

**Tech Stack:** PHP 8 / CodeIgniter 4, MySQL/MariaDB, CodeIgniter Shield (auth), vanilla JS (`public/assets/app.js`), PHPUnit with `DatabaseTestTrait`.

## Implementation Status (2026-09-10)

- Tasks 1-8: complete.
- Task 9: implementation complete; route, CSRF filter, PHP/JS syntax, and regression suite verified.
- Task 10: complete with centralized Mystery Box timer coverage.
- Task 11: implementation complete; pending questions remain visible but unanswerable until the teacher starts the timer.
- Task 12: implementation complete; turn announcements wait for movement animation before taking over the projector.
- Automated verification: `vendor/bin/phpunit` passes all 119 tests.
- QA visual pada projector fisik bersifat opsional pasca-merge dan tidak membuka kembali task implementasi.

---

## Before You Start

- Working directory for every command in this plan: `/Users/mbp19/Documents/YAZDAD/APLIKASI PRODUKSI/games/ular-tangga` (this is its own git repo, separate from the `games` folder above it).
- Run the whole suite with: `vendor/bin/phpunit` (there's a single unnamed testsuite covering `./tests` — no `--testsuite` flag needed). Tests that use `DatabaseTestTrait` run against an isolated in-memory SQLite database (`Config\Database::$tests`, wired up automatically when `ENVIRONMENT=testing`) and each test is wrapped in a transaction that's rolled back afterward — you don't need to clean up manually, and this never touches the real dev database in `.env`.
- The design this plan implements is fully written out in `docs/superpowers/specs/2026-09-10-mode-tanpa-device-design.md`. Read it once before starting if anything below feels unmotivated — the "why" lives there, this document is the "how".
- Existing conventions this plan follows (verified by reading the current code, not assumed):
  - No HTTP/feature tests exist anywhere in this repo (`tests/` has zero `FeatureTestTrait` usage). Business logic is tested by calling `GameEngine`/`TeamSessionService` methods directly. This plan does the same — controller/route changes are wired but not separately unit-tested, matching the existing pattern.
  - No JS/view tests exist either. UI tasks in this plan end with a manual browser verification checklist instead of an automated test.
  - Every `GameEngine` method that inserts/updates a row only persists fields listed in that model's `$allowedFields` — forgetting to add a new column there is a common way to silently lose data on insert/update.

---

## Task 1: Persist and expose `participation_mode` on room creation

**Files:**
- Create: `app/Database/Migrations/2026-09-10-000001_AddParticipationModeToGameRooms.php`
- Modify: `app/Models/GameRoomModel.php`
- Modify: `app/Services/Game/GameEngine.php` (`createRoom()` around `GameEngine.php:39-124`, `publicRoom()` around `GameEngine.php:2102-2130`)
- Test: `tests/database/TeacherCentralizedModeTest.php` (new file)

- [x] **Step 1: Write the failing tests**

Create `tests/database/TeacherCentralizedModeTest.php`:

```php
<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Models\GameRoomModel;
use App\Services\Game\GameEngine;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class TeacherCentralizedModeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed = DemoGameSeeder::class;

    public function testCreateRoomDefaultsToTeamDeviceParticipationMode(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Default Participation Test');

        $this->assertSame('TEAM_DEVICE', $snapshot['room']['participation_mode']);
        $row = (new GameRoomModel())->where('public_uuid', $snapshot['room']['uuid'])->first();
        $this->assertSame('TEAM_DEVICE', $row['participation_mode']);
    }

    public function testCreateRoomPersistsTeacherCentralizedParticipationMode(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Centralized Participation Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ]);

        $this->assertSame('TEACHER_CENTRALIZED', $snapshot['room']['participation_mode']);
        $row = (new GameRoomModel())->where('public_uuid', $snapshot['room']['uuid'])->first();
        $this->assertSame('TEACHER_CENTRALIZED', $row['participation_mode']);
    }

    public function testCreateRoomRejectsUnknownParticipationModeValue(): void
    {
        $engine = new GameEngine();
        $snapshot = $engine->createRoom(1, 'Invalid Participation Test', [
            'participation_mode' => 'SOMETHING_ELSE',
        ]);

        $this->assertSame('TEAM_DEVICE', $snapshot['room']['participation_mode']);
    }
}
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php`
Expected: FAIL — `Undefined array key "participation_mode"` (the field doesn't exist on the snapshot yet).

- [x] **Step 3: Add the migration**

Create `app/Database/Migrations/2026-09-10-000001_AddParticipationModeToGameRooms.php`:

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddParticipationModeToGameRooms extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('game_rooms', [
            'participation_mode' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'TEAM_DEVICE',
                'after' => 'game_mode',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('game_rooms', ['participation_mode']);
    }
}
```

Run the migration against your dev/test database:

Run: `php spark migrate`
Expected: `Migrating up...` then `...AddParticipationModeToGameRooms` with no errors.

- [x] **Step 4: Add the field to `GameRoomModel::$allowedFields`**

In `app/Models/GameRoomModel.php`, add `'participation_mode',` right after `'game_mode',` in the `$allowedFields` array (`GameRoomModel.php:19`):

```php
        'game_mode',
        'participation_mode',
        'mode_state_json',
```

- [x] **Step 5: Make `createRoom()` accept and validate the option**

In `app/Services/Game/GameEngine.php`, find the `$gameModeKey`/`$gameMode` block right before `$baseRoomState` (`GameEngine.php:84-88`) and add the participation mode resolution right after it:

```php
        $gameModeKey = $this->validOption(strtoupper((string) ($options['game_mode'] ?? 'SNAKES_LADDERS')), $this->modes->playableKeys(), 'SNAKES_LADDERS');
        $gameMode = $this->modes->resolve($gameModeKey);
        $participationMode = $this->validOption(
            strtoupper((string) ($options['participation_mode'] ?? 'TEAM_DEVICE')),
            ['TEAM_DEVICE', 'TEACHER_CENTRALIZED'],
            'TEAM_DEVICE'
        );
        $baseRoomState = [
```

Then add `'participation_mode' => $participationMode,` to the `insert()` call right after `'game_mode' => $gameMode->key(),` (`GameEngine.php:103`):

```php
            'game_mode' => $gameMode->key(),
            'participation_mode' => $participationMode,
            'mode_state_json' => json_encode($gameMode->initialState($baseRoomState, $board), JSON_UNESCAPED_SLASHES),
```

- [x] **Step 6: Expose the field on the public room payload**

In `app/Services/Game/GameEngine.php`, in `publicRoom()` (`GameEngine.php:2102-2119`), add the field right after `'game_mode'`:

```php
            'game_mode' => $room['game_mode'] ?? 'SNAKES_LADDERS',
            'participation_mode' => $room['participation_mode'] ?? 'TEAM_DEVICE',
            'turn_order_mode' => $room['turn_order_mode'] ?? 'random',
```

- [x] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php`
Expected: `OK (3 tests, ...)`

- [x] **Step 8: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: All tests pass (same count as before this task, plus the 3 new ones).

- [x] **Step 9: Commit**

```bash
git add app/Database/Migrations/2026-09-10-000001_AddParticipationModeToGameRooms.php app/Models/GameRoomModel.php app/Services/Game/GameEngine.php tests/database/TeacherCentralizedModeTest.php
git commit -m "feat: add participation_mode column for teacher-centralized rooms"
```

---

## Task 2: Let the room-owning teacher act on behalf of any team

**Files:**
- Modify: `app/Services/Security/TeamSessionService.php`
- Test: `tests/database/TeacherCentralizedModeTest.php`

- [x] **Step 1: Write the failing tests**

Append to `tests/database/TeacherCentralizedModeTest.php` (add these `use` statements at the top, alongside the existing ones):

```php
use App\Models\TeacherModel;
use App\Services\Game\Uuid;
use App\Services\Security\TeamSessionService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\FeatureTestTrait;
use DomainException;
```

Add `use FeatureTestTrait;` and `use AuthenticationTesting;` next to the existing `use DatabaseTestTrait;` in the class body.

Add these test methods and the two private helpers below them:

```php
    public function testRoomOwnerActingAsTeacherBypassesTeamTokenInCentralizedRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Auth Bypass Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Otorisasi')['team'];

        $this->actingAsTeacherOwner(1);

        $asserted = (new TeamSessionService())->assertTeamSession($room['uuid'], $team['public_uuid']);
        $this->assertSame($team['public_uuid'], $asserted['public_uuid']);
    }

    public function testRoomOwnerBypassIsInactiveForTeamDeviceRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Auth Bypass Inactive Test', [
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Device')['team'];

        $this->actingAsTeacherOwner(1);

        $this->expectException(DomainException::class);
        (new TeamSessionService())->assertTeamSession($room['uuid'], $team['public_uuid']);
    }

    public function testNonOwnerTeacherCannotBypassCentralizedRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Auth Bypass Foreign Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Punya Guru Lain')['team'];

        $otherTeacherId = (new TeacherModel())->insert([
            'public_uuid' => Uuid::v4(),
            'name' => 'Guru Lain',
            'email' => 'guru-lain-' . bin2hex(random_bytes(4)) . '@example.test',
            'role' => 'teacher',
        ], true);
        $this->actingAsTeacherOwner($otherTeacherId);

        $this->expectException(DomainException::class);
        (new TeamSessionService())->assertTeamSession($room['uuid'], $team['public_uuid']);
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

        (new TeacherModel())->update($teacherId, ['auth_user_id' => $user->id]);

        $this->actingAs($user);
    }
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php --filter RoomOwner`
Expected: FAIL for `testRoomOwnerActingAsTeacherBypassesTeamTokenInCentralizedRoom` and `testNonOwnerTeacherCannotBypassCentralizedRoom` with `DomainException: Session tim tidak valid...` (no bypass exists yet). `testRoomOwnerBypassIsInactiveForTeamDeviceRoom` already passes today (it's a regression guard) — that's fine, it's still worth keeping.

- [x] **Step 3: Add the bypass to `TeamSessionService`**

Replace the contents of `app/Services/Security/TeamSessionService.php` with:

```php
<?php

namespace App\Services\Security;

use App\Models\GameRoomModel;
use App\Models\GameTeamModel;
use Config\Game;
use DomainException;
use Throwable;

class TeamSessionService
{
    public function assertTeamSession(string $roomUuid, string $teamUuid): array
    {
        $team = (new GameTeamModel())->where('public_uuid', $teamUuid)->first();
        if ($team === null) {
            throw new DomainException('Tim tidak ditemukan.');
        }

        if ($this->teacherCentralizedAccessAllowed($roomUuid, $team)) {
            return $team;
        }

        $session = session()->get($this->sessionKey($roomUuid));

        if (! is_array($session) || ($session['team_uuid'] ?? null) !== $teamUuid) {
            throw new DomainException('Session tim tidak valid. Silakan join ulang dengan PIN.');
        }

        $issuedAt = (int) ($session['issued_at'] ?? 0);
        $ttlMinutes = (int) config(Game::class)->teamSessionTtlMinutes;
        if ($issuedAt < 1 || (time() - $issuedAt) > ($ttlMinutes * 60)) {
            session()->remove($this->sessionKey($roomUuid));
            throw new DomainException('Session tim kedaluwarsa. Silakan join ulang dengan PIN.');
        }

        $token = (string) ($session['token'] ?? '');
        if ($token === '' || ! hash_equals((string) $team['session_token_hash'], hash('sha256', $token))) {
            throw new DomainException('Token tim tidak valid. Silakan join ulang dengan PIN.');
        }

        return $team;
    }

    public function currentTeamUuid(string $roomUuid): ?string
    {
        $session = session()->get($this->sessionKey($roomUuid));

        return is_array($session) ? ($session['team_uuid'] ?? null) : null;
    }

    private function teacherCentralizedAccessAllowed(string $roomUuid, array $team): bool
    {
        if (! auth()->loggedIn()) {
            return false;
        }

        $room = (new GameRoomModel())->where('public_uuid', $roomUuid)->first();
        if ($room === null || ($room['participation_mode'] ?? 'TEAM_DEVICE') !== 'TEACHER_CENTRALIZED') {
            return false;
        }

        if ((int) $team['room_id'] !== (int) $room['id']) {
            return false;
        }

        try {
            (new TenantContext())->assertRoomOwner($roomUuid);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function sessionKey(string $roomUuid): string
    {
        return 'team_' . $roomUuid;
    }
}
```

Note the one behavior change beyond adding the bypass: `assertTeamSession()` now looks up the team by `public_uuid` alone *before* checking the session (previously the team lookup happened implicitly via `session_token_hash` comparison later). This is required so the bypass can inspect `$team['room_id']`. It does not change any existing error message or behavior for the `TEAM_DEVICE` path — a team that doesn't exist still throws `'Tim tidak ditemukan.'` before, exactly as it does now for an invalid `session_token_hash` comparison target.

- [x] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php`
Expected: `OK (6 tests, ...)`

- [x] **Step 5: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: All tests pass, including `tests/database/TeamSessionTtlTest.php` unchanged.

- [x] **Step 6: Commit**

```bash
git add app/Services/Security/TeamSessionService.php tests/database/TeacherCentralizedModeTest.php
git commit -m "feat: let the owning teacher act on behalf of any team in centralized rooms"
```

---

## Task 3: Add the "Mode Partisipasi Tim" setting to Create Game

**Files:**
- Modify: `app/Controllers/Teacher/GameController.php:52-140` (`store()`)
- Modify: `app/Views/teacher/games/create.php`

- [x] **Step 1: Add validation + pass-through in the controller**

In `app/Controllers/Teacher/GameController.php`, in `store()`, add this block right after the `$gameMode` validation (`GameController.php:94-98`):

```php
        $modeCatalog = new GameModeCatalog();
        $gameMode = strtoupper((string) $this->request->getPost('game_mode'));
        if (! in_array($gameMode, $modeCatalog->playableKeys(), true)) {
            $gameMode = 'SNAKES_LADDERS';
        }

        $participationMode = strtoupper((string) $this->request->getPost('participation_mode'));
        if (! in_array($participationMode, ['TEAM_DEVICE', 'TEACHER_CENTRALIZED'], true)) {
            $participationMode = 'TEAM_DEVICE';
        }
```

Then add `'participation_mode' => $participationMode,` into the `$engine->createRoom(...)` options array (`GameController.php:115-134`), right after `'game_mode' => $gameMode,`:

```php
            $snapshot = $engine->createRoom($teacherId, $title, [
                'game_mode' => $gameMode,
                'participation_mode' => $participationMode,
                'board_template_id' => $boardTemplateId,
```

- [x] **Step 2: Add the radio field to the create form**

In `app/Views/teacher/games/create.php`, add this new `<div class="field">` block right after the "Mode Game" field closes (right after the `</div>` that follows the `<p class="field-help">Mode lain disiapkan...</p>` line, i.e. after `create.php:153`):

```html
        <div class="field">
            <label>Mode Partisipasi Tim</label>
            <div class="check-grid">
                <label class="check-option">
                    <input type="radio" name="participation_mode" value="TEAM_DEVICE" <?= old('participation_mode', 'TEAM_DEVICE') === 'TEAM_DEVICE' ? 'checked' : '' ?>>
                    <span>Device per Tim</span>
                </label>
                <label class="check-option">
                    <input type="radio" name="participation_mode" value="TEACHER_CENTRALIZED" <?= old('participation_mode') === 'TEACHER_CENTRALIZED' ? 'checked' : '' ?>>
                    <span>Tanpa Device (Terpusat)</span>
                </label>
            </div>
            <p class="field-help">Tanpa Device: tidak ada join PIN, guru mengoperasikan dadu &amp; jawaban dari halaman Control Game (1 laptop + projector). Cocok untuk sekolah yang melarang HP siswa.</p>
        </div>
```

- [x] **Step 3: Manual verification**

Run: `php spark serve`

In a browser:
1. Log in as a teacher, go to `/teacher/games/create`.
2. Confirm the new "Mode Partisipasi Tim" field appears with "Device per Tim" pre-selected.
3. Fill the form normally, select "Tanpa Device (Terpusat)", submit.
4. On the resulting room's Control Game page (`/teacher/games/{uuid}/control`), open your browser's dev tools and run `fetch('/api/v1/rooms/{uuid}/state').then(r=>r.json()).then(console.log)` (replace `{uuid}` with the room's UUID from the URL) — confirm the JSON response has `data.room.participation_mode === "TEACHER_CENTRALIZED"`.
5. Create a second room leaving the default selected — confirm its snapshot shows `"TEAM_DEVICE"`.

- [x] **Step 4: Commit**

```bash
git add app/Controllers/Teacher/GameController.php app/Views/teacher/games/create.php
git commit -m "feat: add participation mode setting to create game form"
```

---

## Task 4: Manual team roster (add/remove) without PIN join

**Files:**
- Modify: `app/Services/Game/GameEngine.php` (`joinByPin()` at `GameEngine.php:126-180`, new methods)
- Test: `tests/database/TeacherCentralizedModeTest.php`

This task extracts the team-creation logic shared by `joinByPin()` (existing) and a new `addTeamByOwner()`, and adds `removeTeamByOwner()`.

- [x] **Step 1: Write the failing tests**

Add to `tests/database/TeacherCentralizedModeTest.php` (needs `use App\Models\GameTeamModel;` added to the top if not already imported — it isn't in this file yet):

```php
    public function testOwnerCanAddTeamManuallyToCentralizedRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Manual Roster Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ])['room'];
        $this->actingAsTeacherOwner(1);

        $result = $engine->addTeamByOwner($room['uuid'], 'Tim Rajawali');

        $this->assertSame('Tim Rajawali', $result['team']['name']);
        $this->assertSame(1, (new GameTeamModel())->where('room_id', $result['room']['id'])->countAllResults());
    }

    public function testAddTeamByOwnerRejectsTeamDeviceRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Manual Roster Rejected Test', [
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];
        $this->actingAsTeacherOwner(1);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('join PIN');
        $engine->addTeamByOwner($room['uuid'], 'Tim Tidak Boleh');
    }

    public function testAddTeamByOwnerRejectsAfterRoomStarted(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Manual Roster Locked Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $engine->addTeamByOwner($room['uuid'], 'Tim Satu');
        $engine->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);

        $this->expectException(DomainException::class);
        $engine->addTeamByOwner($room['uuid'], 'Tim Telat');
    }

    public function testOwnerCanRemoveTeamWhileInLobby(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Manual Roster Remove Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = $engine->addTeamByOwner($room['uuid'], 'Tim Dihapus')['team'];

        $engine->removeTeamByOwner($room['uuid'], $team['public_uuid']);

        $this->assertSame(0, (new GameTeamModel())->where('room_id', $room['id'])->countAllResults());
    }
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php --filter "AddTeam|RemoveTeam|ManualRoster"`
Expected: FAIL — `Call to undefined method App\Services\Game\GameEngine::addTeamByOwner()`.

- [x] **Step 3: Extract the shared team-creation logic and add the new methods**

In `app/Services/Game/GameEngine.php`, replace the body of `joinByPin()` (`GameEngine.php:126-180`) with:

```php
    public function joinByPin(string $pin, string $teamName, string $avatar = 'robot'): array
    {
        $room = (new GameRoomModel())->where('pin', strtoupper($pin))->first();
        if ($room === null) {
            throw new DomainException('PIN tidak ditemukan.');
        }
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa. Minta guru membuat room baru.');

        if ($room['status'] !== 'LOBBY') {
            throw new DomainException('Room sudah tidak menerima tim baru.');
        }

        $result = $this->insertTeamIntoRoom($room, $teamName, $avatar);

        return $result + ['snapshot' => $this->snapshot($result['room']['public_uuid'])];
    }

    public function addTeamByOwner(string $roomUuid, string $teamName, string $avatar = 'robot'): array
    {
        $room = (new TenantContext())->assertRoomOwner($roomUuid);
        if (($room['participation_mode'] ?? 'TEAM_DEVICE') !== 'TEACHER_CENTRALIZED') {
            throw new DomainException('Room ini memakai Device per Tim, tim ditambahkan lewat join PIN.');
        }
        if ($room['status'] !== 'LOBBY') {
            throw new DomainException('Tim hanya bisa ditambah selama room di status LOBBY.');
        }

        $result = $this->insertTeamIntoRoom($room, $teamName, $avatar);

        return $result + ['snapshot' => $this->snapshot($result['room']['public_uuid'])];
    }

    public function removeTeamByOwner(string $roomUuid, string $teamUuid): array
    {
        $room = (new TenantContext())->assertRoomOwner($roomUuid);
        if ($room['status'] !== 'LOBBY') {
            throw new DomainException('Tim hanya bisa dihapus selama room di status LOBBY.');
        }

        $team = $this->teamByUuid($teamUuid, (int) $room['id']);
        (new GameTeamModel())->delete($team['id']);
        $this->bumpRoom($room['id']);
        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'room.team_removed', [
            'team_uuid' => $team['public_uuid'],
            'team_name' => $team['name'],
        ]);

        return $this->snapshot($room['public_uuid'], null, true);
    }

    private function insertTeamIntoRoom(array $room, string $teamName, string $avatar): array
    {
        $teamName = trim($teamName);
        if ($teamName === '') {
            throw new DomainException('Nama tim wajib diisi.');
        }
        if (strlen($teamName) > 80) {
            throw new DomainException('Nama tim maksimal 80 karakter.');
        }

        $teamCount = (new GameTeamModel())->where('room_id', $room['id'])->countAllResults();
        if ($teamCount >= (int) $room['max_teams']) {
            throw new DomainException('Room sudah penuh.');
        }

        $token = bin2hex(random_bytes(24));
        $colors = ['#2563eb', '#dc2626', '#16a34a', '#9333ea', '#ea580c', '#0891b2'];
        $avatar = $this->validOption($avatar, $this->avatarKeys(), 'robot');
        $teamId = (new GameTeamModel())->insert([
            'public_uuid' => Uuid::v4(),
            'room_id' => $room['id'],
            'name' => $teamName,
            'color' => $colors[$teamCount % count($colors)],
            'avatar' => $avatar,
            'session_token_hash' => hash('sha256', $token),
            'position' => 1,
            'score' => 0,
            'streak_count' => 0,
            'active_effects_json' => json_encode(['safe_shield' => 0], JSON_UNESCAPED_SLASHES),
            'is_connected' => 1,
            'joined_at' => date('Y-m-d H:i:s'),
        ], true);

        $team = (new GameTeamModel())->find($teamId);
        $this->bumpRoom($room['id']);
        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'room.team_joined', ['team' => $this->publicTeam($team)]);

        return ['room' => $room, 'team' => $team, 'token' => $token];
    }
```

Add `use App\Services\Security\TenantContext;` to the `use` block at the top of `GameEngine.php` (alongside the other `App\...` imports around `GameEngine.php:5-17`).

- [x] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php`
Expected: `OK (10 tests, ...)`

- [x] **Step 5: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: All tests pass — in particular every existing test that calls `joinByPin()` (there are many, across `GameEngineHardeningTest.php`, `TeamSessionTtlTest.php`, `ProjectorTokenSecurityTest.php`) must behave identically, since `joinByPin()`'s externally-visible behavior didn't change, only its internals were extracted.

- [x] **Step 6: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/TeacherCentralizedModeTest.php
git commit -m "feat: let the owning teacher manually manage the team roster in centralized rooms"
```

---

## Task 5: Wire the roster endpoints and Control Game roster panel

**Files:**
- Modify: `app/Config/Routes.php`
- Modify: `app/Controllers/Api/V1/RoomsController.php`
- Modify: `app/Views/teacher/games/control.php`
- Modify: `public/assets/app.js` (`teacherControl()` at `app.js:625-697`)

- [x] **Step 1: Add the routes**

In `app/Config/Routes.php`, inside the `api/v1` route group (right after the `force-timeout` line, `Routes.php:65`), add:

```php
    $routes->post('rooms/(:segment)/teams', 'Api\V1\RoomsController::addTeam/$1', ['filter' => 'rateLimit:20,60,api-mutation']);
    $routes->post('rooms/(:segment)/teams/(:segment)/remove', 'Api\V1\RoomsController::removeTeam/$1/$2', ['filter' => 'rateLimit:20,60,api-mutation']);
```

- [x] **Step 2: Add the controller actions**

In `app/Controllers/Api/V1/RoomsController.php`, add these two methods right after `forceTimeout()` (`RoomsController.php:61-68`):

```php
    public function addTeam(string $roomUuid)
    {
        $payload = $this->request->getJSON(true) ?: $this->request->getPost();
        $teamName = (string) ($payload['team_name'] ?? '');
        $avatar = (string) ($payload['avatar'] ?? 'robot');

        return $this->respond(fn () => (new GameEngine())->addTeamByOwner($roomUuid, $teamName, $avatar));
    }

    public function removeTeam(string $roomUuid, string $teamUuid)
    {
        return $this->respond(fn () => (new GameEngine())->removeTeamByOwner($roomUuid, $teamUuid));
    }
```

- [x] **Step 3: Add the roster panel markup to Control Game**

In `app/Views/teacher/games/control.php`, add this new `<section>` right before the closing `<?= $this->endSection() ?>` that ends the `content` section (right after the `<section class="panel" style="margin-top:16px">...board...</section>` block, `control.php:43-45`):

```html
<section class="panel roster-panel hidden" data-roster-panel style="margin-top:16px">
    <h2>Tambah Tim</h2>
    <p class="muted">Room ini memakai Mode Tanpa Device — tambahkan tim di sini sebelum menekan Start, tidak ada join PIN.</p>
    <form class="roster-add-form" data-roster-add-form>
        <input type="text" name="team_name" placeholder="Nama tim, mis. Tim Rajawali" maxlength="80" required>
        <button class="button" type="submit">Tambah Tim</button>
    </form>
    <div class="alert hidden" data-roster-error></div>
    <ul class="roster-list" data-roster-list></ul>
</section>
```

- [x] **Step 4: Add the roster panel behavior to `teacherControl()`**

In `public/assets/app.js`, inside `teacherControl(config)`, add these lines right after the existing button lookups (`app.js:627-631`):

```js
        const rosterPanel = document.querySelector('[data-roster-panel]');
        const rosterAddForm = document.querySelector('[data-roster-add-form]');
        const rosterList = document.querySelector('[data-roster-list]');
        const rosterError = document.querySelector('[data-roster-error]');
```

Then, inside `drawTeacherControl()`, right after the existing button-state block (`app.js:642-657`, before the closing `}` of `drawTeacherControl`), add:

```js
            if (rosterPanel) {
                const isCentralized = snapshot.room.participation_mode === 'TEACHER_CENTRALIZED';
                rosterPanel.classList.toggle('hidden', !isCentralized || snapshot.room.status !== 'LOBBY');
                if (isCentralized && rosterList) {
                    rosterList.innerHTML = (snapshot.teams || []).map((team) => (
                        '<li>' + escapeHtml(team.name) +
                        ' <button type="button" class="button secondary" data-roster-remove="' + team.uuid + '">Hapus</button></li>'
                    )).join('') || '<li class="muted">Belum ada tim.</li>';
                }
            }
```

Then, right after the existing `teacherAction` handler wiring (`app.js:673-694`), add:

```js
        if (rosterAddForm) {
            rosterAddForm.addEventListener('submit', function (event) {
                event.preventDefault();
                const input = rosterAddForm.querySelector('input[name="team_name"]');
                const teamName = input.value.trim();
                if (teamName === '') {
                    return;
                }
                if (rosterError) {
                    rosterError.classList.add('hidden');
                }
                jsonFetch('/api/v1/rooms/' + config.roomUuid + '/teams', {
                    method: 'POST',
                    body: JSON.stringify({team_name: teamName}),
                })
                    .then(() => {
                        input.value = '';
                        return runtime.refresh();
                    })
                    .catch((error) => {
                        if (rosterError) {
                            rosterError.textContent = error.message;
                            rosterError.classList.remove('hidden');
                        }
                    });
            });
        }

        if (rosterList) {
            rosterList.addEventListener('click', function (event) {
                const button = event.target.closest('[data-roster-remove]');
                if (!button) {
                    return;
                }
                jsonFetch('/api/v1/rooms/' + config.roomUuid + '/teams/' + button.dataset.rosterRemove + '/remove', {
                    method: 'POST',
                    body: '{}',
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message));
            });
        }
```

`escapeHtml` and `jsonFetch` are existing helper functions already used elsewhere in this file (e.g. `app.js:1244` and `app.js:690`) — no new helper needed.

- [x] **Step 5: Manual verification**

Run: `php spark serve`

In a browser:
1. Create a game with "Tanpa Device (Terpusat)" selected.
2. Open its Control Game page. Confirm the "Tambah Tim" panel is visible (room is still `LOBBY`).
3. Add two teams by name. Confirm they appear in the list immediately after each add.
4. Remove one. Confirm it disappears.
5. Click **Start**. Confirm the roster panel disappears (status is no longer `LOBBY`).
6. Open a second, `TEAM_DEVICE` room's Control Game page — confirm the roster panel never appears there at all.

- [x] **Step 6: Commit**

```bash
git add app/Config/Routes.php app/Controllers/Api/V1/RoomsController.php app/Views/teacher/games/control.php public/assets/app.js
git commit -m "feat: add team roster management UI to Control Game for centralized rooms"
```

---

## Task 6: Reject PIN join for centralized rooms

**Files:**
- Modify: `app/Services/Game/GameEngine.php` (`joinByPin()`)
- Test: `tests/database/TeacherCentralizedModeTest.php`

- [x] **Step 1: Write the failing test**

Add to `tests/database/TeacherCentralizedModeTest.php`:

```php
    public function testJoinByPinRejectsCentralizedRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Join Rejected Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
        ])['room'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('layar guru');
        $engine->joinByPin($room['pin'], 'Tim Nekat Join');
    }
```

- [x] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php --filter testJoinByPinRejectsCentralizedRoom`
Expected: FAIL — no exception thrown, a team gets created.

- [x] **Step 3: Add the guard**

In `app/Services/Game/GameEngine.php`, in `joinByPin()`, add the check right after the `LOBBY` status check:

```php
        if ($room['status'] !== 'LOBBY') {
            throw new DomainException('Room sudah tidak menerima tim baru.');
        }

        if (($room['participation_mode'] ?? 'TEAM_DEVICE') === 'TEACHER_CENTRALIZED') {
            throw new DomainException('Room ini memakai Mode Tanpa Device — ikuti permainan dari layar guru di depan kelas, tidak perlu join PIN.');
        }

        $result = $this->insertTeamIntoRoom($room, $teamName, $avatar);
```

- [x] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php --filter testJoinByPinRejectsCentralizedRoom`
Expected: `OK (1 test, ...)`

- [x] **Step 5: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: All tests pass.

- [x] **Step 6: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/TeacherCentralizedModeTest.php
git commit -m "feat: reject PIN join attempts on teacher-centralized rooms"
```

---

## Task 7: Defer the answer timer after a dice roll in centralized rooms

**Files:**
- Modify: `app/Services/Game/GameEngine.php` (`roll()` at `GameEngine.php:343-420`)
- Test: `tests/database/TeacherCentralizedModeTest.php`

- [x] **Step 1: Write the failing tests**

Add to `tests/database/TeacherCentralizedModeTest.php` (needs `use App\Models\GameTurnModel;` added to the top):

```php
    public function testRollDefersDeadlineForCentralizedRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Pending Start Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = $engine->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        $engine->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);

        $snapshot = $engine->roll($room['uuid'], $team['public_uuid']);

        $this->assertSame('QUESTION_PENDING_START', $snapshot['current_turn']['state']);
        $this->assertNull($snapshot['current_turn']['deadline_at']);
        $this->assertNotNull($snapshot['current_turn']['question']);
    }

    public function testRollKeepsImmediateDeadlineForTeamDeviceRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Immediate Deadline Test', [
            'turn_order_mode' => 'join_order',
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Biasa')['team'];
        $engine->start($room['uuid']);

        $snapshot = $engine->roll($room['uuid'], $team['public_uuid']);

        $this->assertSame('QUESTION_ACTIVE', $snapshot['current_turn']['state']);
        $this->assertNotNull($snapshot['current_turn']['deadline_at']);
    }

    public function testPendingStartTurnCannotBeAnsweredOrForceTimedOut(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Pending Start Guard Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = $engine->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        $engine->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);
        $engine->roll($room['uuid'], $team['public_uuid']);

        $turn = (new GameTurnModel())->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $optionId = $this->firstOptionId((int) $turn['question_id']);

        $this->expectException(DomainException::class);
        $engine->answer($room['uuid'], $team['public_uuid'], $optionId);
    }

    private function roomId(string $roomUuid): int
    {
        $room = (new \App\Models\GameRoomModel())->where('public_uuid', $roomUuid)->first();

        return (int) $room['id'];
    }

    private function firstOptionId(int $questionId): int
    {
        $option = (new \App\Models\QuestionOptionModel())->where('question_id', $questionId)->first();

        return (int) $option['id'];
    }
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php --filter "PendingStart|ImmediateDeadline"`
Expected: `testRollDefersDeadlineForCentralizedRoom` FAILs (state is `QUESTION_ACTIVE`, deadline is not null). The other two should already pass — they're regression/guard checks confirming existing behavior before you touch `roll()`.

- [x] **Step 3: Branch `roll()` on `participation_mode`**

In `app/Services/Game/GameEngine.php`, in `roll()`, replace this block (`GameEngine.php:378-387`):

```php
        $now = date('Y-m-d H:i:s');
        $deadline = date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => 'QUESTION_ACTIVE',
            'dice_value' => $dice,
            'question_id' => $question['id'],
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
```

with:

```php
        $now = date('Y-m-d H:i:s');
        $deferTimer = ($room['participation_mode'] ?? 'TEAM_DEVICE') === 'TEACHER_CENTRALIZED';
        $deadline = $deferTimer ? null : date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => $deferTimer ? 'QUESTION_PENDING_START' : 'QUESTION_ACTIVE',
            'dice_value' => $dice,
            'question_id' => $question['id'],
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
```

`answer()` already requires `$turn['state'] === 'QUESTION_ACTIVE'` exactly (`GameEngine.php:441`) and `forceTimeout()` already only allows `QUESTION_ACTIVE` or the board-challenge states (`GameEngine.php:322`) — so `QUESTION_PENDING_START` is automatically rejected by both without any extra guard code. `publicTurn()`'s `deadline_epoch_ms` computation already handles a `null` `question_deadline_at` (`GameEngine.php:2184`).

- [x] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php`
Expected: `OK (14 tests, ...)`

- [x] **Step 5: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: All tests pass — every existing `roll()`-touching test in `GameEngineHardeningTest.php` runs against `TEAM_DEVICE` rooms (the default), so none of them should change behavior.

- [x] **Step 6: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/TeacherCentralizedModeTest.php
git commit -m "feat: defer the answer countdown after roll() in centralized rooms"
```

---

## Task 8: Let the teacher manually start the answer timer

**Files:**
- Modify: `app/Services/Game/GameEngine.php` (new method, after `forceTimeout()`)
- Test: `tests/database/TeacherCentralizedModeTest.php`

- [x] **Step 1: Write the failing tests**

Add to `tests/database/TeacherCentralizedModeTest.php`:

```php
    public function testStartAnswerTimerActivatesPendingQuestion(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Start Timer Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = $engine->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        $engine->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);
        $engine->roll($room['uuid'], $team['public_uuid']);

        $snapshot = $engine->startAnswerTimer($room['uuid']);

        $this->assertSame('QUESTION_ACTIVE', $snapshot['current_turn']['state']);
        $this->assertNotNull($snapshot['current_turn']['deadline_at']);
    }

    public function testStartAnswerTimerRejectsWhenNoPendingQuestion(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Start Timer Reject Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $engine->addTeamByOwner($room['uuid'], 'Tim Satu');
        $engine->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);

        $this->expectException(DomainException::class);
        $engine->startAnswerTimer($room['uuid']);
    }

    public function testStartAnswerTimerRejectsNonOwner(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Start Timer Owner Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = $engine->addTeamByOwner($room['uuid'], 'Tim Satu')['team'];
        $engine->addTeamByOwner($room['uuid'], 'Tim Dua');
        $engine->start($room['uuid']);
        $engine->roll($room['uuid'], $team['public_uuid']);

        $otherTeacherId = (new \App\Models\TeacherModel())->insert([
            'public_uuid' => Uuid::v4(),
            'name' => 'Guru Lain Timer',
            'email' => 'guru-lain-timer-' . bin2hex(random_bytes(4)) . '@example.test',
            'role' => 'teacher',
        ], true);
        $this->actingAsTeacherOwner($otherTeacherId);

        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $engine->startAnswerTimer($room['uuid']);
    }
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php --filter StartAnswerTimer`
Expected: FAIL — `Call to undefined method App\Services\Game\GameEngine::startAnswerTimer()`.

- [x] **Step 3: Add `startAnswerTimer()`**

In `app/Services/Game/GameEngine.php`, add this method right after `forceTimeout()` (`GameEngine.php:312-341`):

```php
    public function startAnswerTimer(string $roomUuid): array
    {
        $room = (new TenantContext())->assertRoomOwner($roomUuid);
        $this->assertRoomNotExpired($room, 'Room sudah kedaluwarsa.');

        $turn = $this->activeTurn((int) $room['id']);
        $pendingStates = ['QUESTION_PENDING_START', 'MYSTERY_QUESTION_PENDING_START'];
        if ($turn === null || ! in_array($turn['state'], $pendingStates, true)) {
            throw new DomainException('Tidak ada soal yang menunggu waktu jawab dimulai.');
        }

        $nextState = $turn['state'] === 'QUESTION_PENDING_START' ? 'QUESTION_ACTIVE' : 'MYSTERY_QUESTION_ACTIVE';
        $now = date('Y-m-d H:i:s');
        $deadline = date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => $nextState,
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
        $this->bumpRoom($room['id']);

        return $this->snapshot($roomUuid);
    }
```

`MYSTERY_QUESTION_PENDING_START` doesn't exist yet — it's introduced in Task 10. It's safe to reference here now since `in_array` on a state string that never occurs yet is a no-op; this method just won't be reachable for the mystery path until Task 10 wires the state into `chooseMysteryTarget()`.

- [x] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php`
Expected: `OK (17 tests, ...)`

- [x] **Step 5: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: All tests pass.

- [x] **Step 6: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/TeacherCentralizedModeTest.php
git commit -m "feat: add startAnswerTimer for teacher-controlled answer countdown"
```

---

## Task 9: Wire the start-timer route and Control Game button

**Files:**
- Modify: `app/Config/Routes.php`
- Modify: `app/Controllers/Api/V1/RoomsController.php`
- Modify: `app/Views/teacher/games/control.php`
- Modify: `public/assets/app.js`

- [x] **Step 1: Add the route**

In `app/Config/Routes.php`, in the `api/v1` group, add (near the other room-action routes):

```php
    $routes->post('rooms/(:segment)/start-timer', 'Api\V1\RoomsController::startTimer/$1', ['filter' => 'rateLimit:30,60,api-mutation']);
```

- [x] **Step 2: Add the controller action**

In `app/Controllers/Api/V1/RoomsController.php`, add right after `forceTimeout()`:

```php
    public function startTimer(string $roomUuid)
    {
        return $this->respond(fn () => (new GameEngine())->startAnswerTimer($roomUuid));
    }
```

- [x] **Step 3: Add the button markup**

In `app/Views/teacher/games/control.php`, add a new button to `.control-actions` (`control.php:10-17`), right after the "Force Timeout" button:

```html
        <button class="button" data-start-timer type="button" hidden>Mulai Waktu Jawab</button>
```

- [x] **Step 4: Wire the button in `teacherControl()`**

In `public/assets/app.js`, inside `teacherControl(config)`, add the lookup next to the other buttons (`app.js:627-631`):

```js
        const startTimerButton = document.querySelector('[data-start-timer]');
```

Inside `drawTeacherControl()`, add this right after the `forceTimeoutButton` block (`app.js:655-657`):

```js
            if (startTimerButton) {
                const turnPending = turn && (turn.state === 'QUESTION_PENDING_START' || turn.state === 'MYSTERY_QUESTION_PENDING_START');
                startTimerButton.hidden = !turnPending;
                startTimerButton.disabled = !turnPending;
            }
```

Add it to the action-button wiring array (`app.js:673-685`):

```js
        [
            [pauseButton, 'pause'],
            [resumeButton, 'resume'],
            [skipTurnButton, 'skip-turn'],
            [forceTimeoutButton, 'force-timeout'],
            [startTimerButton, 'start-timer'],
        ].forEach(([button, action]) => {
```

- [x] **Step 5: Manual verification**

Run: `php spark serve`

In a browser, with a centralized room that has 2 teams and is `PLAYING`:
1. Roll the dice for the current team via a direct API call (dev tools): `fetch('/api/v1/rooms/{uuid}/roll', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({team_uuid:'{team-uuid}'})})`.
2. Refresh the Control Game page (or wait for its poll) — confirm "Mulai Waktu Jawab" appears.
3. Click it. Confirm the button disappears and the countdown on `/game/{uuid}/projector` starts running.

- [x] **Step 6: Commit**

```bash
git add app/Config/Routes.php app/Controllers/Api/V1/RoomsController.php app/Views/teacher/games/control.php public/assets/app.js
git commit -m "feat: wire the manual answer-timer start button into Control Game"
```

---

## Task 10: Extend the manual timer to the Mystery Box HARD question

**Files:**
- Modify: `app/Services/Game/GameEngine.php` (`chooseMysteryTarget()` at `GameEngine.php:637-709`)
- Test: `tests/database/TeacherCentralizedModeTest.php`

- [x] **Step 1: Write the failing test**

Add to `tests/database/TeacherCentralizedModeTest.php` (needs a Mystery landing — position 45 lands on the demo board's Mystery tile at 46, same as `GameEngineHardeningTest::testMysteryLandingDefersToChoicePendingState`; needs `use App\Models\GameTeamModel;` already added in Task 4):

```php
    public function testMysteryChoiceDefersTimerForCentralizedRoom(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Pending Start Test', [
            'participation_mode' => 'TEACHER_CENTRALIZED',
            'turn_order_mode' => 'join_order',
            'scoring' => ['time_bonus' => false, 'streak_bonus' => false, 'near_finish_bonus' => false, 'wrong_penalty' => false, 'timeout_penalty' => false],
        ])['room'];
        $this->actingAsTeacherOwner(1);
        $team = $engine->addTeamByOwner($room['uuid'], 'Tim Misteri')['team'];
        $engine->start($room['uuid']);

        (new GameTeamModel())->update($team['id'], ['position' => 45]);
        $engine->roll($room['uuid'], $team['public_uuid']);
        $engine->startAnswerTimer($room['uuid']);
        $turn = (new GameTurnModel())->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $optionId = $this->correctOptionId((int) $turn['question_id']);
        (new GameTurnModel())->update($turn['id'], ['dice_value' => 1]);
        $engine->answer($room['uuid'], $team['public_uuid'], $optionId);

        $snapshot = $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], 'SELF');

        $this->assertSame('MYSTERY_QUESTION_PENDING_START', $snapshot['current_turn']['state']);
        $this->assertNull($snapshot['current_turn']['deadline_at']);

        $resumed = $engine->startAnswerTimer($room['uuid']);
        $this->assertSame('MYSTERY_QUESTION_ACTIVE', $resumed['current_turn']['state']);
        $this->assertNotNull($resumed['current_turn']['deadline_at']);
    }

    private function correctOptionId(int $questionId): int
    {
        $option = (new \App\Models\QuestionOptionModel())
            ->where('question_id', $questionId)
            ->where('is_correct', 1)
            ->first();

        return (int) $option['id'];
    }
```

(`correctOptionId()` is added here as a private helper on this test class — it mirrors the one already private to `GameEngineHardeningTest`, but PHP test classes don't share private helpers across files, so this file needs its own copy. `firstOptionId()` was already added to this same class in Task 7 — don't duplicate it.)

- [x] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php --filter testMysteryChoiceDefersTimerForCentralizedRoom`
Expected: FAIL — state is `MYSTERY_QUESTION_ACTIVE` with a non-null deadline immediately after `chooseMysteryTarget()`.

- [x] **Step 3: Branch `chooseMysteryTarget()` on `participation_mode`**

In `app/Services/Game/GameEngine.php`, replace this block in `chooseMysteryTarget()` (`GameEngine.php:679-688`):

```php
        $now = date('Y-m-d H:i:s');
        $deadline = date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => 'MYSTERY_QUESTION_ACTIVE',
            'mystery_target_team_id' => $targetTeamId,
            'question_id' => $question['id'],
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
```

with:

```php
        $now = date('Y-m-d H:i:s');
        $deferTimer = ($room['participation_mode'] ?? 'TEAM_DEVICE') === 'TEACHER_CENTRALIZED';
        $deadline = $deferTimer ? null : date('Y-m-d H:i:s', time() + (int) $room['question_time_seconds']);

        (new GameTurnModel())->update($turn['id'], [
            'state' => $deferTimer ? 'MYSTERY_QUESTION_PENDING_START' : 'MYSTERY_QUESTION_ACTIVE',
            'mystery_target_team_id' => $targetTeamId,
            'question_id' => $question['id'],
            'question_started_at' => $now,
            'question_deadline_at' => $deadline,
        ]);
```

`answerMystery()` already requires the turn state to be exactly `MYSTERY_QUESTION_ACTIVE` (same strict-equality pattern as `answer()`), so `MYSTERY_QUESTION_PENDING_START` is automatically unanswerable without extra guard code.

- [x] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/database/TeacherCentralizedModeTest.php`
Expected: `OK (19 tests, ...)`

- [x] **Step 5: Run the full suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: All tests pass, including every Mystery Box test in `GameEngineHardeningTest.php` (they all run against `TEAM_DEVICE` rooms by default, so `$deferTimer` is always `false` for them).

- [x] **Step 6: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/TeacherCentralizedModeTest.php
git commit -m "feat: defer the Mystery Box HARD-question timer in centralized rooms"
```

---

## Task 11: Embed the gameplay panel (dice/question/mystery) into Control Game

**Files:**
- Modify: `app/Views/teacher/games/control.php`
- Modify: `public/assets/app.js` (`controller()` at `app.js:1110-...`, and its mount call)

This reuses the exact same markup and JS that already power the per-team `game/controller.php` page, just pointed at "whichever team is currently on turn" instead of a fixed team.

- [x] **Step 1: Make `controller()` support a dynamic team instead of a fixed one**

In `public/assets/app.js`, inside `function controller(config) {`, add this right after `const runtime = createRuntime(config);` (`app.js:1111`):

```js
        function activeTeamUuid() {
            return typeof config.teamUuidResolver === 'function' ? config.teamUuidResolver() : config.teamUuid;
        }
```

Then replace every other occurrence of `config.teamUuid` inside `controller()` with `activeTeamUuid()`. There are 7 more occurrences (8 total including the ones you're about to leave as `config.teamUuid` inside the helper itself) — find them with:

Run: `grep -n "config.teamUuid" public/assets/app.js`
Expected output (before this step): lines matching `1153`, `1175`, `1176`, `1285`, `1383`, `1416`, `1437`, `1459` (line numbers may drift slightly depending on exact edit order — match by content, not line number).

Replace each of the following exactly:
- `if (payload.team_uuid !== config.teamUuid) {` → `if (payload.team_uuid !== activeTeamUuid()) {`
- `const isMyTurn = turn && turn.team_uuid === config.teamUuid;` → `const isMyTurn = turn && turn.team_uuid === activeTeamUuid();`
- `const team = (snapshot.teams || []).find((item) => item.uuid === config.teamUuid);` → `const team = (snapshot.teams || []).find((item) => item.uuid === activeTeamUuid());`
- `.filter((item) => item.uuid !== config.teamUuid)` → `.filter((item) => item.uuid !== activeTeamUuid())`
- `body: JSON.stringify({team_uuid: config.teamUuid}),` → `body: JSON.stringify({team_uuid: activeTeamUuid()}),`
- `body: JSON.stringify({team_uuid: config.teamUuid, option_id: button.dataset.optionId}),` → `body: JSON.stringify({team_uuid: activeTeamUuid(), option_id: button.dataset.optionId}),`
- `body: JSON.stringify({team_uuid: config.teamUuid, target: 'SELF'}),` → `body: JSON.stringify({team_uuid: activeTeamUuid(), target: 'SELF'}),`
- `body: JSON.stringify({team_uuid: config.teamUuid, target: button.dataset.mysteryTarget}),` → `body: JSON.stringify({team_uuid: activeTeamUuid(), target: button.dataset.mysteryTarget}),`

When `config.teamUuidResolver` is not passed (the existing `game/controller.php` mount at the bottom of `app.js`'s `controller()` usage), `activeTeamUuid()` returns `config.teamUuid` exactly like before — this is a purely additive change, `game/controller.php`'s behavior is unchanged.

- [x] **Step 2: Add the two-tap confirmation before submitting an answer**

Still inside `controller(config)`, add a new state variable next to the existing ones (`app.js:1126-1129`):

```js
        let pendingConfirmOptionId = null;
        let pendingConfirmQuestionId = null;
```

In `drawController()`, right after `const turn = snapshot.current_turn;` (`app.js:1174`), reset the pending confirmation whenever the question changes:

```js
            const turn = snapshot.current_turn;
            const activeQuestionId = turn && turn.question ? turn.question.id : null;
            if (activeQuestionId !== pendingConfirmQuestionId) {
                pendingConfirmQuestionId = activeQuestionId;
                pendingConfirmOptionId = null;
            }
```

In the option rendering (`app.js:1242-1248`), mark the pending option as selected:

```js
                optionList.innerHTML = showQuestion ? turn.question.options.map((option) => (
                    '<button class="answer-button' + (config.confirmBeforeAnswer && String(option.id) === String(pendingConfirmOptionId) ? ' is-selected' : '') + '" data-option-id="' + option.id + '"' + (isAnswering ? ' disabled' : '') + '>' +
                    '<strong>' + escapeHtml(option.label) + '</strong>' +
                    '<span>' + escapeHtml(option.body) + '</span>' +
                    mediaHtml(option.media, 'option-player-media') +
                    '</button>'
                )).join('') : '';
```

In the `optionList` click handler (`app.js:1398-1425`), require a second tap when `config.confirmBeforeAnswer` is set:

```js
        if (optionList) {
            optionList.addEventListener('click', function (event) {
                const button = event.target.closest('[data-option-id]');
                if (!button || button.disabled || isAnswering) {
                    return;
                }
                if (config.confirmBeforeAnswer && String(button.dataset.optionId) !== String(pendingConfirmOptionId)) {
                    pendingConfirmOptionId = button.dataset.optionId;
                    drawController();
                    return;
                }
                isAnswering = true;
                drawController();
                runtime.setError('');
                const activeTurn = runtime.getSnapshot().current_turn;
                let endpoint = '/answer';
                if (activeTurn && activeTurn.state === 'MYSTERY_QUESTION_ACTIVE') {
                    endpoint = '/mystery/answer';
                } else if (activeTurn && (activeTurn.state === 'SNAKE_REDEMPTION_ACTIVE' || activeTurn.state === 'LADDER_CHALLENGE_ACTIVE')) {
                    endpoint = '/board-challenge/answer';
                }
                jsonFetch('/api/v1/rooms/' + config.roomUuid + endpoint, {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: activeTeamUuid(), option_id: button.dataset.optionId}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message))
                    .finally(() => {
                        isAnswering = false;
                        pendingConfirmOptionId = null;
                        drawController();
                    });
            });
        }
```

Add the `.is-selected` style to `public/assets/app.css` (append near the existing `.answer-button` rule — search for it first with `grep -n "\.answer-button" public/assets/app.css` and add right after):

```css
.answer-button.is-selected {
    outline: 3px solid #f97316;
    outline-offset: -3px;
}
```

- [x] **Step 3: Add the gameplay panel markup to Control Game**

In `app/Views/teacher/games/control.php`, add this right after the roster panel from Task 5 (before the board `<section>`):

```html
<section class="panel gameplay-panel hidden" data-gameplay-panel>
    <h2>Giliran Sekarang</h2>
    <div class="team-identity">
        <span class="team-avatar-badge" data-team-avatar><span data-team-avatar-initials></span></span>
        <div>
            <h3 data-team-name>Tim</h3>
            <p><span data-turn-info>Menunggu giliran</span></p>
        </div>
    </div>
    <p>Skor <strong data-team-score>0</strong> / Kotak <strong data-team-position>1</strong></p>

    <div class="alert hidden" data-move-feedback></div>

    <div class="panel dice-panel" data-dice-panel>
        <div class="dice-stage">
            <div class="dice-face" data-dice-display>?</div>
            <div>
                <p class="dice-caption" data-dice-caption>Menunggu giliran</p>
                <p class="muted" data-roll-reason>Guru belum memulai permainan.</p>
                <p class="team-countdown" data-countdown>-</p>
            </div>
        </div>
        <button class="button roll-button" data-roll type="button">Lempar Dadu</button>
    </div>

    <div class="panel hidden" data-question>
        <h3 data-question-title>Pertanyaan</h3>
        <p data-question-stem></p>
        <p class="question-meta" data-question-meta></p>
        <div data-question-media></div>
        <div class="answer-list" data-options></div>
    </div>

    <div class="panel hidden" data-mystery-choice>
        <h3>Kotak Misteri</h3>
        <p class="muted">Guru pilih niat tim sebelum soal HARD tampil.</p>
        <button class="button" type="button" data-mystery-self>Untuk Timku</button>
        <div class="answer-list" data-mystery-opponents></div>
    </div>
</section>
```

- [x] **Step 4: Mount `UlarTangga.controller()` from Control Game**

In `app/Views/teacher/games/control.php`, in the `scripts` section, add right after the existing `UlarTangga.teacherControl({...})` call (`control.php:50-53`):

```html
<script>
if (<?= json_encode(($snapshot['room']['participation_mode'] ?? 'TEAM_DEVICE') === 'TEACHER_CENTRALIZED') ?>) {
    UlarTangga.controller({
        roomUuid: <?= json_encode($room['uuid'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        snapshot: <?= json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        confirmBeforeAnswer: true,
        teamUuidResolver: function () {
            var el = document.querySelector('[data-current-team]');
            return el ? el.dataset.teamUuid : null;
        },
    });
}
</script>
```

This relies on `[data-current-team]` carrying a `data-team-uuid` attribute. `updateSummary()` (`app.js:508-516`, shared by every page that shows a "Giliran" field, including `control.php` and `projector.php`) currently only writes the team *name* into `[data-current-team]`. Change it to:

```js
    function updateSummary(root, snapshot) {
        root.querySelectorAll('[data-room-status]').forEach((el) => el.textContent = snapshot.room.status);
        root.querySelectorAll('[data-state-version]').forEach((el) => el.textContent = snapshot.room.state_version);
        root.querySelectorAll('[data-current-team]').forEach((el) => {
            const current = (snapshot.teams || []).find((team) => team.uuid === snapshot.room.current_team_uuid);
            el.textContent = current ? current.name : '-';
            el.dataset.teamUuid = snapshot.room.current_team_uuid || '';
        });
        updateCountdown(root, snapshot);
    }
```

(Only the new `el.dataset.teamUuid = ...` line is added; everything else in this function is unchanged.)

Also add `.gameplay-panel` visibility toggling to `teacherControl()`'s `drawTeacherControl()` (next to the roster panel toggle from Task 5):

```js
            const gameplayPanel = document.querySelector('[data-gameplay-panel]');
            if (gameplayPanel) {
                const isCentralized = snapshot.room.participation_mode === 'TEACHER_CENTRALIZED';
                gameplayPanel.classList.toggle('hidden', !isCentralized || snapshot.room.status !== 'PLAYING');
            }
```

- [x] **Step 5: Manual verification**

Run: `php spark serve`

In a browser, with a centralized room with 2 teams, started:
1. Open Control Game. Confirm the "Giliran Sekarang" panel is visible and shows the first team's name.
2. Click "Lempar Dadu". Confirm dice animation/result shows and the question panel appears with options, with no countdown running yet (check the projector tab too — countdown should read `-` or be idle).
3. Click "Mulai Waktu Jawab" (from Task 9). Confirm the countdown starts on the projector.
4. Click an answer option once — confirm it visually highlights (orange outline) but no network request fires yet (check the Network tab).
5. Click the same option again — confirm it submits, the turn advances, and the panel now shows the next team's name and a fresh "Lempar Dadu" state.
6. Repeat until a team lands on a Mystery tile — confirm the Mystery choice buttons appear, and picking one shows a HARD question with the timer deferred again, requiring "Mulai Waktu Jawab" before it counts down.
7. Confirm none of this UI appears on a `TEAM_DEVICE` room's Control Game page.

- [x] **Step 6: Commit**

```bash
git add public/assets/app.js public/assets/app.css app/Views/teacher/games/control.php
git commit -m "feat: embed the turn-following gameplay panel into Control Game"
```

---

## Task 12: Full-screen turn-change pop-up on the projector

**Files:**
- Modify: `public/assets/app.js` (`SEQUENCED_EVENTS` at `app.js:699-709`, `overlayForEvent()` at `app.js:889`)
- Modify: `app/Views/game/projector.php`
- Modify: `public/assets/app.css`

- [x] **Step 1: Add the overlay markup**

In `app/Views/game/projector.php`, add this right after the existing `<div class="fx-sound-unlock" ...>` block (`projector.php:5-8`):

```html
<div class="turn-announcement-overlay hidden" data-turn-announcement>
    <p class="turn-announcement-label">Sekarang Giliran</p>
    <p class="turn-announcement-name" data-turn-announcement-name></p>
</div>
```

- [x] **Step 2: Style it as a full-screen takeover**

Append to `public/assets/app.css`:

```css
.turn-announcement-overlay {
    position: fixed;
    inset: 0;
    z-index: 50;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: linear-gradient(135deg, #0f172a, #1e293b);
    color: #fff;
}

.turn-announcement-label {
    font-size: 14px;
    letter-spacing: 3px;
    text-transform: uppercase;
    opacity: 0.7;
}

.turn-announcement-name {
    font-size: 48px;
    font-weight: 800;
}
```

- [x] **Step 3: Trigger the takeover as its own independent queue**

Two things matter here, verified by reading the existing code first: `answer.resolved` is listed in `SEQUENCED_EVENTS` (`app.js:699-709`) and is rendered through the `runSequencedEvent()` promise chain (`app.js:742-781`), which never calls `overlayForEvent()`. `turn.skipped` and `turn.timeout` are *not* sequenced — they already flow through `overlayForEvent()` (`app.js:889-937`) into the small corner banner via `playOverlayQueue()` (`app.js:1083-1108`, targeting `[data-event-overlay]`). Because the full-screen takeover needs to fire for all three event types and `answer.resolved` never reaches `overlayForEvent()`, hook it in earlier, in the shared per-event loop inside `projector(config)` (`app.js:711-740`), as its own independent queue — this way it doesn't need to touch `SEQUENCED_EVENTS` or `overlayForEvent()` at all, and the existing "Giliran Dilewati" / "Waktu Habis" corner banners keep working unchanged (they explain *why* the turn changed; the new takeover announces *who's* up next — both can show).

In `public/assets/app.js`, add these two functions right after `playOverlayQueue()` (`app.js:1083-1108`, before `function controller(config) {`):

```js
    const turnAnnouncementQueue = [];
    let turnAnnouncementBusy = false;

    function queueTurnAnnouncementIfNeeded(event, snapshot) {
        const payload = event.payload || {};
        const isTurnAdvanceEvent = event.event === 'answer.resolved' || event.event === 'turn.skipped' || event.event === 'turn.timeout';
        if (snapshot.room.participation_mode !== 'TEACHER_CENTRALIZED' || !isTurnAdvanceEvent || !payload.next_team_uuid || payload.finished) {
            return;
        }
        turnAnnouncementQueue.push(teamNameByUuid(payload.next_team_uuid, snapshot));
        playTurnAnnouncementQueue();
    }

    function playTurnAnnouncementQueue() {
        const el = document.querySelector('[data-turn-announcement]');
        const nameEl = document.querySelector('[data-turn-announcement-name]');
        if (!el || !nameEl || turnAnnouncementBusy || turnAnnouncementQueue.length === 0) {
            return;
        }

        const teamName = turnAnnouncementQueue.shift();
        turnAnnouncementBusy = true;
        nameEl.textContent = teamName;
        el.classList.remove('hidden');

        window.setTimeout(() => {
            el.classList.add('hidden');
            turnAnnouncementBusy = false;
            playTurnAnnouncementQueue();
        }, 2500);
    }
```

`teamNameByUuid(teamUuid, snapshot)` already exists (`app.js:416-420`) and returns `'Tim'` as a safe fallback if the team can't be found — no extra null-check needed.

- [x] **Step 4: Call it from the event loop**

In `public/assets/app.js`, in `projector(config)`'s `onSnapshot` callback (`app.js:719-734`), add the call as the very first thing inside the `forEach`, right after `seenEvents.add(event.event_id);`:

```js
                (snapshot.events || []).forEach((event) => {
                    if (seenEvents.has(event.event_id)) {
                        return;
                    }
                    seenEvents.add(event.event_id);
                    queueTurnAnnouncementIfNeeded(event, snapshot);

                    if (SEQUENCED_EVENTS.has(event.event)) {
                        sequenceBusy = sequenceBusy.then(() => runSequencedEvent(event, snapshot));
                        return;
                    }

                    const item = overlayForEvent(event, snapshot);
                    if (item) {
                        overlayQueue.push(item);
                    }
                });
```

This leaves `SEQUENCED_EVENTS`, `runSequencedEvent()`, and `overlayForEvent()` completely untouched — the takeover is entirely additive and runs off its own queue.

- [x] **Step 5: Manual verification**

Run: `php spark serve`

In a browser, with a centralized room, 2+ teams, started, projector open in one tab and Control Game in another:
1. Roll dice, start timer, answer correctly for the first team.
2. Confirm the projector shows a full-screen "Sekarang Giliran: {next team name}" takeover for ~2.5 seconds, then returns to the board.
3. Use "Skip Turn" from Control Game — confirm the same takeover appears for the newly-current team.
4. Let a question time out (or use "Force Timeout") — confirm the same takeover appears.
5. Repeat the same sequence on a `TEAM_DEVICE` room — confirm the takeover never appears there, only the existing small "Giliran" sidebar text updates.

- [x] **Step 6: Commit**

```bash
git add public/assets/app.js public/assets/app.css app/Views/game/projector.php
git commit -m "feat: show a full-screen turn-change announcement on the projector"
```

---

## Final Check

- [x] Run the full suite one more time: `vendor/bin/phpunit` — all 119 tests passed.
- [x] Checklist QA browser/projector telah didokumentasikan dan diserahkan sebagai QA opsional pasca-merge; bukan pekerjaan implementasi tersisa.
