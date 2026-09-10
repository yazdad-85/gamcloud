# Design: Quiz Race Device per Tim - Balapan Serentak

**Tanggal:** 2026-09-10
**Status:** Direvisi setelah review implementasi, siap dijadikan acuan plan
**Phase:** Lanjutan setelah `2026-09-10-quiz-race-sprint-tanpa-dadu-plan.md`
**Terkait:**
- `docs/superpowers/specs/2026-09-10-quiz-race-mode-design.md`
- `docs/superpowers/plans/2026-09-10-quiz-race-sprint-tanpa-dadu-plan.md`
- `app/Services/Game/GameEngine.php`
- `app/Services/Game/RaceTrackService.php`
- `app/Services/Game/Modes/QuizRaceModeEngine.php`

## Ringkasan

Phase 11 sudah membuat `QUIZ_RACE` playable untuk `TEACHER_CENTRALIZED` sebagai Sprint Tanpa Dadu. Phase ini mengerjakan bagian yang sengaja ditunda: `QUIZ_RACE` untuk `TEAM_DEVICE`, disebut **Balapan Serentak**.

Balapan Serentak tidak memakai dadu dan tidak memakai giliran per tim. Semua tim yang join lewat PIN melihat soal yang sama pada waktu yang sama di perangkat masing-masing. Jawaban benar membuat tim maju, dan tim tercepat di antara jawaban benar mendapat bonus kecepatan. Ronde berulang sampai ada tim mencapai finish.

Keputusan arsitektur utama: phase ini menambah model **round bersama** baru, bukan memaksa `game_turns.team_id` atau `game_rooms.current_team_id` yang sekarang memang berarti "satu tim sedang giliran". Ini menjaga implementasi Phase 11 tetap stabil dan membuat dua mesin Quiz Race hidup berdampingan:

- `TEACHER_CENTRALIZED`: tetap memakai `game_turns`, `selectDifficultyTier()`, dan `answer()`.
- `TEAM_DEVICE`: memakai `game_rounds`, `game_round_answers`, dan endpoint khusus round.

## Istilah dan Hadiah

`Ronde` dan `lap` bukan hal yang sama:

- **Ronde** adalah satu siklus soal bersama: soal dibuka, semua tim menjawab, hasil dihitung, lalu hasil ditampilkan. Tim tercepat yang menjawab benar mendapat bonus kecepatan `+2` pada ronde tersebut.
- **Lap** adalah segmen lintasan. Batas antar-lap adalah **checkpoint**. Setiap tim mendapat bonus checkpoint `+1` ketika tim itu sendiri pertama kali melewati batas lap tersebut.
- Hadiah ronde diberikan berdasarkan kecepatan jawaban. Hadiah checkpoint diberikan berdasarkan progres masing-masing tim, bukan hanya kepada tim pertama yang mencapai lap.
- Satu checkpoint hanya boleh memberi hadiah sekali kepada tim yang sama. Bergerak mundur tidak ada di phase ini, sehingga checkpoint yang sudah dilewati tidak dapat dipanen ulang.

Dengan demikian, bukan "satu pemenang checkpoint setiap ronde". Dalam satu ronde bisa ada bonus tercepat dan, secara terpisah, satu atau beberapa tim dapat bonus checkpoint karena gerakannya melewati batas lap.

## Status Phase 11 yang Dipakai Ulang

Sudah ada dan harus dipakai ulang:

- `board_templates.game_mode`, `game_rooms.lap_count`, dan `game_turns.selected_tier`.
- `RaceTrackService` untuk track length, Boost, Oil Spill, lap, checkpoint, dan tile generation.
- Seed 3 tema lintasan: Stadion Atletik Senja, Arena Kartun Ceria, Arena Neon Digital.
- Create Game sudah punya field Quiz Race: tema lintasan, panjang lintasan, jumlah lap, warning bank soal.
- Controller tim (`/game/{room}/controller`) dan Control Game sudah punya runtime polling, answer list, countdown, event feed, dan board fallback `quiz_race_track`.

Yang perlu diubah dari Phase 11:

- Larangan `QUIZ_RACE + TEAM_DEVICE` dihapus.
- Saat Quiz Race dipilih, radio `Device per Tim` tidak lagi disabled.
- Mode state Quiz Race harus membedakan action berdasarkan `participation_mode`.

## Mekanik Balapan Serentak

### Ronde

