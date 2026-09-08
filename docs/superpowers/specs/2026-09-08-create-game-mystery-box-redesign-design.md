# Design: Perbaikan Create Game/Bank Soal + Redesign Mystery Box

**Tanggal:** 2026-09-08
**Status:** Disetujui, siap masuk tahap rencana implementasi
**Terkait:** `docs/superpowers/plans/2026-09-05-game-hardening-todolist.md` (Priority 10)

## Ringkasan

Review halaman `teacher/games/create` dan `teacher/questions` menemukan beberapa gap antara apa yang dikomunikasikan ke guru dan apa yang sebenarnya terjadi saat game berjalan, plus satu permintaan fitur baru: Mystery Box dibuat jadi kotak yang aktif diincar pemain (bukan cuma efek acak pasif), dengan opsi target diri sendiri atau tim lain, dan jumlah kotak Mystery per papan bisa diatur guru.

Dokumen ini merinci 8 perubahan yang disepakati, di-scope supaya masing-masing bisa diimplementasikan dan ditest secara independen selama urutannya dijaga (Bagian A harus lebih dulu dari G karena keduanya menyentuh helper posisi yang sama; Bagian G harus lebih dulu dari H).

## Lingkup

**In scope:**
- A. Soal dipilih berdasarkan kotak tujuan dadu, bukan posisi sebelum dadu dilempar.
- B. Zona difficulty proporsional terhadap ukuran papan aktual (bukan hardcode 1-30/31-70/71-100).
- C. Preview visual tema papan di form create game.
- D. Info jumlah kotak papan + penjelasan alur soal di form create game.
- E. Sembunyikan kotak DUEL dari papan sampai efeknya diimplementasikan.
- F. Peringatan di halaman bank soal untuk soal tanpa tag difficulty eksplisit.
- G. Guru mengatur jumlah kotak Mystery per room saat create game.
- H. Redesign perilaku Mystery Box: pilih target (diri sendiri/tim lain) → soal HARD → reward/hukuman, dengan efek boomerang saat gagal.

**Out of scope (dicatat untuk nanti, bukan bagian pekerjaan ini):**
- Implementasi penuh kotak DUEL (masih disembunyikan, bukan diaktifkan).
- Editor board template penuh untuk guru (bikin papan custom, ladder/snake sendiri).
- Efek Mystery Box berupa gangguan giliran (skip turn) — sudah diputuskan dibatasi ke poin+posisi saja untuk versi pertama.

---

## Bagian A — Soal Sesuai Kotak Tujuan Dadu

**Masalah:** `GameEngine::roll()` (`GameEngine.php:310-336`) memilih soal berdasarkan `$team['position']` (posisi SEBELUM dadu dilempar), padahal dadu sudah diketahui nilainya saat soal dipilih. Akibatnya level soal tidak sinkron dengan kotak yang sebenarnya dituju.

**Perbaikan:**
1. Ekstrak helper baru `private function computeLandedTile(int $from, int $dice, array $room): int` dari logika clamp/bounce yang sudah ada di `movementForCorrectAnswer()` (`GameEngine.php:721-733`, khusus bagian `$rolledTo`/`$finishBounced`/`$landed`, TANPA memanggil `applyBoardJump`/`applySpecialTileEffect` — itu tetap hanya jalan setelah jawaban benar).
2. `roll()` memanggil `computeLandedTile($team['position'], $dice, $room)` untuk dapat kotak tujuan, lalu itu yang dipakai di `targetDifficultyForTurn($room, $landedTile)` — ganti dari `$team['position']`.
3. `movementForCorrectAnswer()` direfaktor supaya memakai helper yang sama untuk `$landed`, menghindari duplikasi logika clamp/bounce.
4. Posisi pion tetap TIDAK berubah sampai jawaban benar — ini tidak diubah. Yang berubah hanya tingkat kesulitan soal yang ditampilkan.

**Dampak ke test yang ada:** Test yang menggunakan posisi tim yang sudah di-set manual sebelum roll (mis. `answerCorrectWithForcedMove`, `testDifficultyZoneSelectsMediumQuestionForMiddleBoard`) perlu dicek ulang — beberapa mungkin sudah forced-set posisi lalu roll dadu asli secara acak, sehingga assumption "posisi X pasti dapat soal difficulty Y" bisa berubah kalau dadu membawa ke zona lain. Test-test ini perlu direvisi supaya deterministik terhadap kotak tujuan, bukan kotak awal.

