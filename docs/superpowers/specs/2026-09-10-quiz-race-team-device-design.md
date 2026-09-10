# Design: Quiz Race Device per Tim - Balapan Serentak Multi-Ronde

**Tanggal:** 2026-09-10
**Revisi:** 2026-09-11
**Status:** Disetujui untuk menjadi dasar implementation plan
**Phase:** Lanjutan setelah `2026-09-10-quiz-race-sprint-tanpa-dadu-plan.md`
**Terkait:**
- `docs/superpowers/specs/2026-09-10-quiz-race-mode-design.md`
- `docs/superpowers/plans/2026-09-10-quiz-race-sprint-tanpa-dadu-plan.md`
- `app/Services/Game/GameEngine.php`
- `app/Services/Game/RaceTrackService.php`
- `app/Services/Game/Modes/QuizRaceModeEngine.php`

## Ringkasan

Phase 11 sudah membuat `QUIZ_RACE + TEACHER_CENTRALIZED` playable sebagai Sprint Tanpa Dadu. Phase ini menambahkan `QUIZ_RACE + TEAM_DEVICE` sebagai **Balapan Serentak Multi-Ronde**.

Semua tim menerima soal yang sama pada saat yang sama. Jawaban benar menggerakkan kendaraan, dan tim tercepat di antara jawaban benar mendapat bonus gerak. Permainan dibagi menjadi beberapa ronde; setiap ronde berisi beberapa siklus soal. Race langsung selesai setelah resolusi satu soal menghasilkan minimal satu tim di garis finish, walaupun alokasi soal belum habis.

## Istilah yang Dikunci

- **Game/race:** keseluruhan permainan sampai ada kendaraan mencapai finish atau seluruh alokasi soal habis.
- **Ronde:** satu tahap yang berisi beberapa soal, bukan satu soal.
- **Siklus soal:** satu soal bersama, satu deadline, satu jawaban per tim, lalu satu resolusi gerakan serentak.
- **Lap/checkpoint ronde:** penanda selesainya seluruh alokasi soal dalam satu ronde.
- **Hadiah ronde:** tambahan skor saja untuk juara ronde; tidak menambah langkah kendaraan.

Contoh konfigurasi utama:

| Ronde/Lap | Jumlah soal maksimum | Kesulitan |
|---|---:|---|
| 1 | 15 | Campuran EASY, MEDIUM, HARD |
| 2 | 15 | Campuran EASY, MEDIUM, HARD |
| 3 | 20 | Campuran EASY, MEDIUM, HARD |
| **Total** | **50** | |

Alokasi 50 soal adalah batas maksimum. Jika ada tim mencapai finish pada soal ke-9 Ronde 2, race selesai pada saat resolusi soal itu dan sisa soal tidak dimainkan.

## Arsitektur

Jangan memaksa `game_turns.team_id` atau `game_rooms.current_team_id` untuk permainan serentak. Dua mesin Quiz Race hidup berdampingan:

- `TEACHER_CENTRALIZED`: tetap memakai `game_turns`, `selectDifficultyTier()`, dan `answer()` dari Phase 11.
- `TEAM_DEVICE`: memakai `game_rounds`, `game_round_questions`, `game_round_answers`, dan endpoint siklus soal khusus.

Pembagian tanggung jawab:

- `RaceRoundService`: pembagian jumlah soal, jadwal difficulty, klasemen ronde, dan hadiah skor ronde.
- `RaceQuestionService`: resolusi jawaban dan gerakan satu siklus soal secara pure/DB-free.
- `RaceTrackService`: posisi, Boost, Oil Spill, clamp finish, dan tile generation yang sudah ada.
- `GameEngine`: transaksi, otorisasi, state transition, event, dan snapshot.

## Konfigurasi Room

Room `QUIZ_RACE + TEAM_DEVICE` menyimpan:

- `race_question_limit`, default `50`.
- `race_round_question_counts_json`, default `[15, 15, 20]`.
- `race_round_winner_bonus_points`, default `100`.
- `lap_count` selalu sama dengan jumlah item alokasi ronde.
- `max_position` tetap berasal dari panjang lintasan yang dipilih guru.
- `finish_rule` selalu `clamp_finish`.
- `near_finish_bonus` selalu `false`.

Validasi:

