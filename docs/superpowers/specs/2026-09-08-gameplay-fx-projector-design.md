# Design: Animasi, Efek, dan Sound Gameplay (Fokus Projector)

**Tanggal:** 2026-09-08
**Status:** Disetujui, siap masuk tahap rencana implementasi
**Terkait:** `docs/superpowers/specs/2026-09-08-board-theme-visual-redesign-design.md` (papan & pion tema — pekerjaan ini menambah lapisan animasi/efek/suara di atas papan yang sudah ada, tidak mengubah papan itu sendiri)

## Ringkasan

Setelah dicoba main langsung, gameplay terasa datar terutama di layar projector (layar yang ditonton banyak orang):

1. Jawab benar/salah tidak ada notifikasi atau animasi meriah.
2. Kena trap/bonus/mystery terasa seperti jalan biasa, tidak ada notifikasi yang jelas.
3. Pion pindah kotak secara instan (teleport), bukan efek jalan.
4. Animasi dadu lemah — angka hasil muncul di teks lebih cepat daripada dadu "berhenti".

Investigasi kode menemukan bahwa sebagian infrastruktur untuk ini **sudah ada** tapi punya bug/kekurangan konkret, bukan kosong total:

- **Bug pion teleport**: `animateMovementEvent()` di `app.js` sudah membuat elemen "ghost" (`.board-mover`) yang terbang mengikuti path kotak. Tapi `renderBoard()` menggambar ulang seluruh papan dari `snapshot.teams[].position` (posisi final) di **setiap** poll snapshot — termasuk poll yang sama yang memicu animasi ghost. Akibatnya pion asli di grid sudah nongol di kotak tujuan seketika, sementara ghost terbang di atasnya secara terpisah. Dua elemen tumpang tindih, bukan satu gerakan halus.
- **Race condition dadu**: tombol "Lempar Dadu" di controller memicu `rollDiceAnimation()` (flicker angka acak, fix 960ms) **dan** `fetch` API secara paralel. Karena API biasanya selesai < 960ms, angka asli menimpa flicker di tengah siklus — dadu tidak pernah terasa "berhenti" dengan sengaja. Projector malah tidak pernah menganimasikan dadu sama sekali, cuma tempel angka ke banner teks.
- **Mystery tile tidak pernah animasi jalan**: event `mystery.resolved` di backend (`GameEngine::resolveMysteryOutcome`) tidak membawa payload `movement`, jadi `animateMovementEvent()` (yang di-trigger khusus untuk event `answer.resolved`) tidak pernah jalan untuk pergerakan akibat mystery box — pion mystery selalu lompat instan di render berikutnya.
- **Notifikasi trap/bonus/mystery/benar-salah sebenarnya sudah ada** (`overlayForEvent()` + `playOverlayQueue()`, dipakai di `projector()`) — tapi berupa banner teks polos tanpa ikon per-jenis, tanpa partikel, tanpa suara, dan jalan di antrian sendiri yang tidak sinkron dengan animasi pion. Ini alasan kenapa terasa "seperti biasa" meski secara teknis ada.
- **Suara: nol** — tidak ada file audio atau kode Web Audio di project ini sama sekali.

Solusi ini murni **client-side + satu perubahan kecil di backend event payload** (untuk mystery movement). Tidak ada migrasi database.

## Lingkup

**In scope:**
- A. Dadu 3D CSS/SVG dengan sequencing yang benar (controller + projector), replace `rollDiceAnimation()` dan tampilan `dice.rolled` di projector.
- B. Perbaikan sinkronisasi posisi pion supaya ghost animation = satu-satunya representasi visual saat bergerak (fix bug teleport).
- C. Payload `movement` untuk `mystery.resolved` di backend supaya pergerakan mystery ikut animasi jalan.
- D. Overlay perayaan jawaban benar (confetti + ikon + banner + suara) dan jawaban salah (flash + ikon + suara), scoped ke projector.
- E. Ikon + warna + suara unik per jenis tile khusus (Trap/Bonus/Mystery/Safe), dipicu tepat saat pion tiba di kotak itu.
- F. Sequencer satu-timeline per giliran di projector: dadu → jalan pion → efek tile (jika ada) → hasil jawaban → (sub-alur mystery jika ada) → pemenang (jika game selesai) — menggantikan dua sistem terpisah (queue banner vs ghost animation) yang sekarang berjalan sendiri-sendiri.
- G. Sound engine sintetis (Web Audio API, tanpa file), dipasang khusus untuk projector, dengan overlay "Aktifkan Suara" satu kali untuk memenuhi kebijakan autoplay browser.

