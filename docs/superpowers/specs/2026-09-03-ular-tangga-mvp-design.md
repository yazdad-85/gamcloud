# Design Spec - Ular Tangga Edukatif MVP

**Tanggal:** 2026-09-03  
**Status:** Implemented MVP baseline  
**Sumber:** `../MASTER-BACKEND-SPEC-Ular-Tangga-CI4-Pusher.md` dan `../MASTER-FRONTEND-SPEC-Ular-Tangga-CI4-Alpine-Pusher-Spline.md`

## Analisa Inti

Backend harus menjadi source of truth. Client hanya mengirim intent seperti join, start, roll, dan answer; nilai dadu, validasi jawaban, skor, posisi pion, ladder/snake, dan giliran dihitung di server.

Frontend membutuhkan tiga permukaan berbeda: teacher/admin UI, projector view, dan controller tim. Realtime Pusher disiapkan, tetapi MVP juga harus tetap berjalan dengan polling fallback supaya game tidak berhenti saat kredensial Pusher belum diisi.

## Keputusan MVP

| Area | Keputusan |
| --- | --- |
| Framework | CodeIgniter 4 appstarter |
| Database lokal | SQLite di `writable/ular_tangga.sqlite` untuk demo cepat |
| Database produksi | Schema disusun agar mudah dipindah ke MySQL/MariaDB |
| Auth | Shield dipasang; layar auth penuh ditunda setelah engine stabil |
| Realtime | `RealtimeService` menulis outbox dan publish ke Pusher jika kredensial tersedia |
| Projector | 2D fallback board sebagai baseline |
| Controller | Mobile-first dengan roll dan answer |
| State recovery | `GET /api/v1/rooms/{uuid}/state` |

## Domain MVP

- `teachers`
- `questions`
- `question_options`
- `board_templates`
- `game_rooms`
- `game_teams`
- `game_turns`
- `game_answers`
- `score_transactions`
- `game_events`
- `idempotency_keys`
- `realtime_outbox`

## State Flow MVP

```text
LOBBY -> PLAYING -> FINISHED
ROLL_READY -> QUESTION_ACTIVE -> TURN_COMPLETED -> ROLL_READY
```

MVP belum mengaktifkan `PAUSED`, `REDEMPTION`, import XLSX, laporan detail, dan Spline 3D. Struktur file sengaja disiapkan agar fitur tersebut masuk sebagai fase berikutnya tanpa memindahkan business logic ke controller/view.