- Minimal 1 dan maksimal 5 ronde.
- Setiap ronde minimal 1 soal.
- Total alokasi minimal 3 dan maksimal 100 soal.
- `race_question_limit` harus sama dengan jumlah seluruh alokasi ronde.
- Default `[15, 15, 20]` menghasilkan total 50 soal dan 3 lap.

## Alur Game

1. Guru membuat room `QUIZ_RACE + TEAM_DEVICE` dan menentukan alokasi soal per ronde.
2. Tim join melalui PIN selama room masih `LOBBY`.
3. Guru menekan Start.
4. Engine membuat Ronde 1 dan Siklus Soal 1.
5. Semua device melihat soal dan deadline yang sama.
6. Setiap tim boleh submit satu jawaban.
7. Siklus ditutup saat semua tim menjawab, deadline habis, atau guru memilih `Tutup Soal`.
8. Engine menyelesaikan semua jawaban dan gerakan secara serentak.
9. Hasil soal ditampilkan singkat.
10. Jika ada tim mencapai finish, race selesai saat itu juga.
11. Jika belum finish dan jatah soal ronde belum habis, engine membuka soal berikutnya.
12. Jika jatah soal ronde habis, engine menghitung juara ronde, memberikan bonus skor, dan menampilkan checkpoint ronde.
13. Setelah checkpoint, ronde berikutnya dimulai otomatis.
14. Jika seluruh alokasi soal habis tanpa ada tim mencapai finish, fallback winner ditentukan dari posisi dan skor.

## Siklus Soal

Satu siklus soal memiliki tepat satu soal dan satu deadline bersama. Gerak dasar:

| Kondisi | Gerak |
|---|---:|
| Jawaban benar | +1 |
| Jawaban salah | +0 |
| Timeout/tidak menjawab | +0 |
| Tercepat di antara jawaban benar | +2 tambahan |

Tim tercepat yang benar bergerak total normal `+3`. Kecepatan tidak pernah menguntungkan jawaban salah.

Jika beberapa jawaban benar mempunyai `response_ms` yang benar-benar sama, semuanya mendapat bonus tercepat. Waktu dihitung dari epoch milidetik server; helper berbasis `time()` satu detik tidak boleh dipakai.

## Kesulitan Soal

Setiap ronde berisi campuran `EASY`, `MEDIUM`, dan `HARD`. Difficulty tidak lagi ditentukan dari posisi pemimpin.

Engine membuat `difficulty_schedule_json` ketika ronde dibuat. Distribusi dibuat seimbang, dengan selisih jumlah antar-difficulty maksimal satu. Untuk contoh:

- 15 soal: 5 EASY, 5 MEDIUM, 5 HARD.
- 20 soal: 7 EASY, 6 MEDIUM, 7 HARD.

Urutan diacak sekali di server lalu disimpan agar restart/polling tidak mengubah jadwal. Pemilihan soal tetap dibatasi pada topik room. Jika pool difficulty tertentu kosong, fallback ke soal published lain dalam topik terpilih; recycle baru dilakukan setelah seluruh pool relevan habis.

Riwayat soal terpakai wajib membaca `game_round_questions.question_id` selain `game_turns`.

## Ronde, Checkpoint, dan Hadiah

Ronde selesai hanya setelah seluruh siklus soal yang dialokasikan untuk ronde itu resolved **dan** resolusi terakhir tidak menghasilkan finisher. Mencapai garis finish selalu mengambil prioritas atas penyelesaian ronde: race langsung berhenti dan ronde aktif menjadi `ROUND_INTERRUPTED`, termasuk jika finish terjadi tepat pada soal terakhir ronde.

Juara ronde dihitung dari skor yang diperoleh **di ronde tersebut sebelum hadiah ronde**:

1. Skor ronde tertinggi.
2. Jika seri, jawaban benar terbanyak dalam ronde.
3. Jika masih seri, jumlah `response_ms` jawaban benar paling rendah.
4. Jika seluruh kriteria tetap sama, semua tim terkait menjadi co-winner ronde.

Setiap juara/co-winner ronde menerima tambahan skor `race_round_winner_bonus_points`, default `100`. Hadiah dicatat sebagai score transaction `RACE_ROUND_WINNER`. Hadiah tidak mengubah posisi, tidak memicu tile, dan tidak diberikan untuk ronde yang terhenti karena race sudah finish. Karena penentuan finisher dijalankan lebih dahulu, hadiah ronde tidak dapat mengubah pemenang race.

