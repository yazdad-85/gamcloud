# To Do List - Game Hardening Ruang Main Guru

**Tanggal dibuat:** 2026-09-05  
**Status:** In Progress  
**Roadmap terkait:** `2026-09-05-game-hardening-roadmap.md`

## Prinsip Kerja

- Kerjakan bertahap.
- Jangan memindahkan rule penting ke client.
- Backend tetap menjadi sumber kebenaran untuk dadu, timer, skor, posisi, tile effect, dan pemenang.
- Projector harus menjadi layar utama yang menarik untuk siswa.
- Controller tim harus ringan, jelas, dan tidak membingungkan.
- Setiap perubahan rule game harus punya test.

## Priority 0 - Baseline Check

- [x] Pastikan server lokal berjalan di `http://127.0.0.1:8090`.
- [ ] Pastikan login guru aktif.
- [ ] Pastikan create room bisa dilakukan guru.
- [ ] Pastikan tim bisa join memakai PIN.
- [ ] Pastikan roll dan answer masih berjalan.
- [x] Jalankan `./vendor/bin/phpunit`.
- [ ] Catat bug baseline sebelum hardening.

## Priority 1 - Core Game Rules

### 1.1 Turn Order

- [x] Tambah migration `turn_order_mode` di `game_rooms`.
- [x] Tambah pilihan mode giliran di form create room.
- [x] Simpan pilihan mode giliran saat create room.
- [x] Implement mode `join_order`.
- [x] Implement mode `random`.
- [ ] Siapkan struktur untuk mode `teacher_pick`.
- [ ] Siapkan struktur untuk mode `opening_roll`.
- [x] Update snapshot agar mode giliran terbaca client.
- [x] Tambah event `turn_order.selected`.
- [x] Tambah test start game dengan mode `join_order`.
- [ ] Tambah test start game dengan mode `random`.

### 1.2 Backend Timer Enforcement

- [x] Audit penggunaan `question_deadline_at`.
- [x] Update `GameEngine::answer()` agar menolak jawaban lewat deadline.
- [x] Tentukan rule timeout: salah otomatis atau turn hangus.
- [x] Implement state `QUESTION_TIMEOUT`.
- [x] Tambah event `turn.timeout`.
- [x] Pastikan next team berjalan setelah timeout.
- [ ] Tambah test jawaban sebelum deadline diterima.
- [x] Tambah test jawaban setelah deadline ditolak.
- [x] Tambah test timeout memindahkan giliran.

### 1.3 Finish Rule

- [x] Tambah migration `finish_rule` di `game_rooms`.
- [x] Tambah pilihan finish rule di create room.
- [x] Implement `clamp_finish`.
- [x] Implement `exact_finish`.
- [x] Untuk `exact_finish`, jika dadu melebihi finish, pion memantul mundur.
- [ ] Tambah event `finish.bounce`.
- [ ] Tambah test `clamp_finish`.
- [x] Tambah test `exact_finish`.

## Priority 2 - Projector Game Feel

### 2.1 Board Renderer

- [x] Refactor `renderBoard()` agar mudah ditambah layer visual.
- [x] Tambah wrapper board dengan grid layer dan overlay layer.
- [x] Hitung koordinat tengah setiap tile di client.
- [x] Render tile start lebih menonjol.
- [x] Render tile finish lebih menonjol.
- [x] Highlight current team tile.
- [x] Highlight recent movement tile.
- [x] Pastikan board tetap responsif di projector.

### 2.2 Snake And Ladder Visual

- [x] Buat renderer SVG untuk tangga.
- [x] Buat renderer SVG/canvas untuk ular.
- [x] Tangga harus menghubungkan tile `from` ke `to`.
- [x] Ular harus menghubungkan tile `from` ke `to`.
- [x] Ular punya kepala dan ekor yang jelas.
- [x] Tangga punya anak tangga yang jelas.
- [x] Pastikan angka tile tetap terbaca.
- [x] Pastikan pion tidak tertutup ular/tangga.

### 2.3 Movement Animation

- [x] Simpan posisi pion sebelumnya di client.
- [x] Deteksi event `answer.resolved`.
- [x] Animasi pion bergerak per kotak.
- [x] Animasi naik tangga.
- [x] Animasi turun karena ular.
- [x] Tambah efek shake saat kena ular.
- [x] Tambah efek glow saat naik tangga.
- [x] Pastikan animasi tidak rusak saat polling update masuk.

