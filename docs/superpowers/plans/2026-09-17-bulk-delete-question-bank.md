# Bulk Delete di Bank Soal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a teacher delete many questions from Bank Soal at once — either all questions in one topic (topic itself removed once emptied) or a manually checked subset of the current page (max 12) — while still respecting the existing "can't delete a question in active use" safety rule.

**Architecture:** Two new pure, directly-testable methods on `QuestionBankService` (`deleteMany()`, `deleteQuestionsInTopic()`) carry all the business rules (skip-in-use, partial success, topic-emptying condition). Two new controller actions (`QuestionTopicController::destroyAll()`, `QuestionController::bulkDelete()`) stay thin — they only do tenant-ownership authorization (reusing the existing `TenantContext` methods already used by single-question delete) and call the service. The view adds a topic-scoped delete button and a checkbox multi-select bar, wired with a small inline vanilla-JS snippet (no new JS files, no new libraries).

**Tech Stack:** CodeIgniter 4.7 + Shield 1.4 (PHP), PHPUnit 10 with SQLite3 in-memory for tests, vanilla JS/CSS (no build step — this project ships plain files under `public/assets/`).

**Reference spec:** `docs/superpowers/specs/2026-09-17-bulk-delete-question-bank-design.md`

---

## Context you need before starting

- **`QuestionBankService::delete(array $question): void`** (`app/Services/Question/QuestionBankService.php:125`) already exists. It calls `assertNotUsedByActiveRoom()` (throws `DomainException` if the question is used by a `PLAYING`/`PAUSED` game) then does a **soft delete** via `QuestionModel::delete()` (Shield's model soft-delete: row stays with `deleted_at` set, `find()` no longer returns it, but `withDeleted()->find()` still does — options are kept for historical game reports). You are not changing this method; `deleteMany()` calls it as-is.
- **`TenantContext`** (`app/Services/Security/TenantContext.php`) has `assertQuestionOwner(string $questionUuid): array` and `assertQuestionTopicOwner(string $topicUuid): array`. Both throw `CodeIgniter\Exceptions\PageNotFoundException` (not `DomainException`) if the row doesn't exist or belongs to a different teacher (superadmin bypasses the ownership check). Both are already used by the existing single-delete controller actions — reuse them, don't reinvent.
- **No existing test in this codebase does an HTTP POST to any `teacher/*` route.** That's because `teacher/*` routes are CSRF-protected (`app/Config/Filters.php` — `'csrf' => ['before' => ['teacher/*', ...]]`), and neither `FeatureTestTrait` nor this project has any helper for posting a valid CSRF token in tests. Building that from scratch is a separate, unrelated undertaking. Follow the existing pattern instead: the two new controller actions stay thin and untested at the HTTP layer — exactly like the existing `QuestionController::delete()` and `QuestionTopicController::destroy()`, which also have no dedicated tests today. All the actual business logic you need to verify lives in the service methods, which **are** fully unit-testable and **will** be tested in this plan.
- Existing test files to extend: `tests/database/QuestionBankServiceTest.php` (uses `$this->topic()` and `$this->payload()` private helpers already defined there — reuse them, don't duplicate) and no changes needed to `tests/database/QuestionTopicTest.php` (its existing tests target `QuestionTopicModel` directly and are unaffected by this change).
- Every test file in this project uses `protected $seed = DemoGameSeeder::class;` and assumes teacher id `1` already exists after seeding (see any existing test in `QuestionBankServiceTest.php`).
- Run the full suite with: `vendor/bin/phpunit` from the project root (`/Users/mbp19/Documents/YAZDAD/APLIKASI PRODUKSI/games/ular-tangga`). A single file: `vendor/bin/phpunit tests/database/QuestionBankServiceTest.php`. A single test: add `--filter testName`.

---

### Task 1: `QuestionBankService::deleteMany()`

**Files:**
- Modify: `app/Services/Question/QuestionBankService.php`
- Test: `tests/database/QuestionBankServiceTest.php`

- [ ] **Step 1: Write the failing test**

Open `tests/database/QuestionBankServiceTest.php` and add this test method right after `testQuestionUsedByActiveRaceCannotBeDeleted()` (before the closing `private function topic(...)` helper section):

```php
    public function testDeleteManyDeletesEligibleQuestionsAndSkipsActiveOnes(): void
    {
        $topicId = $this->topic(1, 'CRUD Bulk Delete');
        $service = new QuestionBankService();
        $questionA = $service->create(1, $this->payload($topicId));
        $questionB = $service->create(1, $this->payload($topicId));

        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'CRUD Bulk Delete Active Race', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];
        $engine->joinByPin($room['pin'], 'Tim Bulk Delete');
        $engine->start($room['uuid']);
        $storedRoom = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        $round = (new GameRoundModel())->where('room_id', $storedRoom['id'])->first();
        $roundQuestion = (new GameRoundQuestionModel())
            ->where('round_id', $round['id'])
            ->where('state', 'QUESTION_ACTIVE')
            ->first();
        $activeQuestion = (new QuestionModel())->find($roundQuestion['question_id']);

        $result = $service->deleteMany([$questionA, $questionB, $activeQuestion]);

        $this->assertSame(['deleted' => 2, 'skipped' => 1], $result);
        $this->assertNull((new QuestionModel())->find($questionA['id']));
        $this->assertNull((new QuestionModel())->find($questionB['id']));
        $this->assertNotNull((new QuestionModel())->find($activeQuestion['id']));
    }
```

This reuses the exact same fixture pattern as `testQuestionUsedByActiveRaceCannotBeDeleted()` just above it in the same file — a `QUIZ_RACE`/`TEAM_DEVICE` room whose first race question is auto-selected and locked into `QUESTION_ACTIVE`, which is exactly the state `assertNotUsedByActiveRoom()` blocks on.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testDeleteManyDeletesEligibleQuestionsAndSkipsActiveOnes tests/database/QuestionBankServiceTest.php`
Expected: FAIL — `Call to undefined method App\Services\Question\QuestionBankService::deleteMany()`

- [ ] **Step 3: Write minimal implementation**

Open `app/Services/Question/QuestionBankService.php`. Add this new public method right after `delete()` (currently at line 125-129):

```php
    /**
     * @param list<array<string,mixed>> $questions
     * @return array{deleted:int,skipped:int}
     */
    public function deleteMany(array $questions): array
    {
        $deleted = 0;
        $skipped = 0;
        foreach ($questions as $question) {
            try {
                $this->delete($question);
                $deleted++;
            } catch (DomainException) {
                $skipped++;
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }
```

`DomainException` is already imported at the top of this file (used by `delete()`'s own `assertNotUsedByActiveRoom()` call), so no new `use` statement is needed.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testDeleteManyDeletesEligibleQuestionsAndSkipsActiveOnes tests/database/QuestionBankServiceTest.php`
Expected: PASS (1 test, 4 assertions)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Question/QuestionBankService.php tests/database/QuestionBankServiceTest.php
git commit -m "feat: add QuestionBankService::deleteMany() for partial-success bulk delete"
```

---

### Task 2: `QuestionBankService::deleteQuestionsInTopic()`

**Files:**
- Modify: `app/Services/Question/QuestionBankService.php`
- Test: `tests/database/QuestionBankServiceTest.php`

- [ ] **Step 1: Write the failing tests**

Add these two test methods right after the one you just added in Task 1:

```php
    public function testDeleteQuestionsInTopicDeletesTopicWhenAllQuestionsAreRemoved(): void
    {
        $topicId = $this->topic(1, 'Topik Bulk Kosong');
        $topic = (new QuestionTopicModel())->find($topicId);
        $service = new QuestionBankService();
        $service->create(1, $this->payload($topicId));
        $service->create(1, $this->payload($topicId));

        $result = $service->deleteQuestionsInTopic($topic);

        $this->assertSame(['deleted' => 2, 'skipped' => 0, 'topic_deleted' => true], $result);
        $this->assertSame(0, (new QuestionModel())->where('topic_id', $topicId)->countAllResults());
        $this->assertNull((new QuestionTopicModel())->find($topicId));
    }

    public function testDeleteQuestionsInTopicKeepsTopicWhenSomeQuestionsAreActive(): void
    {
        $topicId = $this->topic(1, 'Topik Bulk Sebagian Aktif');
        $topic = (new QuestionTopicModel())->find($topicId);
        $service = new QuestionBankService();
        $service->create(1, $this->payload($topicId));

        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'CRUD Bulk Topic Active Race', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];
        $engine->joinByPin($room['pin'], 'Tim Bulk Topic');
        $engine->start($room['uuid']);
        $storedRoom = (new GameRoomModel())->where('public_uuid', $room['uuid'])->first();
        $round = (new GameRoundModel())->where('room_id', $storedRoom['id'])->first();
        $roundQuestion = (new GameRoundQuestionModel())
            ->where('round_id', $round['id'])
            ->where('state', 'QUESTION_ACTIVE')
            ->first();
        (new QuestionModel())->update($roundQuestion['question_id'], ['topic_id' => $topicId]);

        $result = $service->deleteQuestionsInTopic($topic);

        $this->assertSame(['deleted' => 1, 'skipped' => 1, 'topic_deleted' => false], $result);
        $this->assertNotNull((new QuestionTopicModel())->find($topicId));
        $this->assertSame(1, (new QuestionModel())->where('topic_id', $topicId)->countAllResults());
    }
```

The second test moves the race's auto-selected active question into the test topic (a plain metadata update — the round already locked in `question_id`, so this doesn't disturb the running race) so the topic ends up with exactly one deletable question and one blocked one.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter "testDeleteQuestionsInTopicDeletesTopicWhenAllQuestionsAreRemoved|testDeleteQuestionsInTopicKeepsTopicWhenSomeQuestionsAreActive" tests/database/QuestionBankServiceTest.php`
Expected: FAIL — `Call to undefined method App\Services\Question\QuestionBankService::deleteQuestionsInTopic()`

- [ ] **Step 3: Write minimal implementation**

In `app/Services/Question/QuestionBankService.php`, add this new public method right after the `deleteMany()` method you added in Task 1:

```php
    /**
     * @param array<string,mixed> $topic
     * @return array{deleted:int,skipped:int,topic_deleted:bool}
     */
    public function deleteQuestionsInTopic(array $topic): array
    {
        $questions = (new QuestionModel())->where('topic_id', $topic['id'])->findAll();
        $result = $this->deleteMany($questions);

        $topicDeleted = false;
        if ($result['skipped'] === 0) {
            (new QuestionTopicModel())->delete($topic['id']);
            $topicDeleted = true;
        }

        return $result + ['topic_deleted' => $topicDeleted];
    }
```

`QuestionTopicModel` is already imported at the top of this file (used by `normalize()`).

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "testDeleteQuestionsInTopicDeletesTopicWhenAllQuestionsAreRemoved|testDeleteQuestionsInTopicKeepsTopicWhenSomeQuestionsAreActive" tests/database/QuestionBankServiceTest.php`
Expected: PASS (2 tests, 7 assertions)

- [ ] **Step 5: Run the whole test file to confirm no regressions**

Run: `vendor/bin/phpunit tests/database/QuestionBankServiceTest.php`
Expected: PASS, all tests green (should now be 10 tests total in this file)

- [ ] **Step 6: Commit**

```bash
git add app/Services/Question/QuestionBankService.php tests/database/QuestionBankServiceTest.php
git commit -m "feat: add QuestionBankService::deleteQuestionsInTopic() for topic-wide bulk delete"
```

---

### Task 3: Routes

**Files:**
- Modify: `app/Config/Routes.php`

- [ ] **Step 1: Add the two new routes**

Open `app/Config/Routes.php`. Find this existing line (topic delete route):

```php
$routes->post('teacher/topics/(:segment)/delete', 'Teacher\QuestionTopicController::destroy/$1', ['filter' => 'teacherAccess']);
```

Add a new line directly below it:

```php
$routes->post('teacher/topics/(:segment)/delete-all', 'Teacher\QuestionTopicController::destroyAll/$1', ['filter' => ['teacherAccess', 'rateLimit:10,300,question-topic-delete-all']]);
```

Then find this existing line (single-question delete route):

```php
$routes->post('teacher/questions/(:segment)/delete', 'Teacher\QuestionController::delete/$1', ['filter' => ['teacherAccess', 'rateLimit:20,300,question-delete']]);
```

Add a new line directly below it:

```php
$routes->post('teacher/questions/bulk-delete', 'Teacher\QuestionController::bulkDelete', ['filter' => ['teacherAccess', 'rateLimit:20,300,question-bulk-delete']]);
```

- [ ] **Step 2: Verify routes are registered**

Run: `php spark routes | grep -i "delete-all\|bulk-delete"`
Expected output includes two lines showing both new routes mapped to `Teacher\QuestionTopicController::destroyAll` and `Teacher\QuestionController::bulkDelete` (they'll 404 until Tasks 4-5 add the controller methods — that's expected at this point).

- [ ] **Step 3: Commit**

```bash
git add app/Config/Routes.php
git commit -m "feat: add routes for topic bulk delete and question bulk delete"
```

---

### Task 4: `QuestionTopicController::destroyAll()`

**Files:**
- Modify: `app/Controllers/Teacher/QuestionTopicController.php`

No dedicated automated test for this task — see "Context you need before starting" above for why (no CSRF-testing helper exists in this project, and this mirrors the existing untested `destroy()` action right above it in the same file). Verify manually in Step 2.

- [ ] **Step 1: Add the controller action**

Open `app/Controllers/Teacher/QuestionTopicController.php`. Add this import at the top, alongside the existing `use` statements:

```php
use App\Services\Question\QuestionBankService;
```

Then add this new public method at the end of the class, right after `destroy()`:

```php
    public function destroyAll(string $topicUuid)
    {
        $topic = (new TenantContext())->assertQuestionTopicOwner($topicUuid);
        $result = (new QuestionBankService())->deleteQuestionsInTopic($topic);

        if ($result['topic_deleted']) {
            return redirect()->to('/teacher/questions')->with(
                'message',
                'Topik "' . $topic['name'] . '" dan ' . $result['deleted'] . ' soal di dalamnya berhasil dihapus.'
            );
        }

        return redirect()->to('/teacher/questions')->with(
            'message',
            $result['deleted'] . ' soal dihapus. ' . $result['skipped']
                . ' soal dilewati karena sedang dipakai game aktif — topik "' . $topic['name']
                . '" belum dihapus karena masih berisi ' . $result['skipped'] . ' soal.'
        );
    }
```

`TenantContext` is already imported and used by `destroy()` in this same file.

- [ ] **Step 2: Verify manually**

This controller has no automated test, so confirm it works by hand once the view button exists (Task 6) — or right now via `php spark` shell:

```bash
php spark tinker
```

Then in the tinker shell:

```php
$topic = (new \App\Models\QuestionTopicModel())->insert(['public_uuid' => \App\Services\Game\Uuid::v4(), 'owner_teacher_id' => 1, 'name' => 'Manual Check'], true);
(new \App\Services\Question\QuestionBankService())->create(1, ['topic_id' => $topic, 'question_type' => 'TRUE_FALSE', 'stem' => 'Cek manual?', 'difficulty' => 'MEDIUM', 'status' => 'PUBLISHED', 'points' => 100, 'time_limit_seconds' => 30, 'correct_option' => 'A']);
$topicRow = (new \App\Models\QuestionTopicModel())->find($topic);
(new \App\Services\Question\QuestionBankService())->deleteQuestionsInTopic($topicRow);
// Expect: ['deleted' => 1, 'skipped' => 0, 'topic_deleted' => true]
```

If this doesn't match, stop and fix `deleteQuestionsInTopic()` before moving on — the controller action just wraps this exact call.

- [ ] **Step 3: Commit**

```bash
git add app/Controllers/Teacher/QuestionTopicController.php
git commit -m "feat: add QuestionTopicController::destroyAll() for topic bulk delete"
```

---

### Task 5: `QuestionController::bulkDelete()`

**Files:**
- Modify: `app/Controllers/Teacher/QuestionController.php`

No dedicated automated test for this task — same reasoning as Task 4 (mirrors the existing untested `delete()` action in the same file; the ownership check it relies on, `TenantContext::assertQuestionOwner()`, is the same method the already-shipped single-delete action depends on).

- [ ] **Step 1: Add the controller action**

Open `app/Controllers/Teacher/QuestionController.php`. Add this import at the top, alongside the existing `use` statements:

```php
use CodeIgniter\Exceptions\PageNotFoundException;
```

Then add this new public method right after `delete()`:

```php
    public function bulkDelete()
    {
        $tenant = new TenantContext();
        $submittedUuids = (array) $this->request->getPost('question_uuids');
        $uuids = array_slice(array_values(array_unique(array_filter($submittedUuids, 'is_string'))), 0, 12);

        $questions = [];
        foreach ($uuids as $uuid) {
            try {
                $questions[] = $tenant->assertQuestionOwner($uuid);
            } catch (PageNotFoundException) {
                continue;
            }
        }

        if ($questions === []) {
            return redirect()->back()->with('error', 'Tidak ada soal valid yang dipilih untuk dihapus.');
        }

        $result = (new QuestionBankService())->deleteMany($questions);

        $message = $result['deleted'] . ' soal berhasil dihapus.';
        if ($result['skipped'] > 0) {
            $message .= ' ' . $result['skipped'] . ' soal dilewati karena sedang dipakai game aktif.';
        }

        $topicFilter = (string) $this->request->getPost('topic_filter');
        $redirectUrl = $topicFilter === '' ? '/teacher/questions' : '/teacher/questions?topic=' . rawurlencode($topicFilter);

        return redirect()->to($redirectUrl)->with('message', $message);
    }
```

`TenantContext` and `QuestionBankService` are already imported at the top of this file (used by `delete()`/`store()`). `12` matches the question list's page size (`paginate(12, 'questions')` in `index()`), so a tampered request can never claim to bulk-delete more than what a single page could ever show as checkable.

- [ ] **Step 2: Verify manually**

```bash
php spark tinker
```

```php
$topic = (new \App\Models\QuestionTopicModel())->insert(['public_uuid' => \App\Services\Game\Uuid::v4(), 'owner_teacher_id' => 1, 'name' => 'Manual Check 2'], true);
$q1 = (new \App\Services\Question\QuestionBankService())->create(1, ['topic_id' => $topic, 'question_type' => 'TRUE_FALSE', 'stem' => 'Soal 1?', 'difficulty' => 'MEDIUM', 'status' => 'PUBLISHED', 'points' => 100, 'time_limit_seconds' => 30, 'correct_option' => 'A']);
$q2 = (new \App\Services\Question\QuestionBankService())->create(1, ['topic_id' => $topic, 'question_type' => 'TRUE_FALSE', 'stem' => 'Soal 2?', 'difficulty' => 'MEDIUM', 'status' => 'PUBLISHED', 'points' => 100, 'time_limit_seconds' => 30, 'correct_option' => 'A']);
$rows = [(new \App\Models\QuestionModel())->find($q1['id']), (new \App\Models\QuestionModel())->find($q2['id'])];
(new \App\Services\Question\QuestionBankService())->deleteMany($rows);
// Expect: ['deleted' => 2, 'skipped' => 0]
```

If this doesn't match, stop and fix `deleteMany()` before moving on.

- [ ] **Step 3: Commit**

```bash
git add app/Controllers/Teacher/QuestionController.php
git commit -m "feat: add QuestionController::bulkDelete() for checkbox multi-select delete"
```

---

### Task 6: "Hapus Topik + Semua Soalnya" button in the view

**Files:**
- Modify: `app/Views/teacher/questions/index.php`

- [ ] **Step 1: Track the full selected topic row, not just its name**

Open `app/Views/teacher/questions/index.php`. Find this block near the top (lines 15-25):

```php
$selectedTopicName = 'Semua soal';
if ($topicFilter === 'none') {
    $selectedTopicName = 'Tanpa topik';
} elseif ($topicFilter !== '') {
    foreach ($topics as $topic) {
        if ($topic['public_uuid'] === $topicFilter) {
            $selectedTopicName = $topic['name'];
            break;
        }
    }
}
```

Replace it with:

```php
$selectedTopicName = 'Semua soal';
$selectedTopic = null;
if ($topicFilter === 'none') {
    $selectedTopicName = 'Tanpa topik';
} elseif ($topicFilter !== '') {
    foreach ($topics as $topic) {
        if ($topic['public_uuid'] === $topicFilter) {
            $selectedTopicName = $topic['name'];
            $selectedTopic = $topic;
            break;
        }
    }
}
```

- [ ] **Step 2: Add the button**

Find this block (the `question-list-head` div):

```php
        <div class="question-list-head">
            <div>
                <span class="section-kicker">Daftar soal</span>
                <h2><?= esc($selectedTopicName) ?></h2>
            </div>
            <?php if ($pagination['total'] > 0): ?>
                <p class="muted">Menampilkan <?= esc((string) $pagination['from']) ?>-<?= esc((string) $pagination['to']) ?> dari <?= esc((string) $pagination['total']) ?></p>
            <?php endif ?>
        </div>
```

Replace it with:

```php
        <div class="question-list-head">
            <div>
                <span class="section-kicker">Daftar soal</span>
                <h2><?= esc($selectedTopicName) ?></h2>
            </div>
            <?php if ($pagination['total'] > 0): ?>
                <p class="muted">Menampilkan <?= esc((string) $pagination['from']) ?>-<?= esc((string) $pagination['to']) ?> dari <?= esc((string) $pagination['total']) ?></p>
            <?php endif ?>
            <?php if ($selectedTopic !== null): ?>
                <?php $selectedTopicQuestionCount = (int) ($questionCounts[(string) $selectedTopic['id']] ?? 0); ?>
                <form method="post" action="/teacher/topics/<?= esc($selectedTopic['public_uuid']) ?>/delete-all" onsubmit="return confirm('Hapus topik ini beserta seluruh soal di dalamnya? Soal yang sedang dipakai game aktif akan dilewati. Tindakan ini tidak bisa dibatalkan.');">
                    <?= csrf_field() ?>
                    <button class="button danger-outline" type="submit">Hapus Topik + Semua Soalnya (<?= esc((string) $selectedTopicQuestionCount) ?>)</button>
                </form>
            <?php endif ?>
        </div>
```

This button only renders when a specific real topic is selected (not "Semua soal" or "Tanpa topik"), matching the design spec. The confirm text stays generic (no interpolated topic name) — this matches the existing per-topic "×" delete form a few lines up in the same file, which uses the same plain-static-string `confirm()` pattern rather than embedding user data inside a JS string literal.

- [ ] **Step 3: Verify manually**

```bash
php spark serve
```

Visit `http://localhost:8080/teacher/questions` (logged in as a teacher), click a topic in the sidebar, and confirm the new red-outline button appears above the question list showing the correct count. Submitting it should redirect back with a flash message and the topic should be gone from the sidebar if all its questions were deletable.

- [ ] **Step 4: Commit**

```bash
git add app/Views/teacher/questions/index.php
git commit -m "feat: add topic bulk-delete button to Bank Soal view"
```

---

### Task 7: Checkbox multi-select + bulk action bar

**Files:**
- Modify: `app/Views/teacher/questions/index.php`

- [ ] **Step 1: Add the hidden bulk-delete form and the "select all" + bulk bar markup**

Still in `app/Views/teacher/questions/index.php`, find this block (right after the `question-list-head` div you just edited in Task 6, before the `<section class="question-list" ...>` opening tag):

```php
        <section class="question-list" aria-label="Daftar soal">
```

Replace it with:

```php
        <form id="bulk-delete-form" method="post" action="/teacher/questions/bulk-delete" onsubmit="return confirm('Hapus soal yang dipilih? Soal yang sedang dipakai game aktif akan dilewati.');">
            <?= csrf_field() ?>
            <input type="hidden" name="topic_filter" value="<?= esc($topicFilter) ?>">
        </form>

        <?php if ($questions !== []): ?>
            <div class="question-bulk-toolbar">
                <label class="question-select-all">
                    <input type="checkbox" data-select-all-questions>
                    <span>Pilih semua di halaman ini</span>
                </label>
                <div class="bulk-action-bar hidden" data-bulk-bar>
                    <span data-bulk-count>0 soal dipilih</span>
                    <button class="button danger-outline small-button" type="submit" form="bulk-delete-form">Hapus Terpilih</button>
                </div>
            </div>
        <?php endif ?>

        <section class="question-list" aria-label="Daftar soal">
```

This `<form>` is intentionally empty — every checkbox and the submit button below reference it by `form="bulk-delete-form"` (an HTML5 attribute that associates an input with a form anywhere in the same document, even outside it). That sidesteps the fact that each question row already has its own single-delete `<form>`, and HTML doesn't allow nesting one form inside another.

- [ ] **Step 2: Add a checkbox to each question row**

Find this line inside the `foreach ($questions as $question)` loop:

```php
                <article class="question-row">
                    <div class="question-row-main">
                        <div class="question-index" aria-hidden="true"><?= esc((string) $questionNumber) ?></div>
```

Replace it with:

```php
                <article class="question-row">
                    <div class="question-row-main">
                        <label class="question-select">
                            <input type="checkbox" name="question_uuids[]" value="<?= esc($question['public_uuid']) ?>" form="bulk-delete-form" data-question-checkbox aria-label="Pilih soal ini">
                        </label>
                        <div class="question-index" aria-hidden="true"><?= esc((string) $questionNumber) ?></div>
```

- [ ] **Step 3: Add the inline script**

At the very end of the file, right before the final `<?= $this->endSection() ?>` (the one closing the `content` section), add a new scripts section:

```php
<?= $this->section('scripts') ?>
<script>
(function () {
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('[data-question-checkbox]'));
    var selectAll = document.querySelector('[data-select-all-questions]');
    var bulkBar = document.querySelector('[data-bulk-bar]');
    var bulkCount = document.querySelector('[data-bulk-count]');

    function updateBulkBar() {
        var checkedCount = checkboxes.filter(function (box) { return box.checked; }).length;
        if (bulkCount) {
            bulkCount.textContent = checkedCount + ' soal dipilih';
        }
        if (bulkBar) {
            bulkBar.classList.toggle('hidden', checkedCount === 0);
        }
        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && checkedCount === checkboxes.length;
        }
    }

    checkboxes.forEach(function (box) {
        box.addEventListener('change', updateBulkBar);
    });

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (box) {
                box.checked = selectAll.checked;
            });
            updateBulkBar();
        });
    }

    updateBulkBar();
})();
</script>
<?= $this->endSection() ?>
```

This file currently has no `section('scripts')` block of its own (it only extends `layouts/teacher`, which already loads `/assets/app.js` globally) — this is a new, self-contained addition, not a change to `app.js`.

- [ ] **Step 4: Verify manually**

Reload `http://localhost:8080/teacher/questions`. Check a couple of question checkboxes — the "N soal dipilih" bar should appear/update live, and "Pilih semua di halaman ini" should toggle every visible checkbox. Click "Hapus Terpilih", confirm the dialog, and verify only the checked questions are gone after redirect (and any that were in an active game are reported as skipped in the flash message, if you have one running).

- [ ] **Step 5: Commit**

```bash
git add app/Views/teacher/questions/index.php
git commit -m "feat: add checkbox multi-select bulk delete to Bank Soal view"
```

---

### Task 8: CSS for the new elements

**Files:**
- Modify: `public/assets/app.css`

- [ ] **Step 1: Make room for the checkbox column in the question row grid**

Find this rule (around line 1650):

```css
.question-row-main {
    align-items: start;
    display: grid;
    gap: 12px;
    grid-template-columns: 34px minmax(0, 1fr) auto;
    padding: 16px;
}
```

Change `grid-template-columns` to add a new first column for the checkbox:

```css
.question-row-main {
    align-items: start;
    display: grid;
    gap: 12px;
    grid-template-columns: 20px 34px minmax(0, 1fr) auto;
    padding: 16px;
}
```

- [ ] **Step 2: Style the checkbox cell**

Add this new rule right after `.question-row-main` (before `.question-index`):

```css
.question-select {
    align-items: center;
    display: flex;
    height: 34px;
}

.question-select input {
    height: 16px;
    width: 16px;
}
```

- [ ] **Step 3: Fix the mobile breakpoint**

Find this rule (around line 2159, inside the mobile media query):

```css
    .question-row-main {
        grid-template-columns: 34px minmax(0, 1fr);
        padding: 14px;
    }

    .question-thumbnails {
        grid-column: 2;
    }

    .question-row-side {
        grid-column: 2;
        justify-items: start;
    }
```

Replace it with:

```css
    .question-row-main {
        grid-template-columns: 20px 34px minmax(0, 1fr);
        padding: 14px;
    }

    .question-thumbnails {
        grid-column: 3;
    }

    .question-row-side {
        grid-column: 3;
        justify-items: start;
    }
```

(The checkbox keeps its own narrow column on mobile too; the thumbnails/side content that used to sit under column 2 now sits under column 3, since a column was inserted before it.)

- [ ] **Step 4: Add the toolbar and bulk-action-bar styles**

Add these new rules anywhere near `.question-list-head` (e.g. right after its existing rules, around line 1633):

```css
.question-bulk-toolbar {
    align-items: center;
    display: flex;
    gap: 16px;
    justify-content: space-between;
    padding: 0 2px 10px;
}

.question-select-all {
    align-items: center;
    color: #475569;
    display: flex;
    font-size: 13px;
    font-weight: 700;
    gap: 8px;
}

.bulk-action-bar {
    align-items: center;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    display: flex;
    gap: 16px;
    justify-content: space-between;
    padding: 10px 14px;
}

.bulk-action-bar span {
    color: #991b1b;
    font-size: 13px;
    font-weight: 750;
}
```

- [ ] **Step 5: Verify manually**

Reload `http://localhost:8080/teacher/questions` and visually confirm: checkboxes line up next to the question number badge on desktop and mobile widths, and the bulk bar (when visible) has a light red background consistent with the other destructive actions on this page (`danger-outline` buttons already use a similar red).

- [ ] **Step 6: Commit**

```bash
git add public/assets/app.css
git commit -m "style: add CSS for question bulk-select checkbox and action bar"
```

---

### Task 9: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: Run the entire PHPUnit suite**

Run: `vendor/bin/phpunit`
Expected: PASS, all tests green (no failures, no errors — total test count should be 3 higher than before this plan started: 1 from Task 1, 2 from Task 2; no other task adds automated tests, per the CSRF-testing gap noted at the top of this plan).

- [ ] **Step 2: Lint the touched PHP files**

Run:
```bash
php -l app/Services/Question/QuestionBankService.php
php -l app/Controllers/Teacher/QuestionTopicController.php
php -l app/Controllers/Teacher/QuestionController.php
php -l app/Views/teacher/questions/index.php
php -l app/Config/Routes.php
```
Expected: `No syntax errors detected` for every file.

- [ ] **Step 3: Sanity-check the new inline JS**

Confirm the inline `<script>` you added in Task 7 is syntactically valid by extracting it and checking with node:

```bash
sed -n '/<script>/,/<\/script>/p' "app/Views/teacher/questions/index.php" | sed '1d;$d' > /tmp/bulk-delete-inline.js
node --check /tmp/bulk-delete-inline.js
```
Expected: no output (valid syntax). Clean up: `rm /tmp/bulk-delete-inline.js`

- [ ] **Step 4: Final commit if anything was fixed during this pass**

Only if Steps 1-3 required any fixes:
```bash
git add -A
git commit -m "fix: address regressions found in bulk-delete full test pass"
```

If nothing needed fixing, there is nothing to commit for this task — every prior task already committed its own work.
