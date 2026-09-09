# Design Spec — Landing Visual Match Login (Approach A)

**Tanggal:** 2026-09-09  
**Status:** Approved (user chose A, then ok to implement)  
**Site:** `https://edugame.cloudedu.id`

## Problem

- `/` memakai layout landing terang yang berbeda dari `/login`.
- Di production, CSS landing sering tidak tampil (cache Cloudflare pada `/assets/app.css`), sehingga beranda terlihat polos (angka 1–16 tanpa style).
- User ingin beranda **disamakan visual** dengan `/login`, tanpa menggabungkan URL.

## Goals

1. `/` memakai shell visual yang sama dengan `/login` (`login-landing`: hero gelap + preview papan + feature strip + kartu kanan).
2. Kartu kanan di `/` adalah **kartu aksi** (Daftar Guru / Login Guru / Join Tim), bukan form login.
3. SEO meta beranda tetap (title, description, OG, favicon/logo dari platform settings).
4. Cache-bust ringan untuk `app.css` agar perubahan style langsung terlihat di CDN.

## Non-goals

- Menggabungkan `/` dan `/login` menjadi satu URL.
- Memindahkan form login ke beranda.
- Redesign penuh `/daftar-guru` atau `/join`.

## Design

### `/` structure

```
section.login-landing
  div.login-hero
    brand_logo (platform settings)
    eyebrow (tagline / site name)
    h1 + login-copy (copy beranda SEO)
    game-preview (sama pola /login)
    feature-strip (3 manfaat)
  aside.login-card
    head: “Mulai di sini”
    CTA: Daftar Guru (primary), Login Guru, Join Tim
    note + copyright tahun
```

### `/login`

Tidak berubah fungsi; tetap form. Visual sudah selaras karena berbagi class shell.

### CSS

- Reuse class `login-landing` / `login-hero` / `login-card` / `feature-strip` / `game-preview`.
- Tambah utility kecil untuk stack tombol di kartu beranda (mis. `.landing-actions`).
- Layout public untuk `/` sama seperti login (`public-page` full viewport), bukan `landing-body` scroll document lama.
- `layouts/public.php`: `app.css?v=<filemtime>` (atau hash pendek) untuk bypass cache CDN.

### SEO

- `Home::index` tetap set `seoTitle` / `seoDescription` / `seoPath=/`.
- Description boleh diambil dari `PlatformSettingsService` branding jika ada.

## Success criteria

- Tamu membuka `/` melihat UI visual setara `/login`, dengan kartu aksi di kanan.
- Logo/favicon dari pengaturan tampil.
- Hard refresh tidak wajib setelah deploy (berkat query `v=` pada CSS).
- Suite PHPUnit tetap hijau.

## Deploy

`git pull` di server. Purge cache Cloudflare untuk `/assets/app.css` jika masih menyajikan file lama (opsional setelah cache-bust).