### 2.4 Event Overlay

- [x] Tambah overlay besar di projector.
- [x] Tampilkan hasil dadu.
- [x] Tampilkan benar/salah.
- [x] Tampilkan naik tangga.
- [x] Tampilkan kena ular.
- [x] Tampilkan winner scene.
- [x] Buat event queue agar overlay tidak saling tabrakan.

### 2.5 Countdown

- [x] Tampilkan countdown di projector.
- [x] Tampilkan countdown di controller tim.
- [x] Hitung countdown dari `deadline_at`.
- [x] Saat sisa waktu <= 10 detik, ubah visual menjadi danger.
- [x] Saat waktu habis, tampilkan status menunggu sistem/guru.

## Priority 3 - Theme And Avatar

### 3.1 Theme

- [x] Perluas struktur `theme_json`.
- [x] Tambah theme `jungle_quest`.
- [x] Tambah theme `space_mission`.
- [x] Tambah theme `ocean_quest`.
- [x] Tambah theme `city_challenge`.
- [x] Tambah theme `lab_challenge`.
- [x] Update seeder board template.
- [x] Update create room untuk memilih theme.
- [x] Kirim theme di snapshot.
- [x] Render warna board berdasarkan theme.

### 3.2 Avatar

- [x] Tentukan daftar avatar awal.
- [x] Tambah pilihan avatar di join form.
- [x] Simpan avatar ke `game_teams.avatar`.
- [x] Render avatar di projector.
- [x] Render avatar di controller.
- [x] Sediakan fallback jika avatar tidak valid.
- [x] Pastikan avatar tetap jelas pada layar kecil.

## Priority 4 - Special Tile

### 4.1 Data Model

- [x] Tambah `special_tiles_json` di `board_templates`.
- [x] Tambah `active_effects_json` di `game_teams`.
- [x] Tentukan format JSON special tile.
- [x] Update model allowed fields.
- [x] Update seeder.
- [x] Update snapshot.

### 4.2 Tile Effects

- [x] Implement `BONUS`.
- [x] Implement `TRAP`.
- [x] Implement `SAFE`.
- [x] Implement `MYSTERY`.
- [x] Siapkan struktur `DUEL`.
- [x] Catat semua efek ke event log.
- [x] Tambah score transaction untuk efek skor.
- [x] Tambah test tiap efek.

## Priority 5 - Scoring And Tension

- [x] Tambah `streak_count` di `game_teams`.
- [x] Tambah `scoring_json` di `game_rooms`.
- [x] Hitung streak benar.
- [x] Reset streak saat salah.
- [x] Reset streak saat timeout.
- [x] Tambah time bonus.
- [x] Tambah optional wrong penalty.
- [x] Tambah optional timeout penalty.
- [x] Tambah near-finish tension.
- [x] Update leaderboard agar menampilkan streak.
- [x] Update event label untuk bonus/penalti.
- [x] Tambah test skor dasar.
- [x] Tambah test time bonus.
- [x] Tambah test streak bonus.
- [x] Tambah test timeout penalty.

## Priority 6 - Teacher Control

- [x] Tambah API pause.
- [x] Tambah API resume.
- [x] Tambah API skip turn.
- [x] Tambah API force timeout.
- [x] Tambah tombol pause/resume di teacher control.
- [x] Tambah tombol skip turn di teacher control.
- [x] Tambah tombol force timeout di teacher control.
- [x] Pastikan semua API memakai ownership check.
- [x] Catat event `game.paused`.
- [x] Catat event `game.resumed`.
- [x] Catat event `turn.skipped`.
- [x] Catat event `teacher.override`.
- [ ] Tambah test teacher ownership.
- [ ] Tambah test superadmin bypass.

## Priority 7 - Anti Abuse And Free Quota

- [x] Tentukan kuota free awal.
- [x] Batasi jumlah room aktif per guru.
- [x] Batasi jumlah room dibuat per hari.
- [x] Batasi jumlah room dibuat per bulan.
- [x] Batasi panjang nama tim.
- [x] Batasi max team dari backend.
- [x] Cegah join ke room expired.
- [x] Cegah roll/answer ke room expired.
- [x] Tambah command `rooms:expire`.
- [x] Tambah command `rooms:cleanup`.
- [ ] Tambah audit log abuse ringan.
- [x] Tambah test quota.
- [x] Tambah test expired room.

