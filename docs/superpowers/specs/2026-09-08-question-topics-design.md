# Design: Topik untuk Bank Soal

**Tanggal:** 2026-09-08
**Status:** Disetujui, siap masuk tahap rencana implementasi
**Terkait:** `docs/superpowers/plans/2026-09-05-game-hardening-todolist.md` Priority 8 ("Tambah metadata `topic` ke soal" — item lama yang belum dikerjakan, direalisasikan di sini). Ini adalah **sub-project 1 dari 2** — sub-project 2 (memilih Topik saat membuat game) akan dirancang terpisah setelah fitur ini selesai dan bisa dipakai.

## Ringkasan

Guru bisa membuat "Topik" (daftar datar per guru, tidak berjenjang) untuk mengelompokkan bank soal. Satu soal masuk ke tepat satu Topik (atau tidak sama sekali — "Tanpa Topik"). Import DOCX bisa langsung menargetkan soal-soal hasil import ke satu Topik (pilih yang sudah ada, atau buat baru langsung dari form import). Menghapus Topik tidak menghapus soal di dalamnya — soal itu cuma kembali jadi "Tanpa Topik".

## Lingkup

**In scope:**
- Tabel & model baru untuk Topik.
- Buat Topik (dari panel bank soal, atau langsung dari form import).
- Hapus Topik (soal tidak ikut terhapus).
- Import DOCX menargetkan satu Topik.
- Filter daftar bank soal per Topik.
- Tampilkan nama Topik di tiap kartu soal.

**Out of scope (sengaja, untuk menjaga spec ini tetap fokus):**
- Form tambah-soal manual (satu-satunya jalur membuat soal tetap import DOCX, sama seperti sekarang) — topik lain terpisah.
- Rename Topik (belum diminta; gampang ditambah nanti kalau perlu, cukup 1 endpoint + 1 field).
- Topik berjenjang (Mata Pelajaran > Topik) — sudah diputuskan pakai daftar datar saja.
- Satu soal di banyak Topik — sudah diputuskan satu soal = satu Topik.
- Memilih Topik saat membuat game — itu sub-project 2, dirancang setelah ini selesai.

## Bagian A — Migrasi & Model

Migration baru `app/Database/Migrations/2026-09-08-000011_CreateQuestionTopics.php`:

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

Model baru `app/Models/QuestionTopicModel.php`:

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

`app/Models/QuestionModel.php`: tambah `'topic_id',` ke `$allowedFields` (setelah `'owner_teacher_id',`).

## Bagian B — Routes

Di `app/Config/Routes.php`, tambah setelah baris `teacher/questions/import-docx` yang sudah ada:

```php
$routes->post('teacher/topics', 'Teacher\QuestionTopicController::store', ['filter' => 'teacherAccess']);
$routes->post('teacher/topics/(:segment)/delete', 'Teacher\QuestionTopicController::destroy/$1', ['filter' => 'teacherAccess']);
```

## Bagian C — `QuestionTopicController`

File baru `app/Controllers/Teacher/QuestionTopicController.php`:

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

`resolveTopicId()` yang dipakai import (Bagian D) sengaja TIDAK ditaruh di sini — ia hanya relevan untuk alur import, jadi ditaruh sebagai method private di `QuestionController` sendiri (dekat `importDocx()`), bukan diekspos sebagai endpoint terpisah.

## Bagian D — Import DOCX Menargetkan Topik

`app/Services/Question/DocxQuestionImportService.php` — ubah signature `import()`:

```php
public function import(string $docxPath, int $teacherId, ?int $topicId = null): array
```

Teruskan `$topicId` ke `persist()`, dan di `persist()`'s `$questionModel->insert([...])`, tambah `'topic_id' => $topicId,` ke array yang di-insert (di antara field yang sudah ada, posisi tidak penting).

`app/Controllers/Teacher/QuestionController.php` — di `importDocx()`, sebelum baris yang memanggil `(new DocxQuestionImportService())->import(...)`, tambah resolusi topik:

```php
        $topicId = $this->resolveTopicId($teacherId);
```

