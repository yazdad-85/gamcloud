# Bulk Delete di Bank Soal — Design Spec

Status: Approved (brainstorming), belum diimplementasi.

## Latar belakang

Bank Soal (`/teacher/questions`) saat ini hanya punya hapus satu-per-satu
(`QuestionController::delete()`). Tombol "×" di sidebar topik menghapus
**topiknya saja** — soal di dalamnya tidak ikut terhapus, hanya menjadi
"Tanpa Topik" (`QuestionTopicController::destroy()`).

Guru butuh cara membersihkan banyak soal sekaligus, terutama:
1. Membuang seluruh isi satu topik yang sudah tidak relevan (mis. hasil
   import yang salah).
2. Memilih beberapa soal spesifik (bisa lintas difficulty/status) untuk
   dihapus bersamaan.

## Keputusan dari sesi brainstorming

- **Kedua skenario dibutuhkan** — bukan salah satu saja.
- Saat menghapus semua soal di satu topik, **topiknya ikut terhapus**
  (bukan cuma dikosongkan) — sebagai aksi terpisah dari tombol "×" yang
  sudah ada, supaya tidak mengubah perilaku tombol lama yang mungkin
  sudah dikenal penggunanya.
- Multi-select via checkbox **dibatasi per halaman** (12 soal/halaman,
  sesuai pagination yang sudah ada) — tidak ada "pilih semua lintas
  halaman". Kebutuhan hapus banyak lintas halaman cukup diarahkan ke
  aksi "hapus semua di topik" di atas.

## Aturan keamanan (berlaku untuk kedua mekanisme)