## Priority 8 - Board And Question Relationship

- [x] Keputusan: papan tidak berubah per soal.
- [x] Keputusan: papan mengikuti tema/materi umum.
- [x] Keputusan: tile/zona boleh mempengaruhi difficulty soal.
- [ ] Tambah metadata `subject` ke soal.
- [ ] Tambah metadata `topic` ke soal.
- [ ] Tambah metadata `category` ke soal.
- [ ] Tambah metadata `grade_level` ke soal.
- [x] Tambah `question_selection_json` di `game_rooms`.
- [x] Tambah pilihan strategi pengambilan soal saat buat game.
- [x] Tampilkan ringkasan bank soal saat buat game.
- [x] Tampilkan ringkasan bank soal di detail/control game.
- [x] Tambah difficulty zone:
  - Kotak 1-30: `EASY`.
  - Kotak 31-70: `MEDIUM`.
  - Kotak 71-100: `HARD`.
- [x] Update `selectQuestion()` agar mempertimbangkan zona posisi.
- [x] Tambah fallback jika soal difficulty tertentu kosong.
- [x] Tampilkan difficulty soal aktif di controller pemain.
- [x] Tambah test pemilihan soal berdasarkan zona.

## Priority 8A - Bank Soal Import DOCX

- [x] Tambah migration `media_json` di `question_options`.
- [x] Buat importer DOCX berbasis `ZipArchive` tanpa dependency baru.
- [x] Parse format pilihan ganda dari nomor soal dan opsi A-H.
- [x] Parse format true/false atau benar/salah.
- [x] Dukung penanda jawaban benar: `*B`, `(benar)`, `[x]`, `[correct]`, dan `Jawaban/Kunci`.
- [x] Dukung difficulty inline: `[EASY]`, `[MEDIUM]`, `[HARD]`, `[MUDAH]`, `[SEDANG]`, `[SULIT]`.
- [x] Ekstrak gambar dari DOCX untuk stem soal.
- [x] Ekstrak gambar dari DOCX untuk opsi jawaban.
- [x] Batasi file import `.docx` maksimal 5 MB.
- [x] Batasi gambar hasil ekstraksi maksimal 2 MB per gambar.
- [x] Terima hanya gambar JPG, PNG, GIF, dan WEBP.
- [x] Blok relasi gambar yang mengarah keluar dari `word/media`.
- [x] Simpan gambar ke `public/uploads/question-imports/{teacherId}/{batchUuid}`.
- [x] Guru hanya bisa import ke bank soal miliknya.
- [x] Superadmin bisa memilih guru pemilik soal saat import.
- [x] Tampilkan gambar soal dan opsi di halaman bank soal.
- [x] Kirim media soal dan opsi ke snapshot game.
- [x] Tampilkan gambar soal dan opsi di controller pemain.
- [x] Tambah test importer DOCX untuk pilgan, true/false, dan gambar.
- [x] Rapikan test agar tidak meninggalkan file upload baru.
- [x] Buat template DOCX resmi untuk guru.
- [x] Tampilkan jumlah soal yang dilewati karena format belum valid.
- [ ] Tambah preview hasil parsing sebelum benar-benar menyimpan.
- [ ] Tambah laporan baris/soal yang gagal diparse.

## Priority 9 - Multi Game Mode Foundation

- [x] Tambah `game_mode` di `game_rooms`.
- [x] Tambah `mode_state_json` di `game_rooms`.
- [x] Buat `GameModeEngineInterface`.
- [x] Buat `GameModeCatalog`.
- [x] Buat adapter mode `SnakesLaddersModeEngine`.
- [x] Siapkan placeholder `QuizRaceModeEngine`.
- [x] Siapkan placeholder `BossBattleModeEngine`.
- [x] Siapkan placeholder `TreasureHuntModeEngine`.
- [x] Siapkan placeholder `DuelArenaModeEngine`.
- [x] Tambah pilihan mode game di create room.
- [x] Kunci mode yang belum playable agar tidak bisa membuat room rusak.
- [x] Tambah `mode_state` ke snapshot.
- [x] Projector memilih renderer berdasarkan mode.
- [x] Controller memilih aksi berdasarkan mode.
- [x] Tambah test mode aktif dan fallback mode belum playable.
- [ ] Extract penuh logic ular tangga dari `GameEngine` ke engine mode khusus.
- [ ] Aktifkan satu mode kedua sebagai vertical slice.

