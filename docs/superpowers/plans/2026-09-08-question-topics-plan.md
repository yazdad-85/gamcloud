# Question Topics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a teacher group their question bank into "Topik" (flat, per-teacher, no hierarchy) — create topics, import DOCX questions straight into one, filter the bank-soal list by topic, and delete a topic without losing its questions (they just become untagged).

**Architecture:** New `question_topics` table + `QuestionTopicModel`, plus a nullable `topic_id` FK on the existing `questions` table. A new thin `QuestionTopicController` handles create/delete. `DocxQuestionImportService::import()` gains an optional `?int $topicId` parameter threaded through to its existing insert. `QuestionController::index()`/`importDocx()` are extended to resolve, filter, and list topics. No existing behavior changes for teachers who never touch a topic — every question stays queryable exactly as before, just with an extra nullable column.

**Tech Stack:** PHP 8.2, CodeIgniter 4, SQLite, PHPUnit + `DatabaseTestTrait`. This codebase has **no HTTP/controller-level test convention anywhere** (confirmed by grep — no `FeatureTestTrait` usage exists) — every existing test calls a service/model directly. This plan follows that same pattern: controller wiring tasks are verified by `php -l` + manual browser check, and automated tests target the underlying data-layer behavior directly (which is what the controllers thinly wrap).

**Spec:** `docs/superpowers/specs/2026-09-08-question-topics-design.md`

**Working directory for every command below:** `/Users/mbp19/Documents/YAZDAD/APLIKASI PRODUKSI/games/ular-tangga`

---

### Task 1: Migration + `QuestionTopicModel` + `topic_id` on questions

**Files:**
- Create: `app/Database/Migrations/2026-09-08-000011_CreateQuestionTopics.php`
- Create: `app/Models/QuestionTopicModel.php`
- Modify: `app/Models/QuestionModel.php`
- Create: `tests/database/QuestionTopicTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/database/QuestionTopicTest.php`:

```php
<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Models\QuestionModel;
use App\Models\QuestionTopicModel;
use App\Services\Game\Uuid;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class QuestionTopicTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed = DemoGameSeeder::class;

    public function testCreateTopicPersistsForOwningTeacher(): void
    {
        $topicId = (new QuestionTopicModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => 1,
            'name' => 'Bab 1 - Pecahan',
        ], true);

        $topic = (new QuestionTopicModel())->find($topicId);

        $this->assertSame(1, (int) $topic['owner_teacher_id']);
        $this->assertSame('Bab 1 - Pecahan', $topic['name']);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `./vendor/bin/phpunit tests/database/QuestionTopicTest.php`
Expected: FAIL — `question_topics` table and `QuestionTopicModel` don't exist yet.

- [ ] **Step 3: Create the migration**

Create `app/Database/Migrations/2026-09-08-000011_CreateQuestionTopics.php`:

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateQuestionTopics extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'public_uuid' => ['type' => 'VARCHAR', 'constraint' => 36],
            'owner_teacher_id' => ['type' => 'INTEGER'],
            'name' => ['type' => 'VARCHAR', 'constraint' => 140],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_uuid');
        $this->forge->addKey(['owner_teacher_id']);
        $this->forge->createTable('question_topics', true);

        $this->forge->addColumn('questions', [
            'topic_id' => [
                'type' => 'INTEGER',
                'null' => true,
                'after' => 'owner_teacher_id',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('questions', 'topic_id');
        $this->forge->dropTable('question_topics', true);
    }
}
```

- [ ] **Step 4: Create the model**

Create `app/Models/QuestionTopicModel.php`:

```php
<?php

namespace App\Models;

use CodeIgniter\Model;

class QuestionTopicModel extends Model
{
    protected $table = 'question_topics';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['public_uuid', 'owner_teacher_id', 'name'];
    protected $useTimestamps = true;
}
```

- [ ] **Step 5: Add `topic_id` to `QuestionModel`'s allowed fields**

In `app/Models/QuestionModel.php`, find:

```php
    protected $allowedFields = [
        'public_uuid',
        'owner_teacher_id',
        'source_type',
```

Replace with:

```php
    protected $allowedFields = [
        'public_uuid',
        'owner_teacher_id',
        'topic_id',
        'source_type',
```

- [ ] **Step 6: Apply the migration and run the test**

Run: `php spark migrate`
Expected: confirms `CreateQuestionTopics` migrated.

