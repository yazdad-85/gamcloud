# Design: Mode Game Quiz Race (Balapan Kuis)

**Tanggal:** 2026-09-10
**Status:** Disetujui (brainstorming), siap masuk tahap rencana implementasi
**Terkait:** `app/Services/Game/Modes/QuizRaceModeEngine.php` (placeholder yang sudah ada, `isPlayable() = false`), `docs/superpowers/specs/2026-09-10-mode-tanpa-device-design.md` (pola state giliran manual-start yang dipakai ulang di sini)

## Ringkasan

`GameModeCatalog` sudah menyiapkan slot untuk `QUIZ_RACE` sejak awal (label "Balapan cepat berbasis soal tanpa ular dan tangga"), tapi masih `PlannedModeEngine` — belum ada papan, belum ada aturan main. Dokumen ini merancang tahap kedua platform: mode kedua yang benar-benar bisa dimainkan setelah Ular Tangga Kuis.

Bedanya dari Ular Tangga bukan sekadar ganti kulit papan: **Quiz Race menghilangkan dadu sepenuhnya** dan menggantinya dengan mekanisme yang benar-benar berbeda tergantung `participation_mode` room:

- Room `TEAM_DEVICE` (tiap tim pegang HP/laptop sendiri) → **Balapan Serentak**: semua tim dapat soal yang sama di waktu yang sama, kecepatan menjawab menentukan seberapa jauh melaju.
- Room `TEACHER_CENTRALIZED` (Mode Tanpa Device) → **Sprint Tanpa Dadu**: tetap bergiliran seperti sekarang, tapi tim memilih sendiri tingkat kesulitan soal — semakin berani, semakin jauh melaju kalau benar.

Konsep ini muncul dari sesi brainstorming interaktif (termasuk mockup bentuk lintasan & nuansa projector) — semua keputusan di bawah sudah dikonfirmasi user secara eksplisit.

## Lingkup

**In scope:**
- A. `QuizRaceModeEngine` jadi mode kedua yang `isPlayable() = true`, dengan papan lintasan lurus (bukan grid 10×10).
- B. Dua mesin gameplay berbeda dalam satu mode, dipilih otomatis dari `participation_mode` room (bukan pilihan terpisah guru) — lihat Bagian B.
- C. Sistem lap: lintasan dibagi beberapa lap, tiap lap = zona kesulitan soal + checkpoint sorotan di projector — lihat Bagian C.
- D. Lima kotak spesial versi balapan (pengganti BONUS/TRAP/SAFE/MYSTERY Ular Tangga) — lihat Bagian D.
- E. Peringatan (bukan blokir) saat panjang lintasan + jumlah lap yang dipilih guru berisiko melebihi kapasitas bank soal topik terpilih — lihat Bagian E.
- F. Katalog 3 tema visual lintasan (Stadion Atletik Senja, Arena Kartun Ceria, Arena Neon Digital), dipilih guru saat membuat room, pola yang sama seperti "Tema Papan" Ular Tangga sekarang.

**Out of scope (dicatat, bukan bagian pekerjaan ini):**
- Mode game lain yang masih placeholder (`BOSS_BATTLE`, `TREASURE_HUNT`, `DUEL_ARENA`) — belum digarap.
- Editor kustom lintasan/tema oleh guru (jumlah kotak & lap tetap bisa diatur, tapi tema visual & jenis kotak spesial adalah preset, bukan builder bebas seperti board template Ular Tangga).
- Migrasi/konversi room `SNAKES_LADDERS` yang sudah berjalan ke `QUIZ_RACE` — mode terkunci sejak room dibuat, sama seperti sekarang.
- Leaderboard/turnamen lintas-room untuk Quiz Race — di luar cakupan satu room seperti biasa.
- Desain pasti animasi projector (durasi transisi, gaya partikel finish line, dll.) — itu keputusan implementasi, bukan requirement produk di dokumen ini.

---

## Bagian A — Lintasan & Tema

Lintasan Quiz Race adalah **jalur lurus sejajar**: tiap tim punya jalurnya sendiri dari garis start (kiri) ke garis finish (kanan), semua posisi tim terlihat sekaligus di satu layar — beda total dari grid 10×10 berkelok milik Ular Tangga, dan sengaja bukan sirkuit putaran (tidak ada tim yang harus menyusul dari belakang secara berulang, cukup satu arah ke depan).

Tema visual jadi katalog terpisah dari papan Ular Tangga (bentuk datanya beda: tidak ada `ladders_json`/`snakes_json`, tapi tetap butuh `theme_json` untuk palet warna, ikon kotak spesial, dan aset latar projector). Tiga tema tersedia sejak awal, guru pilih satu saat membuat room:

1. **Stadion Atletik Senja** — nuansa stadion atletik sungguhan, langit senja hangat, lintasan merah-bata. Kesan megah tapi tenang.
2. **Arena Kartun Ceria** — ilustrasi flat, warna cerah, playful — paling ramah untuk siswa usia sekolah.
3. **Arena Neon Digital** — gaya papan skor esport modern, gelap dengan aksen neon cyan/pink — kesan kompetitif/high-tech.