Checkpoint ronde adalah fase hasil/klasemen, bukan bonus langkah `+1`. Bonus checkpoint langkah milik Sprint Tanpa Dadu Phase 11 tetap tidak diubah.

## Skor dan Pemenang Race

Skor per siklus memakai konfigurasi room yang sudah ada:

- Jawaban benar: poin benar, default `100`.
- Time bonus: maksimal sesuai konfigurasi, dihitung dari `response_ms` jawaban itu.
- Streak bonus: tetap berlaku per tim dan direset pada awal setiap ronde agar kompetisi ronde adil.
- Jawaban salah dan timeout: mengikuti konfigurasi penalti room.
- Bonus tercepat `+2`, Boost, dan Oil Spill memengaruhi posisi, bukan skor.
- Hadiah juara ronde menambah skor saja.

Pemenang normal adalah tim yang mencapai `max_position` setelah resolusi suatu siklus soal. Tim dengan skor tinggi yang belum finish tidak dapat mengalahkan tim yang sudah finish.

Jika beberapa tim mencapai finish dalam resolusi yang sama:

1. Skor total tertinggi di antara para finisher.
2. Jika seri, jawaban benar total terbanyak.
3. Jika masih seri, jumlah `response_ms` jawaban benar paling rendah.
4. Jika seluruh kriteria sama, hasilnya co-winner.

Jika semua soal maksimum habis dan tidak ada tim mencapai finish:

1. Posisi tertinggi.
2. Skor total tertinggi.
3. Jawaban benar total terbanyak.
4. Jumlah `response_ms` jawaban benar paling rendah.
5. Co-winner jika masih identik.

Event `game.finished` membawa `finish_reason` (`TRACK_FINISH` atau `QUESTION_LIMIT`), `winner_team_uuids`, dan compatibility field `winner_team_uuid` berisi UUID pertama.

## Tile Khusus

Phase ini hanya memakai tile Phase 11 yang sudah stabil:

| Tile | Efek per siklus soal |
|---|---|
| Boost | Setelah posisi landed dihitung, langsung `+2` langkah dan clamp ke finish. |
| Oil Spill | Tim tetap menjawab soal berikutnya, tetapi tidak eligible mendapat bonus tercepat pada satu siklus soal berikutnya. |

Urutan resolusi:

1. Tentukan jawaban benar/salah/timeout.
2. Tentukan bonus tercepat setelah mengecualikan Oil Spill lock lama.
3. Hitung posisi landed dari langkah dasar + bonus tercepat.
4. Terapkan satu tile pada posisi landed.
5. Clamp posisi akhir ke `max_position`.
6. Naikkan penghitung soal resolved pada ronde.
7. Evaluasi finisher setelah seluruh tim dihitung.
8. Jika ada finisher, akhiri race sebelum evaluasi checkpoint/hadiah ronde.

Oil Spill lama dikonsumsi pada siklus itu. Jika tim mendarat di Oil Spill lagi pada siklus yang sama, lock baru disimpan untuk siklus berikutnya.

## State Machine

State ronde:

- `ROUND_ACTIVE`: masih mempunyai siklus soal yang berjalan/akan berjalan.
- `ROUND_COMPLETED`: kuota soal ronde habis tanpa finisher dan hadiah ronde sudah disimpan.
- `ROUND_INTERRUPTED`: race finish mengambil prioritas sebelum penyelesaian ronde, termasuk pada soal terakhir ronde.
- `ROUND_CLOSED`: checkpoint selesai dan ronde berikutnya sudah dibuat.

State siklus soal:

- `QUESTION_ACTIVE`: jawaban diterima.
- `QUESTION_RESOLVING`: atomic claim internal; jawaban baru ditolak.
- `QUESTION_RESOLVED`: hasil gerakan ditampilkan sampai `reveal_until`.
- `QUESTION_CLOSED`: siklus berikutnya atau checkpoint ronde sudah dibuat.

Transisi `QUESTION_ACTIVE -> QUESTION_RESOLVING` dan `QUESTION_RESOLVED -> QUESTION_CLOSED` harus berupa conditional update dalam transaksi. Insert jawaban melakukan conditional increment `answer_count` pada row siklus yang sama agar submit dan resolver terserialisasi. Idempotency key bukan pengganti lock transaksi.

## Model Data

Tambahkan konfigurasi room melalui migration:

- `race_question_limit` integer nullable.
- `race_round_question_counts_json` text nullable.
- `race_round_winner_bonus_points` integer nullable.