**Out of scope:**
- Sound di controller/device tim (dikonfirmasi: projector saja untuk sesi ini).
- Perubahan visual papan/tema/pion itu sendiri (sudah selesai di spec board-theme-visual-redesign; sesi ini murni lapisan animasi/efek di atasnya).
- Mode game selain `snakes_ladders_board` (mode placeholder lain tidak disentuh).
- Duel tile (`overlay-dice`/`Duel Tile` sudah ada sebagai placeholder banner, belum ada mekanik duel — tetap placeholder, tidak diberi efek baru di luar cakupan tile-type icon yang generic).

## Arsitektur: file baru `game-fx.js` + `game-fx.css`

Daripada menambah lagi ke `app.js` (sudah 1270 baris) dan `app.css` (sudah 3000+ baris), fitur ini masuk file baru:

- `public/assets/game-fx.js` — modul `window.GameFx` berisi: dice cube engine, confetti engine, tile-effect icon burst, sound engine, dan sequencer timeline.
- `public/assets/game-fx.css` — semua style/keyframe baru untuk di atas.

Dimuat via `<script>`/`<link>` tambahan **hanya** di `app/Views/layouts/projector.php` dan (untuk komponen dadu 3D saja) di layout controller. `app.js` tetap pemilik polling/state/DOM-diff seperti sekarang; ia memanggil `GameFx.*` di titik-titik yang sudah ada (`overlayForEvent`, `animateMovementEvent`, klik tombol roll) alih-alih membangun banner/flicker sendiri secara inline. Ini menjaga `app.js` sebagai "game state & rendering" dan `game-fx.js` sebagai "presentasi/showbiz", konsisten dengan prinsip file board-theme yang sudah memisahkan concern (rendering papan tetap di `app.js`, styling di `app.css`).

## Bagian A — Dadu 3D CSS/SVG

Kubus dibangun dari 6 `<div class="die-face">` yang diposisikan absolut dengan `transform: translateZ(±half) / rotateX/Y(90deg)` di dalam container `transform-style: preserve-3d` (pola CSS cube standar). Tiap wajah berisi pip (titik) sebagai `<span>` bulat kecil dalam grid 3x3, jumlah & posisi pip mengikuti pola dadu asli untuk 1-6.

**Sequencing (menggantikan race condition):**

```js
GameFx.rollDice(cubeEl, {
    resultPromise,      // promise yang resolve ke angka 1-6 asli dari API
    minDurationMs: 1200 // durasi minimum tumbling, TIDAK peduli seberapa cepat API selesai
}).then((value) => { /* lanjut ke tahap berikutnya di sequencer */ });
```

Implementasi: fase "tumble" memutar kubus cepat & acak (requestAnimationFrame, rotasi X/Y increment besar) sampai `Promise.all([resultPromise, delay(minDurationMs)])` selesai — **bukan** salah satu duluan. Begitu keduanya selesai, fase "settle" menghitung rotasi target yang menampilkan wajah sesuai `value` (mapping tetap: tiap nilai 1-6 punya rotasi X/Y dasar yang diketahui), lalu animasi easing-out singkat (~400ms) menuju rotasi itu persis. Hold ~500ms sebelum sequencer lanjut. Ini menghilangkan race karena reveal **selalu** menunggu kedua syarat, tidak pernah salah satu mendahului yang lain.

