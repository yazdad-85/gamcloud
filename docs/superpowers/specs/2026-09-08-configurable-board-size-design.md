# Design: Ukuran Papan Bisa Diatur Guru

**Tanggal:** 2026-09-08
**Status:** Disetujui, siap masuk tahap rencana implementasi
**Terkait:** `docs/superpowers/plans/2026-09-08-create-game-mystery-box-redesign-plan.md` Task 7 (`applyMysteryTileCount()` — pola clone-on-difference yang dipakai ulang di sini)

## Ringkasan

Semua papan saat ini selalu 100 kotak (nilai tetap dari `board_templates.tile_count`). Guru minta bisa memilih ukuran papan lebih kecil. Solusinya memakai pola yang sama seperti fitur "Jumlah Kotak Mystery": kalau ukuran yang dipilih guru beda dari ukuran default template (selalu 100), engine meng-clone `board_templates` row itu dengan tata letak ular/tangga/kotak spesial yang sudah dirancang khusus untuk ukuran itu — tema (warna) tetap ikut template asal, cuma tata letaknya yang beda. Kalau guru pilih "Besar (100 kotak)" (default), tidak ada clone sama sekali — perilaku persis seperti sekarang, tidak ada regresi untuk siapa pun yang tidak mengubah pengaturan ini.

## Lingkup

**In scope:**
- 3 pilihan ukuran: Kecil (50 kotak), Sedang (70 kotak), Besar (100 kotak, default).
- Field baru "Ukuran Papan" di form create game, terpisah dari "Tema Papan".
- Tata letak ular/tangga/kotak spesial baru untuk 50 dan 70 kotak (dirancang tangan, bukan hasil skala otomatis dari layout 100 kotak).
- Kompatibel dengan fitur "Jumlah Kotak Mystery" yang sudah ada (clone berantai: ukuran dulu, baru mystery count).
- Cleanup otomatis saat room dihapus (memakai mekanisme `status='ROOM_INSTANCE'` yang sudah ada, tidak perlu kode baru).

**Out of scope:**
- Ukuran bebas/custom (selain 50/70/100) — sudah diputuskan pakai preset tetap saja.
- Mengubah `orderedTiles()` di client untuk mendukung ukuran yang bukan kelipatan 10 — tidak relevan karena semua preset sudah kelipatan 10.
- Redesign tata letak ular/tangga untuk papan 100 kotak yang sudah ada — tidak disentuh.

## Alasan teknis: kenapa harus kelipatan 10

`orderedTiles(total)` di `public/assets/app.js` membangun grid 10-kolom dengan pola ular (boustrophedon) memakai 10 baris tetap (posisi 1-100), lalu memfilter ke `<= total`. Untuk `total` kelipatan 10, hasilnya baris-baris penuh tanpa sisa (baris teratas yang tidak terpakai otomatis kosong seluruhnya, bukan sebagian) — jadi 50, 70, 100 semuanya aman dipakai tanpa mengubah kode client sama sekali. Ukuran yang bukan kelipatan 10 akan menghasilkan baris teratas yang terpotong separuh (rusak), makanya dihindari.

## Bagian A — Tata Letak Baru

### Papan 70 Kotak

- Tangga (6): `5→14`, `11→27`, `18→38`, `24→45`, `33→52`, `41→60`
- Ular (6): `16→6`, `30→13`, `47→22`, `55→31`, `64→50`, `68→57`
- Kotak spesial (6): `9=BONUS(50)`, `20=TRAP(3)`, `36=SAFE`, `43=MYSTERY`, `53=BONUS(75)`, `62=TRAP(4)`

### Papan 50 Kotak

- Tangga (5): `3→9`, `8→18`, `14→28`, `19→34`, `26→39`
- Ular (5): `12→4`, `22→11`, `31→16`, `42→24`, `47→32`
- Kotak spesial (5): `6=BONUS(50)`, `15=TRAP(3)`, `21=SAFE`, `30=MYSTERY`, `37=BONUS(75)`

Kedua tata letak di atas sudah diverifikasi manual: tidak ada satu kotak pun yang dipakai lebih dari sekali di antara posisi tangga/ular/spesial pada masing-masing ukuran (tidak ada tabrakan).