---

## Bagian B — Zona Difficulty Proporsional Terhadap Ukuran Papan

**Masalah:** `questionSelectionRules()` (`GameEngine.php:1129-1149`) hardcode zona `1-30 EASY / 31-70 MEDIUM / 71-100 HARD`, tidak membaca `room['max_position']`.

**Perbaikan:** Ubah `questionSelectionRules()` agar menerima `int $maxPosition` dan menghitung batas zona secara proporsional:
- EASY: `1` sampai `(int) round($maxPosition * 0.3)`
- MEDIUM: batas EASY + 1 sampai `(int) round($maxPosition * 0.7)`
- HARD: batas MEDIUM + 1 sampai `$maxPosition`

Untuk `max_position = 100` (semua board saat ini), ini menghasilkan batas yang **identik** dengan hardcode lama (30/70/100) — tidak ada perubahan perilaku untuk board yang ada, hanya jadi generik untuk `tile_count` lain di masa depan. `targetDifficultyForTurn()` juga disesuaikan agar posisi di luar rentang (harusnya tidak terjadi lagi) tidak fallback diam-diam ke `'HARD'`.

**Test baru:** zona difficulty benar untuk board dengan `tile_count` selain 100 (mis. 60 kotak → EASY 1-18, MEDIUM 19-42, HARD 43-60).

---

## Bagian C — Preview Tema Papan di Form Create Game

**Masalah:** Dropdown "Tema Papan" (`create.php:113-123`) hanya berupa `<select>` nama tema, guru tidak tahu tampilannya seperti apa.

**Perbaikan:** Tiap `board_templates.theme_json` sudah punya `palette` (warna `board`, `board2`, `tileA`, `tileB`, `accent`, `snake`, `ladder` — lihat `DemoGameSeeder.php:173-283`). Tidak perlu aset gambar baru:
- Ganti/lengkapi `<select>` dengan kartu radio per tema (atau tetap `<select>` + panel preview di sampingnya) yang menampilkan mini-swatch papan: grid kecil (mis. 4x4) berselang-seling warna `tileA`/`tileB`, garis aksen warna `accent`, dan dua garis kecil mewakili warna `snake`/`ladder`.
- Preview dibangun murni dari inline CSS/HTML berdasarkan `palette` yang sudah dikirim controller ke view (`GameController::create()` sudah mem-fetch daftar board template — pastikan `palette` ikut di-passing ke view).
- Update preview saat guru ganti pilihan tema (vanilla JS, tanpa reload).

---

## Bagian D — Info Ukuran Papan & Penjelasan Alur Soal

**Masalah:** Form create tidak pernah menyebut jumlah kotak papan, dan tidak menjelaskan bahwa soal muncul di setiap giliran (bukan hanya di kotak spesial).

**Perbaikan:**
- Tampilkan `tile_count` di bawah/di dalam kartu preview tema ("Papan {tile_count} kotak").
- Tambah kalimat penjelas di dekat opsi "Zona difficulty": "Soal muncul di setiap giliran lempar dadu, disesuaikan dengan kotak yang dituju dadu. Kotak BONUS/TRAP/SAFE/MYSTERY adalah efek tambahan yang berlaku setelah jawaban benar, bukan syarat munculnya soal."
- Tampilkan jumlah kotak papan juga di halaman detail room (`show.php`) di baris info yang sudah ada (baris `Mode: ... / Giliran pertama: ... / Finish: ...`).

---

## Bagian E — Sembunyikan Kotak DUEL

**Masalah:** Kotak DUEL dipajang ke guru/pemain tapi `applySpecialTileEffect()` mengembalikan `status: PENDING_IMPLEMENTATION` tanpa efek nyata (`GameEngine.php:820-831`).

