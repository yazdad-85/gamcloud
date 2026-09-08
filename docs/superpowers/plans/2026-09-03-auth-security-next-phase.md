# Implementation Plan - Auth, Ownership, dan Security

**Tanggal:** 2026-09-03  
**Status:** Step 1 login shield implemented

## Task 1: Ownership Baseline

- [x] Tambah `TenantContext`
- [x] Filter dashboard guru berdasarkan teacher aktif
- [x] Filter bank soal berdasarkan teacher aktif
- [x] Filter daftar game berdasarkan teacher aktif
- [x] Kunci detail/control room ke owner
- [x] Game engine memilih soal milik guru room
- [x] Kunci API start game ke owner room
- [x] Kunci controller tim dan API roll/answer ke session token tim
- [x] Tambah laporan dasar guru yang owner-scoped

## Task 2: Abuse Protection Baseline

- [x] Tambah filter rate limit umum
- [x] Rate limit join page dan join submit
- [x] Rate limit API state polling
- [x] Rate limit API mutation
- [x] Aktifkan security headers

## Task 3: Shield Auth Penuh

- [x] Jalankan/publish Shield setup secara terkontrol
- [x] Migration tabel user Shield
- [x] Seeder superadmin dan guru demo
- [x] Login/logout UI
- [x] Group dan permission
- [x] Auth filter untuk `/teacher/*`
- [x] Permission filter untuk `/superadmin/*`
- [x] Hilangkan fallback guru demo di production
- [x] Redirect login berbasis role
- [x] Login throttling dasar

## Task 4: Superadmin

- [x] Dashboard superadmin
- [x] Daftar guru
- [x] Approval pengajuan akun guru
- [ ] Detail guru: room, soal, aktivitas
- [ ] Daftar room aktif semua guru
- [ ] Audit log

## Task 5: Hardening Produksi

- [x] CSRF web forms aktif untuk login/logout/join/teacher
- [x] Self-registration guru dengan kode verifikasi email
- [x] Akun guru baru aktif setelah approval superadmin
- [ ] API team token validation
- [x] Login throttling dasar
- [ ] Upload size/MIME validation untuk import
- [ ] Backup dan restore checklist
- [ ] Cloudflare/proxy deployment checklist untuk mitigasi DDoS
- [ ] Error page production tanpa stack trace