### `game_rounds`

- `id`, `public_uuid`, `room_id`, `round_number`.
- `state`.
- `question_target_count`, `question_resolved_count`.
- `difficulty_schedule_json`.
- `round_winner_team_ids_json`, `round_score_summary_json`.
- `started_at`, `completed_at`.
- `reveal_until`, `reveal_until_epoch_ms`, `paused_remaining_ms`.
- timestamps.

Constraint/index:

- Unique `public_uuid`.
- Unique `room_id + round_number`.
- Index `room_id + state`.

### `game_round_questions`

- `id`, `public_uuid`, `round_id`, `question_number`.
- `question_id`, `difficulty`, `state`, `answer_count`.
- `started_at`, `started_at_epoch_ms`.
- `deadline_at`, `deadline_epoch_ms`, `paused_remaining_ms`.
- `resolved_at`, `reveal_until`, `reveal_until_epoch_ms`.
- `fastest_team_ids_json`, `finisher_team_ids_json`, `movement_summary_json`.
- timestamps.

Constraint/index:

- Unique `public_uuid`.
- Unique `round_id + question_number`.
- Index `round_id + state`.

### `game_round_answers`

- `id`, `public_uuid`, `round_question_id`, `team_id`, `question_id`.
- `option_id`, `answer_text`, `is_correct`.
- `outcome`: `CORRECT`, `WRONG`, `TIMEOUT`.
- `answered_at`, `answered_at_epoch_ms`, `response_ms`.
- `score_delta`, `score_breakdown_json`.
- timestamps.

Constraint/index:

- Unique `public_uuid`.
- Unique `round_question_id + team_id`.
- Index `round_question_id + is_correct + response_ms`.

Saat resolve, setiap tim yang belum submit mendapat row sintetis `TIMEOUT`. Satu siklus resolved selalu memiliki tepat satu outcome per tim. Cleanup dilakukan eksplisit karena schema lama tidak mengandalkan cascade foreign key.

## API

Endpoint baru memakai istilah siklus soal, bukan ronde:

- `POST /api/v1/rooms/{roomUuid}/race-question/answer`
- `POST /api/v1/rooms/{roomUuid}/race-question/resolve`

`answer` memerlukan session tim valid. `resolve` memerlukan owner room dan `force: true`; tim yang belum menjawab menjadi timeout.

Scope idempotensi:

- `race-question-answer:{roomUuid}:{roundQuestionUuid}:{teamUuid}`
- `race-question-resolve:{roomUuid}:{roundQuestionUuid}`

Endpoint `roll`, `select-tier`, dan `answer` lama tetap khusus flow turn-based.

## Snapshot Publik

Tambahkan field secara additive:

```json
{
  "current_round": {
    "uuid": "...",
    "round_number": 1,
    "state": "ROUND_ACTIVE",
    "question_target_count": 15,
    "question_resolved_count": 4,
    "current_question": {
      "uuid": "...",
      "question_number": 5,
      "state": "QUESTION_ACTIVE",
      "question": {},
      "deadline_epoch_ms": 123,
      "answers": []
    }
  },
  "last_resolved_question": null,
  "last_completed_round": null
}
```

Saat soal aktif, payload hanya membuka siapa yang sudah menjawab. Option terpilih, correctness, dan response time tim lain tidak boleh muncul sebelum resolve. Hasil resolved tetap tersedia melalui `last_resolved_question` setelah soal berikutnya aktif agar reconnect tidak kehilangan feedback.

`mode_state` untuk Team Device:

```json
{
  "key": "QUIZ_RACE",
  "renderer": "quiz_race_track",
  "actions": ["race_question_answer"],
  "round_model": "multi_question_round",
  "can_answer": true
}
```

## Concurrency dan Idempotensi

- Conditional increment `answer_count` dan insert answer berada dalam satu transaksi.
- Hanya resolver yang berhasil mengubah state ke `QUESTION_RESOLVING` boleh mengubah posisi/skor.
- Advancement soal dan ronde memakai conditional transition dan unique number guard.
- Duplicate submit tanpa idempotency key menghasilkan response domain stabil, bukan HTTP 500.
- Event dan realtime tidak boleh mempublikasikan hasil transaksi yang rollback.
- Polling boleh memicu timeout/advancement hanya melalui atomic claim.

## Pause dan Resume

