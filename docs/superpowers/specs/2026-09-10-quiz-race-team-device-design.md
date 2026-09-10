# Design: Quiz Race Device per Tim - Balapan Serentak

**Tanggal:** 2026-09-10
**Status:** Draft siap masuk rencana implementasi
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
7. Round selesai saat semua tim sudah menjawab atau deadline habis.
8. Engine menghitung gerak semua tim sekaligus.
9. Jika belum ada pemenang, round berikutnya dibuat otomatis.

State room tetap `PLAYING` selama balapan berjalan dan berubah ke `FINISHED` saat minimal satu tim mencapai `max_position`.

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

### Kesulitan Soal

Karena semua tim mendapat soal yang sama, kesulitan tidak dipilih per tim. Kesulitan round ditentukan otomatis dari posisi pemimpin saat round dibuat:

- Lap awal: `EASY`
- Lap tengah: `MEDIUM`
- Lap akhir: `HARD`

Implementasi memakai `RaceTrackService::lapForPosition()` sebagai dasar. Kalau bank soal untuk difficulty itu kosong, gunakan fallback `selectQuestion()` yang sudah ada: tetap dari topik terpilih, lalu recycle jika pool habis.

### Kotak Spesial Phase Ini

Phase ini tetap serasi dengan Phase 11 dan hanya memakai dua tile yang sudah stabil:

| Tile | Efek di Balapan Serentak |
|---|---|
| Boost | Setelah posisi landed dihitung, langsung +2 kotak tambahan. |
| Oil Spill | Tim tetap boleh menjawab round berikutnya, tapi tidak eligible mendapat bonus tercepat +2 pada round berikutnya. Setelah round berikutnya selesai, lock habis. |

Lap checkpoint tetap memberi +1 langkah saat tim melewati batas lap. Reward ini konsisten dengan Phase 11 dan tetap menjadi placeholder yang nanti bisa diganti Nitro.

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
- `state`: `ROUND_ACTIVE`, `ROUND_RESOLVED`
- `question_id`
- `difficulty`
- `started_at`
- `deadline_at`
- `resolved_at`
- `fastest_team_ids_json`
- `movement_summary_json`
- `created_at`
- `updated_at`

Constraint/index:

- Unique `public_uuid`.
- Unique `room_id + round_number`.
- Index `room_id + state`.

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
- `answered_at`
- `response_ms`
- `created_at`

Constraint/index:

- Unique `public_uuid`.
- Unique `round_id + team_id` supaya satu tim hanya menjawab sekali.
- Index `round_id + is_correct + response_ms`.

## API

Endpoint baru:

- `POST /api/v1/rooms/{roomUuid}/race-round/answer`
- `POST /api/v1/rooms/{roomUuid}/race-round/resolve`

`answer` dipakai device tim. Validasi memakai `TeamSessionService::assertTeamSession()`.

`resolve` boleh dipakai Control Game untuk force resolve setelah deadline atau untuk tombol "Tutup Ronde". Validasi memakai `TenantContext::assertRoomOwner()`. Engine juga boleh resolve otomatis dari `raceRoundAnswer()` saat semua tim sudah menjawab.

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
  }
}
```

Untuk device tim, jawaban benar/salah tim lain tidak dibuka sebelum round resolved. Yang aman ditampilkan saat active: siapa sudah menjawab dan countdown. Setelah resolved, tampilkan ringkasan gerak.

`mode_state` untuk `QUIZ_RACE + TEAM_DEVICE`:

```json
{
  "key": "QUIZ_RACE",
  "renderer": "quiz_race_track",
  "actions": ["race_answer"],
  "round_model": "shared_round",
  "current_round_state": "ROUND_ACTIVE"
}
```

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

## Realtime/Event

Event baru:

- `race.round_started`
- `race.answer_submitted`
- `race.round_resolved`
- `race.round_finished`

Event yang tetap dipakai:

- `room.team_joined`
- `game.started`
- `tile.special_triggered`
- `lap.checkpoint`
- `game.finished`

## Acceptance Criteria

- Guru bisa membuat room `Quiz Race` dengan `Device per Tim`.
- Tim bisa join lewat PIN dan membuka controller masing-masing.
- Start membuat round aktif dengan soal yang sama untuk semua device.
- Setiap tim hanya bisa menjawab sekali per round.
- Benar +1, tercepat benar +2, salah/timeout +0.
- Boost, Oil Spill, dan checkpoint +1 bekerja untuk round bersama.
- Jika tim mencapai finish, room menjadi `FINISHED`.
- Quiz Race Tanpa Device tetap berjalan seperti Phase 11.
- Ular Tangga Kuis tetap berjalan seperti sebelumnya.