Satu ronde memiliki satu soal dan satu deadline bersama.

Alur:

1. Guru membuat room `QUIZ_RACE` + `TEAM_DEVICE`.
2. Tim join lewat PIN seperti Ular Tangga biasa.
3. Guru klik Start.
4. Engine membuat round pertama dalam state `ROUND_ACTIVE`, memilih satu soal, dan memasang deadline.
5. Semua device tim melihat soal yang sama.
6. Tiap tim boleh submit jawaban sekali.
7. Round ditutup saat semua tim sudah menjawab, deadline habis, atau guru memilih force resolve.
8. Satu request memenangkan atomic claim `ROUND_ACTIVE -> ROUND_RESOLVING`; request lain tidak boleh menghitung gerakan lagi.
9. Engine menghitung dan menyimpan gerak semua tim sekaligus, lalu round menjadi `ROUND_RESOLVED` selama fase hasil singkat.
10. Jika belum ada pemenang, setelah fase hasil berakhir engine membuat round berikutnya secara otomatis.

State room tetap `PLAYING` selama balapan berjalan dan berubah ke `FINISHED` saat minimal satu tim mencapai `max_position`.

State round:

- `ROUND_ACTIVE`: soal dapat dijawab.
- `ROUND_RESOLVING`: lock internal sementara; tidak boleh ada jawaban atau resolver kedua.
- `ROUND_RESOLVED`: hasil dapat dilihat sampai `reveal_until`.
- `ROUND_CLOSED`: fase hasil selesai dan, jika room belum selesai, round berikutnya sudah dibuat.

Transisi `ROUND_ACTIVE -> ROUND_RESOLVING` dan `ROUND_RESOLVED -> ROUND_CLOSED` harus berupa conditional update di dalam transaksi. `Idempotency-Key` tetap dipakai, tetapi bukan pengganti lock transaksi.

### Skema Gerak

Gerak dasar:

| Kondisi | Gerak |
|---|---:|
| Jawaban benar | +1 |
| Jawaban salah | +0 |
| Tidak menjawab sampai deadline | +0 |
| Tercepat di antara jawaban benar | +2 tambahan |

Total normal tercepat benar = +3.

Jika ada seri tercepat yang benar, semua tim dengan waktu tercepat yang sama mendapat bonus +2. Ini menghindari keputusan arbitrer ketika dua submit masuk dalam waktu server yang sama.

Kecepatan dihitung dari `response_ms` berbasis waktu server dengan resolusi milidetik. Implementasi tidak boleh memakai helper `time()` yang hanya beresolusi satu detik. Round menyimpan epoch mulai/deadline dan answer menyimpan epoch submit agar hasil konsisten pada SQLite maupun MySQL.

### Kesulitan Soal

Karena semua tim mendapat soal yang sama, kesulitan tidak dipilih per tim. Kesulitan round ditentukan otomatis dari progres posisi pemimpin saat round dibuat:

- Progres `< 30%`: `EASY`.
- Progres `>= 30%` dan `< 70%`: `MEDIUM`.
- Progres `>= 70%`: `HARD`.

Progres dihitung dengan `(leader_position - 1) / (max_position - 1)` dan di-clamp ke `0..1`. Jika beberapa tim memimpin pada posisi yang sama, hasilnya tetap sama. Lap tetap dipakai sebagai checkpoint visual/progres; pembagian 30/40/30 memakai posisi agar tetap konsisten untuk semua nilai `lap_count` 1-10. Kalau bank soal untuk difficulty itu kosong, gunakan fallback `selectQuestion()` yang sudah ada: tetap dari topik terpilih, lalu recycle jika pool habis. Riwayat soal terpakai wajib membaca `game_rounds` selain `game_turns`.

### Kotak Spesial Phase Ini

Phase ini tetap serasi dengan Phase 11 dan hanya memakai dua tile yang sudah stabil:

| Tile | Efek di Balapan Serentak |
|---|---|
| Boost | Setelah posisi landed dihitung, langsung +2 kotak tambahan. |
| Oil Spill | Tim tetap boleh menjawab round berikutnya, tapi tidak eligible mendapat bonus tercepat +2 pada round berikutnya. Setelah round berikutnya selesai, lock habis. |

Lap checkpoint tetap memberi +1 langkah saat tim melewati batas lap. Reward ini konsisten dengan Phase 11 dan tetap menjadi placeholder yang nanti bisa diganti Nitro.

