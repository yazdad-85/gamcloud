# Design Spec — Platform Settings + Superadmin/Teacher Profiles

**Tanggal:** 2026-09-09  
**Status:** Approved (user chose scope C + storage approach 1)  
**Konteks:** Belum ada UI profil/pengaturan. Superadmin butuh branding (logo, dll.); guru butuh profil + ganti password.

## Goals

1. Superadmin mengelola **pengaturan platform** (nama situs, logo, favicon, OG image, deskripsi SEO default).
2. Superadmin mengelola **profil akun sendiri** (nama, email, password).
3. Guru mengelola **profil sendiri** (nama, sekolah, password; email read-only di MVP).
4. Landing/SEO/layout memakai branding dari DB dengan fallback ke aset default di `public/assets/brand/`.

## Non-goals (MVP)

- White-label per sekolah / multi-tenant branding.
- Tema warna custom, SMTP UI, 2FA.
- Foto profil guru.
- Mengubah email guru tanpa alur verifikasi (email tetap read-only).

## Approach

**Tabel `platform_settings` (key-value)** + **kolom `school_name` pada `teachers`** + ganti password via Shield `User::setPassword` setelah verifikasi password lama.

---

## 1. Data model

### `platform_settings`

| Kolom | Tipe | Keterangan |
|-------|------|------------|
| `id` | PK | |
| `key` | VARCHAR(64) UNIQUE | e.g. `site_name`, `logo_path`, `favicon_path`, `og_image_path`, `tagline`, `seo_description` |
| `value` | TEXT NULL | string path relatif `/uploads/brand/...` atau teks |
| `updated_at` | datetime | |

Keys MVP:

- `site_name` — default `Ular Tangga Edukatif`
- `tagline` — opsional
- `seo_description` — default meta description
- `logo_path` — relatif web, e.g. `/uploads/brand/logo-xxx.png`
- `favicon_path`
- `og_image_path`

### `teachers`

Tambah:

- `school_name` VARCHAR(190) NULL

### Auth

Password & email tetap di Shield (`users` / `auth_identities`). Nama tampilan guru di `teachers.name`; untuk superadmin tanpa baris `teachers`, simpan `display_name` di `platform_settings` key `superadmin_display_name` **atau** pakai `users.username` / extra identity — **keputusan MVP:** superadmin profil memakai Shield `username` + email identity; field “Nama tampilan” meng-update `username` (atau full name jika ada). Prefer: update Shield user `username` sebagai nama tampilan.

---

## 2. Routes & akses

| Method | Path | Role | Aksi |
|--------|------|------|------|
| GET/POST | `/superadmin/settings` | superadmin | Form branding platform |
| GET/POST | `/superadmin/profile` | superadmin | Profil + password |
| GET/POST | `/teacher/profile` | teacher (+ superadmin boleh buka jika punya teacher row; else hanya superadmin profile) | Profil guru |

Filter: `superadminAccess` / `teacherAccess` seperti modul lain. CSRF wajib.

Nav: tambah link di sidebar teacher layout (Profil) dan superadmin nav (Pengaturan, Profil).

---

## 3. Upload branding

- Folder: `public/uploads/brand/` (+ `.htaccess` deny PHP, sama pola uploads).
- Allowed: `image/png`, `image/jpeg`, `image/webp`, `image/svg+xml` (logo/favicon); OG: png/jpeg/webp.
- Max size: 2MB logo/favicon; 3MB OG.
- Simpan nama unik (`bin2hex(random_bytes(8))` + ext).
- Saat ganti file: hapus file lama jika path di bawah `/uploads/brand/` (jangan hapus default `/assets/brand/*`).

Service: `App\Services\Platform\PlatformSettingsService` — get/set, `branding()` array untuk views, upload helpers.

---

## 4. Consumption di UI/SEO

- `partials/seo_head.php`: `site_name`, `seo_description`, `og_image` dari settings (fallback default).
- Landing logo: dari `logo_path` atau `/assets/brand/logo.svg`.
- Favicon link: dari settings atau default.
- Sidebar text brand: `site_name`.

Helper view atau View Cell opsional: `branding()` once per request via service singleton/cached array.

---

## 5. Profil & password

### Guru POST `/teacher/profile`

- Update `teachers.name`, `teachers.school_name`
- Email ditampilkan read-only
- Password opsional: jika diisi, wajib `current_password`, `password`, `password_confirm` (min 10); verifikasi current via Shield authenticator/`check`; lalu `setPassword` + save

### Superadmin POST `/superadmin/profile`

- Update username/display + email identity (hati-hati unique email)
- Password sama pola

---

## 6. Files (expected)

| File | Role |
|------|------|
| Migration settings + `school_name` | Schema |
| `PlatformSettingsService` | Read/write/upload |
| `Superadmin\SettingsController` | Branding UI |
| `Superadmin\ProfileController` | Superadmin profil |
| `Teacher\ProfileController` | Guru profil |
| Views `superadmin/settings.php`, `superadmin/profile.php`, `teacher/profile.php` | Forms |
| Update layouts + `seo_head` + landing | Consume branding |
| Tests: settings get/set, upload reject bad mime, teacher profile update | PHPUnit |

---

## 7. Success criteria

- Superadmin dapat upload logo; landing & favicon berubah tanpa deploy ulang aset default.
- Guru dapat ubah nama/sekolah dan password.
- Tanpa setting, fallback aset default tetap jalan.
- Non-superadmin tidak akses `/superadmin/settings`.
- Guru A tidak bisa ubah profil guru B.

## Deploy

`git pull` → `composer install` → `php spark migrate` → pastikan `public/uploads/brand` writable.
