# Design: Redesign Visual Papan Per Tema

**Tanggal:** 2026-09-08
**Status:** Disetujui, siap masuk tahap rencana implementasi
**Terkait:** `docs/superpowers/specs/2026-09-08-create-game-mystery-box-redesign-design.md` (preview tema di form create game — pekerjaan ini melengkapi papan gameplay-nya sendiri)

## Ringkasan

Papan ular tangga saat ini (di halaman create/detail guru, controller tim, dan projector) cuma checkerboard 2 warna datar plus gradient gelap latar belakang — 6 tema yang ada hanya beda warna, tidak ada elemen dekoratif. Guru meminta papan terasa lebih "ceria" dan tidak monoton, mengikuti tema, tanpa masuk ke 3D penuh (sudah ada prinsip roadmap: "Jangan mulai 3D penuh sebelum projector 2D terasa menarik dan stabil").

Solusinya murni di sisi client (CSS + `public/assets/app.js`) — **tidak ada perubahan backend/database/migration sama sekali**, karena warna tema (`palette`) dan kunci tema (`theme.key`) sudah mengalir dari `GameEngine::publicTheme()` ke snapshot dan sudah dipasang sebagai CSS custom property oleh `applyBoardTheme()` yang sudah ada.

## Lingkup

**In scope:**
- A. Set ikon SVG per tema (6 ikon, satu per tema).
- B. Tekstur latar belakang papan pakai ikon tema (watermark samar).
- C. Ikon tipis di sebagian kotak biasa (deterministic, tidak menimpa kotak spesial).
- D. Variasi bentuk/border kotak per tema.
- E. Glow pion (baik yang diam di kotak maupun yang sedang animasi bergerak) memakai warna aksen tema papan.

**Out of scope:**
- 3D penuh (WebGL/kamera bebas) — keputusan lama di roadmap, tidak diubah di sini.
- Avatar pion itu sendiri (robot/explorer/dst) — itu identitas pilihan tim, tidak diubah oleh tema papan (sudah diklarifikasi dengan guru).
- Preview tema di form create game (`create.php`) — sudah selesai di pekerjaan sebelumnya (Task 5, commit `85533ca`); pekerjaan ini fokus ke papan gameplay yang sebenarnya.

## Fondasi Teknis (sudah ada, dipakai ulang)

Dari `app/Services/Game/GameEngine.php::publicTheme()`, snapshot papan sudah membawa `theme.key` (mis. `'jungle_quest'`) dan `theme.palette` (board/board2/tileA/tileB/accent/snake/ladder). Dari `public/assets/app.js::applyBoardTheme(element, theme)` (baris ~129-141), setiap kali papan dirender, fungsi ini SUDAH:
- Set `element.dataset.theme = theme.key` pada elemen `.board` (jadi CSS bisa langsung pakai selector `.board[data-theme="jungle_quest"]` tanpa kerja tambahan).
- Set custom property `--board-accent`, `--board-bg-a`, `--board-tile-a`, dst di elemen `.board`.

Ini berarti **Bagian D (variasi border) dan sebagian besar Bagian E (glow pion yang diam di kotak) tidak butuh perubahan JS sama sekali** — cukup CSS baru yang memanfaatkan `data-theme` dan `--board-accent` yang sudah tersedia.

## Bagian A — Ikon SVG per Tema

6 ikon sederhana, satu shape per tema, disimpan sebagai lookup di `app.js` (bukan file terpisah, konsisten dengan gaya codebase yang tidak punya folder aset gambar untuk board). Setiap ikon adalah SVG kecil (`viewBox="0 0 24 24"`) berisi satu bentuk siluet — dipakai lewat CSS `mask-image` (bukan `background-image`) supaya warnanya bisa diatur lewat `background-color`/`currentColor` di CSS, bukan di-bake ke dalam SVG per tema.