Urutan resolusi gerak per tim:

1. Hitung langkah dasar dari jawaban dan bonus tercepat.
2. Tentukan kotak landed.
3. Terapkan Boost atau Oil Spill pada kotak landed.
4. Jika perpindahan akhir memasuki lap baru, berikan bonus checkpoint `+1` satu kali dan clamp ke finish.
5. Bonus checkpoint tidak memicu tile khusus kedua secara berantai.

Oil Spill yang sudah aktif dibaca sebelum bonus tercepat dihitung, lalu dikonsumsi pada ronde itu. Jika tim mendarat pada Oil Spill lagi dalam ronde yang sama, lock baru disimpan untuk ronde berikutnya.

### Skor dan Streak

Gerakan dan skor adalah dua hal berbeda. Untuk menjaga laporan Phase 11 tetap serasi:

- Jawaban benar/salah memakai poin dasar dari konfigurasi room yang sudah ada.
- Timeout diperlakukan seperti jawaban salah untuk poin dan mereset streak.
- Time bonus, jika aktif, dihitung dari `response_ms` milik jawaban tersebut, bukan waktu saat round di-resolve.
- Streak bonus tetap per tim; tim yang salah atau timeout kembali ke streak 0.
- `near_finish_bonus` tetap nonaktif untuk semua Quiz Race.
- Bonus tercepat, Boost, dan checkpoint mengubah posisi tetapi tidak menambah poin kecuali nanti ada keputusan produk terpisah.

### Finish Bersamaan

Karena gerakan diselesaikan serentak, lebih dari satu tim dapat mencapai finish pada ronde yang sama. Semua tim tersebut adalah **co-winner**. Event `game.finished` membawa `winner_team_uuids` dan tetap membawa `winner_team_uuid` berisi UUID pertama sebagai compatibility field untuk consumer lama. Projector dan laporan harus dapat menandai seluruh co-winner.

### Out of Scope

Tetap ditunda agar phase ini tidak melebar:

- Nitro.
- Pit Stop.
- Duel Susul.
- Renderer lintasan kustom penuh.
- Turnamen multi-room.

## Model Data

Tambahkan dua tabel baru.

### `game_rounds`

Mewakili satu ronde bersama dalam room `QUIZ_RACE + TEAM_DEVICE`.

Kolom inti:

- `id`
- `public_uuid`
- `room_id`
- `round_number`
- `state`: `ROUND_ACTIVE`, `ROUND_RESOLVING`, `ROUND_RESOLVED`, `ROUND_CLOSED`
- `question_id`
- `difficulty`
- `answer_count`, default `0`
- `started_at`
- `started_at_epoch_ms`
- `deadline_at`
- `deadline_epoch_ms`
- `paused_remaining_ms`
- `resolved_at`
- `reveal_until`
- `reveal_until_epoch_ms`
- `fastest_team_ids_json`
- `winner_team_ids_json`
- `movement_summary_json`
- `created_at`
- `updated_at`

Constraint/index:

- Unique `public_uuid`.
- Unique `room_id + round_number`.
- Index `room_id + state`.
- Relasi logis `room_id -> game_rooms.id` dan `question_id -> questions.id`; cleanup eksplisit tetap wajib karena schema lama tidak mengandalkan cascade foreign key.

### `game_round_answers`

Mewakili jawaban satu tim untuk satu ronde.

Kolom inti:

- `id`
- `public_uuid`
- `round_id`
- `team_id`
- `question_id`
- `option_id`
- `answer_text`
- `is_correct`
- `outcome`: `CORRECT`, `WRONG`, `TIMEOUT`
- `answered_at`
- `answered_at_epoch_ms`
- `response_ms`
- `created_at`

Constraint/index:

- Unique `public_uuid`.
- Unique `round_id + team_id` supaya satu tim hanya menjawab sekali.
- Index `round_id + is_correct + response_ms`.
- Relasi logis ke round, team, question, dan option harus divalidasi di engine serta dibersihkan eksplisit saat room dihapus.

Ketika round di-resolve, engine membuat row `TIMEOUT` untuk setiap tim yang belum submit (`option_id`, `answer_text`, dan `response_ms` null). Dengan begitu satu round yang resolved selalu mempunyai tepat satu outcome per tim dan laporan dapat membedakan salah dari tidak menjawab. `answer_count` hanya menghitung submit nyata, bukan row timeout sintetis.

## API

