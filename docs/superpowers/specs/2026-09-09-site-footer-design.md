# Design Spec — Site Footer (Public + Dashboard)

**Tanggal:** 2026-09-09  
**Status:** Approved (user: scope C, content 1, year range from start year)  
**Site:** `https://edugame.cloudedu.id`

## Goals

1. Footer konsisten di halaman publik dan dashboard guru/superadmin.
2. Copyright memakai rentang tahun: `2026` atau `2026–YYYY` (tahun mulai → tahun berjalan).
3. Nama brand dari `PlatformSettingsService` (`site_name`, default Edugame).
4. Layar game (projector/controller) tetap tanpa footer.

## Non-goals

- CMS footer, multi-bahasa, link legal/privacy penuh.
- Footer di dalam room permainan.

## Design

### Year helper

- Konstanta `APP_START_YEAR = 2026` di config/service kecil.
- Format: jika tahun sekarang == start → `2026`; else `2026–2027` (en-dash).

### Partial `partials/site_footer.php`

- Props/view data: `$footerVariant` = `public` | `app`
- `public`: © + site name + links Beranda `/`, Login `/login`, Join `/join`
- `app`: © + site name only

### Layouts

- `layouts/public.php` — include footer setelah `<main>`
- `layouts/teacher.php` — include footer di dalam shell setelah `<main>` (atau di bawah main column)
- Jangan ubah `layouts/projector.php` / `layouts/controller.php`

### Landing cleanup

- Hapus baris © di kartu aksi `landing.php` (diganti footer layout)

### CSS

- `.site-footer` tipografi kecil, muted
- Variant public: boleh strip di bawah viewport; scroll pages tetap OK
- Variant app: padding ringan di bawah konten dashboard

## Success criteria

- `/`, `/login`, `/join`, daftar/verifikasi guru menampilkan footer publik.
- Dashboard teacher/superadmin menampilkan footer app.
- Projector/controller tanpa footer.
- Tahun rentang benar saat `date('Y')` > 2026 (uji helper).
