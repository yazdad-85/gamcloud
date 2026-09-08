# Game Hardening Roadmap - Ruang Main Guru

**Tanggal:** 2026-09-05  
**Status:** In Progress  
**Scope:** Hardening aturan permainan, game feel, variasi papan, dan fondasi platform game kuis.

## Tujuan

Game harus tetap aman dan server-authoritative, tetapi terasa seperti permainan kelas yang hidup. Targetnya bukan hanya "ular tangga digital", melainkan platform game kuis yang bisa berkembang ke banyak mode permainan.

## Evaluasi Kondisi Saat Ini

Fondasi teknis sudah cukup baik:

- Room dibuat oleh guru.
- Tim join memakai PIN.
- Tim punya token session.
- Dadu, jawaban, skor, giliran, dan posisi dihitung di server.
- Snapshot tersedia untuk projector dan controller.
- Event log sudah ada.
- Idempotency key sudah dipakai pada aksi `roll` dan `answer`.
- Board template sudah menyimpan ular dan tangga dalam JSON.

Kelemahan utama:

- Alur masih terlalu linear: lempar dadu, jawab, maju/diam, pindah giliran.
- Timer soal belum ditegakkan kuat di backend.
- Giliran pertama masih berdasarkan urutan join.
- Papan masih terlihat seperti grid angka, belum seperti game ular tangga.
- Ular dan tangga belum divisualkan sebagai objek di papan.
- Pion masih berupa titik warna.
- Projector belum menjadi panggung utama permainan.
- Belum ada variasi tile, efek, tension, streak, bonus, atau penalti.

## Prinsip Hardening

1. Server tetap menjadi source of truth.
2. Client hanya mengirim intent: start, roll, answer, join.
3. Semua aturan penting dihitung di backend.
4. Projector menjadi layar drama utama kelas.
5. Controller tim dibuat ringan dan fokus untuk aksi.
6. Variasi game ditambahkan bertahap, bukan sekaligus.
7. Game harus tetap berjalan di perangkat sekolah yang tidak terlalu kuat.

## Phase 1 - Core Game Hardening

### Goal

Membuat aturan game lebih adil, konsisten, dan tidak mudah dimanipulasi.

### Specs

- Tambahkan mode penentuan giliran pertama:
  - `join_order`
  - `random`
  - `teacher_pick`
  - `opening_roll`
- Default disarankan: `random`.
- Backend wajib menolak jawaban yang melewati `question_deadline_at`.
- Tambahkan state timeout:
  - `QUESTION_TIMEOUT`
  - `TURN_COMPLETED`
- Tambahkan aturan finish:
  - `clamp_finish`: jika melebihi kotak akhir, tetap berhenti di finish.
  - `exact_finish`: harus pas; jika dadu lebih, pion memantul mundur.
- Event penting harus dicatat:
  - `turn.timeout`
  - `team.moved`
  - `tile.special_triggered`
  - `game.finished`

### To Do

- [ ] Tambah kolom `turn_order_mode` di `game_rooms`.
- [ ] Tambah kolom `finish_rule` di `game_rooms`.
- [ ] Update form create room agar guru bisa pilih mode giliran.
- [ ] Update form create room agar guru bisa pilih aturan finish.
- [ ] Update `GameEngine::start()` untuk mode giliran pertama.
- [ ] Tambah mekanisme `opening_roll` jika dipilih.
- [ ] Update `GameEngine::answer()` agar mengecek deadline.
- [ ] Tambah event timeout.
- [ ] Tambah test bukan giliran ditolak.
- [ ] Tambah test jawaban lewat deadline ditolak.
- [ ] Tambah test `random` first turn valid.
- [ ] Tambah test `exact_finish`.

## Phase 2 - Projector Game Feel

### Goal

Membuat layar projector terasa seperti panggung permainan, bukan dashboard monitoring.

### Specs

- Papan harus terlihat sebagai ular tangga:
  - Tangga digambar dari tile awal ke tile tujuan.
  - Ular digambar dari kepala ke ekor.
  - Start dan finish dibuat lebih menonjol.
  - Tile aktif diberi spotlight.
- Dadu harus tampil besar saat roll.
- Pion bergerak per kotak, bukan langsung lompat ke hasil akhir.
- Saat naik tangga, pion bergerak mengikuti arah tangga.
- Saat kena ular, pion turun mengikuti kurva ular.
- Saat jawaban benar/salah, projector menampilkan overlay event.
- Countdown harus terlihat jelas.
- Sepuluh detik terakhir diberi visual tension.

### To Do