Dipakai di dua tempat:
- **Controller** (`app.js` klik `rollButton`, baris ~1110-1131 sekarang): ganti `rollDiceAnimation(diceDisplay)` dengan `GameFx.rollDice(cubeEl, {resultPromise: jsonFetchPromise, minDurationMs: 1200})`, replace elemen `.dice-face` (teks) dengan cube.
- **Projector**: tambah elemen dice cube di dalam (atau menggantikan) `.projector-event-overlay` khusus untuk event `dice.rolled` — karena di projector nilai dadu sudah diketahui saat event diterima (bukan promise yang belum resolve), `resultPromise` langsung `Promise.resolve(payload.dice_value)`, tapi `minDurationMs` tetap dipaksa supaya tetap ada suspense visual, bukan langsung tempel angka seperti sekarang.

## Bagian B — Fix Sinkronisasi Posisi Pion (bug teleport)

`app.js::renderBoard()` sekarang menggambar pion dari `team.position` (posisi final snapshot) langsung. Perbaikan menambah satu layer indirection tanpa mengubah bentuk data snapshot:

```js
const displayPositions = new Map(); // team_uuid -> tile number, module-level di projector()

function effectivePosition(team) {
    return displayPositions.has(team.uuid) ? displayPositions.get(team.uuid) : Number(team.position || 1);
}
```

`renderBoard()` dipanggil dengan `displayPositions` (atau baca dari closure di projector context) dan pakai `effectivePosition(team)` alih-alih `team.position` langsung saat mengelompokkan `teamsByPosition`.

Alur saat `animateMovementEvent()` dipicu (di dalam `projector()`, saat event `answer.resolved` atau `mystery.resolved` diterima):
1. **Sebelum** animasi mulai: `displayPositions.set(team.uuid, movement.from)` lalu render ulang papan sekali (supaya papan tidak "lompat duluan" ke posisi akhir yang baru saja diterima dari snapshot).
2. Jalankan animasi ghost seperti sekarang (`mover.animate(keyframes, ...)`).
3. **Setelah** animasi selesai (`animation.finished`): `displayPositions.delete(team.uuid)` (kembali memakai `team.position` asli dari snapshot, yang sekarang sudah sama dengan `movement.to`), render ulang papan sekali lagi.

Hasilnya: satu representasi visual pion yang bergerak (ghost), pion statis di grid tetap "diam" di posisi lama sampai ghost benar-benar tiba, baru muncul di kotak baru — tidak ada lagi tumpang tindih/snap instan.

**Trap berjalan mundur per-kotak**: `movementTilePath()` sekarang menambahkan `to` sebagai satu titik lompatan tunggal setelah `landed` kalau beda (baris ~811-813). Untuk TRAP, ganti jadi loop tile-by-tile mundur dari `landed` ke `to` (sama seperti loop maju yang sudah ada di atasnya), supaya ghost benar-benar "melangkah mundur" kotak demi kotak, bukan meluncur garis lurus melewati kotak-kotak di antaranya.

## Bagian C — Movement Payload untuk Mystery (backend)

`GameEngine::resolveMysteryOutcome()` (baris ~691-760) memanggil `applyMysteryDeltaToTeam()` yang mengubah posisi tim tapi posisi lama tidak pernah dikirim ke event. Tambahkan penangkapan posisi sebelum/sesudah, lalu sertakan di payload `mystery.resolved`:

```php
// Di tiap cabang (REWARD_SELF: $movedTeam = $team; PUNISH_OPPONENT: $movedTeam = $opponent;
// BOOMERANG_SELF: $movedTeam = $team), tangkap posisi sebelum delta diterapkan:
$fromPosition = (int) $movedTeam['position'];
$toPosition = $this->applyMysteryDeltaToTeam($room, $movedTeam, $pointsDelta, $stepsDelta, $maxPosition);
// lalu sertakan di payload event, menggantikan pemanggilan recordEvent yang sudah ada:
$this->recordEvent($room, 'mystery.resolved', [
    'team_uuid' => $team['public_uuid'],
    'affected_team_uuid' => $affectedTeamUuid,
    'is_correct' => $isCorrect,
    'outcome' => $outcome,
    'movement' => ['from' => $fromPosition, 'landed' => $fromPosition, 'to' => $toPosition],
]);
```