## Bagian B — Dua Mesin Gameplay

Mode partisipasi room menentukan mesin gameplay secara otomatis — guru tidak memilih mekanisme balapan secara terpisah, cukup pilih `participation_mode` seperti sekarang saat membuat room.

### B1. Balapan Serentak (room `TEAM_DEVICE`)

Berjalan dalam **ronde**, bukan giliran per tim:

1. Satu soal yang sama tampil ke semua tim yang masih dalam permainan, di perangkat masing-masing, dengan satu jendela waktu jawab bersama (reuse timer/deadline yang sudah ada).
2. Setiap tim yang menjawab **benar** otomatis maju **+1 kotak**.
3. Tim **tercepat** di antara yang benar (berdasarkan timestamp submit di server) dapat bonus tambahan **+2 kotak** (total +3).
4. Salah jawab atau tidak sempat menjawab = tidak maju (+0), tidak ada mundur.
5. Ronde ditutup begitu deadline tercapai (atau semua tim sudah menjawab), posisi semua tim diperbarui sekaligus, lalu ronde berikutnya dimulai otomatis. Berulang sampai ada tim menembus garis finish.

Karena satu soal dipakai bersama oleh semua tim per ronde, mekanisme ini jauh lebih hemat bank soal dibanding B2 — lihat Bagian E.

### B2. Sprint Tanpa Dadu (room `TEACHER_CENTRALIZED`)

Tetap bergiliran satu tim pada satu waktu seperti Ular Tangga Kuis sekarang (reuse penuh alur Mode Tanpa Device: guru mewakili tim yang sedang giliran, timer jawab manual-start via "Mulai Waktu Jawab"). Bedanya: **tidak ada dadu**. Sebelum soal muncul, tim (lewat guru) memilih tingkat kesulitan soal — pilihan ini sekaligus menentukan jarak tempuh kalau benar:

| Tingkat | Langkah kalau benar | Langkah kalau salah |
|---|---|---|
| EASY | +1 | 0 |
| MEDIUM | +2 | 0 |
| HARD | +3 | 0 (tidak mundur) |

Tombol "Lempar Dadu" pada panel gameplay digantikan tiga tombol tingkat (EASY/MEDIUM/HARD) khusus di mode ini — menekan salah satu langsung menarik soal tingkat itu, tetap satu ketukan seperti alur lempar dadu sekarang. Setelah itu alur sama persis seperti Ular Tangga Tanpa Device: soal tampil dulu (`QUESTION_PENDING_START`), guru tekan "Mulai Waktu Jawab", baru timer berjalan.

*Catatan EASY:* aturan salah-jawab seragam di ketiga tingkat (0 langkah, tidak ada mundur) — EASY terasa "aman" murni karena soalnya objektif lebih gampang dijawab benar, bukan karena kesalahannya dimaafkan. Ini menjaga logika resiko/imbalan tetap konsisten: makin berani pilih tingkat, makin besar potensi majunya, tapi peluang gagalnya pun nyata di ketiga tingkat.

## Bagian C — Lap: Zona Kesulitan & Checkpoint

Lintasan dibagi rata menjadi beberapa **lap** (mis. lintasan 50 kotak ÷ 5 lap = 10 kotak per lap). Jumlah lap diatur guru saat membuat room (default disarankan **5 lap**), independen dari jumlah kotak.

Lap punya dua efek, **tapi tidak keduanya berlaku di kedua mesin gameplay** — lihat catatan di bawah:

1. **Zona kesulitan soal** (khusus **B1 — Balapan Serentak**) — reuse mekanisme "Zona difficulty" yang sudah ada di Ular Tangga (soal dipilih berdasarkan posisi tim di papan): lap-lap awal soal didominasi EASY, lap tengah MEDIUM, lap-lap akhir HARD-dominan. Ini membuat balapan makin menegangkan mendekati finish.
2. **Checkpoint** (berlaku di **B1 maupun B2**) — begitu posisi tim melewati batas lap, projector menampilkan sorotan singkat ("Tim Elang menyelesaikan Lap 3!") dan tim mendapat **1 Nitro gratis** (lihat Bagian D) sebagai hadiah kecil, mendorong tim untuk buru-buru menuntaskan lap yang sedang berjalan.

**Kenapa poin 1 tidak berlaku di B2:** di Sprint Tanpa Dadu, tim sendiri yang memilih tingkat soal tiap giliran (EASY/MEDIUM/HARD, lihat Bagian B2) — itu inti strategi mode ini. Kalau sistem *juga* memaksakan zona kesulitan otomatis dari posisi lap, dua mekanisme itu akan rebutan menentukan hal yang sama. Jadi di B2, tim tetap bebas pilih tingkat di lap manapun; lap di B2 hanya soal checkpoint (poin 2).

## Bagian D — Kotak Spesial di Lintasan

Padanan versi balapan dari kotak BONUS/TRAP/SAFE/MYSTERY milik Ular Tangga, dicek setiap kali posisi baru seorang tim dihitung (berlaku di B1 maupun B2):