**Perbaikan:** Di `boardSpecialTiles()` (`GameEngine.php:47-70`), keluarkan `'DUEL'` dari daftar tipe yang di-allow (`in_array($type, ['BONUS','TRAP','SAFE','MYSTERY','DUEL'])` → hapus `'DUEL'`) sehingga tile DUEL tidak muncul sama sekali di papan/snapshot sampai diimplementasikan penuh (item roadmap terpisah, di luar scope ini). Di seeder, entri DUEL (`DemoGameSeeder.php:49`) dibiarkan ada di data tapi otomatis tidak dirender karena filter di atas — atau opsional dihapus saja dari seed data supaya bersih.

---

## Bagian F — Peringatan Soal Tanpa Tag Difficulty

**Masalah:** `DocxQuestionImportService.php:211` default semua soal tanpa tag `[EASY]/[MEDIUM]/[HARD]` jadi `MEDIUM` secara diam-diam.

**Perbaikan:**
- Importer sudah tahu per-soal apakah difficulty berasal dari tag eksplisit atau default (`$difficulty` di `parseQuestion`-nya, sekitar baris 211-230) — tambah flag `'difficulty_explicit' => bool` ke hasil parse per soal.
- Setelah import selesai, hitung jumlah soal yang default (`difficulty_explicit === false`) dan tampilkan ringkasan di halaman `teacher/questions` ("X soal diimpor tanpa tag difficulty, otomatis dianggap MEDIUM — edit manual jika perlu"), mirip pola pesan "N soal dilewati" yang sudah ada (`Priority 8A` todolist item "Tampilkan jumlah soal yang dilewati").

---

## Bagian G — Guru Atur Jumlah Kotak Mystery per Room

**Masalah:** Jumlah & posisi kotak Mystery sepenuhnya ditentukan `board_templates.special_tiles_json` (seeder), guru tidak bisa mengubahnya.

**Perbaikan (tanpa migration baru):**
1. Tambah input jumlah kotak Mystery (0-6, default = jumlah MYSTERY yang ada di template terpilih, biasanya 2) di form create game, dekat pilihan tema papan.
2. Di `GameEngine::createRoom()`, setelah `$board` (template) di-resolve: hitung jumlah tile `MYSTERY` yang ada di `$board['special_tiles_json']`. Kalau sama dengan input guru, pakai `$board` apa adanya (perilaku existing, tidak ada perubahan data).
3. Kalau berbeda: bangun ulang array special tiles — ambil semua entri non-MYSTERY dari template, lalu tempatkan N entri MYSTERY baru di posisi acak yang valid (kotak `2..tile_count-1`, tidak boleh bentrok dengan posisi tile spesial lain, `ladders_json`/`snakes_json` `from`/`to`, atau satu sama lain). **Clone** row `board_templates` (nama, `tile_count`, `ladders_json`, `snakes_json`, `theme_json` sama; `special_tiles_json` baru) dengan `status = 'ROOM_INSTANCE'` (bukan `'ACTIVE'`, supaya tidak muncul di dropdown pilihan tema guru lain — query pemilihan tema sudah filter `where('status','ACTIVE')`). Simpan `board_template_id` room ke clone ini.
4. Tidak perlu kolom baru di `game_rooms` — jumlah kotak Mystery yang dipakai bisa dihitung langsung dari `special_tiles_json` board yang dipakai room itu (untuk ditampilkan di Bagian D / halaman detail).

**Test baru:** create room dengan `mystery_tile_count = 4` menghasilkan tepat 4 tile MYSTERY di board room tersebut, tidak bentrok dengan tile lain; `mystery_tile_count` sama dengan default template tidak membuat clone board baru (opsional, bisa diverifikasi lewat `board_template_id` tetap sama).

---

## Bagian H — Redesign Mystery Box

**Masalah/tujuan:** Saat ini mendarat di kotak MYSTERY langsung memicu 1-dari-3 efek acak (`applyMysteryEffect`, `GameEngine.php:840-897`), semuanya ke tim yang mendarat, tanpa pilihan atau ketegangan tambahan. Guru ingin kotak ini jadi incaran: tim yang mendarat memilih niat dulu (reward diri sendiri atau serang tim lain pilihannya), lalu harus menjawab soal HARD untuk mewujudkannya; gagal = efek negatif balik ke diri sendiri (boomerang).

### Alur pemain