Frontend: di `projector()`, panggil `animateMovementEvent(event, snapshot)` juga untuk `event.event === 'mystery.resolved'` (sekarang baris ~616-618 hanya cek `answer.resolved`), memakai `event.payload.affected_team_uuid` sebagai tim yang dianimasikan (bukan `team_uuid`, karena pada `PUNISH_OPPONENT` yang bergerak adalah lawan, bukan tim yang menjawab).

## Bagian D — Overlay Perayaan Jawaban Benar/Salah

**Benar**: `GameFx.celebrateCorrect(anchorTile, team)` — dipicu dari sequencer setelah pion tiba (bukan langsung saat event diterima, supaya tersinkron dengan posisi pion baru):
- Confetti burst: potongan kecil (`<div>` warna-warni, ukuran ~6-10px) di-spawn dari posisi kotak tujuan pion, masing-masing dapat lintasan acak (arah, rotasi, jarak jatuh) lewat `.animate()` Web Animations API (pola sama seperti `.board-mover` yang sudah ada — `createElement` → `.animate()` → `.finished.then(remove)`), ~30-40 potongan, ~1.5s, lalu auto-cleanup.
- Ikon centang besar + nama tim, banner hijau (reuse struktur `.projector-event-overlay` yang ada, style baru).
- Suara `GameFx.sound.correct()`.

**Salah**: `GameFx.celebrateWrong()` — flash merah tepi layar (`.screen-flash-danger`, keyframe opacity pulse full-viewport overlay), ikon silang, banner merah, suara `GameFx.sound.wrong()`. Tanpa confetti (sesuai keputusan: tidak perlu semeriah jawaban benar), durasi lebih singkat (~1.5s vs ~2.5s).

## Bagian E — Ikon & Suara per Jenis Tile Khusus

Dipicu dari sequencer tepat saat ghost pion **tiba** di kotak bertipe khusus (bukan langsung saat event diterima), lewat `GameFx.tileEffect(tileEl, type, payload)`:

| Tipe | Ikon (SVG inline, pola sama seperti `THEME_ICON_SHAPES` yang sudah ada di `app.js`) | Warna aksen | Suara |
|---|---|---|---|
| `BONUS` | Koin/bintang | Kuning keemasan | `sound.bonus()` — dua nada naik pendek |
| `TRAP` | Duri/jebakan | Merah tua | `sound.trap()` — nada turun pendek |
| `MYSTERY` | Kotak tanda tanya | Ungu | `sound.mystery()` — sweep naik misterius |
| `SAFE` / `SAFE_BLOCK` | Perisai | Biru | `sound.safe()` — chime lembut |

Icon muncul sebagai burst kecil di atas kotak (scale-up + fade, ~1s) plus banner singkat menggantikan banner generik `specialOverlay()` yang sekarang. `SAFE_BLOCK` (perisai berhasil menahan ular) tetap dapat ikon perisai + suara `safe()` tapi teks banner beda ("Perisai Aktif" — sudah ada teksnya, tinggal pasang ikon+suara).

## Bagian F — Sequencer Timeline per Giliran

Menggantikan `overlayQueue`/`playOverlayQueue()` yang sekarang (banner independen, tidak tahu-menahu soal animasi pion) dengan satu fungsi `runTurnSequence(events, snapshot)` di `projector()` yang, untuk sekumpulan event yang datang dari satu poll (biasanya: `dice.rolled` dari giliran sebelumnya sudah selesai ditampilkan; batch baru berisi `answer.resolved` + 0-n `tile.special_triggered` + opsional `mystery.*` + opsional `game.finished`), menjalankan **await berurutan**:

