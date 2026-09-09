# Design Spec — Theme Atmosphere Enrichment (C)

**Tanggal:** 2026-09-09  
**Status:** Approved (user chose C; confirmed projector surface)  
**Depends on:** board 2.5D + audio cache-bust fixes

## Problem

Tema city (dan tema lain) hanya siluet ikon sangat samar di kotak — kurang terbaca sebagai “kota pendidikan”. Proyektor juga sempat menyajikan aset JS/CSS lama karena tanpa query `?v=`.

## Goals

1. Setiap tema punya **atmosfer latar** (skyline/hutan/laut/dll.) di belakang grid.
2. Motif kotak lebih sering & lebih jelas di proyektor.
3. City = skyline + aksen jendela/sekolah (bukan foto realistis).
4. Cache-bust aset di layout proyektor/controller.

## Non-goals

- Foto/PNG berat per tema.
- Ubah aturan game / backend.
- Panel samping 2.5D.

## Approach

- Layer `.board-atmosphere` + CSS var `--board-atmosphere` (SVG data-URL per `theme.key`).
- Naikkan densitas `tile-theme-icon`.
- `projector.php` / `controller.php`: `app.css|js` & `game-fx.*` pakai `?v=filemtime`.