1. Tim menjawab benar soal giliran normal → pion bergerak → mendarat di kotak MYSTERY (proses ini tidak berubah).
2. Giliran **belum selesai**. Client (team controller) menampilkan pilihan:
   - "Untuk timku" (reward), atau
   - "Serang tim lain" → lanjut memilih 1 tim lawan dari daftar tim aktif di room.
3. Begitu pilihan dikirim, sistem mengambil 1 soal **HARD** (pakai `selectQuestion($teacherId, 'HARD')` yang sudah ada) dan menampilkannya dengan deadline seperti soal biasa (`question_time_seconds` room).
4. Tim menjawab:
   - **Benar + pilih "untuk timku"** → REWARD ke diri sendiri: **+80 skor, maju 3 kotak** (clamp ke `max_position`; kalau jadi ≥ `max_position`, room langsung `FINISHED` seperti aturan finish yang sudah ada).
   - **Benar + pilih "serang tim lain"** → PUNISH ke tim lawan yang dipilih: **-60 skor, mundur 4 kotak** (minimum kotak 1).
   - **Salah, ATAU waktu menjawab habis** → PUNISH ke diri sendiri (boomerang), berapapun pilihan awalnya: **-60 skor, mundur 4 kotak** (minimum kotak 1).
   - **Tidak memilih niat sama sekali sampai waktu habis** → dianggap seperti timeout biasa (event `turn.timeout`), tidak ada efek sama sekali, giliran lanjut ke tim berikutnya. Tidak ada default pilihan otomatis.
5. Setelah efek diterapkan (ke diri sendiri atau ke lawan), giliran baru dianggap selesai dan pindah ke tim berikutnya, sama seperti alur normal.

### Pemisahan skor: langsung vs ditunda

Skor dari jawaban giliran normal (`basePoints`, `time_bonus`, `streak_bonus`, `near_finish_bonus`) tetap dihitung dan disimpan **langsung** di `answer()` seperti sekarang, tidak menunggu Mystery selesai. Yang ditunda hanya baris skor "tile khusus" (`SPECIAL_TILE`): kalau `movement['special'] === 'MYSTERY'`, `answer()` mengembalikan `score_delta = 0` untuk bagian itu (tidak ada baris `SPECIAL_TILE` yang dicatat saat ini), dan baris skor REWARD/PUNISH baru dicatat oleh `answerMystery()` setelah hasil diketahui.

### Perubahan state machine

Tambah 2 state baru di `game_turns.state` (mengikuti pola existing `ROLL_READY → QUESTION_ACTIVE → TURN_COMPLETED` / `QUESTION_TIMEOUT`):
- `MYSTERY_CHOICE_PENDING` — menunggu tim memilih niat (self/target lawan). Masuk state ini persis setelah `answer()` mendeteksi `movement.special === 'MYSTERY'` dan BATALKAN transisi ke `TURN_COMPLETED`/pindah tim yang biasanya terjadi di akhir `answer()` — tunda sampai mystery ini tuntas.
- `MYSTERY_QUESTION_ACTIVE` — soal HARD sudah tampil, menunggu jawaban.

**Migration baru:** tambah kolom nullable ke `game_turns`:
- `mystery_target_team_id` (INT, nullable, FK ke `game_teams.id`) — diisi saat tim memilih target lawan; NULL kalau pilih "untuk timku" atau belum memilih.
- `mystery_question_id` (INT, nullable) — soal HARD yang ditampilkan untuk mystery ini, dipakai `answerMystery()` untuk validasi opsi jawaban (pola sama seperti `turn.question_id` untuk soal biasa).