**Catatan konsekuensi kecil, sengaja diterima:** default nilai input "Jumlah Kotak Mystery" di form (angka 2) tidak otomatis menyesuaikan ke jumlah default kotak Mystery di tata letak ukuran yang dipilih (1 kotak untuk 50 dan 70 kotak). Ini bukan bug — kalau guru pilih ukuran 50/70 dan membiarkan input Mystery di angka default 2, hasilnya tetap benar (2 kotak Mystery di papan 50/70 kotak, lewat clone berantai di Bagian C), cuma angka "2" yang tertera di form tidak mencerminkan default asli ukuran itu. Tidak perlu JS tambahan untuk mensinkronkan ini — di luar cakupan.

## Bagian B — Field "Ukuran Papan" di Form

Di `app/Views/teacher/games/create.php`, tambah field baru setelah field "Jumlah Kotak Mystery" (atau di dekatnya):

```php
<div class="field">
    <label>Ukuran Papan</label>
    <div class="check-grid">
        <label class="check-option">
            <input type="radio" name="board_size" value="50" <?= old('board_size') === '50' ? 'checked' : '' ?>>
            <span>Kecil (50 kotak)</span>
        </label>
        <label class="check-option">
            <input type="radio" name="board_size" value="70" <?= old('board_size') === '70' ? 'checked' : '' ?>>
            <span>Sedang (70 kotak)</span>
        </label>
        <label class="check-option">
            <input type="radio" name="board_size" value="100" <?= old('board_size', '100') === '100' ? 'checked' : '' ?>>
            <span>Besar (100 kotak)</span>
        </label>
    </div>
    <p class="field-help">Ukuran memengaruhi jumlah kotak dan tata letak ular/tangga/kotak spesial, tidak mengganti tema warna papan.</p>
</div>
```

`.check-grid`/`.check-option` sudah ada di `app.css` (dipakai field "Scoring Tension"), jadi tidak perlu CSS baru.

## Bagian C — Backend: `applyBoardSize()`

Method baru di `GameEngine`, ditempatkan di dekat `applyMysteryTileCount()` (mengikuti pola yang persis sama, hanya bedanya meng-clone 3 kolom JSON sekaligus, bukan cuma `special_tiles_json`):

```php
private const BOARD_SIZE_LAYOUTS = [
    50 => [
        'ladders' => [
            ['from' => 3, 'to' => 9],
            ['from' => 8, 'to' => 18],
            ['from' => 14, 'to' => 28],
            ['from' => 19, 'to' => 34],
            ['from' => 26, 'to' => 39],
        ],
        'snakes' => [
            ['from' => 12, 'to' => 4],
            ['from' => 22, 'to' => 11],
            ['from' => 31, 'to' => 16],
            ['from' => 42, 'to' => 24],
            ['from' => 47, 'to' => 32],
        ],
        'special_tiles' => [
            ['tile' => 6, 'type' => 'BONUS', 'points' => 50, 'label' => 'Bonus 50'],
            ['tile' => 15, 'type' => 'TRAP', 'steps' => 3, 'label' => 'Trap mundur 3'],
            ['tile' => 21, 'type' => 'SAFE', 'label' => 'Perisai aman'],
            ['tile' => 30, 'type' => 'MYSTERY', 'label' => 'Misteri'],
            ['tile' => 37, 'type' => 'BONUS', 'points' => 75, 'label' => 'Bonus 75'],
        ],
    ],
    70 => [
        'ladders' => [
            ['from' => 5, 'to' => 14],
            ['from' => 11, 'to' => 27],
            ['from' => 18, 'to' => 38],
            ['from' => 24, 'to' => 45],
            ['from' => 33, 'to' => 52],
            ['from' => 41, 'to' => 60],
        ],
        'snakes' => [
            ['from' => 16, 'to' => 6],
            ['from' => 30, 'to' => 13],
            ['from' => 47, 'to' => 22],
            ['from' => 55, 'to' => 31],
            ['from' => 64, 'to' => 50],
            ['from' => 68, 'to' => 57],
        ],
        'special_tiles' => [
            ['tile' => 9, 'type' => 'BONUS', 'points' => 50, 'label' => 'Bonus 50'],
            ['tile' => 20, 'type' => 'TRAP', 'steps' => 3, 'label' => 'Trap mundur 3'],
            ['tile' => 36, 'type' => 'SAFE', 'label' => 'Perisai aman'],
            ['tile' => 43, 'type' => 'MYSTERY', 'label' => 'Misteri'],
            ['tile' => 53, 'type' => 'BONUS', 'points' => 75, 'label' => 'Bonus 75'],
            ['tile' => 62, 'type' => 'TRAP', 'steps' => 4, 'label' => 'Trap mundur 4'],
        ],
    ],
];

private function applyBoardSize(array $board, int $tileCount): array
{
    $layout = self::BOARD_SIZE_LAYOUTS[$tileCount] ?? null;
    if ($layout === null) {
        return $board;
    }

    $newBoardId = (new BoardTemplateModel())->insert([
        'public_uuid' => Uuid::v4(),
        'name' => $board['name'] . ' (' . $tileCount . ' Kotak)',
        'tile_count' => $tileCount,
        'ladders_json' => json_encode($layout['ladders'], JSON_UNESCAPED_SLASHES),
        'snakes_json' => json_encode($layout['snakes'], JSON_UNESCAPED_SLASHES),
        'special_tiles_json' => json_encode($layout['special_tiles'], JSON_UNESCAPED_SLASHES),
        'theme_json' => $board['theme_json'],
        'status' => 'ROOM_INSTANCE',
    ], true);

    return (new BoardTemplateModel())->find($newBoardId);
}
```