Dan ubah baris pemanggilan importer dari:
```php
            $result = (new DocxQuestionImportService())->import($file->getTempName(), $teacherId);
```
menjadi:
```php
            $result = (new DocxQuestionImportService())->import($file->getTempName(), $teacherId, $topicId);
```

Tambah method private baru di `QuestionController`:

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

(`new_topic_name` diprioritaskan di atas `topic_id` yang dipilih — kalau guru mengisi nama topik baru, itu yang dipakai, dropdown diabaikan. Query topik yang dipilih di-scope ke `owner_teacher_id` supaya guru tidak bisa menaruh soal ke topik milik guru lain lewat parameter yang dimanipulasi.)

Tambah `use App\Models\QuestionTopicModel;` dan `use App\Services\Game\Uuid;` ke bagian atas `QuestionController.php`.

## Bagian E — Bank Soal: Panel Topik, Filter, dan Field Import

`QuestionController::index()` — replace the current method body:

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

(`$filterTopic['id'] ?? 0` when the `?topic=<uuid>` doesn't resolve to a real topic falls back to `where('topic_id', 0)`, which matches nothing — an unknown/tampered topic filter safely shows an empty list rather than silently showing all questions.)

Add `use App\Models\QuestionTopicModel;` to the top of `QuestionController.php` alongside the existing `use App\Models\QuestionModel;` line.

View `app/Views/teacher/questions/index.php` — tambah panel baru SEBELUM panel "Import Soal DOCX" yang sudah ada:

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

Di form import DOCX yang sudah ada, tambah field baru sebelum tombol submit `Import ke Bank Soal`:

```php
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
```

(Tidak perlu JS toggle show/hide — dua kontrol ditampilkan sekaligus dengan penjelasan teks, lebih sederhana untuk versi pertama; `resolveTopicId()` di Bagian D sudah menangani prioritas `new_topic_name` di atas `topic_id`.)

Tiap kartu soal — tambah baris nama topik setelah baris `source_type / question_type / difficulty / status` yang sudah ada:

```php
            <p class="muted">Topik: <?= esc($topicNames[$question['topic_id']] ?? 'Tanpa Topik') ?></p>
```

(`$topicNames` adalah map `topic_id => name` yang dikirim controller, dibangun dari `$topics` yang sudah di-fetch: `array_column($topics, 'name', 'id')`.)

## Testing

- `testCreateTopicPersistsForOwningTeacher` — buat topik, muncul dengan `owner_teacher_id` yang benar.
- `testImportDocxTagsQuestionsWithExistingTopic` — import dengan `topic_id` topik yang sudah ada, semua soal batch itu punya `topic_id` sama.
- `testImportDocxCreatesNewTopicFromInlineName` — import dengan `new_topic_name` diisi, topik baru otomatis terbuat dan soal batch itu ke-tag ke situ.
- `testDeleteTopicDetachesQuestionsWithoutDeletingThem` — hapus topik yang punya soal, soal tetap ada di `questions` tapi `topic_id` jadi `NULL`.
- `testDeleteTopicRejectsNonOwningTeacher` — guru B tidak bisa hapus topik guru A (redirect dengan error, topik tidak terhapus).
- `testQuestionTopicFilterQueryReturnsOnlyMatchingQuestions` — membangun query filter topik langsung lewat `QuestionModel` (bukan lewat HTTP), memverifikasi `->where('topic_id', $topicId)` dan `->where('topic_id', null)` masing-masing mengembalikan hasil yang benar.

**Konvensi test di project ini (dicek langsung, bukan asumsi):** tidak ada satu pun test yang men-simulasikan HTTP request penuh ke controller di seluruh codebase ini (`grep` untuk `FeatureTestTrait` di `app/` dan `tests/` tidak ada hasil) — semua test yang ada (`GameEngineHardeningTest`, `DocxQuestionImportServiceTest`) memanggil service/model secara langsung memakai `CIUnitTestCase` + `DatabaseTestTrait`, dan menaruh hasilnya di `tests/database/`. Test-test di atas mengikuti pola yang sama persis: file baru `tests/database/QuestionTopicTest.php`, memanggil `QuestionTopicModel`/`DocxQuestionImportService::import()`/`QuestionModel` secara langsung (bukan lewat route HTTP), tidak menambah dependency test-framework baru.