## Acceptance Checklist Tahap Awal

- [x] Guru bisa memilih mode giliran pertama.
- [x] Guru bisa memilih aturan finish.
- [x] Jawaban lewat waktu ditolak backend.
- [x] Papan projector terlihat seperti ular tangga sungguhan.
- [x] Ular dan tangga terlihat jelas.
- [x] Pion bukan titik polos.
- [ ] Dadu punya animasi yang terasa.
- [x] Pion bergerak per kotak.
- [x] Ada efek naik tangga.
- [x] Ada efek kena ular.
- [x] Ada countdown.
- [x] Ada winner scene.
- [ ] Semua aksi penting tercatat di event log.
- [x] Test backend lulus.

## Priority 10 - Evaluasi Create Game & Bank Soal (2026-09-08)

Ditemukan saat review UX halaman `teacher/games/create` dan `teacher/questions`. Sudah diverifikasi langsung ke kode (`GameEngine.php`, `create.php`, `DocxQuestionImportService.php`), bukan cuma dugaan dari tampilan.

### Temuan

- Soal muncul di **setiap giliran lempar dadu**, bukan hanya di kotak BONUS/TRAP/SAFE/MYSTERY/DUEL. Kotak spesial adalah efek tambahan yang berlaku setelah jawaban benar dan pion mendarat di kotak itu (`GameEngine.php:310-335`, `:372-436`, `:753-841`). Form create game tidak menjelaskan ini, sehingga label kotak BONUS/TRAP bisa disalahpahami sebagai syarat munculnya soal.
- Kotak `DUEL` masih stub (`status: PENDING_IMPLEMENTATION`, `GameEngine.php:829`) — dipajang ke teacher tapi tidak melakukan apa-apa saat kepijak.
- Jumlah kotak papan (`max_position`) berasal dari `board_templates.tile_count`, bukan input teacher, dan **tidak ditampilkan di mana pun** di form create maupun detail room (`create.php:113-123`). Semua tema board saat ini kebetulan 100 kotak (`DemoGameSeeder.php:141`), tapi ini konvensi seed data, bukan jaminan sistem — tidak ada UI untuk membuat/mengedit board template dengan ukuran lain.
- **Bug laten:** `questionSelectionRules()` hardcode zona difficulty `1-30 EASY / 31-70 MEDIUM / 71-100 HARD` (`GameEngine.php:1129-1149`), tidak membaca `max_position`/`tile_count` board yang sebenarnya dipakai. Belum termanifestasi sebagai bug nyata karena semua board masih 100 kotak, tapi akan salah diam-diam begitu ada board dengan ukuran berbeda (posisi di luar rentang yang diasumsikan jatuh ke fallback `'HARD'` di `GameEngine.php:1164`).
- Tidak ada form tambah-soal manual — satu-satunya jalur input soal adalah import DOCX. Soal yang tidak ditandai `[EASY]/[MEDIUM]/[HARD]` diam-diam default ke `MEDIUM` (`DocxQuestionImportService.php:211`), tanpa peringatan ke teacher.

### Rencana Perbaikan

- [x] Tampilkan jumlah kotak papan di form create game dan di halaman detail/control room.
- [x] Tambah teks penjelas eksplisit di form create: soal muncul tiap giliran, kotak BONUS/TRAP/dst adalah efek tambahan setelah jawaban benar, bukan syarat munculnya soal.
- [x] Ubah `questionSelectionRules()` agar zona difficulty dihitung proporsional terhadap `max_position` room, bukan hardcode 1-30/31-70/71-100.
- [x] Tambah test yang membuktikan zona difficulty tetap benar untuk board dengan `tile_count` selain 100.
- [x] Beri tanda "belum aktif"/sembunyikan kotak DUEL dari board sampai efeknya diimplementasikan.
- [x] Tampilkan peringatan di halaman bank soal/import kalau ada soal yang tidak ditandai difficulty (default diam-diam ke MEDIUM).

## Catatan Prioritas Implementasi

Urutan yang disarankan:

1. Core game rules.
2. Projector game feel.
3. Theme dan avatar.
4. Special tile.
5. Scoring tension.
6. Teacher control.
7. Anti abuse dan quota.
8. Multi game mode.

Jangan mulai 3D penuh sebelum projector 2D terasa menarik dan stabil.