`applyBoardSize()` is only ever called with `50` or `70` (see wiring below) — a request for `100` never reaches this method at all, so there's no need for it to handle "clone to the same 100-tile layout the source already has" as a special case.

**Wiring in `createRoom()`:** insert the call BEFORE the existing `applyMysteryTileCount()` call (so mystery-tile placement, if also requested, operates on the size-appropriate board — its ladders/snakes/occupied-tile calculation already reads whatever `$board` it's given, so this composes correctly with zero changes to `applyMysteryTileCount()` itself):

```php
        if (isset($options['board_size']) && in_array((int) $options['board_size'], [50, 70], true)) {
            $board = $this->applyBoardSize($board, (int) $options['board_size']);
        }

        if (isset($options['mystery_tile_count'])) {
            $board = $this->applyMysteryTileCount($board, (int) $options['mystery_tile_count']);
        }
```

(This replaces the current single `if (isset($options['mystery_tile_count']))` block — the new block goes immediately above it, both inside `createRoom()` right after the existing "Board template belum tersedia" null-check.)

**Cleanup:** no code changes needed. `GameEngine::deleteRoom()` already deletes any `board_templates` row with `status === 'ROOM_INSTANCE'` associated with the deleted room (added for the Mystery-count feature) — a board-size clone uses the identical status value, so it's covered automatically.

## Bagian D — Controller Wiring

In `GameController::store()`, add right after the existing `$mysteryTileCount` block:

```php
        $boardSize = (string) $this->request->getPost('board_size');
        if (! in_array($boardSize, ['50', '70', '100'], true)) {
            $boardSize = '100';
        }
```

And add `'board_size' => (int) $boardSize,` to the options array passed to `$engine->createRoom(...)`, alongside the existing `mystery_tile_count` key.

## Testing

- Create room with `board_size => 70`: resulting room's board has `tile_count === 70`, exactly 6 ladders/6 snakes/6 special tiles matching the Bagian A layout, `status === 'ROOM_INSTANCE'`.
- Create room with `board_size => 100` (or omitted): `board_template_id` stays the SAME as the source ACTIVE template — no clone created.
- Create room with `board_size => 70` AND `mystery_tile_count => 4`: resulting board has `tile_count === 70` AND exactly 4 MYSTERY tiles (chained clone works correctly).
- Delete a room that used a board-size clone: the clone's `board_templates` row is deleted (reusing the existing `ROOM_INSTANCE` cleanup — this test mainly confirms no special-casing was accidentally needed).
- Regression: full suite (currently 52 tests) still passes unchanged.