- [ ] Refactor `renderBoard()` di `public/assets/app.js`.
- [ ] Tambah board layer SVG di atas grid.
- [ ] Simpan koordinat setiap tile di client untuk menggambar path.
- [ ] Buat renderer tangga SVG.
- [ ] Buat renderer ular SVG/canvas.
- [ ] Tambah animation queue untuk event.
- [ ] Tambah overlay event besar di projector.
- [ ] Tambah countdown visual.
- [ ] Tambah class CSS `tile-current`.
- [ ] Tambah class CSS `pawn-moving`.
- [ ] Tambah class CSS `event-overlay`.
- [ ] Tambah class CSS `countdown-danger`.

## Phase 3 - Theme And Character System

### Goal

Guru bisa memilih suasana permainan, dan tim tidak lagi hanya diwakili titik warna.

### Specs

- Board template punya `theme_json` yang lebih kaya:
  - `theme_key`
  - `name`
  - `palette`
  - `background`
  - `tile_style`
  - `snake_style`
  - `ladder_style`
  - `sound_pack`
- Tema awal:
  - `jungle_quest`
  - `space_mission`
  - `ocean_quest`
  - `city_challenge`
  - `lab_challenge`
- Avatar awal:
  - `robot`
  - `explorer`
  - `rocket`
  - `knight`
  - `scientist`
  - `runner`
- Avatar dibuat 2.5D ringan dulu.
- 3D penuh menjadi enhancement, bukan dependency utama.

### To Do

- [x] Update seed `board_templates`.
- [x] Update create room agar guru bisa pilih theme/board.
- [x] Update join form agar tim bisa pilih avatar.
- [x] Update snapshot agar avatar dan theme dikirim ke client.
- [ ] Tambah asset path `public/assets/avatars/`.
- [ ] Tambah asset path `public/assets/themes/`.
- [x] Render pion berdasarkan avatar, bukan hanya warna.
- [x] Tambah fallback avatar jika asset belum tersedia.

## Phase 4 - Special Tile Mechanics

### Goal

Menambah variasi dan ketegangan agar game tidak monoton.

### Specs

Jenis tile awal:

- `NORMAL`: tidak ada efek.
- `LADDER`: naik ke tile tujuan.
- `SNAKE`: turun ke tile tujuan.
- `BONUS`: tambah poin atau langkah.
- `TRAP`: mundur beberapa langkah.
- `DUEL`: tantang tim lain.
- `SAFE`: kebal satu kali dari ular.
- `MYSTERY`: efek acak ringan.

Semua efek harus dihitung backend. Client hanya merender hasil.

### To Do

- [x] Tambah `special_tiles_json` di `board_templates`.
- [x] Tambah `active_effects_json` di `game_teams` untuk efek sementara seperti perisai.
- [x] Update `applySpecialTile()`.
- [x] Tambah event `tile.special_triggered`.
- [x] Update snapshot agar client tahu tipe tile.
- [x] Update projector untuk ikon special tile.
- [x] Tambah test untuk `BONUS`.
- [x] Tambah test untuk `TRAP`.
- [x] Tambah test untuk `SAFE`.
- [x] Tambah test untuk `MYSTERY`.
- [x] Siapkan struktur `DUEL`.

## Phase 5 - Scoring And Tension

### Goal

Skor terasa kompetitif dan tidak hanya bergantung pada benar/salah.

### Specs

- Skor dasar:
  - Jawaban benar: +100.
  - Jawaban salah: 0 atau penalti opsional.
- Bonus:
  - Jawab cepat: +0 sampai +50.
  - Streak benar: +25, +50, +75.
  - Near-finish bonus untuk kotak 85 ke atas.
- Penalti opsional:
  - Salah: -25.
  - Timeout: -25.
- Leaderboard menampilkan:
  - Skor.
  - Posisi.
  - Streak.
  - Efek/kartu aktif.

### To Do

- [x] Tambah kolom `streak_count` di `game_teams`.
- [x] Tambah setting scoring di `game_rooms` atau config.
- [x] Hitung time bonus dari waktu menjawab.
- [x] Tambah `ScoreTransaction` type:
  - `TIME_BONUS`
  - `STREAK_BONUS`
  - `TIMEOUT_PENALTY`
  - `SPECIAL_TILE`
- [x] Update leaderboard UI.
- [x] Tambah test skor benar.
- [x] Tambah test skor salah.
- [x] Tambah test streak.
- [x] Tambah test timeout penalty.

## Phase 6 - Teacher Control Hardening

### Goal

Guru punya kendali kelas tanpa harus masuk database atau mengulang room.

### Specs

Guru bisa:

- Start game.
- Pause game.
- Resume game.
- Skip turn.
- Force timeout.
- Koreksi manual bila terjadi masalah kelas.
- Tampilkan/sembunyikan jawaban benar di projector.

Semua aksi guru wajib masuk event log.

### To Do