Endpoint baru:

- `POST /api/v1/rooms/{roomUuid}/race-round/answer`
- `POST /api/v1/rooms/{roomUuid}/race-round/resolve`

`answer` dipakai device tim. Validasi memakai `TeamSessionService::assertTeamSession()`.

`resolve` dipakai Control Game untuk tombol "Tutup Ronde". Validasi memakai `TenantContext::assertRoomOwner()` dan mengirim `force: true`; tim yang belum menjawab dianggap timeout. Engine juga resolve otomatis tanpa flag force dari `raceRoundAnswer()` saat semua tim sudah menjawab atau dari polling saat deadline lewat.

Endpoint lama tetap:

- `roll` hanya untuk Ular Tangga.
- `select-tier` hanya untuk Quiz Race Tanpa Device.
- `answer` tetap untuk turn-based flow.

## Snapshot Publik

Snapshot perlu menambah key baru:

```json
{
  "current_round": {
    "uuid": "...",
    "state": "ROUND_ACTIVE",
    "round_number": 1,
    "question": {},
    "deadline_at": "...",
    "deadline_epoch_ms": 123,
    "answers": [
      {"team_uuid": "...", "answered": true, "is_correct": null}
    ],
    "fastest_team_uuids": []
  },
  "last_resolved_round": null
}
```

Saat `ROUND_RESOLVED`, `current_round` tetap menunjuk ronde hasil sampai `reveal_until`; belum ada soal baru yang dapat dijawab. Setelah round berikutnya aktif, `last_resolved_round` mempertahankan ringkasan ronde sebelumnya agar refresh/reconnect tidak kehilangan feedback.

Untuk device tim, jawaban benar/salah tim lain tidak dibuka sebelum round resolved. Yang aman ditampilkan saat active: siapa sudah menjawab dan countdown. Setelah resolved, tampilkan ringkasan gerak. Snapshot controller dapat memakai UUID tim dari session untuk memilih ringkasan miliknya, tetapi payload projector/owner tetap boleh melihat ringkasan seluruh tim setelah resolve.

`mode_state` untuk `QUIZ_RACE + TEAM_DEVICE`:

```json
{
  "key": "QUIZ_RACE",
  "renderer": "quiz_race_track",
  "actions": ["race_answer"],
  "round_model": "shared_round",
  "current_round_state": "ROUND_ACTIVE",
  "can_answer": true
}
```

## Concurrency dan Idempotensi

- Insert jawaban dilindungi unique `round_id + team_id`. Duplicate submit dengan idempotency key yang sama mengembalikan response pertama; duplicate tanpa key menghasilkan domain response stabil, bukan error 500.
- Sebelum insert, transaksi answer melakukan conditional increment `answer_count = answer_count + 1` hanya jika round masih `ROUND_ACTIVE` dan deadline belum lewat. Increment dan insert harus commit/rollback bersama. Ini menyerialkan answer dengan resolver pada row round yang sama.
- Resolver harus melakukan conditional update berdasarkan `id` dan state `ROUND_ACTIVE`. Hanya resolver dengan `affectedRows() === 1` yang boleh mengubah tim, skor, efek, dan room.
- Advancement setelah fase hasil memakai pola conditional update yang sama pada `ROUND_RESOLVED -> ROUND_CLOSED`, kemudian membuat round nomor berikutnya dalam transaksi yang sama.
- Jika transaksi gagal, state claim ikut rollback. Event/realtime tidak boleh mempublikasikan hasil yang belum committed.
- Unique `room_id + round_number` adalah guard tambahan, bukan mekanisme lock utama.

Scope idempotensi kanonik:

- `race-round-answer:{roomUuid}:{roundUuid}:{teamUuid}`
- `race-round-resolve:{roomUuid}:{roundUuid}`

Event `race.answer_submitted` hanya membawa identitas tim dan status sudah menjawab. Option, `is_correct`, dan response time tim tidak boleh dipublikasikan selama round masih active.

## Pause, Resume, dan Lifecycle

- Pause menyimpan sisa waktu round aktif ke `paused_remaining_ms`, mengosongkan deadline aktif, dan menolak answer selama room `PAUSED`.
- Resume membangun deadline baru dari sisa waktu tersebut. Fase hasil yang sedang berjalan juga harus mempertahankan sisa durasi reveal atau secara eksplisit diselesaikan sebelum pause.
- `Skip Turn` dan `Start Timer` tidak tersedia untuk shared-round Quiz Race.
- `Force Timeout` pada UI diganti label kontekstual `Tutup Ronde` dan memanggil endpoint resolve dengan `force: true`.
- Polling boleh memicu resolve deadline atau advancement reveal, tetapi selalu melalui atomic claim; GET tidak boleh melakukan mutasi tanpa guard tersebut.