Run: `./vendor/bin/phpunit tests/database/QuestionTopicTest.php`
Expected: PASS (1 test).

- [ ] **Step 7: Run the full suite**

Run: `./vendor/bin/phpunit`
Expected: all tests PASS (should be 58 — 57 + 1 new).

- [ ] **Step 8: Commit**

```bash
git add app/Database/Migrations/2026-09-08-000011_CreateQuestionTopics.php app/Models/QuestionTopicModel.php app/Models/QuestionModel.php tests/database/QuestionTopicTest.php
git commit -m "feat: add question_topics table and topic_id on questions"
```

---

### Task 2: `DocxQuestionImportService` accepts a topic

**Files:**
- Modify: `app/Services/Question/DocxQuestionImportService.php:25` (`import()`), `:301` (`persist()`)
- Test: `tests/database/DocxQuestionImportServiceTest.php`

- [ ] **Step 1: Write the failing tests**

In `tests/database/DocxQuestionImportServiceTest.php`, add `use App\Models\QuestionTopicModel;` to the top imports (alongside the existing `use App\Models\QuestionModel;`), and add these two test methods to the class:

```php
    public function testImportDocxTagsQuestionsWithGivenTopicId(): void
    {
        $topicId = (new QuestionTopicModel())->insert([
            'public_uuid' => \App\Services\Game\Uuid::v4(),
            'owner_teacher_id' => 1,
            'name' => 'Topik Uji',
        ], true);

        $path = $this->makeDocxFixture();
        $result = (new DocxQuestionImportService())->import($path, 1, $topicId);
        $this->pathsToClean[] = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'uploads/question-imports/1/' . $result['batch_uuid'];

        $imported = (new QuestionModel())->where('owner_teacher_id', 1)->findAll();
        $taggedCount = count(array_filter(
            $imported,
            static fn (array $question): bool => (int) ($question['topic_id'] ?? 0) === $topicId,
        ));

        $this->assertSame(3, $taggedCount);
    }

    public function testImportDocxWithoutTopicLeavesQuestionsUntagged(): void
    {
        $path = $this->makeDocxFixture();
        $result = (new DocxQuestionImportService())->import($path, 1);
        $this->pathsToClean[] = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'uploads/question-imports/1/' . $result['batch_uuid'];

        $imported = (new QuestionModel())->where('owner_teacher_id', 1)->findAll();
        foreach ($imported as $question) {
            $this->assertNull($question['topic_id']);
        }
    }
```

- [ ] **Step 2: Run them to confirm the first one fails**