- [x] Tambah API `POST /api/v1/rooms/{room}/pause`.
- [x] Tambah API `POST /api/v1/rooms/{room}/resume`.
- [x] Tambah API `POST /api/v1/rooms/{room}/skip-turn`.
- [x] Tambah API `POST /api/v1/rooms/{room}/force-timeout`.
- [x] Tambah event teacher override.
- [x] Update teacher control page.
- [x] Tambah event `game.paused`.
- [x] Tambah event `game.resumed`.
- [x] Tambah event `turn.skipped`.
- [x] Tambah event `teacher.override`.
- [ ] Tambah test ownership.
- [x] Tambah test action rule.

## Phase 7 - Anti Abuse And Runtime Stability

### Goal

Game aman dipakai di kelas ramai dan cukup kuat untuk versi free.

### Specs

- Rate limit tetap dipisah:
  - Join.
  - State polling.
  - Roll.
  - Answer.
  - Resend email.
- Team session token wajib untuk roll dan answer.
- Idempotency wajib untuk POST penting.
- Room expired tidak boleh dipakai.
- Nama tim dibatasi panjangnya.
- Max team dibatasi.
- Guru free dibatasi kuota room.

### To Do

- [x] Tambah quota room aktif per guru.
- [x] Tambah quota room per hari/bulan.
- [x] Tambah command `php spark rooms:expire`.
- [x] Tambah command `php spark rooms:cleanup`.
- [ ] Tambah audit untuk join abnormal.
- [ ] Tambah audit untuk roll abnormal.
- [ ] Tambah audit untuk answer abnormal.
- [x] Tambah test room expired.
- [x] Tambah test quota free.

## Phase 8 - Multi Game Mode Roadmap

### Goal

Platform tidak terkunci pada ular tangga.

### Specs

Mode awal:

- `SNAKES_LADDERS`: mode sekarang.
- `QUIZ_RACE`: balapan track.
- `BOSS_BATTLE`: kelas melawan boss HP.
- `TREASURE_HUNT`: cari item di papan.
- `DUEL_ARENA`: duel cepat antar tim.

Arsitektur diarahkan ke game mode engine:

- `GameModeEngineInterface`
- `SnakesLaddersEngine`
- `QuizRaceEngine`
- `BossBattleEngine`

### To Do

- [x] Tambah `game_mode` di `game_rooms`.
- [x] Tambah `mode_state_json` di `game_rooms`.
- [x] Buat interface engine per mode.
- [x] Buat catalog mode game.
- [x] Siapkan engine aktif `SNAKES_LADDERS`.
- [x] Siapkan placeholder mode `QUIZ_RACE`.
- [x] Siapkan placeholder mode `BOSS_BATTLE`.
- [x] Siapkan placeholder mode `TREASURE_HUNT`.
- [x] Siapkan placeholder mode `DUEL_ARENA`.
- [x] Snapshot punya `mode_state`.
- [x] Projector memilih renderer berdasarkan mode.
- [x] Controller memilih aksi berdasarkan mode.
- [ ] Pisahkan logic board dari logic soal secara penuh.
- [ ] Aktifkan satu mode kedua sebagai vertical slice.

## Phase 8A - Bank Soal Import DOCX

### Goal

Guru bisa membawa bank soal dari dokumen Word yang sudah biasa mereka pakai, termasuk gambar pada stem soal maupun opsi jawaban, tanpa membuka akses upload yang berbahaya.

### Specs

- Import hanya menerima `.docx` maksimal 5 MB.
- Parser membaca isi DOCX sebagai ZIP dan XML, bukan mengeksekusi macro atau objek embedded.
- Gambar hanya diambil dari relasi aman di `word/media`.
- Gambar yang disimpan dibatasi:
  - JPG.
  - PNG.
  - GIF.
  - WEBP.
  - Maksimal 2 MB per gambar.
- Format soal awal:
  - Pilihan ganda dengan nomor soal dan opsi A-H.
  - True/false atau benar/salah.
  - Kunci jawaban melalui `Jawaban:` atau `Kunci:`.
  - Penanda benar langsung pada opsi seperti `*B.`, `(benar)`, atau `[x]`.
- Soal hasil import langsung masuk bank soal guru yang sedang login.
- Superadmin boleh memilih guru pemilik soal.
- Media soal dan opsi dikirim ke snapshot agar bisa tampil saat permainan.

### To Do

- [x] Tambah kolom `question_options.media_json`.
- [x] Buat `DocxQuestionImportService`.
- [x] Tambah endpoint import di bank soal guru.
- [x] Tambah form import DOCX.
- [x] Tampilkan media di bank soal.
- [x] Kirim media di snapshot game.
- [x] Tampilkan media di controller pemain.
- [x] Tambah test parsing pilgan, true/false, dan gambar.
- [x] Buat template DOCX resmi untuk guru.
- [x] Tampilkan jumlah soal yang dilewati karena format belum valid.
- [ ] Tambah preview hasil parsing sebelum simpan.
- [ ] Tambah detail laporan soal yang dilewati.