| Tema (`theme.key`) | Ikon | Bentuk SVG (path, viewBox 0 0 24 24) |
|---|---|---|
| `classic_arena` | Perisai | `<path d="M12 2 20 5v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5Z"/>` |
| `jungle_quest` | Daun | `<path d="M12 2C4 6 4 14 4 20 10 20 20 14 20 4 16 4 14 3 12 2Z"/>` |
| `space_mission` | Bintang jatuh | `<path d="M12 2 14 10 22 12 14 14 12 22 10 14 2 12 10 10Z"/>` |
| `ocean_quest` | Ombak | `<path d="M2 15c3-4 5-4 8 0s5 4 8 0 5-4 4 0" fill="none" stroke="black" stroke-width="2.4" stroke-linecap="round"/>` |
| `city_challenge` | Siluet gedung | `<path d="M3 21V10H8V21M10 21V4H15V21M17 21V13H21V21" fill="none" stroke="black" stroke-width="2"/>` |
| `lab_challenge` | Labu erlenmeyer | `<path d="M9 2h6v6l5 12c.8 1.8-.5 3-2.4 3H6.4C4.5 23 3.2 21.8 4 20l5-12Z"/>` |

Implementasi: sebuah object lookup `THEME_ICON_SHAPES` (key tema → string `<path>` di atas) plus fungsi baru `themeIconMaskUrl(themeKey)` di `app.js`. Fungsi ini membungkus shape terpilih (fallback ke `classic_arena` untuk key yang tidak dikenal, konsisten dengan fallback yang sudah ada di `applyBoardTheme`) ke dalam template `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">...</svg>`, meng-encode lewat `encodeURIComponent()` bawaan JS, lalu mengembalikan `'url("data:image/svg+xml,' + encoded + '")'`. Dipanggil dari `applyBoardTheme()` untuk set custom property baru:

```js
'--board-icon': themeIconMaskUrl(theme && theme.key),
```

## Bagian B — Tekstur Latar Belakang Papan

Layer baru `.board::before`, full-cover, di belakang `.board-grid` (perlu `.board-grid` diberi `position: relative; z-index: 1;` supaya tegas di atas layer tekstur ini):

```css
.board::before {
    background-color: var(--board-accent, #f97316);
    content: "";
    inset: 0;
    -webkit-mask-image: var(--board-icon);
    mask-image: var(--board-icon);
    -webkit-mask-repeat: repeat;
    mask-repeat: repeat;
    -webkit-mask-size: 46px 46px;
    mask-size: 46px 46px;
    opacity: .07;
    pointer-events: none;
    position: absolute;
    z-index: 0;
}
```

Tidak ada perubahan JS untuk bagian ini — cukup CSS baru, `--board-icon` dan `--board-accent` sudah tersedia dari Bagian A + fondasi teknis yang sudah ada.

## Bagian C — Ikon Tipis di Sebagian Kotak Biasa

Di `renderBoard()` (`app.js`, sekitar baris 102-123), tambah kondisi baru saat membangun `classes` per tile: kotak dapat class `tile-theme-icon` HANYA jika:
- Bukan kotak start (`tile !== 1`) atau finish (`tile !== total`).
- Tidak punya `special` (bukan ladder/snake/BONUS/TRAP/SAFE/MYSTERY — variabel `special` yang sudah dihitung di baris yang sama).
- `tile % 6 === 3` (pola tetap/deterministic berdasarkan nomor kotak — bukan acak tiap render — menghasilkan sebaran genap sekitar 1 dari 6 kotak).

```js
const classes = [
    'tile',
    tile === 1 ? 'tile-start' : '',
    tile === total ? 'tile-finish' : '',
    special ? 'tile-has-special' : '',
    (!special && tile !== 1 && tile !== total && tile % 6 === 3) ? 'tile-theme-icon' : '',
    (teamsByPosition[tile] || []).some((team) => team.uuid === currentTeamUuid) ? 'tile-current' : '',
    recentMovement && (recentMovement.from === tile || recentMovement.to === tile || recentMovement.landed === tile) ? 'tile-recent' : '',
].filter(Boolean).join(' ');
```

CSS baru:

```css
.tile-theme-icon::before {
    background-color: currentColor;
    bottom: 3px;
    content: "";
    -webkit-mask-image: var(--board-icon);
    mask-image: var(--board-icon);
    -webkit-mask-position: center;
    mask-position: center;
    -webkit-mask-repeat: no-repeat;
    mask-repeat: no-repeat;
    -webkit-mask-size: contain;
    mask-size: contain;
    opacity: .16;
    position: absolute;
    right: 3px;
    height: 60%;
    width: 60%;
    z-index: 0;
}
```

(`.tile` sudah `position: relative`, jadi `::before` ini otomatis relatif ke kotaknya. `color: #142033` sudah ada di `.tile`, jadi `currentColor` otomatis gelap — cukup kontras di atas warna kotak yang terang, tapi tetap samar karena opacity rendah.)