Run: `./vendor/bin/phpunit --filter "testImportDocxTagsQuestionsWithGivenTopicId|testImportDocxWithoutTopicLeavesQuestionsUntagged" tests/database/DocxQuestionImportServiceTest.php`
Expected: `testImportDocxTagsQuestionsWithGivenTopicId` FAILS (with a "too many arguments" / arity error, since `import()` doesn't accept a third parameter yet); `testImportDocxWithoutTopicLeavesQuestionsUntagged` already PASSES (every question already has `topic_id = NULL` by default from Task 1's migration, even before this task's code changes — that's expected and fine, it's a valid regression guard either way).

- [ ] **Step 3: Add the `$topicId` parameter to `import()`**

In `app/Services/Question/DocxQuestionImportService.php`, find:

```php
    public function import(string $docxPath, int $teacherId): array
    {
```

Replace with:

```php
    public function import(string $docxPath, int $teacherId, ?int $topicId = null): array
    {
```

Then find, inside the same method:

```php
            return $this->persist($parsed, $teacherId, $batchUuid, $this->skippedQuestions);
```

Replace with:

```php
            return $this->persist($parsed, $teacherId, $batchUuid, $this->skippedQuestions, $topicId);
```

- [ ] **Step 4: Add the `$topicId` parameter to `persist()` and stamp it on each inserted question**

Find:

```php
    private function persist(array $questions, int $teacherId, string $batchUuid, int $skipped): array
    {
```

Replace with:

```php
    private function persist(array $questions, int $teacherId, string $batchUuid, int $skipped, ?int $topicId = null): array
    {
```

Then find, inside the same method's `foreach` loop:

```php
            $questionId = $questionModel->insert([
                'public_uuid' => Uuid::v4(),
                'owner_teacher_id' => $teacherId,
                'source_type' => 'PERSONAL',
```

Replace with:

```php
            $questionId = $questionModel->insert([
                'public_uuid' => Uuid::v4(),
                'owner_teacher_id' => $teacherId,
                'topic_id' => $topicId,
                'source_type' => 'PERSONAL',
```

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `./vendor/bin/phpunit --filter "testImportDocxTagsQuestionsWithGivenTopicId|testImportDocxWithoutTopicLeavesQuestionsUntagged" tests/database/DocxQuestionImportServiceTest.php`
Expected: both PASS.

- [ ] **Step 6: Run the full suite**

Run: `./vendor/bin/phpunit`
Expected: all tests PASS (should be 60 — 58 + 2 new).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Question/DocxQuestionImportService.php tests/database/DocxQuestionImportServiceTest.php
git commit -m "feat: let DOCX import tag questions with a topic"
```

---

### Task 3: `QuestionTopicController` (create/delete) + routes

**Files:**
- Create: `app/Controllers/Teacher/QuestionTopicController.php`
- Modify: `app/Config/Routes.php`
- Test: `tests/database/QuestionTopicTest.php` (data-layer test for the delete-detach behavior the controller implements)

- [ ] **Step 1: Write the failing test**

The controller itself isn't unit-testable in this codebase's existing style (no HTTP-simulation convention — see the plan header). Instead, add a test that directly exercises the exact same two database operations `destroy()` will perform, so the behavior is protected by a real regression test even though the controller method itself is only verified manually (Step 6 below).

Add this method to `tests/database/QuestionTopicTest.php`:

```php
    public function testDeleteTopicDetachesQuestionsWithoutDeletingThem(): void
    {
        $topicId = (new QuestionTopicModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => 1,
            'name' => 'Topik Dihapus',
        ], true);
        $questionId = (new QuestionModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => 1,
            'topic_id' => $topicId,
            'source_type' => 'PERSONAL',
            'question_type' => 'MULTIPLE_CHOICE',
            'stem' => 'Soal uji hapus topik',
            'difficulty' => 'MEDIUM',
            'status' => 'PUBLISHED',
            'points' => 100,
            'time_limit_seconds' => 30,
        ], true);

        (new QuestionModel())->where('topic_id', $topicId)->set(['topic_id' => null])->update();
        (new QuestionTopicModel())->delete($topicId);

        $this->assertNull((new QuestionTopicModel())->find($topicId));
        $question = (new QuestionModel())->find($questionId);
        $this->assertNotNull($question);
        $this->assertNull($question['topic_id']);
    }
```

- [ ] **Step 2: Run it to confirm it passes on its own**

Run: `./vendor/bin/phpunit --filter testDeleteTopicDetachesQuestionsWithoutDeletingThem tests/database/QuestionTopicTest.php`
Expected: PASS (this test doesn't depend on the controller existing — it directly proves the two-statement detach-then-delete sequence is correct, which `QuestionTopicController::destroy()` will replicate verbatim in Step 4).

- [ ] **Step 3: Add the routes**

In `app/Config/Routes.php`, find the existing `teacher/questions/import-docx` route line and add these two lines directly after it:

```php
$routes->post('teacher/topics', 'Teacher\QuestionTopicController::store', ['filter' => 'teacherAccess']);
$routes->post('teacher/topics/(:segment)/delete', 'Teacher\QuestionTopicController::destroy/$1', ['filter' => 'teacherAccess']);
```

- [ ] **Step 4: Create the controller**

Create `app/Controllers/Teacher/QuestionTopicController.php`:

```php
<?php

namespace App\Controllers\Teacher;

use App\Controllers\BaseController;
use App\Models\QuestionModel;
use App\Models\QuestionTopicModel;
use App\Models\TeacherModel;
use App\Services\Game\Uuid;
use App\Services\Security\TenantContext;

class QuestionTopicController extends BaseController
{
    public function store()
    {
        $tenant = new TenantContext();
        $teacherId = $tenant->isSuperadmin()
            ? (int) $this->request->getPost('owner_teacher_id')
            : $tenant->teacherId();

        if ($teacherId < 1 || (new TeacherModel())->find($teacherId) === null) {
            return redirect()->back()->with('error', 'Pilih guru pemilik topik yang valid.');
        }

        $name = trim((string) $this->request->getPost('name'));
        if ($name === '') {
            return redirect()->back()->with('error', 'Nama topik wajib diisi.');
        }
        if (strlen($name) > 140) {
            $name = substr($name, 0, 140);
        }

        (new QuestionTopicModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => $teacherId,
            'name' => $name,
        ]);