- Reuse pola otorisasi yang sudah ada: `TenantContext::assertQuestionOwner()`
  dan `assertQuestionTopicOwner()` (guru hanya bisa mengelola miliknya
  sendiri; superadmin bisa semua — konsisten dengan delete satu-soal yang
  sudah ada, soal/topik yang bukan milik guru tsb dianggap "tidak
  ditemukan", bukan error izin, supaya tidak bocor informasi).
- Soal yang sedang dipakai game **aktif** (status room `PLAYING`/`PAUSED`)
  tidak boleh terhapus — aturan `QuestionBankService::assertNotUsedByActiveRoom()`
  yang sudah ada dipakai ulang, per-soal.
- Bulk delete bersifat **partial-success**: soal yang terhalang aturan di
  atas **dilewati**, bukan menggagalkan seluruh batch. Hasil akhir selalu
  melaporkan jumlah dihapus vs dilewati — konsisten dengan pola pesan yang
  sudah dipakai fitur import DOCX ("X soal masuk, Y dilewati").
- Rate limit baru untuk endpoint ini (pola sama seperti route
  delete/import yang sudah ada), supaya tidak disalahgunakan.

## Mekanisme 1 — Hapus semua soal di satu topik

### UI
- Tombol baru di `question-list-head` (`app/Views/teacher/questions/index.php`),
  **hanya muncul saat `topicFilter` menunjuk ke topik nyata** (bukan
  "Semua soal" atau "Tanpa topik"): `Hapus Topik + Semua Soalnya (N)` —
  N diambil dari `$questionCounts` yang sudah dihitung controller.
- Konfirmasi via `confirm()` browser (pola sama seperti tombol hapus topik
  yang sudah ada), teksnya menyebutkan jumlah soal dan bahwa topiknya
  ikut terhapus.

### Backend
- Route baru: `POST teacher/topics/(:segment)/delete-all` →
  `QuestionTopicController::destroyAll($topicUuid)`.
- Alur:
  1. `assertQuestionTopicOwner($topicUuid)` → dapat baris topik.
  2. Ambil semua `QuestionModel` dengan `topic_id` = topik ini.
  3. Panggil `QuestionBankService::deleteMany($questions)` (baru, lihat
     di bawah) → `['deleted' => int, 'skipped' => int]`.
  4. **Topik hanya ikut dihapus jika `skipped === 0`** (semua soal
     berhasil dihapus, topik benar-benar kosong). Kalau ada yang
     dilewati, topik **tetap ada** berisi soal yang tersisa — supaya
     tidak ada soal yang "menggantung" tanpa topik yang sebenarnya belum
     layak dihapus.
  5. Redirect ke `/teacher/questions` dengan pesan flash:
     - Semua berhasil: `Topik "X" dan N soal di dalamnya berhasil dihapus.`
     - Sebagian dilewati: `N soal dihapus. M soal dilewati karena sedang
       dipakai game aktif — topik "X" belum dihapus karena masih berisi
       M soal.`

## Mekanisme 2 — Pilih manual via checkbox, hapus terpilih (per halaman)

### UI
- Checkbox di setiap `<article class="question-row">` (value = `public_uuid`
  soal, name `question_uuids[]`), dibungkus dalam satu `<form>` yang
  melingkupi seluruh daftar soal di halaman itu.
- Checkbox "Pilih semua di halaman ini" di `question-list-head`,
  meng-toggle semua checkbox baris via JS kecil (vanilla JS, konsisten
  dengan `app.js` yang sudah ada — tidak perlu library baru).
- Bar aksi (baris tersembunyi by default, `class="hidden"`), muncul via
  JS begitu ≥1 checkbox tercentang: teks `"N soal dipilih"` + tombol
  submit `Hapus Terpilih`, submit-nya adalah tombol submit form yang
  sama (POST ke endpoint bulk-delete), dengan `onsubmit="return
  confirm(...)"`.
- Form ini juga membawa `topic` filter aktif (hidden input) supaya
  redirect kembali ke halaman/filter yang sama.

### Backend
- Route baru: `POST teacher/questions/bulk-delete` →
  `QuestionController::bulkDelete()`.
- Alur:
  1. Baca `question_uuids[]` dari POST (batasi maks. 12 — sama dengan
     ukuran halaman, mencegah payload direkayasa lebih besar dari yang
     seharusnya bisa dipilih di UI).
  2. Untuk tiap uuid: coba `TenantContext::assertQuestionOwner($uuid)` di
     dalam try/catch — uuid yang tidak ditemukan/bukan milik guru ybs
     di-*filter keluar* di sini (bukan dihitung sebagai "dilewati" yang
     dilaporkan ke user; ini beda dari penghitung `skipped` di langkah 3,
     yang khusus untuk soal valid tapi sedang dipakai game aktif).
     Mencegah payload hasil modifikasi manual mempengaruhi soal orang
     lain, tanpa memunculkan pesan error yang membingungkan untuk kasus
     yang seharusnya tidak terjadi lewat UI normal.
  3. Kumpulkan baris soal yang lolos langkah 2, panggil `deleteMany()`
     yang sama — inilah satu-satunya sumber angka `skipped` yang
     dilaporkan ke user.
  4. Redirect balik ke `topicRedirect()` (helper yang sudah ada) dengan
     pesan flash serupa: `"N soal dihapus. M soal dilewati karena sedang
     dipakai game aktif."` (atau tanpa kalimat kedua kalau M = 0).

## Perubahan service: `QuestionBankService::deleteMany()`

Method baru, dipakai oleh kedua mekanisme di atas:

```php
/** @param list<array<string,mixed>> $questions */
public function deleteMany(array $questions): array
{
    $deleted = 0;
    $skipped = 0;
    foreach ($questions as $question) {
        try {
            $this->delete($question); // reuse existing single-delete logic as-is
            $deleted++;
        } catch (DomainException) {
            $skipped++;
        }
    }

    return ['deleted' => $deleted, 'skipped' => $skipped];
}
```

Tidak dibungkus satu transaksi besar — supaya satu soal yang dilewati
(karena sedang dipakai game aktif) tidak ikut membatalkan penghapusan
soal lain yang valid dalam batch yang sama.

## Testing

- `tests/database/QuestionBankServiceTest.php`: test baru untuk
  `deleteMany()` — menghapus beberapa soal sekaligus, melewati yang
  sedang dipakai game aktif (reuse fixture pola yang sudah ada di test
  `assertNotUsedByActiveRoom`), memastikan hitungan `deleted`/`skipped`
  benar.
- `tests/database/QuestionTopicTest.php`: test baru untuk
  `QuestionTopicController::destroyAll()` — kasus semua soal berhasil
  dihapus (topik ikut hilang), dan kasus sebagian dilewati (topik tetap
  ada dengan soal sisa).
- Test baru (bisa di `QuestionBankServiceTest.php` atau file controller
  test baru) untuk `QuestionController::bulkDelete()` — memverifikasi:
  hanya uuid yang valid & milik guru ybs yang terhapus, uuid milik guru
  lain diabaikan, dan soal yang sedang dipakai game aktif dilewati.

## Di luar cakupan (sengaja tidak dibangun sekarang)

- Pilih lintas halaman ("select all N soal" tanpa batas jumlah).
- Bulk delete berdasarkan filter lain (difficulty/status/tanggal import)
  — saat ini index soal cuma punya filter topik; menambah filter baru
  di luar cakupan permintaan ini.
- Undo/soft-delete — soal yang dihapus, hilang permanen (sama seperti
  perilaku delete satu-soal yang sudah ada sekarang).