## Integrasi Data Platform

- `usedQuestionIdsForRoom()` memasukkan `game_rounds.question_id` agar recycle pool tetap benar.
- Proteksi edit/hapus soal aktif memasukkan question pada active/resolving race round.
- `deleteRoom()` menghapus `game_round_answers`, `game_rounds`, dan idempotency key race sebelum menghapus team/room.
- `GameReportService` menggabungkan jawaban turn-based dan round-based ke bentuk laporan yang sama.
- Laporan, projector, leaderboard, dan event `game.finished` mendukung co-winner.

## UI

### Create Game

Saat mode `Quiz Race` dipilih:

- `Tanpa Device (Terpusat)` tetap tersedia.
- `Device per Tim` juga tersedia.
- Field lintasan yang sudah ada tetap dipakai untuk dua participation mode.
- Warning bank soal disesuaikan:
  - Tanpa Device: `jumlah_tim x ceil(panjang_lintasan / 2)`.
  - Device per Tim: `ceil(panjang_lintasan / 1.5)`.

### Controller Device Tim

Untuk `QUIZ_RACE + TEAM_DEVICE`:

- Sembunyikan tombol Lempar Dadu.
- Sembunyikan tombol EASY/MEDIUM/HARD.
- Tampilkan soal aktif round bersama.
- Tim bisa menjawab sekali.
- Setelah menjawab, device menampilkan status "Jawaban terkirim, menunggu ronde selesai".

### Control Game Guru

Untuk `QUIZ_RACE + TEAM_DEVICE`:

- Tampilkan status round: nomor round, jumlah tim sudah menjawab, deadline.
- Tampilkan tombol "Tutup Ronde" hanya jika round masih active.
- Tidak ada roster manual, karena tim join lewat PIN.
- Tidak ada Start Timer manual per soal; round langsung punya deadline ketika dibuat.
- Saat hasil ronde ditampilkan, tombol jawab nonaktif dan ringkasan gerak terlihat sebelum ronde berikutnya.

## Realtime/Event

Event baru:

- `race.round_started`
- `race.answer_submitted`
- `race.round_resolved`

Event yang tetap dipakai:

- `room.team_joined`
- `game.started`
- `tile.special_triggered`
- `lap.checkpoint`
- `game.finished`

`race.round_resolved` memuat movement summary dan daftar fastest team. Penyelesaian seluruh game tetap memakai satu event kanonik `game.finished`; tidak ada event duplikat `race.round_finished`.

## Acceptance Criteria

- Guru bisa membuat room `Quiz Race` dengan `Device per Tim`.
- Tim bisa join lewat PIN dan membuka controller masing-masing.
- Start membuat round aktif dengan soal yang sama untuk semua device.
- Setiap tim hanya bisa menjawab sekali per round.
- Benar +1, tercepat benar +2, salah/timeout +0.
- Boost, Oil Spill, dan checkpoint +1 bekerja untuk round bersama.
- Round hanya di-resolve sekali walaupun jawaban terakhir, polling, dan force resolve datang bersamaan.
- Jawaban yang balapan dengan penutupan deadline tidak pernah tersimpan pada round yang sudah di-resolve.
- Hasil round terlihat sebelum soal berikutnya aktif dan tetap tersedia setelah reconnect.
- Pause/resume mempertahankan sisa waktu round.
- Tombol Tutup Ronde dapat force resolve sebelum deadline dan mencatat tim yang belum menjawab sebagai timeout.
- Pemilihan soal tidak mengulang sebelum pool round-based habis.
- Jika tim mencapai finish, room menjadi `FINISHED`.
- Semua tim yang mencapai finish pada resolusi yang sama dicatat sebagai co-winner.
- Laporan room memuat jawaban, statistik soal, skor, dan pemenang Quiz Race Team Device.
- Menghapus room membersihkan seluruh row round dan idempotency terkait.
- Quiz Race Tanpa Device tetap berjalan seperti Phase 11.
- Ular Tangga Kuis tetap berjalan seperti sebelumnya.
