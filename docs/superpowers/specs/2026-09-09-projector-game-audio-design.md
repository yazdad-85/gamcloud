# Design Spec — Projector Game Audio (Phase 1)

**Tanggal:** 2026-09-09  
**Status:** Approved (user: roadmap C → priority audio first → Web Audio synth A)  
**Produk:** Edugame / mode Ular Tangga Kuis  
**Site:** `https://edugame.cloudedu.id`

## Context

- Roadmap visual jangka panjang: **C** — papan 2.5D dulu, WebGL 3D belakangan (proyektor saja); controller tetap 2D.
- Fase implementasi **pertama** yang disetujui: **audio event**, bukan papan 3D.
- Sumber suara MVP: **Web Audio API / synth** (perluas `public/assets/game-fx.js`), tanpa pack MP3.
- Sudah ada: unlock UI di proyektor, `SOUND_LIBRARY` internal (dice/correct/wrong/bonus/trap/mystery/safe/winner), dipakai sebagian saat dadu; **belum** diekspos sebagai API cue + belum terhubung ke semua event proyektor + belum ada tension timer / mute.

## Goals

1. Proyektor memainkan cue suara yang jelas untuk event permainan penting.
2. Timer soal ≤10 detik terasa tegang (pulse/loop naik intensitas).
3. Guru bisa **mute/unmute** di proyektor.
4. API cue stabil (`GameFx.sound.play(name)`) agar nanti bisa diganti file pack tanpa ubah pemanggilan di `app.js`.

## Non-goals (fase ini)

- Papan 2.5D / karakter 3D / WebGL.
- BGM penuh sepanjang game (kecuali loop tension singkat saat timer kritis).
- Pack MP3/OGG (fase hybrid belakangan).
- Audio wajib di controller tim (opsional kemudian; autoplay HP sering diblok).
- Mengubah aturan backend / snapshot schema demi audio.

## Approach

**Client-only render cues** dari event/state yang sudah ada di proyektor (`app.js` + overlay queue + countdown). Server tetap source of truth; suara tidak mempengaruhi gameplay.

---

## 1. Sound API (`game-fx.js`)

Ekspos:

```js
GameFx.sound.unlock()
GameFx.sound.play(name)          // one-shot
GameFx.sound.startTension()      // loop/pulse saat timer kritis
GameFx.sound.stopTension()
GameFx.sound.setMuted(boolean)
GameFx.sound.isMuted()
```

### Cue names (MVP)

| Name | Kapan |
|------|--------|
| `dice` | Lempar dadu (sudah ada sebagian) |
| `correct` | Jawaban benar / maju skor |
| `wrong` | Jawaban salah / timeout |
| `ladder` | Resolusi naik tangga sukses (baru atau map dari `bonus`) |
| `snake` | Resolusi turun ular (baru atau map dari `trap`) |
| `mystery` | Misteri muncul / resolved |
| `shield` | Dapat / pakai perisai (`safe`) |
| `points` | Tile bonus poin |
| `winner` | Game selesai |
| `tension` | Internal via start/stopTension, bukan one-shot |

Alias boleh: `bonus`→`points`/`ladder`, `trap`→`snake`, `safe`→`shield` agar tidak pecah pemanggilan lama.

### Tension behavior

- Mulai saat countdown proyektor menampilkan sisa **≤ 10** detik dan ada soal aktif.
- Berhenti saat: jawaban masuk, timeout selesai, giliran berganti, room tidak `PLAYING`, atau mute.
- Intensitas naik pelan saat mendekati 0 (opsional: period pulse lebih cepat).
- Jangan overlapping double-start (idempotent `startTension`).

### Mute

- Toggle di UI proyektor (selalu terlihat setelah unlock, atau di panel samping).
- Persist `localStorage` key `edugame.projector.muted` (opsional tapi disarankan).
- Saat muted: `play` no-op; tension tidak berbunyi.

---

## 2. Wiring proyektor (`app.js` + `projector.php`)

Map overlay/event yang sudah di-queue ke `GameFx.sound.play(...)`:

- Overlay snake/ladder/mystery/shield/points/winner/correct/wrong
- Dice roll path existing
- Countdown bar → tension start/stop

Tetap hormati alur unlock suara yang sudah ada (`data-fx-sound-unlock`).

---

## 3. Success criteria

- Setelah “Aktifkan Suara”, event ular/tangga/misteri/perisai/poin/benar-salah/pemenang terdengar beda.
- 10 detik terakhir timer terasa tegang; berhenti setelah resolve.
- Mute mematikan semua cue.
- Controller & template guru tidak wajib berubah di fase ini.
- Tidak ada regresi visual proyektor yang material.

## 4. Out of scope follow-ups (fase berikutnya)

1. Papan 2.5D proyektor + preview template guru.  
2. WebGL 3D proyektor.  
3. Hybrid: file audio di belakang API `play(name)` yang sama.

## 5. Files (expected)

| File | Role |
|------|------|
| `public/assets/game-fx.js` | Library cue + tension + mute |
| `public/assets/app.js` | Wire proyektor events → cues |
| `app/Views/game/projector.php` (+ CSS kecil) | Tombol mute |
| Spec/plan docs | Jejak keputusan |

## Deploy note

Hanya aset JS/CSS/view — `git pull` cukup; tidak ada migrasi DB.
