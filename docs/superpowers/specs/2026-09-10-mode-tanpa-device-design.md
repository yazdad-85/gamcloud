# Design: Mode Tanpa Device (Partisipasi Terpusat oleh Guru)

**Tanggal:** 2026-09-10
**Status:** Disetujui, siap masuk tahap rencana implementasi
**Terkait:** `docs/superpowers/specs/2026-09-08-create-game-mystery-box-redesign-design.md` (pola state turn & overlay projector yang dipakai ulang di sini)

## Ringkasan

Gameplay saat ini (`participation_mode` implisit = Device per Tim) mengasumsikan setiap tim join lewat PIN di HP masing-masing (`Public\JoinController`), lalu bermain dari halaman `game/controller.php` sendiri-sendiri (lempar dadu, jawab soal, pilih kotak misteri). Ini tidak bisa dipakai di sekolah yang melarang siswa membawa HP.

Dokumen ini merancang **Mode Tanpa Device**: satu setting baru saat membuat game yang memindahkan seluruh input gameplay (lempar dadu, jawab soal, pilih kotak misteri) ke satu **Guru Control View** (gabungan ke halaman Control Game yang sudah ada), dipakai lewat **extended display** (laptop guru = Control View privat, projector = Board View publik). Siswa menjawab verbal/angkat kartu, guru yang menekan tombol jawaban atas nama tim. Server tetap satu-satunya wasit (prinsip arsitektur #2 di master spec) — tidak ada perubahan pada validasi skor/posisi/jawaban, hanya perubahan **siapa yang boleh memicu aksi itu**.

Desain ini disepakati lewat sesi brainstorming interaktif (termasuk mockup gaya pop-up giliran di projector) — keputusan kunci yang sudah dikonfirmasi user:
1. Guru tap opsi A/B/C/D yang sama seperti tombol jawaban tim (bukan tombol Benar/Salah generik) — scoring otomatis tetap dari server berdasarkan opsi yang dipilih.
2. Timer jawaban **tidak** auto-start saat soal muncul — guru tekan tombol "Mulai Waktu Jawab" setelah selesai membacakan soal ke kelas.
3. Roster tim diisi manual oleh guru di Control View sebelum Start (bukan generate otomatis "Tim 1..N").
4. Gameplay panel digabung ke halaman Control Game yang sudah ada (`teacher/games/{id}/control`), bukan halaman baru.
5. Pop-up giliran di projector berupa **full-screen takeover** singkat (2-3 detik) lalu otomatis kembali ke papan.

## Lingkup

**In scope:**
- A. Setting `participation_mode` (`TEAM_DEVICE` default / `TEACHER_CENTRALIZED`) di form Buat Game.
- B. Setup tim manual tanpa PIN (tambah/hapus tim dari Control View selama status `LOBBY`).
- C. Otorisasi: guru pemilik room bisa memicu roll/answer/mystery atas nama tim manapun, hanya berlaku untuk room `TEACHER_CENTRALIZED`.
- D. Panel gameplay (dadu, timer manual, soal+opsi, pilihan misteri) di Control View, auto-mengikuti tim yang sedang giliran.
- E. Timer jawaban manual-start: state turn baru `QUESTION_PENDING_START` / `MYSTERY_QUESTION_PENDING_START`, dipakai khusus room `TEACHER_CENTRALIZED`.
- F. Pop-up full-screen "Giliran: Tim X" di projector, dipicu event turn-advance yang sudah ada.
- G. Halaman join (`/join`) menolak PIN untuk room `TEACHER_CENTRALIZED` dengan pesan yang jelas.

**Out of scope (dicatat, bukan bagian pekerjaan ini):**
- Mode campuran (sebagian tim device, sebagian tidak) — satu room hanya satu mode partisipasi.
- Ganti mode di tengah game — terkunci sejak room dibuat, sama seperti `game_mode`.
- Cetak kartu jawaban fisik A/B/C/D — material kelas, di luar tanggung jawab aplikasi.
- Mode game selain `SNAKES_LADDERS` (QuizRace, DuelArena, dll., masih "Tahap berikutnya") — pola yang sama tinggal diterapkan nanti saat mode itu digarap.
- Fitur undo/pembatalan jawaban setelah submit tersimpan di server — cukup dicegah lewat konfirmasi dua-tap di klien sebelum submit (lihat Bagian D).

---

## Bagian A — Setting Mode Partisipasi di Create Game

**Migration baru** (pola sama seperti `2026-09-08-000008_AddGameModeColumns.php`):

```php
$this->forge->addColumn('game_rooms', [
    'participation_mode' => [
        'type' => 'VARCHAR',
        'constraint' => 30,
        'default' => 'TEAM_DEVICE',
        'after' => 'game_mode',
    ],
]);
```

**Form `teacher/games/create.php`:** tambah satu blok `<div class="field">` baru (setelah blok "Mode Game" di `create.php:134-153`, pola radio yang sama):

```html
<div class="field">
    <label>Mode Partisipasi Tim</label>
    <div class="check-grid">
        <label class="check-option">
            <input type="radio" name="participation_mode" value="TEAM_DEVICE" checked>
            <span>Device per Tim</span>
        </label>
        <label class="check-option">
            <input type="radio" name="participation_mode" value="TEACHER_CENTRALIZED">
            <span>Tanpa Device (Terpusat)</span>
        </label>
    </div>
    <p class="field-help">Tanpa Device: tidak ada join PIN, guru mengoperasikan dadu &amp; jawaban dari layar Control Game (1 laptop + projector). Cocok untuk sekolah yang melarang HP siswa.</p>
</div>
```

`Teacher\GameController::store()` meneruskan `participation_mode` ke `GameEngine::createRoom()` lewat `$options` (pola yang sama seperti `game_mode`, `turn_order_mode`, dll. yang sudah ada di method itu), divalidasi hanya menerima `TEAM_DEVICE`/`TEACHER_CENTRALIZED` (fallback ke `TEAM_DEVICE` kalau nilai lain).

---

## Bagian B — Setup Tim Manual Tanpa PIN

**Masalah:** Tim hanya bisa dibuat lewat `GameEngine::joinByPin()` (`GameEngine.php:126-180`), yang mensyaratkan PIN dan dipanggil dari halaman publik `/join`. Room `TEACHER_CENTRALIZED` tidak memakai jalur ini sama sekali.

**Perbaikan:**
1. Method baru `GameEngine::addTeamByOwner(string $roomUuid, string $teamName, string $avatar = 'robot'): array` — logic identik dengan isi `joinByPin()` baris 138-179 (validasi nama, cek `max_teams`, insert `game_teams`, `recordEvent('room.team_joined', ...)`), tapi:
   - Room dicari lewat `public_uuid` (bukan `pin`), lookup lewat `TenantContext::assertRoomOwner($roomUuid)` (memastikan guru yang login adalah pemiliknya) alih-alih anonim by-PIN.
   - Tambahan guard: `if ($room['participation_mode'] !== 'TEACHER_CENTRALIZED') throw new DomainException('Room ini memakai Device per Tim, tim ditambahkan lewat join PIN.');` — mencegah endpoint ini disalahgunakan untuk room mode biasa.
   - Token sesi tetap digenerate & disimpan hash-nya (`session_token_hash`) untuk konsistensi skema, walau tidak akan dipakai (tidak ada device yang login sebagai tim ini).
2. Route baru: `$routes->post('rooms/(:segment)/teams', 'Api\V1\RoomsController::addTeam/$1', ['filter' => 'rateLimit:20,60,api-mutation']);` — method baru `RoomsController::addTeam()` mengikuti pola `start()`/`pause()` (panggil `TenantContext::assertRoomOwner()` lalu delegasikan ke `GameEngine::addTeamByOwner()`).
3. Route hapus tim (opsional tapi disebutkan user "bisa hapus/edit nama selama LOBBY"): `POST rooms/(:segment)/teams/(:segment)/remove` → `GameEngine::removeTeamByOwner()`, hanya valid selagi `room.status === 'LOBBY'`.
4. **Control View (`control.php`):** tambah panel "Tambah Tim" (input nama + tombol tambah, daftar tim dengan tombol hapus per baris) yang **hanya dirender kalau `snapshot.room.participation_mode === 'TEACHER_CENTRALIZED'` dan `status === 'LOBBY'`**. Setelah `status` berubah dari `LOBBY` (game di-Start), panel ini disembunyikan (roster terkunci, sama seperti Device per Tim yang juga tidak bisa nambah tim setelah Start — `joinByPin()` sudah menolak kalau `status !== 'LOBBY'`).
5. Tombol **Start** yang sudah ada di `control.php:11` mengikuti validasi minimal-2-tim yang sudah ada di `GameEngine::start()` — tidak perlu logic baru.

---

## Bagian C — Otorisasi Terpusat untuk Roll/Jawab/Misteri

Ditemukan saat eksplorasi kode: seluruh aksi mutasi (`start/pause/resume/skip-turn/force-timeout` maupun `roll/answer/mystery/*`) sudah lewat **satu controller yang sama** (`Api\V1\RoomsController`), bedanya cuma otorisasi per-method: aksi admin pakai `TenantContext::assertRoomOwner()`, aksi main pakai `TeamSessionService::assertTeamSession()` (`RoomsController.php:70-156`). Ini menyederhanakan implementasi jauh dari perkiraan awal — **tidak perlu endpoint baru untuk roll/answer/mystery**, cukup satu titik perubahan:

**`TeamSessionService::assertTeamSession()`** (`TeamSessionService.php:11-34`) ditambah jalur bypass di awal method:

```php
public function assertTeamSession(string $roomUuid, string $teamUuid): array
{
    $team = (new GameTeamModel())->where('public_uuid', $teamUuid)->first();
    if ($team === null) {
        throw new DomainException('Tim tidak ditemukan.');
    }

    if ($this->teacherCentralizedAccessAllowed($roomUuid, $team)) {
        return $team;
    }

    // ...perilaku lama persis seperti sekarang (cek session token tim)...
}

private function teacherCentralizedAccessAllowed(string $roomUuid, array $team): bool
{
    if (! auth()->loggedIn()) {
        return false;
    }

    $room = (new GameRoomModel())->where('public_uuid', $roomUuid)->first();
    if ($room === null || ($room['participation_mode'] ?? 'TEAM_DEVICE') !== 'TEACHER_CENTRALIZED') {
        return false;
    }
    if ((int) $team['room_id'] !== (int) $room['id']) {
        return false;
    }

    try {
        (new TenantContext())->assertRoomOwner($roomUuid);
    } catch (Throwable) {
        return false;
    }

    return true;
}
```

Poin penting:
- Bypass **hanya aktif** kalau `participation_mode === 'TEACHER_CENTRALIZED'` — room Device per Tim yang sudah ada (`participation_mode` default `TEAM_DEVICE`) tidak tersentuh sama sekali oleh perubahan ini, jadi tidak menambah permukaan risiko ke room lama.
- `assertRoomOwner()` melempar `PageNotFoundException` untuk siapapun yang bukan pemilik (termasuk guru lain yang login) — ditangkap jadi `false`, bukan diteruskan sebagai error 404 yang membingungkan di endpoint gameplay.
- Ini otomatis berlaku untuk **kelima** endpoint yang memakai `assertTeamSession()` (`roll`, `answer`, `chooseMystery`, `answerMystery`, `answerBoardChallenge`) tanpa menyentuh `RoomsController.php` sama sekali.

---

## Bagian D — Panel Gameplay di Guru Control View

**Masalah:** UI dadu/soal/misteri hanya ada di `game/controller.php` (view per-tim, `team_uuid` statis dari query string), belum ada versinya yang "mengikuti tim yang sedang giliran" untuk dipakai satu guru bagi semua tim.

**Perbaikan — Timer manual-start (state turn baru):**

Turn normal saat ini: `ROLL_READY → QUESTION_ACTIVE → TURN_COMPLETED` (atau `QUESTION_TIMEOUT`), dengan `question_deadline_at` di-set **langsung** saat `roll()` dipanggil (`GameEngine.php:378-387`). Untuk room `TEACHER_CENTRALIZED`, dadu tetap dilempar dan soal tetap dipilih saat itu juga, tapi **countdown tidak boleh langsung jalan** (guru perlu waktu membacakan soal dulu). Solusi: state baru `QUESTION_PENDING_START`, khusus dipakai `TEACHER_CENTRALIZED`:

- `roll()`: kalau `$room['participation_mode'] === 'TEACHER_CENTRALIZED'`, set `state = 'QUESTION_PENDING_START'` dan **jangan** isi `question_deadline_at` (biarkan `NULL`); `question_id`/`question_started_at` tetap diisi seperti biasa supaya soal sudah tersimpan di turn. Untuk `TEAM_DEVICE`, perilaku persis seperti sekarang, tidak berubah.
- Method baru `GameEngine::startAnswerTimer(string $roomUuid): array` — otorisasi `TenantContext::assertRoomOwner()`, mensyaratkan turn `state === 'QUESTION_PENDING_START'`, lalu set `question_started_at = now`, `question_deadline_at = now + question_time_seconds`, `state = 'QUESTION_ACTIVE'`. Setelah ini, alur `answer()`/timeout/snapshot countdown berjalan identik dengan mode biasa — tidak ada percabangan baru di luar titik ini.
- Route: `POST rooms/(:segment)/start-timer` → `RoomsController::startTimer()` (pola sama seperti `pause()`/`resume()`).
- Pola yang sama diterapkan ke alur Mystery: state `MYSTERY_QUESTION_ACTIVE` (dari redesign Mystery Box, lihat spec terkait) dipecah jadi `MYSTERY_QUESTION_PENDING_START → MYSTERY_QUESTION_ACTIVE` untuk `TEACHER_CENTRALIZED`, dipicu tombol "Mulai Waktu Jawab" yang sama, supaya soal HARD dari kotak misteri juga dibacakan guru dulu sebelum timer jalan.
- Timeout sweep (`resolveTimedOutTurn`/`isTurnExpired` dan sejenisnya) sudah keyed dari `question_deadline_at IS NOT NULL AND < now` — karena state `*_PENDING_START` sengaja tidak mengisi `question_deadline_at`, turn di state ini otomatis tidak pernah dianggap timeout oleh mekanisme yang sudah ada, tidak perlu exclude eksplisit. Ini perlu diverifikasi lewat test (lihat bagian Testing).

**Perubahan Control View (`control.php` + `app.js:teacherControl()`):**

Panel baru muncul di bawah tombol admin yang sudah ada, **hanya kalau `participation_mode === 'TEACHER_CENTRALIZED'` dan `room.status === 'PLAYING'`**, isinya reuse hampir seluruh markup+logic yang sudah ada di `controller.php`/`app.js:controller()` (dice panel, question panel, mystery-choice panel — lihat `controller.php:21-46`), dengan dua beda:

1. `team_uuid` yang dipakai untuk tiap aksi (`roll`, `answer`, `chooseMystery`, `answerMystery`) diambil dinamis dari `snapshot.current_turn.team_uuid` (field ini sudah ada, lihat `GameEngine.php:2180`) setiap kali panel digambar ulang — bukan nilai statis dari page-load seperti di `controller.php`.
2. Tombol jawaban (opsi A/B/C/D) butuh **konfirmasi dua langkah** sebelum submit ke server: tap pertama meng-highlight opsi (state lokal di JS, belum ada network call), tap kedua pada opsi yang sama (atau tombol "Kirim Jawaban Tim") baru memanggil `POST /answer`. Ini murni state UI klien — tidak ada perubahan API. Tujuannya mencegah salah pencet mengubah skor tim, karena di mode ini guru adalah perantara (beda dari mode device dimana yang menjawab adalah pemilik jawaban itu sendiri).
3. Tombol dadu menampilkan "Giliran sekarang: **{nama tim}**" mengikuti `current_turn.team_uuid` — tidak ada pemilih tim manual, murni mengikuti state server (konsisten dengan `turn_order_mode` yang sudah dipilih saat create game).
4. Tombol "Mulai Waktu Jawab" muncul saat `turn.state` = `QUESTION_PENDING_START` / `MYSTERY_QUESTION_PENDING_START`, memanggil `POST /start-timer`, hilang setelah state pindah ke `*_ACTIVE`.

Tombol admin yang sudah ada (Pause/Resume/Skip Turn/Force Timeout) tetap tampil apa adanya sebagai jalur override kalau ada kendala kelas — tidak perlu perubahan pada bagian itu.

---

## Bagian E — Pop-up Giliran di Projector

Ditemukan saat eksplorasi kode: setiap event yang memindahkan giliran (`answer.resolved`, `turn.skipped`, `turn.timeout` — lihat `GameEngine.php:582-592`, `303-306`, `727-730`) **sudah** membawa payload `next_team_uuid`. Tidak perlu event baru.

**Perubahan `app.js` (projector):**
1. Tambah `turn.skipped` dan `turn.timeout` ke `SEQUENCED_EVENTS` (`app.js:699-709`) — saat ini hanya `answer.resolved` dkk. yang otomatis masuk antrean overlay, padahal skip/timeout juga memindahkan giliran dan perlu memicu pop-up di mode ini.
2. Di `overlayForEvent()` (`app.js:889`), tambah case baru: kalau `snapshot.room.participation_mode === 'TEACHER_CENTRALIZED'` dan event punya `payload.next_team_uuid` dan game belum `game.finished`, kembalikan overlay tipe baru `turn-announcement` (nama tim dari `snapshot.teams` yang cocok `public_uuid`-nya).
3. CSS/markup overlay baru di `projector.php`/stylesheet projector: full-screen (menutupi `.board`, bukan cuma `.projector-event-overlay` kecil yang sudah ada), auto-hilang setelah ~2.5 detik lewat `setTimeout` mengikuti pola antrean overlay yang sudah ada (`overlayQueue`/`overlayBusy` di `app.js:713-714`) supaya tidak tumpang tindih dengan overlay dadu/efek lain.
4. Overlay ini **hanya muncul untuk room `TEACHER_CENTRALIZED`** — room Device per Tim tetap seperti sekarang (baris "Giliran" kecil di sidebar saja), sesuai keputusan awal bahwa fitur ini spesifik untuk kebutuhan "menggantikan notifikasi HP pribadi".

---

## Bagian F — Halaman Join Menolak Room Terpusat

`Public\JoinController::join()` (`JoinController.php:26-49`) memanggil `GameEngine::joinByPin()`. Tambah pengecekan di awal `joinByPin()` (`GameEngine.php:128-136`, setelah room ditemukan & sebelum cek `status`):

```php
if (($room['participation_mode'] ?? 'TEAM_DEVICE') === 'TEACHER_CENTRALIZED') {
    throw new DomainException('Room ini memakai Mode Tanpa Device — ikuti permainan dari layar guru di depan kelas, tidak perlu join PIN.');
}
```

Pesan ini otomatis muncul di halaman `/join` lewat jalur error-handling yang sudah ada (`JoinController.php:47-49`, flash `error`), tidak perlu view baru.

---

## Ringkasan Perubahan Data Model

| Tabel | Perubahan | Alasan |
|---|---|---|
| `game_rooms` | + `participation_mode` (VARCHAR 30, default `TEAM_DEVICE`) | Bagian A |
| `game_turns` | Tidak ada kolom baru — `state` sudah VARCHAR bebas nilai, tambah 2 nilai baru: `QUESTION_PENDING_START`, `MYSTERY_QUESTION_PENDING_START` | Bagian D |
| `game_teams` | Tidak ada kolom baru | Bagian B (`addTeamByOwner` insert dengan struktur sama seperti `joinByPin`) |

Satu migration baru dibutuhkan (untuk `game_rooms.participation_mode`).

## Testing

- `createRoom()` menerima `participation_mode = 'TEACHER_CENTRALIZED'` dan menyimpannya; default tetap `TEAM_DEVICE` kalau tidak dikirim (regresi ke perilaku lama).
- `addTeamByOwner()`: sukses menambah tim untuk room `TEACHER_CENTRALIZED` milik guru yang login; ditolak untuk room `TEAM_DEVICE` (pesan "tim ditambahkan lewat join PIN"); ditolak untuk guru yang bukan pemilik room; ditolak kalau `status !== 'LOBBY'`; ditolak kalau `max_teams` sudah penuh.
- `joinByPin()` menolak room `TEACHER_CENTRALIZED` dengan `DomainException` pesan baru, tidak menyentuh room `TEAM_DEVICE` sama sekali (regresi: seluruh test `joinByPin` yang sudah ada tetap lulus).
- `TeamSessionService::assertTeamSession()`: guru pemilik room `TEACHER_CENTRALIZED` lolos otorisasi untuk `team_uuid` manapun di room itu tanpa token sesi tim; guru yang sama **ditolak** untuk room `TEAM_DEVICE` milik dirinya sendiri (bypass tidak aktif); guru lain (bukan pemilik) tetap ditolak di kedua mode; siswa dengan token tim valid tetap lolos di `TEAM_DEVICE` seperti sekarang (regresi).
- `roll()` pada room `TEACHER_CENTRALIZED` menghasilkan turn `state = QUESTION_PENDING_START` dengan `question_deadline_at = NULL`; pada room `TEAM_DEVICE` perilaku identik dengan sekarang (regresi).
- `startAnswerTimer()`: sukses memindahkan `QUESTION_PENDING_START → QUESTION_ACTIVE` dengan deadline baru dihitung dari saat dipanggil (bukan dari saat `roll()`); ditolak kalau turn bukan di state itu; ditolak untuk bukan pemilik room.
- Sweep timeout tidak pernah men-timeout-kan turn berstate `*_PENDING_START` (deadline `NULL`) — dites dengan memajukan waktu tanpa memanggil `startAnswerTimer()`.
- `answer()`/`chooseMysteryTarget()`/`answerMystery()` dipanggil dengan sesi guru (bukan token tim) atas nama tim yang sedang giliran di room `TEACHER_CENTRALIZED` → sukses, skor/posisi ter-update sama seperti dipanggil tim itu sendiri di mode biasa.
- Regresi: seluruh test yang sudah lulus sebelumnya (termasuk suite Mystery Box redesign) tetap lulus tanpa perubahan setelah `participation_mode` ditambahkan.

## Urutan Pengerjaan yang Disarankan

1. Bagian A (migration + setting create game) — fondasi, kecil, tidak bergantung apa-apa.
2. Bagian C (otorisasi `TeamSessionService`) — kecil, independen, aman dites sendiri lewat unit test sebelum ada UI yang memakainya.
3. Bagian B (setup tim manual) — butuh Bagian A (kolom `participation_mode`) untuk guard-nya.
4. Bagian F (join menolak room terpusat) — kecil, searah dengan A, bisa paralel dengan B.
5. Bagian D (state `*_PENDING_START` + panel gameplay Control View) — paling besar, butuh A+B+C selesai supaya bisa dites end-to-end (butuh room terpusat dengan tim yang sudah dibuat).
6. Bagian E (pop-up projector) — bergantung pada D selesai (butuh `next_team_uuid` benar-benar mengalir dari turn yang sudah berjalan di mode terpusat untuk dites visual).