## Relasi Papan Dengan Soal

### Keputusan Utama

Papan sebaiknya tidak berubah mengikuti setiap soal satu per satu. Jika setiap pertanyaan mengubah tampilan papan, permainan akan terasa tidak stabil, sulit dipahami siswa, dan berat untuk perangkat sekolah.

Pendekatan yang lebih baik:

1. Papan dipilih saat room dibuat.
2. Papan mengikuti tema/materi game secara umum.
3. Tile atau zona papan dapat menentukan jenis/difficulty soal.
4. Soal tetap dipilih server berdasarkan aturan game.

### Model Yang Disarankan

#### Level 1 - Theme Based Board

Guru memilih tema papan saat membuat room.

Contoh:

- Materi IPA: `lab_challenge` atau `space_mission`.
- Materi sejarah: `city_challenge` atau `heritage_quest`.
- Materi umum: `jungle_quest`.

Papan tidak memilih soal, hanya memberi suasana.

#### Level 2 - Difficulty Zone

Area papan menentukan tingkat kesulitan soal:

- Kotak 1-30: `EASY`.
- Kotak 31-70: `MEDIUM`.
- Kotak 71-100: `HARD`.

Saat tim berada di zona tinggi, engine memilih soal yang lebih menantang.

Ini membuat tensi naik secara natural mendekati finish.

#### Level 3 - Tile Topic Mapping

Tile tertentu bisa punya topik.

Contoh:

- Tile bonus matematika: ambil soal kategori matematika.
- Tile lab: ambil soal kategori IPA.
- Tile duel: ambil soal cepat dengan waktu lebih pendek.

Untuk ini, bank soal perlu metadata tambahan:

- `subject`
- `grade_level`
- `topic`
- `category`
- `difficulty`

Saat ini model soal sudah punya `difficulty`, tetapi belum punya `subject/topic/category`. Jadi adaptasi papan ke soal perlu ditambahkan bertahap.

#### Level 4 - Adaptive Board

Papan bisa merekomendasikan jalur dan tantangan berdasarkan performa kelas:

- Jika banyak salah di topik tertentu, special tile memunculkan soal remedial.
- Jika tim terlalu unggul, soal lebih sulit.
- Jika tim tertinggal jauh, dapat comeback tile.

Ini fase lanjut. Jangan dikerjakan sebelum game feel dan core rules stabil.

### Rekomendasi Praktis

Untuk tahap terdekat, lakukan ini:

1. Guru memilih tema papan saat create room.
2. Engine memilih soal berdasarkan difficulty zona.
3. Tambahkan metadata `subject/topic` setelah bank soal CRUD/import sudah kuat.
4. Jangan ubah tampilan papan per soal.
5. Biarkan soal mempengaruhi challenge, bukan mengganti board secara mendadak.

### Status Implementasi

- [x] Room memakai semua soal `PUBLISHED` milik guru pemilik room.
- [x] Create room menampilkan ringkasan bank soal: total, EASY, MEDIUM, HARD.
- [x] Guru bisa memilih strategi soal:
  - `difficulty_zone`
  - `random`
- [x] `difficulty_zone` memilih soal berdasarkan posisi tim saat giliran dimulai:
  - Kotak 1-30: `EASY`.
  - Kotak 31-70: `MEDIUM`.
  - Kotak 71-100: `HARD`.
- [x] Jika stok difficulty yang diminta kosong, engine fallback ke soal published lain milik guru.
- [x] Snapshot mengirim ringkasan `question_bank` dan `question_selection`.
- [x] Controller pemain menampilkan tipe dan difficulty soal aktif.

## Urutan Eksekusi Yang Disarankan

1. Phase 1: Core game hardening.
2. Phase 2: Projector game feel.
3. Phase 3: Theme and character.
4. Phase 4: Special tile.
5. Phase 5: Scoring and tension.
6. Phase 6: Teacher control hardening.
7. Phase 7: Anti abuse and quota free.
8. Phase 8: Multi game mode.

## Definition Of Done

Game hardening tahap awal dianggap layak demo jika:

- Guru bisa memilih atau mengacak giliran pertama.
- Jawaban lewat waktu ditolak backend.
- Papan terlihat seperti ular tangga, bukan grid kosong.
- Ular dan tangga terlihat jelas di projector.
- Pion bergerak dengan animasi.
- Ada efek saat naik tangga dan kena ular.
- Countdown terlihat jelas.
- Ada winner scene.
- Semua aksi penting tercatat sebagai event.
- Test backend aturan utama lulus.