- Pause menyimpan sisa deadline soal atau sisa fase reveal ke `paused_remaining_ms` dan mengosongkan deadline aktif.
- Answer ditolak selama room `PAUSED`.
- Resume membangun deadline baru dari sisa waktu.
- `Skip Turn` dan `Start Timer` disembunyikan untuk Team Device.
- `Force Timeout` dilabeli `Tutup Soal` dan memanggil endpoint resolve dengan `force: true`.

## UI

### Create Game

- Quiz Race menyediakan `Tanpa Device` dan `Device per Tim`.
- Untuk Team Device, tampilkan editor alokasi ronde dengan default `15, 15, 20`.
- Total soal dihitung otomatis dari alokasi.
- `Jumlah Lap` mengikuti jumlah ronde dan tidak diedit terpisah.
- Warning bank soal membandingkan jumlah soal published topik terpilih dengan total alokasi.
- Field panjang lintasan dan tema tetap tersedia.

### Controller Tim

- Menampilkan `Ronde X/Y` dan `Soal A/B`.
- Tidak menampilkan dadu atau tombol difficulty.
- Setelah submit: `Jawaban terkirim, menunggu soal ditutup`.
- Setelah resolve: hasil jawaban, bonus tercepat, gerakan, tile, posisi, dan skor.
- Saat checkpoint: juara ronde dan bonus skor.

### Control Game dan Projector

- Menampilkan ronde, nomor soal, difficulty, countdown, dan jumlah tim menjawab.
- Tombol `Tutup Soal` hanya saat `QUESTION_ACTIVE`.
- Menampilkan klasemen checkpoint dan hadiah ronde.
- Race finish overlay muncul segera setelah resolusi yang mencapai finish.

## Integrasi Platform

- Riwayat pemilihan soal memasukkan `game_round_questions`.
- Proteksi edit/hapus soal memeriksa active/resolving round question.
- `deleteRoom()` membersihkan answers, round questions, rounds, dan idempotency key terkait.
- `GameReportService` menggabungkan jawaban turn-based dan round-based.
- Laporan memuat round/question number, outcome, response time, score breakdown, juara ronde, dan pemenang race.
- Projector dan laporan mendukung tie-break dan co-winner.

## Event

Event baru:

- `race.round_started`
- `race.question_started`
- `race.answer_submitted`
- `race.question_resolved`
- `race.round_completed`
- `race.round_interrupted`

Event lama yang tetap dipakai:

- `room.team_joined`
- `game.started`
- `tile.special_triggered`
- `game.finished`

`game.finished` adalah event kanonik akhir race; jangan membuat event akhir duplikat.

## Out of Scope

- Nitro.
- Pit Stop.
- Duel Susul.
- Editor tema/lintasan kustom penuh.
- Turnamen multi-room.

## Acceptance Criteria

- Guru dapat membuat Quiz Race Device per Tim dengan alokasi default 15/15/20.
- Ronde berisi beberapa siklus soal dan snapshot menampilkan nomor ronde/soal dengan benar.
- Setiap siklus menampilkan soal yang sama kepada semua tim dan menerima satu jawaban per tim.
- Benar `+1`, tercepat benar `+2`, salah/timeout `+0` gerak.
- Setiap ronde mempunyai campuran EASY/MEDIUM/HARD sesuai jadwal persisted.
- Selesai kuota ronde menghasilkan checkpoint, klasemen, dan bonus skor juara ronde.
- Hadiah ronde tidak mengubah posisi kendaraan.
- Race langsung selesai ketika resolusi soal menghasilkan finisher, walaupun ronde/total soal belum habis.
- Ronde yang terpotong finish berstatus `ROUND_INTERRUPTED` dan tidak memberi hadiah ronde.
- Finish pada soal terakhir ronde tetap menghasilkan `ROUND_INTERRUPTED`; terminasi race diproses sebelum checkpoint dan hadiah ronde.
- Jika beberapa tim finish bersamaan, tie-break skor, akurasi, dan waktu diterapkan.
- Jika semua soal habis tanpa finisher, fallback winner diterapkan.
- Resolve/submit/advancement aman dari request bersamaan dan tidak menggandakan gerak/skor.
- Pause/resume mempertahankan sisa waktu.
- Laporan dan penghapusan room mencakup seluruh data multi-ronde.
- Quiz Race Tanpa Device dan Ular Tangga tetap lulus regression suite.