| Kotak | Padanan Ular Tangga | Efek |
|---|---|---|
| **Boost** | BONUS | Langsung +2 kotak tambahan. |
| **Oil Spill** | TRAP | Ronde/giliran berikutnya tim ini hanya bisa dapat soal EASY (B2) atau kehilangan hak bonus kecepatan (B1). |
| **Pit Stop** | SAFE | Kebal dari Duel Susul selama 1 ronde/giliran berikutnya. |
| **Nitro** | MYSTERY | Tim memilih: pakai ke diri sendiri (B2: tarik 1 soal HARD ekstra, benar = lompat jauh. B1: dijamin dapat bonus kecepatan +2 di ronde berikutnya walau bukan yang tercepat, asalkan jawabannya benar) atau ke lawan (B2: lawan wajib dapat soal HARD di giliran berikutnya. B1: lawan kehilangan hak bonus kecepatan di ronde berikutnya, sama seperti kena Oil Spill — karena semua tim tetap harus dapat soal yang sama per ronde, efeknya diarahkan ke kelayakan bonus, bukan ke soalnya). |
| **Duel Susul** | — (baru, khas balapan) | Kalau posisi baru seorang tim persis menimpa tim lain yang tidak bergerak sejauh itu di ronde/giliran yang sama (dan tim itu tidak sedang Pit Stop), muncul 1 soal duel cepat: menang = menyalip & tim yang disalip mundur 1 kotak; kalah = tim penantang tetap di posisi lama. |

**Kasus tepi yang sudah diputuskan:** kalau dua tim kebetulan mendarat di kotak persis sama di ronde/giliran yang sama (bukan menyusul, tapi sama-sama baru tiba), mereka berbagi kotak itu tanpa duel — menghindari kerumitan duel 3-arah.

## Bagian E — Panjang Lintasan & Peringatan Bank Soal

Guru bebas mengatur jumlah kotak dan jumlah lap saat membuat room, tapi sistem menampilkan **peringatan non-blokir** di form Create Game kalau kombinasi itu berisiko menghabiskan bank soal topik yang dipilih sebelum lintasan selesai. Mesin soal yang ada sudah mendaur ulang pool kalau habis (tidak akan macet), tapi daur ulang berarti soal berulang — kurang ideal, jadi guru perlu diberi tahu di muka.

Karena B1 dan B2 mengonsumsi soal dengan laju sangat berbeda (B1 berbagi 1 soal per ronde untuk semua tim, B2 menghabiskan 1 soal per tim per giliran), ambang peringatannya dihitung terpisah, sebagai estimasi kasar (bukan jaminan presisi):

- **B2 (Sprint Tanpa Dadu):** perkiraan soal yang dibutuhkan ≈ `jumlah_tim × ceil(panjang_lintasan ÷ 2)` (asumsi rata-rata MEDIUM sebagai langkah tengah).
- **B1 (Balapan Serentak):** perkiraan soal yang dibutuhkan ≈ `ceil(panjang_lintasan ÷ 1.5)` (asumsi rata-rata gain per ronde memakai skema B3).

Kalau jumlah soal aktif (published, sesuai topik yang dipilih) di bawah estimasi ini, tampilkan teks peringatan di form (bukan validasi yang menolak submit): *"Bank soal topik ini diperkirakan kurang untuk lintasan sepanjang ini — soal kemungkinan akan berulang sebelum tim mencapai finish."*

Default yang disarankan untuk form (tetap bisa diubah guru): **30 kotak, 5 lap**.

## Bagian F — Kompatibilitas dengan Fitur yang Sudah Ada

Semua ini dipakai ulang tanpa perubahan: PIN join & penolakannya untuk `TEACHER_CENTRALIZED`, roster manual tim, otorisasi guru-mewakili-tim (`TeamSessionService`), timer manual-start (`QUESTION_PENDING_START`), panel Control Game, projector event feed, dan seluruh sistem bank soal/topik/kesulitan. Quiz Race hanya mengganti **papan, kotak spesial, dan cara tim maju** — bukan menulis ulang lapisan partisipasi/otorisasi/sesi yang sudah teruji di Mode Tanpa Device.

## Catatan Teknis untuk Tahap Rencana Implementasi

Satu hal yang perlu diperhatikan serius saat menulis rencana implementasi: `game_rooms.current_team_id` dan `GameTurnModel` saat ini dibangun dengan asumsi **satu tim yang sedang giliran** — asumsi itu valid penuh untuk B2 (masih bergiliran), tapi **tidak valid untuk B1** (Balapan Serentak tidak punya "tim yang sedang giliran", semua tim aktif bersamaan dalam satu ronde). B1 kemungkinan butuh konsep baru di lapisan data (mis. tabel ronde terpisah dari `game_turns`, atau `game_turns` dengan `team_id` nullable merepresentasikan "ronde bersama") — ini keputusan arsitektur yang perlu digali saat sesi `writing-plans`, bukan sesuatu yang perlu diputuskan user di sini.
