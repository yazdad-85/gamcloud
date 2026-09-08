# Design: Soal HARD Ular/Tangga, No-Repeat Soal, Aturan Saat Join

**Tanggal:** 2026-09-08  
**Status:** Draft untuk review  
**Konteks produk:** Platform Gamifikasi Ular Tangga Edukatif (CI4)

---

## 1. Masalah

1. **Ular/tangga tanpa tantangan ilmu:** mendarat di ular/tangga saat ini langsung resolve lewat `applyBoardJump` tanpa soal penebusan/klaim, padahal spesifikasi master sudah mengarahkan ke redemption/HOTS.
2. **Soal berulang:** `selectQuestion()` memakai `array_rand` tanpa mengecualikan soal yang sudah dipakai di room → tim bisa mendapat pertanyaan yang sama berulang kali meskipun bank (mis. topik Hudud) berisi puluhan soal.
3. **Tidak ada narasi aturan:** halaman join hanya form PIN/nama; pemain tidak membaca aturan main (termasuk bahwa salah = menetap, ular/tangga punya soal HARD, pemenang = finish papan).

Keputusan produk (disepakati):

- Jawaban **salah/timeout pada soal reguler = menetap** (tidak maju). Bukan “gerak selalu jalan”.
- Soal tambahan **hanya** saat mendarat di **ular** atau **tangga** (setelah maju karena jawaban benar).
- Edukasi anti-tipu-tipu lewat **aturan permainan wajib dibaca saat join**, bukan lewat mengubah aturan gerak saat salah.

---

## 2. Tujuan

1. Setelah jawaban benar dan pion mendarat di kepala ular → wajib soal **HARD** penebusan: benar = bertahan; salah/timeout = turun.
2. Setelah jawaban benar dan pion mendarat di pangkal tangga → wajib soal **HARD** tantangan: benar = naik; salah/timeout = tetap di pangkal.
3. Seleksi soal **no-repeat per room**; jika pool habis → **recycle** + event log.
4. Halaman join menampilkan **aturan main** + checkbox persetujuan sebelum submit.

---

## 3. Alur Giliran (Authoritative)

```text
ROLL_READY
  → roll dadu (server)
  → pilih soal REGULER (difficulty zone / topik room, no-repeat)
QUESTION_ACTIVE
  → jawab / timeout
  → SALAH / timeout:
        skor 0 (atau aturan skor salah yang sudah ada)
        movement: from = to = posisi sekarang (menetap)
        TURN_COMPLETED
  → BENAR:
        skor + poin (+ bonus yang sudah ada)
        pion maju ke landed sesuai dadu + finish_rule
        lalu resolve tile:

        landed = kepala ULAR
          → SNAKE_REDEMPTION_ACTIVE (soal HARD, no-repeat)
          → benar  = bertahan di kepala ular (+ skor redemption)
          → salah/timeout = turun ke ekor ular

        landed = pangkal TANGGA
          → LADDER_CHALLENGE_ACTIVE (soal HARD, no-repeat)
          → benar  = naik ke ujung tangga (+ skor challenge)
          → salah/timeout = tetap di pangkal

        landed = MYSTERY / BONUS / TRAP / SAFE / biasa
          → perilaku tile existing (mystery choice, dll.)
          → tidak ada soal HARD tambahan kecuali ular/tangga

        → cek finish → TURN_COMPLETED / FINISHED
```

Server tetap sumber kebenaran untuk dadu, jawaban, gerakan, dan state.

---

## 4. State Machine (Tambahan)

State turn baru:

| State | Arti |
|---|---|
| `SNAKE_REDEMPTION_ACTIVE` | Soal HARD penebusan ular sedang aktif |
| `LADDER_CHALLENGE_ACTIVE` | Soal HARD klaim tangga sedang aktif |

Transisi relevan:

```text
QUESTION_ACTIVE + benar + landed snake head
  → SNAKE_REDEMPTION_ACTIVE
  → (jawab/timeout) → posisi final → TURN_COMPLETED | FINISHED

QUESTION_ACTIVE + benar + landed ladder base
  → LADDER_CHALLENGE_ACTIVE
  → (jawab/timeout) → posisi final → TURN_COMPLETED | FINISHED
```

Endpoint/aksi frontend:

- Jawaban reguler: tetap `answer` (atau setara) saat `QUESTION_ACTIVE`.
- Jawaban ular/tangga: endpoint baru atau aksi terbedakan (mis. `answerSpecial` / `answerRedemption`) yang hanya valid pada state di atas — agar tidak bisa submit opsi soal reguler ke fase HARD.

Deadline: pakai `question_time_seconds` room (boleh sama dengan reguler untuk MVP; `redemption_time_seconds` boleh dipakai khusus ular jika sudah ada di config).

---

## 5. Scoring

| Momen | Benar | Salah / timeout |
|---|---|---|
| Soal reguler | Poin + bonus existing | Menetap; skor mengikuti aturan salah existing (biasanya 0) |
| Penebusan ular (HARD) | Bertahan + redemption points (default **+75**) | Turun ke ekor; +0 |
| Tantangan tangga (HARD) | Naik + challenge points (default **+150**) | Tetap di pangkal; +0 |

Skor tetap terpisah dari posisi. **Pemenang utama = pertama sampai finish** (tidak diubah di desain ini).

---

## 6. No-Repeat Soal (A+C)

### Aturan

