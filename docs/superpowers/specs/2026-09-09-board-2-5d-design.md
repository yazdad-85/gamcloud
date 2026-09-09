# Design Spec — Projector Board 2.5D + Richer Snakes

**Tanggal:** 2026-09-09  
**Status:** Approved (user: scope B, focus 2, realistic snakes; ok to implement)  
**Produk:** Edugame / Ular Tangga Kuis  

## Goals

1. Proyektor: papan terasa 2.5D (perspektif ringan, kotak ber-volume, atmosfer tema).
2. Ular digambar lebih realistis (body berpita, kepala/ekor jelas, kurva organik) — tetap SVG, warna tema.
3. Tangga diperhalus (bukan prioritas #1).
4. Form create game guru: preview tema mendekati gaya proyektor.
5. Controller tetap 2D datar (tidak pakai perspektif agresif).

## Non-goals

- WebGL / model 3D.
- PNG sprite ular per tema.
- Redesign panel samping proyektor (leaderboard/event) ke gaya 2.5D.
- Perubahan backend / schema.

## Approach

Client-only CSS + `renderSnakePath` / `renderLadderPath` di `app.js`, plus CSS create-game preview. Manfaatkan `data-theme` + palette CSS vars yang sudah ada.

### Proyektor board

- `.projector .board-stage` (atau `.projector .board`) dengan `perspective` + `rotateX` kecil (~6–10°).
- Tile: inset highlight + drop shadow ringan.
- Atmosfer: `::before`/`::after` pada stage memakai accent/tema.
- Pawn: `translateY` + `filter: drop-shadow` agar “berdiri”.

### Snake SVG

- Path S dengan 2–3 control points (cubic/quadratic chain).
- Stroke lebar + stroke outline gelap; optional second path untuk highlight sisik.
- Head group: ellipse + eyes (bukan circle polos saja).
- Tail: taper via marker atau secondary thin stroke ke ujung.

### Teacher create preview

- Upgrade `.theme-preview` menjadi mini board look: checker tiles + curved snake stroke + ladder rails (CSS/SVG inline kecil), sedikit skew/perspective.

## Success criteria

- Di proyektor, ular terbaca sebagai ular (kepala/badan/ekor), bukan garis + 2 titik.
- Papan punya kedalaman tanpa merusak keterbacaan nomor kotak dari jauh.
- Preview create theme terasa “sama keluarga” dengan proyektor.
- Controller readable, tanpa miring berlebihan.

## Files

| File | Role |
|------|------|
| `public/assets/app.js` | Snake/ladder SVG richer |
| `public/assets/app.css` | 2.5D projector + tile/pawn/atmosphere + create preview |
| `app/Views/teacher/games/create.php` | Markup preview jika perlu |
| Spec/plan docs | Jejak |

## Deploy

`git pull` — aset JS/CSS/view saja.