1. `dice.rolled` (jika ada di batch, dari alur roll normal) → `GameFx.rollDice()`.
2. `answer.resolved` → jika `movement.from !== movement.to`: set `displayPositions` ke `from`, jalankan `animateMovementEvent()` (ghost jalan ke `to`), **selama** ghost berjalan, untuk tiap kotak special di `movement.effects` yang dilewati/dituju, panggil `GameFx.tileEffect()` tepat saat ghost melewati kotak itu (pakai `onprogress`/keyframe offset dari Web Animations API, atau `setTimeout` proporsional terhadap durasi animasi & posisi kotak di path).
3. Setelah ghost selesai → `GameFx.celebrateCorrect()` atau `celebrateWrong()` sesuai `is_correct`.
4. `mystery.target_chosen` / `mystery.resolved` (jika ada) → banner info lalu ulangi langkah 2-3 dengan `movement` dari mystery.
5. `game.finished` (jika ada) → `GameFx.celebrateWinner()` (confetti lebih besar, fanfare, ~4s).

Setiap langkah `await`-nya sendiri (promise dari animasi/durasi terkait), jadi total durasi satu giliran adalah jumlah tahap yang benar-benar terjadi — giliran tanpa tile khusus tetap singkat (cuma dadu + jalan + hasil jawaban), tidak dipaksa mengular ke semua tahap.

## Bagian G — Sound Engine (Web Audio API, tanpa file)

`GameFx.sound` — satu `AudioContext` shared, fungsi-fungsi kecil yang menjadwalkan `OscillatorNode` + `GainNode` (attack-decay envelope) per efek, tanpa file audio sama sekali:

- `dice()` — beberapa "tick" noise pendek berurutan (rattling), sinkron dengan fase tumble dadu.
- `correct()` — arpeggio naik 3 nada (mis. C-E-G) ceria.
- `wrong()` — buzz pendek nada rendah.
- `bonus()` — dua nada naik cepat (efek "ding koin").
- `trap()` — nada turun pendek.
- `mystery()` — sweep frekuensi naik (oscillator frequency ramp).
- `safe()` — chime lembut single-note.
- `winner()` — melodi fanfare pendek (4-5 nada).

**Autoplay policy**: browser memblokir audio sebelum ada gesture pengguna di tab tersebut. Projector adalah tab yang berdiri sendiri (tidak ada klik dari guru/pemain di situ), jadi tambah overlay penuh layar "🔊 Ketuk untuk Aktifkan Suara" saat halaman projector dimuat (`app/Views/game/projector.php` + `layouts/projector.php`), satu klik men-`resume()` `AudioContext` lalu overlay hilang permanen untuk sesi itu. Kalau belum diklik, seluruh visual (dadu, confetti, ikon, banner) tetap jalan seperti biasa — cuma tanpa suara — supaya klik ini tidak memblokir gameplay kalau guru lupa/terlambat mengklik.

## File yang Disentuh

- **Baru**: `public/assets/game-fx.js`, `public/assets/game-fx.css`.
- **Ubah**: `public/assets/app.js` (hook ke `GameFx` di `projector()`, `controller()`, `renderBoard()`, `animateMovementEvent()`, `movementTilePath()`; hapus `rollDiceAnimation()` lama).
- **Ubah**: `app/Views/layouts/projector.php` (tambah `<script src=".../game-fx.js">`, `<link ... game-fx.css>`, overlay aktifkan-suara).
- **Ubah**: `app/Views/game/projector.php` (elemen overlay aktifkan-suara).
- **Ubah**: `app/Views/layouts/*controller*` atau view controller tim yang relevan (tambah `game-fx.js`+css untuk komponen dadu 3D saja, tanpa sound).
- **Ubah**: `app/Services/Game/GameEngine.php` — `resolveMysteryOutcome()` (tambah `movement` di payload `mystery.resolved`).

Tidak ada migrasi database, tidak ada perubahan kontrak API (`movement` yang ditambahkan ke `mystery.resolved` murni penambahan field baru di payload event, tidak mengubah field yang sudah dikonsumsi frontend lain).

## Testing / Verifikasi