        return redirect()->to('/teacher/questions')->with('message', 'Topik "' . $name . '" berhasil dibuat.');
    }

    public function destroy(string $topicUuid)
    {
        $tenant = new TenantContext();
        $topic = (new QuestionTopicModel())->where('public_uuid', $topicUuid)->first();
        if ($topic === null || (! $tenant->isSuperadmin() && (int) $topic['owner_teacher_id'] !== $tenant->teacherId())) {
            return redirect()->back()->with('error', 'Topik tidak ditemukan.');
        }

        (new QuestionModel())->where('topic_id', $topic['id'])->set(['topic_id' => null])->update();
        (new QuestionTopicModel())->delete($topic['id']);

        return redirect()->to('/teacher/questions')->with('message', 'Topik "' . $topic['name'] . '" dihapus. Soal di dalamnya tetap ada, sekarang jadi Tanpa Topik.');
    }
}
```

- [ ] **Step 5: Verify syntax and run the full suite**

Run: `php -l app/Controllers/Teacher/QuestionTopicController.php`
Expected: "No syntax errors detected"

Run: `php -l app/Config/Routes.php`
Expected: "No syntax errors detected"

Run: `./vendor/bin/phpunit`
Expected: all tests PASS (should be 61 — 60 + 1 new).

- [ ] **Step 6: Manual verification**

Start the dev server if not already running, log in as a teacher, and confirm via `curl` that the routes resolve: `php spark routes | grep topics` should show both `teacher/topics` and `teacher/topics/([^/]+)/delete` mapped to `QuestionTopicController::store`/`destroy`. Full end-to-end UI verification happens in Task 6 once the view has a form to actually submit.

- [ ] **Step 7: Commit**

```bash
git add app/Controllers/Teacher/QuestionTopicController.php app/Config/Routes.php tests/database/QuestionTopicTest.php
git commit -m "feat: add topic create/delete endpoints"
```

---

### Task 4: `QuestionController` resolves a topic during import

**Files:**
- Modify: `app/Controllers/Teacher/QuestionController.php`

- [ ] **Step 1: Add the imports**

In `app/Controllers/Teacher/QuestionController.php`, find:

```php
use App\Controllers\BaseController;
use App\Models\QuestionModel;
use App\Models\QuestionOptionModel;
use App\Models\TeacherModel;
```

Replace with:

```php
use App\Controllers\BaseController;
use App\Models\QuestionModel;
use App\Models\QuestionOptionModel;
use App\Models\QuestionTopicModel;
use App\Models\TeacherModel;
use App\Services\Game\Uuid;
```

- [ ] **Step 2: Add the `resolveTopicId()` helper**

Add this new private method anywhere in the `QuestionController` class (e.g. right after `importDocx()`):

```php
    private function resolveTopicId(int $teacherId): ?int
    {
        $newTopicName = trim((string) $this->request->getPost('new_topic_name'));
        if ($newTopicName !== '') {
            $topicId = (new QuestionTopicModel())->insert([
                'public_uuid' => Uuid::v4(),
                'owner_teacher_id' => $teacherId,
                'name' => substr($newTopicName, 0, 140),
            ], true);

            return (int) $topicId;
        }

        $topicId = (int) $this->request->getPost('topic_id');
        if ($topicId < 1) {
            return null;
        }

        $topic = (new QuestionTopicModel())->where('id', $topicId)->where('owner_teacher_id', $teacherId)->first();

        return $topic !== null ? $topicId : null;
    }
