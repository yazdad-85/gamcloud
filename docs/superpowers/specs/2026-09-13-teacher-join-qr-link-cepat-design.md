# Design: QR Join di Link Cepat (Teacher Game Detail)

Tanggal: 2026-09-13  
Status: approved for planning

## Goal

Di halaman detail game guru (`/teacher/games/{uuid}`), panel **Link Cepat** menampilkan QR code join agar tim bisa join dengan mengetik/klik URL **atau** scan QR. URL QR mengikuti host/domain yang sedang dipakai (lokal maupun hosting).

## Non-goals

- QR untuk projector / control
- Download / print QR khusus
- Dependency PHP / Composer baru
- Mengubah alur join atau PIN

## UI

Panel kanan **Link Cepat** pada `app/Views/teacher/games/show.php`, urutan:

1. Judul `Link Cepat`
2. QR code (~180px) berisi absolute join URL
3. Teks bantuan singkat (scan untuk join)
4. Link/tombol `/join/{PIN}` (tetap klikable)
5. Tombol `Buka Projector` (tidak berubah)

## Behavior

- Join URL untuk QR: `window.location.origin + '/join/' + PIN`
- Di hosting otomatis memakai domain aktif; di lokal memakai host yang dibuka guru (mis. `127.0.0.1:8090` atau IP LAN)
- QR digenerate di client saat halaman load
- Jika library QR gagal load, link teks tetap berfungsi

## Technical approach

- Client-side library QR via CDN (pola sama seperti Chart.js di halaman laporan)
- Markup + script di `show.php` (section `scripts` layout teacher)
- CSS minimal di `public/assets/app.css` bila perlu agar QR rapi di panel

## Files expected to change

- `app/Views/teacher/games/show.php`
- `public/assets/app.css` (opsional, styling kecil)

## Testing

- Buka halaman detail game: QR tampil di Link Cepat
- Scan/decode QR → URL absolute `/join/{PIN}` dengan origin halaman saat ini
- Klik link teks `/join/{PIN}` masih bekerja
- Tombol Projector tidak berubah