## Bagian D — Variasi Bentuk/Border Kotak per Tema

Pakai `.board[data-theme="..."] .tile` yang sudah otomatis tersedia dari `applyBoardTheme()`:

```css
.board[data-theme="jungle_quest"] .tile { border-radius: 14px 4px 14px 4px; }
.board[data-theme="space_mission"] .tile { border-radius: 2px; clip-path: polygon(6px 0, 100% 0, 100% calc(100% - 6px), calc(100% - 6px) 100%, 0 100%, 0 6px); }
.board[data-theme="ocean_quest"] .tile { border-radius: 4px 4px 12px 12px; }
.board[data-theme="city_challenge"] .tile { border-radius: 2px; }
.board[data-theme="lab_challenge"] .tile { border-style: dashed; border-width: 1.5px; }
```

(`classic_arena` tidak perlu override — dia yang jadi tampilan default `.tile` yang sudah ada, `border-radius: 6px` solid.)

## Bagian E — Glow Pion Mengikuti Tema Papan

**Pion yang diam di kotak** (`.pawn-token`, dirender di dalam `.board` lewat `renderBoard()`): tidak butuh perubahan JS, `--board-accent` sudah ter-cascade otomatis dari elemen `.board` induknya. Tambah CSS:

```css
.pawn {
    box-shadow: 0 1px 4px rgba(0, 0, 0, .3), 0 0 0 3px color-mix(in srgb, var(--board-accent, #f97316) 45%, transparent);
}
```

**Pion yang sedang animasi bergerak** (`.board-mover`, dibuat lewat `animateMovementEvent()` di `app.js` dan di-append ke `document.body` — DI LUAR elemen `.board`, jadi custom property `--board-accent` tidak ikut ter-cascade otomatis). Perlu satu baris JS tambahan di `animateMovementEvent()` (setelah baris `mover.style.setProperty('--team-color', team.color);`, sekitar baris 730):

```js
mover.style.setProperty('--board-accent', (snapshot.board && snapshot.board.theme && snapshot.board.theme.palette && snapshot.board.theme.palette.accent) || '#f97316');
```

CSS baru untuk `.board-mover` (tambahkan ke rule yang sudah ada di `app.css:1203`):

```css
.board-mover {
    box-shadow: 0 1px 4px rgba(0, 0, 0, .3), 0 0 0 4px color-mix(in srgb, var(--board-accent, #f97316) 55%, transparent);
}
```

## Testing / Verifikasi

Ini murni perubahan visual (CSS + JS rendering), tidak menyentuh logika game sama sekali — tidak ada test PHPUnit baru. Verifikasi:
- `node --check public/assets/app.js` — pastikan sintaks JS valid.
- Manual: buka dev server, buat room dengan tiap satu dari 6 tema, buka halaman detail (`/teacher/games/{uuid}`), controller tim, dan projector — screenshot tiap tema, konfirmasi:
  - Ikon tema terlihat di tekstur latar (samar, tidak mengganggu keterbacaan warna kotak).
  - Sekitar 1 dari 6 kotak biasa punya ikon tipis (bukan di kotak start/finish/BONUS/TRAP/SAFE/MYSTERY).
  - Bentuk kotak berbeda per tema (perhatikan sudut/border).
  - Pion (diam maupun animasi bergerak) punya cincin glow warna aksen tema papan.
  - Angka kotak tetap jelas terbaca di semua tema (kriteria gagal kalau ada tema di mana angka jadi susah dibaca).
- Regresi: jalankan `./vendor/bin/phpunit` — harus tetap 52/52 (tidak ada logic yang disentuh, tapi tetap dicek supaya yakin tidak ada file backend yang ikut ter-diff).

## Risiko

- **Browser compatibility `mask-image`**: didukung baik di Chrome/Edge/Firefox/Safari modern (dengan prefix `-webkit-` untuk Safari, sudah dimasukkan di atas). Proyek ini target-nya browser sekolah/laptop modern, risiko rendah.
- **Kepadatan visual**: opacity kecil (7% untuk tekstur latar, 16% untuk ikon kotak) sengaja dipilih konservatif supaya tidak mengganggu keterbacaan; kalau setelah dicoba di browser ternyata masih terlalu ramai atau malah kurang terasa, angka opacity ini yang paling gampang disetel ulang tanpa mengubah struktur kode.