```

- [ ] **Step 3: Wire it into `importDocx()`**

In the same file, find:

```php
        try {
            $result = (new DocxQuestionImportService())->import($file->getTempName(), $teacherId);
        } catch (DomainException $error) {
```

Replace with:

```php
        $topicId = $this->resolveTopicId($teacherId);

        try {
            $result = (new DocxQuestionImportService())->import($file->getTempName(), $teacherId, $topicId);
        } catch (DomainException $error) {
```

- [ ] **Step 4: Verify syntax and run the full suite**

Run: `php -l app/Controllers/Teacher/QuestionController.php`
Expected: "No syntax errors detected"

Run: `./vendor/bin/phpunit`
Expected: all tests still PASS (should remain 61 — this task wires HTTP-facing logic that has no automated test in this codebase's convention, matching how `mystery_tile_count`/`board_size` controller wiring in earlier plans also had no dedicated controller test; the underlying behavior it calls — `DocxQuestionImportService::import()` with a topic — was already fully tested in Task 2).

- [ ] **Step 5: Commit**

```bash
git add app/Controllers/Teacher/QuestionController.php
git commit -m "feat: resolve an existing or newly-named topic during DOCX import"
```

---

### Task 5: Bank soal list gains topic data and filtering

**Files:**
- Modify: `app/Controllers/Teacher/QuestionController.php` (`index()`)
- Test: `tests/database/QuestionTopicTest.php`

- [ ] **Step 1: Write the failing test**

Add this method to `tests/database/QuestionTopicTest.php`:

```php
    public function testTopicFilterQueryReturnsOnlyMatchingQuestions(): void
    {
        $topicId = (new QuestionTopicModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => 1,
            'name' => 'Topik Filter',
        ], true);
        $taggedId = (new QuestionModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => 1,
            'topic_id' => $topicId,
            'source_type' => 'PERSONAL',
            'question_type' => 'MULTIPLE_CHOICE',
            'stem' => 'Soal bertopik',
            'difficulty' => 'MEDIUM',
            'status' => 'PUBLISHED',
            'points' => 100,
            'time_limit_seconds' => 30,
        ], true);
        $untaggedId = (new QuestionModel())->insert([
            'public_uuid' => Uuid::v4(),
            'owner_teacher_id' => 1,
            'topic_id' => null,
            'source_type' => 'PERSONAL',
            'question_type' => 'MULTIPLE_CHOICE',
            'stem' => 'Soal tanpa topik',
            'difficulty' => 'MEDIUM',
            'status' => 'PUBLISHED',
            'points' => 100,
            'time_limit_seconds' => 30,
        ], true);

        $byTopic = (new QuestionModel())->where('owner_teacher_id', 1)->where('topic_id', $topicId)->findAll();
        $untagged = (new QuestionModel())->where('owner_teacher_id', 1)->where('topic_id', null)->findAll();

        $this->assertCount(1, $byTopic);
        $this->assertSame($taggedId, $byTopic[0]['id']);
        $this->assertTrue(in_array($untaggedId, array_column($untagged, 'id'), true));
        $this->assertFalse(in_array($taggedId, array_column($untagged, 'id'), true));
    }
```

This proves the exact `where('topic_id', ...)` clauses `index()` will use (Step 3 below) behave correctly — it's the query logic under test, independent of the controller/HTTP layer around it.

- [ ] **Step 2: Run it to confirm it passes on its own**

Run: `./vendor/bin/phpunit --filter testTopicFilterQueryReturnsOnlyMatchingQuestions tests/database/QuestionTopicTest.php`
Expected: PASS (this test only exercises `QuestionModel`, already fully functional after Task 1 — it doesn't depend on anything from this task's `index()` rewrite).

- [ ] **Step 3: Replace `index()`**

In `app/Controllers/Teacher/QuestionController.php`, find:

```php
    public function index(): string
    {
        $tenant = new TenantContext();
        $questionQuery = (new QuestionModel())->orderBy('id', 'DESC');

        if (! $tenant->isSuperadmin()) {
            $questionQuery->where('owner_teacher_id', $tenant->teacherId());
        }

        $questions = $questionQuery->findAll();
        $options = [];
        $optionModel = new QuestionOptionModel();

        foreach ($questions as $question) {
            $options[$question['id']] = $optionModel->where('question_id', $question['id'])->orderBy('sort_order')->findAll();
        }

        return view('teacher/questions/index', [
            'questions' => $questions,
            'options' => $options,
            'isSuperadmin' => $tenant->isSuperadmin(),
            'teachers' => $tenant->isSuperadmin() ? (new TeacherModel())->orderBy('name', 'ASC')->findAll() : [],
        ]);
    }