Perubahan backend kecil (satu payload event baru) + perubahan frontend besar (visual/animasi). Verifikasi:
- `./vendor/bin/phpunit` — harus tetap hijau; kalau ada test yang mengecek shape payload `mystery.resolved`, update assertion untuk field `movement` baru (additive, seharusnya tidak break test yang sudah ada kecuali test itu strict-match seluruh payload).
- `node --check public/assets/app.js public/assets/game-fx.js` — validasi sintaks.
- Manual, buka dev server dengan minimal 2 tim, mainkan sampai menemui tiap skenario di layar projector:
  - Roll dadu → dadu 3D tumbling lalu berhenti pas di angka yang benar (bandingkan dengan angka di banner/state, harus sama persis), tidak ada kedipan angka duluan.
  - Jawab benar tanpa tile khusus → pion jalan kotak-demi-kotak (bukan snap), lalu confetti + banner + suara.
  - Jawab benar kena BONUS → ikon koin + suara bonus muncul pas pion tiba di kotak itu, baru lanjut ke perayaan jawaban benar.
  - Jawab benar kena TRAP → pion jalan maju dulu ke kotak landing, lalu terlihat jalan **mundur** kotak-demi-kotak (bukan meluncur lurus) ke kotak trap, ikon+suara trap, baru banner (nada beda, bukan hijau/confetti karena secara skor ini tetap "jawaban benar" tapi kena trap — konfirmasi ke guru saat demo bagaimana overlay-nya digabung: banner benar + ikon trap keduanya tampil berurutan, tidak saling menutupi).
  - Jawab salah → flash merah + silang + suara buzz, tanpa confetti, tanpa pion bergerak (posisi tidak berubah — pastikan tidak ada animasi jalan dipicu kalau `from === to`).
  - Kena MYSTERY box → alur pilih target lalu jawab soal HARD → outcome REWARD_SELF/PUNISH_OPPONENT/BOOMERANG_SELF masing-masing dites, pastikan pion yang bergerak benar (tim penyerang vs tim lawan sesuai `affected_team_uuid`) dan animasi jalan (bukan lompat).
  - Game selesai → confetti besar + fanfare ~4 detik, tidak terpotong oleh poll berikutnya.
  - Reload halaman projector di tengah game → overlay "Aktifkan Suara" muncul lagi (state audio tidak dipersist, itu memang sesuai kebijakan browser), visual tetap jalan sebelum diklik.
- Cek beban visual tidak membuat browser projector (biasanya laptop/PC guru biasa, bukan gaming rig) lag — kalau confetti/banyak elemen `.animate()` bersamaan (misal 2 tim selesai giliran cepat berurutan) terasa berat, turunkan jumlah potongan confetti dari default (~30-40) sebagai penyetelan pertama tanpa mengubah struktur kode.

## Risiko

- **Kompleksitas sinkronisasi timeline (Bagian F)**: ini bagian paling berisiko meleset dari yang direncanakan karena harus mengoordinasikan beberapa `Promise`/animasi berurutan dengan durasi bervariasi. Mitigasi: bangun & uji tiap primitive (`rollDice`, `animateMovementEvent` yang sudah diperbaiki, `celebrateCorrect/Wrong`, `tileEffect`) secara independen dulu sebelum merangkai sequencer penuh — tiap primitive punya kontrak promise yang jelas (`resolve` saat animasinya selesai), jadi sequencer tinggal `await` berurutan tanpa perlu tahu detail internal tiap primitive.
- **Autoplay browser berbeda antar-browser/OS**: kebijakan unlock audio via 1 klik adalah pola standar dan seharusnya cukup, tapi kalau projector browser tertentu (mis. Safari lama) masih memblokir meski sudah diklik, sound bisa senyap di device itu — visual tetap berfungsi penuh sebagai fallback, jadi tidak blocking untuk gameplay.
- **Beban render saat banyak animasi bersamaan** (lihat catatan di Testing) — parameter jumlah confetti/durasi sengaja dibuat sebagai angka yang gampang disetel belakangan, bukan di-hardcode dalam logika kompleks.
- **Payload `mystery.resolved` baru** berpotensi mempengaruhi test PHPUnit yang melakukan assert ketat terhadap struktur payload event tersebut — perlu dicek saat implementasi (lihat Testing).