1. Lingkup: **satu room** (semua tim share pool pemakaian).
2. Setiap `question_id` yang pernah di-assign ke turn di room itu (reguler, ular, tangga, mystery) dianggap **terpakai**.
3. `selectQuestion(...)` hanya memilih dari kandidat yang **belum terpakai**, dengan filter teacher/topik/difficulty yang sama seperti sekarang.
4. Jika tidak ada kandidat tersisa untuk filter tersebut:
   - **Recycle:** anggap pool untuk filter itu kosong → pilih lagi dari set penuh filter (boleh mengulang).
   - Catat event `question.pool_recycled` dengan payload minimal: `room_uuid`, `difficulty` (nullable), `topic_ids`, `reason: exhausted`.
5. Fallback difficulty (jika HARD kosong tapi topik masih ada EASY/MEDIUM): ikuti perilaku fallback existing **setelah** exclude used; jika setelah exclude kosong lalu fallback juga kosong → recycle pada fallback set.

### Implementasi yang disarankan

- Helper `usedQuestionIdsForRoom(int $roomId): array` dari `game_turns.question_id` (non-null) di room itu.
- Opsional cache di `mode_state_json` / kolom room — tidak wajib MVP; query turns cukup selama volume kecil.

---

## 7. Aturan Permainan Saat Join

### UX

Halaman `/join` (dan `/join/{pin}`):

1. Judul + form existing (PIN, nama tim, avatar).
2. Blok **“Aturan Permainan”** (narasi singkat, readable di mobile).
3. Checkbox wajib: **“Saya sudah membaca dan memahami aturan permainan.”**
4. Tombol **Masuk** disabled sampai checkbox dicentang (progressive enhancement: validasi server juga menolak submit tanpa flag).

### Isi narasi (Bahasa Indonesia, MVP)

Ringkas, satu kolom, poin-poin:

- Ini permainan **ular tangga kuis**. Pemenang utama: tim yang **pertama sampai kotak finish**.
- **Skor** mengukur prestasi menjawab; skor tinggi tidak menggantikan juara papan.
- Lempar dadu → jawab soal. **Jawaban salah atau waktu habis: pion menetap.** Jawaban benar: pion maju sesuai dadu.
- Mendarat di **ular**: ada soal **sulit (HARD)** untuk menyelamatkan diri. Benar = bertahan; salah = turun.
- Mendarat di **tangga**: ada soal **sulit (HARD)** untuk naik. Benar = naik; salah = tetap di pangkal.
- Jawablah jujur sesuai pengetahuan — tipu-tipu merugikan belajar dan semangat fair play.
- Ikuti arahan guru di layar projector.

### Validasi server

- Field post mis. `rules_accepted=1` wajib; jika tidak ada → redirect back dengan error.

---

## 8. Dampak Frontend / FX

- Controller: setelah `answer.resolved` dengan movement menetap → tidak animasi jalan; dengan movement maju → animasi; jika state jadi redemption/challenge → tampilkan soal HARD kedua.
- Projector sequencer: sisipkan langkah efek ular/tangga + banner “Soal penyelamat ular” / “Soal klaim tangga” sebelum resolve posisi final.
- Event baru (contoh nama):
  - `snake.redemption_started` / `snake.redemption_resolved`
  - `ladder.challenge_started` / `ladder.challenge_resolved`
  - `question.pool_recycled`

Payload movement tetap menyertakan `from` / `landed` / `to` agar animasi konsisten dengan FX existing.

---

## 9. Testing

### Backend (PHPUnit, wajib)

- Benar + landed snake → state `SNAKE_REDEMPTION_ACTIVE` + soal HARD; benar → posisi tetap kepala; salah → posisi ekor.
- Benar + landed ladder → `LADDER_CHALLENGE_ACTIVE`; benar → ujung tangga; salah → pangkal.
- Salah reguler → posisi tidak berubah.
- `selectQuestion` tidak mengembalikan `question_id` yang sudah ada di turns room (sampai recycle).
- Setelah semua soal topik terpakai, pemilihan berikutnya memicu recycle (event atau flag terobservasi).
- Join tanpa `rules_accepted` ditolak (feature test / controller test bila ada harness HTTP; minimal unit pada validasi jika dipisah).

### Manual

- Join: checkbox wajib, narasi terbaca di mobile.
- Satu room, topik terbatas: pastikan tidak ada duplikat sampai pool habis; setelah habis boleh ulang.
- Ular & tangga end-to-end di projector + controller.

---

## 10. Out of Scope

- Mengubah definisi pemenang (tetap finish-first).
- “Always move even when wrong.”
- Redesign visual besar / Spline.
- Matching question type / PWA.
- Menulis ulang seluruh bank soal.

---

## 11. Decisions Log

| Topik | Keputusan |
|---|---|
| Gerak saat salah reguler | Menetap |
| Soal tambahan | Hanya ular & tangga, difficulty HARD |
| Ular benar/salah | Bertahan / turun |
| Tangga benar/salah | Naik / tetap pangkal |
| No-repeat | Per room + recycle saat habis (A+C) |
| Anti tipu-tipu | Narasi + checkbox aturan saat join |

---

## 12. Success Criteria

- Tidak ada tipu-tipu “salah supaya tidak kena ular” yang di-*encourage* oleh UI tanpa penjelasan; aturan tertulis di join.
- Ular/tangga selalu melibatkan soal HARD sebelum posisi final.
- Dalam satu room, soal tidak berulang sampai pool filter habis.
- Join menolak submit tanpa persetujuan aturan.