```

Replace with:

```php
    public function index(): string
    {
        $tenant = new TenantContext();
        $questionQuery = (new QuestionModel())->orderBy('id', 'DESC');
        $topicQuery = (new QuestionTopicModel())->orderBy('name', 'ASC');

        if (! $tenant->isSuperadmin()) {
            $questionQuery->where('owner_teacher_id', $tenant->teacherId());
            $topicQuery->where('owner_teacher_id', $tenant->teacherId());
        }

        $topicFilter = (string) $this->request->getGet('topic');
        if ($topicFilter === 'none') {
            $questionQuery->where('topic_id', null);
        } elseif ($topicFilter !== '') {
            $filterTopic = (new QuestionTopicModel())->where('public_uuid', $topicFilter)->first();
            $questionQuery->where('topic_id', $filterTopic['id'] ?? 0);
        }

        $questions = $questionQuery->findAll();
        $options = [];
        $optionModel = new QuestionOptionModel();

        foreach ($questions as $question) {
            $options[$question['id']] = $optionModel->where('question_id', $question['id'])->orderBy('sort_order')->findAll();
        }

        $topics = $topicQuery->findAll();

        return view('teacher/questions/index', [
            'questions' => $questions,
            'options' => $options,
            'isSuperadmin' => $tenant->isSuperadmin(),
            'teachers' => $tenant->isSuperadmin() ? (new TeacherModel())->orderBy('name', 'ASC')->findAll() : [],
            'topics' => $topics,
            'topicNames' => array_column($topics, 'name', 'id'),
        ]);
    }
```

- [ ] **Step 4: Verify syntax and run the full suite**

Run: `php -l app/Controllers/Teacher/QuestionController.php`
Expected: "No syntax errors detected"

Run: `./vendor/bin/phpunit`
Expected: all tests PASS (should be 62 — 61 + 1 new).

- [ ] **Step 5: Commit**

```bash
git add app/Controllers/Teacher/QuestionController.php tests/database/QuestionTopicTest.php
git commit -m "feat: list and filter the question bank by topic"
```

---

### Task 6: Bank soal view — Topics panel, import field, per-card topic name

**Files:**
- Modify: `app/Views/teacher/questions/index.php`

- [ ] **Step 1: Add the Topics panel**

In `app/Views/teacher/questions/index.php`, find:

```php
<section class="panel import-panel">
    <div>
        <h2>Import Soal DOCX</h2>
```

Insert this new section directly ABOVE it (before `<section class="panel import-panel">`):

```php
<section class="panel">
    <h2>Topik</h2>
    <p class="muted">Kelompokkan bank soal supaya lebih mudah dikelola.</p>
    <div class="check-grid">
        <a class="check-option" href="/teacher/questions" style="text-decoration:none">
            <span>Semua Soal (<?= esc((string) count($questions)) ?>)</span>
        </a>
        <a class="check-option" href="/teacher/questions?topic=none" style="text-decoration:none">
            <span>Tanpa Topik</span>
        </a>
        <?php foreach ($topics as $topic): ?>
            <div class="check-option" style="justify-content:space-between">
                <a href="/teacher/questions?topic=<?= esc($topic['public_uuid']) ?>" style="text-decoration:none"><span><?= esc($topic['name']) ?></span></a>
                <form method="post" action="/teacher/topics/<?= esc($topic['public_uuid']) ?>/delete" onsubmit="return confirm('Hapus topik ini? Soal di dalamnya TIDAK ikut terhapus, hanya jadi Tanpa Topik.');" style="display:inline">
                    <?= csrf_field() ?>
                    <button class="button danger" type="submit" style="min-height:auto;padding:4px 8px">Hapus</button>
                </form>
            </div>
        <?php endforeach ?>
    </div>
    <form class="form" method="post" action="/teacher/topics" style="margin-top:12px">
        <?= csrf_field() ?>
        <?php if (! empty($isSuperadmin)): ?>
            <input type="hidden" name="owner_teacher_id" value="<?= esc((string) old('owner_teacher_id')) ?>">
        <?php endif ?>
        <div class="field">
            <label for="topic_name">Topik Baru</label>
            <input id="topic_name" name="name" placeholder="Contoh: Bab 1 - Pecahan" required>
        </div>
        <button class="button secondary" type="submit">Buat Topik</button>
    </form>
</section>
```

- [ ] **Step 2: Add the topic field to the import form**

In the same file, find:

```php
        <div class="field">
            <label for="docx_file">File DOCX</label>
            <input id="docx_file" name="docx_file" type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
            <p class="field-help">Maksimal 5 MB. Gambar yang diterima: JPG, PNG, GIF, WEBP.</p>
        </div>
        <button class="button" type="submit">Import ke Bank Soal</button>