**Method baru di `GameEngine`:**
- `chooseMysteryTarget(string $roomUuid, string $teamUuid, string $target): array` — `$target` = `'SELF'` atau UUID tim lawan. Validasi: turn harus `MYSTERY_CHOICE_PENDING` dan milik tim ini; kalau target UUID, tim itu harus ada di room yang sama dan bukan tim itu sendiri. Set `mystery_target_team_id`, ambil soal HARD, set `mystery_question_id`, pindah state ke `MYSTERY_QUESTION_ACTIVE`, set deadline baru.
- `answerMystery(string $roomUuid, string $teamUuid, int $optionId, ?string $idempotencyKey = null): array` — validasi turn `MYSTERY_QUESTION_ACTIVE`, cek jawaban terhadap `mystery_question_id`. Terapkan REWARD/PUNISH sesuai aturan di atas (termasuk update skor+posisi tim TARGET yang bisa berbeda dari tim yang menjawab — butuh `GameTeamModel()->update()` kedua untuk tim lawan, plus `recordScore()` dan event terpisah untuk tim itu). Baru setelah ini turn selesai dan giliran pindah ke tim berikutnya (logika "next team"/"finish check" yang sudah ada di akhir `answer()` dipakai ulang lewat helper bersama, bukan diduplikasi).
- Timeout untuk kedua state baru ini ditangani lewat mekanisme `resolveTimedOutTurn`/pengecekan `isTurnExpired` yang sudah ada, diperluas untuk tahu bedakan "timeout saat memilih niat" (tidak ada efek) vs "timeout saat menjawab soal HARD" (boomerang, dianggap sama seperti jawaban salah).

**Event baru** (dicatat ke `game_events`, mengikuti pola event lain seperti `tile.special_triggered`):
- `mystery.target_chosen` — payload: tim, target (self/uuid lawan).
- `mystery.resolved` — payload: tim, target, benar/salah, efek yang diterapkan (skor & posisi kedua tim kalau target lawan).

**Perubahan client (projector/controller JS, `app.js`):**
- Team controller: setelah event `mystery.target_chosen` milik tim sendiri belum ada / setelah mendarat MYSTERY, render pilihan (2 tombol + daftar tim lawan) sebelum soal HARD muncul.
- Projector: overlay baru untuk tahap pilih ("Tim X sedang membuka Kotak Misteri...") dan tahap hasil (`mystery.resolved`) dengan tone sukses/gagal sesuai hasil — memperluas `overlayForEvent()`/`specialOverlay()` yang sudah ada, bukan bikin sistem overlay baru.

### Testing

- `chooseMysteryTarget` menolak target diri sendiri (harus pilih tim lain, bukan diri sendiri) dan target tim yang tidak ada di room.
- Reward ke diri sendiri saat jawaban benar (skor +80, posisi +3, event `mystery.resolved`).
- Punish ke tim lawan spesifik saat jawaban benar (skor lawan -60, posisi lawan -4, skor/posisi tim yang menjawab TIDAK berubah selain dari soal mystery ini).
- Boomerang ke diri sendiri saat jawaban salah, baik dari pilihan self maupun pilihan attack-lawan.
- Boomerang saat soal HARD timeout setelah target dipilih.
- Timeout biasa (tanpa efek) kalau tim tidak memilih niat sama sekali.
- Regression: seluruh 38 test yang sudah lulus sebelumnya tetap lulus setelah refactor Bagian A (khususnya test yang bergantung pada urutan lama `roll()`).

---

## Ringkasan Perubahan Data Model

| Tabel | Perubahan | Alasan |
|---|---|---|
| `game_turns` | + `mystery_target_team_id` (nullable int, FK) | Bagian H |
| `game_turns` | + `mystery_question_id` (nullable int) | Bagian H |
| `board_templates` | Tidak ada kolom baru; `status` sudah cukup fleksibel untuk nilai baru `'ROOM_INSTANCE'` | Bagian G |
| `game_rooms` | Tidak ada kolom baru | Bagian G, D |

Satu migration baru dibutuhkan (untuk `game_turns`).

## Urutan Pengerjaan yang Disarankan

1. Bagian A (fix urutan soal) — fondasi, kecil, banyak test existing bergantung padanya.
2. Bagian B (zona proporsional) — kecil, searah dengan A.
3. Bagian E, F (sembunyikan DUEL, peringatan difficulty) — kecil, independen.
4. Bagian C, D (preview tema, info papan) — UI murni, independen.
5. Bagian G (jumlah kotak Mystery) — perlu ada sebelum H supaya H bisa dites dengan board yang punya lebih dari 2 kotak Mystery.
6. Bagian H (redesign Mystery Box) — paling besar, bergantung pada G untuk testing penuh, dan pada A/B secara tidak langsung (semua berbagi `GameEngine`).
