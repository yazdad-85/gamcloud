# Production Security Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce projector display tokens (PIN no longer readable via UUID alone), CSRF on teacher mutate APIs, production cookie/HTTPS env wiring, upload script deny, demo CLI production block, team session TTL, tighter join rate limit.

**Architecture:** Add `projector_token` + `projector_token_hash` on `game_rooms` (A3). Gate projector page and PIN-in-snapshot on valid `?t=`. Teacher links include plain token. CSRF header from teacher layout for guru POSTs. Small config/filter/CLI/upload hardening elsewhere.

**Tech Stack:** PHP 8.2 / CI4, PHPUnit + DatabaseTestTrait, existing `app.js` jsonFetch.

**Spec:** `docs/superpowers/specs/2026-09-09-production-security-hardening-design.md`

---

## File map

| File | Responsibility |
|---|---|
| `app/Database/Migrations/2026-09-09-000001_AddProjectorTokenToGameRooms.php` | Columns + backfill |
| `app/Services/Game/GameEngine.php` | createRoom tokens; snapshot($uuid, ?$t); publicRoom pin gate |
| `app/Controllers/Game/ProjectorController.php` | Require valid `t` |
| `app/Controllers/Api/V1/RoomsController.php` | Pass `t` into snapshot |
| `app/Views/teacher/games/show.php`, `control.php` | Projector URL with `?t=` |
| `app/Views/game/projector.php` | Pass token into JS bootstrap |
| `public/assets/app.js` | Poll state with `t` |
| `app/Config/Filters.php`, `Routes.php` | CSRF teacher API; join 8/60 |
| `app/Views/layouts/teacher.php` | CSRF meta |
| `app/Config/App.php`, `Cookie.php` | Env-driven secure flags |
| `public/uploads/.htaccess` | Deny script execution |
| `app/Commands/Demo*.php` | Production guard |
| `app/Services/Security/TeamSessionService.php` | TTL check |
| `app/Controllers/Public/JoinController.php` | Store `issued_at` |
| `tests/database/ProjectorTokenSecurityTest.php` | Token + PIN strip tests |
| `tests/database/TeamSessionTtlTest.php` | TTL expiry |

---

### Task 1: Migration + engine token + snapshot PIN gate (TDD)

**Files:** migration, `GameEngine.php`, `tests/database/ProjectorTokenSecurityTest.php`

- [ ] **Step 1: Failing tests**

```php
public function testSnapshotWithoutTokenOmitsPin(): void { /* create room, snapshot() without t, assert !isset pin or pin null */ }
public function testSnapshotWithValidTokenIncludesPin(): void { /* use room projector_token */ }
public function testSnapshotWithInvalidTokenOmitsPin(): void { /* */ }
public function testCreateRoomPersistsProjectorTokenHash(): void { /* hash_equals */ }
```

- [ ] **Step 2: Migration** — add `projector_token VARCHAR(64) null`, `projector_token_hash VARCHAR(64) null`; backfill existing rows with random tokens.

- [ ] **Step 3: createRoom** — generate plain+hash; include `projector_token` in returned room array for teacher; store both columns.

- [ ] **Step 4: snapshot(string $roomUuid, ?string $projectorToken = null)** — if token valid via hash_equals on hash, include pin in publicRoom; else omit pin. Never expose `projector_token` in publicRoom.

- [ ] **Step 5: Tests PASS; commit** `feat: add projector tokens and gate PIN in public snapshots`

---

### Task 2: Projector controller + state API + teacher links + JS

**Files:** `ProjectorController.php`, `RoomsController.php`, teacher views, `projector.php`, `app.js`

- [ ] **Step 1: ProjectorController** — require `t`; 404 if missing/invalid; pass token into view/snapshot with PIN.

- [ ] **Step 2: RoomsController::state** — `$t = $this->request->getGet('t'); snapshot($uuid, $t)`.

- [ ] **Step 3: Teacher show/control** — links `/game/{uuid}/projector?t={esc(token)}` from room row.

- [ ] **Step 4: projector.php scripts** — `projectorToken: ...` in bootstrap.

- [ ] **Step 5: app.js** — append `?t=` when polling state if token present.

- [ ] **Step 6: Feature/manual smoke; commit** `feat: require projector token on display URL and state polls`

---

### Task 3: CSRF on teacher mutate APIs

**Files:** `Filters.php`, `layouts/teacher.php`, `app.js` (jsonFetch / teacherControl)

- [ ] **Step 1:** Change CSRF except to team mutate paths only (roll, answer, mystery, board-challenge) — OR apply csrf filter on teacher POST routes explicitly.

- [ ] **Step 2:** Meta `<meta name="csrf-token" content="<?= csrf_hash() ?>">` (+ header name if needed).

- [ ] **Step 3:** jsonFetch sends `X-CSRF-TOKEN` from meta for teacher POSTs.

- [ ] **Step 4: Commit** `security: require CSRF for teacher room control API`

---

### Task 4: Cookie/HTTPS env, uploads, demo guard, join limit, team TTL

**Files:** `App.php`, `Cookie.php`, `public/uploads/.htaccess`, Demo commands, `Routes.php`, `TeamSessionService.php`, `JoinController.php`, TTL test

- [ ] **Step 1:** Wire `forceGlobalSecureRequests` and `Cookie::$secure` from env (default false).

- [ ] **Step 2:** `public/uploads/.htaccess` deny PHP/scripts + Options -Indexes.

- [ ] **Step 3:** Demo commands exit if `ENVIRONMENT === 'production'`.

- [ ] **Step 4:** Join POST rateLimit `8,60`.

- [ ] **Step 5:** Join stores `issued_at`; TeamSessionService rejects expired; test.

- [ ] **Step 6: Commit** `security: harden cookies, uploads, demo CLI, join limit, team TTL`

---

### Task 5: Full phpunit + push

- [ ] `./vendor/bin/phpunit` all green
- [ ] Push to `origin/master`

---

## Self-review

Matches approved spec sections 1–7. No regenerate-token UI (fase 2). A3 plain+hash columns. Team CSRF deferred.