```

Replace with:

```php
        <div class="field">
            <label for="docx_file">File DOCX</label>
            <input id="docx_file" name="docx_file" type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
            <p class="field-help">Maksimal 5 MB. Gambar yang diterima: JPG, PNG, GIF, WEBP.</p>
        </div>
        <div class="field">
            <label for="topic_id">Topik</label>
            <select id="topic_id" name="topic_id">
                <option value="">Tanpa Topik</option>
                <?php foreach ($topics as $topic): ?>
                    <option value="<?= esc((string) $topic['id']) ?>"><?= esc($topic['name']) ?></option>
                <?php endforeach ?>
            </select>
            <p class="field-help">Atau isi nama topik baru di bawah ini (mengabaikan pilihan di atas kalau diisi):</p>
            <input id="new_topic_name" name="new_topic_name" placeholder="Nama topik baru (opsional)">
        </div>
        <button class="button" type="submit">Import ke Bank Soal</button>
```

- [ ] **Step 3: Show the topic name on each question card**

In the same file, find:

```php
            <p class="muted"><?= esc($question['source_type']) ?> / <?= esc($question['question_type']) ?> / <?= esc($question['difficulty']) ?> / <?= esc($question['status']) ?></p>
```

Add this line directly after it:

```php
            <p class="muted">Topik: <?= esc($topicNames[$question['topic_id']] ?? 'Tanpa Topik') ?></p>
```

- [ ] **Step 4: Verify syntax**

Run: `php -l app/Views/teacher/questions/index.php`
Expected: "No syntax errors detected"

Run: `./vendor/bin/phpunit`
Expected: all tests still PASS (62 — this task is view-only, no test changes).

- [ ] **Step 5: Commit**

```bash
git add app/Views/teacher/questions/index.php
git commit -m "feat: add topics panel, import targeting, and per-question topic label"
```

---

### Task 7: Manual verification and final regression

**Files:** none (verification only)

- [ ] **Step 1: Run the full suite**

Run: `./vendor/bin/phpunit`
Expected: 62/62 tests pass.

- [ ] **Step 2: Lint every touched PHP file together**

Run:
```bash
php -l app/Database/Migrations/2026-09-08-000011_CreateQuestionTopics.php
php -l app/Models/QuestionTopicModel.php
php -l app/Models/QuestionModel.php
php -l app/Services/Question/DocxQuestionImportService.php
php -l app/Controllers/Teacher/QuestionTopicController.php
php -l app/Controllers/Teacher/QuestionController.php
php -l app/Config/Routes.php
php -l app/Views/teacher/questions/index.php
```
Expected: "No syntax errors detected" for all 8 files.

- [ ] **Step 3: Manual browser check**

Start the dev server if it isn't already running, log in as a teacher, open `/teacher/questions`, and confirm:
- A new "Topik" panel appears above "Import Soal DOCX", with "Semua Soal (N)" and "Tanpa Topik" links plus a "Topik Baru" mini-form.
- Create a topic (e.g. "Bab 1 - Pecahan") — it appears in the panel with a "Hapus" button.
- Import a `.docx` file, selecting that topic from the dropdown — after import, the new questions show "Topik: Bab 1 - Pecahan" on their cards, and clicking the topic's link in the panel filters the list to just those questions.
- Import another `.docx` file, this time typing a brand-new name into "Nama topik baru" instead of using the dropdown — confirm a new topic is created and used, and the dropdown selection (if any) was correctly ignored.
- Click "Hapus" on a topic that has questions in it — confirm the topic disappears from the panel, but its questions are still listed under "Tanpa Topik" (not gone).
- Confirm existing questions that were imported before this feature (if any exist in your test data) still show "Topik: Tanpa Topik" and were not affected.

- [ ] **Step 4: Fix and re-verify if anything from Step 3 looks wrong**

If something doesn't work as expected, the most likely causes are: the topic dropdown/hidden fields not matching the `name=` attributes `resolveTopicId()` reads (`topic_id`, `new_topic_name`), or the `?topic=` query parameter not matching what `index()` expects (`none` for untagged, a topic's `public_uuid` otherwise). Re-check against `docs/superpowers/specs/2026-09-08-question-topics-design.md`, fix the specific file, re-run the full suite, and commit:

```bash
git add -A
git commit -m "fix: correct question topics wiring after manual review"
```

(Skip this step entirely if Step 3 found nothing to fix.)
