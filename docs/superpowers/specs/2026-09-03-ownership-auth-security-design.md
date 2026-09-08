# Design Spec - Ownership, Auth, dan Security

**Tanggal:** 2026-09-03  
**Status:** Login shield step 1 implemented  
**Konteks:** Aplikasi free, tetapi data guru dan room tetap harus terkunci per user.

## Prinsip Wajib

1. Guru wajib login untuk mengelola bank soal, membuat room, start game, kontrol game, dan melihat laporan.
2. Guru hanya dapat melihat/mengelola soal miliknya sendiri.
3. Guru tidak boleh melihat soal guru lain melalui UI, API, query manual endpoint, export, search, pagination, atau report.
4. Room dikunci ke `teacher_id` pemilik room.
5. Semua mutation game oleh guru harus memvalidasi pemilik room.
6. Tim tidak memakai akun, tetapi memakai PIN room dan session token tim.
7. Superadmin dapat mengakses seluruh modul dan melihat semua guru/aktivitas sistem; audit mutation akan ditambahkan bertahap.
8. Aplikasi free tetap harus punya rate limiting, CSRF, session security, logging, dan backup.
9. Self-registration guru harus memakai verifikasi email dan approval superadmin sebelum akun aktif.

## Role

| Role | Akses |
| --- | --- |
| Superadmin | seluruh modul, daftar guru, status guru, room aktif, audit, statistik platform |
| Guru | soal sendiri, room sendiri, laporan sendiri |
| Tim | controller room tempat dia join |

## Self-Registration Guru

Flow aman:

```text
Daftar -> Kode email -> Email terverifikasi -> Approval superadmin -> Akun aktif
```

Aturan:

- password disimpan oleh Shield pada akun nonaktif,
- kode verifikasi hanya disimpan sebagai hash,
- kode berlaku 15 menit,
- percobaan kode dibatasi,
- resend kode punya cooldown,
- akun guru baru belum dapat login sebelum approval,
- upload file tidak dibuka di flow registrasi.

## Data Ownership

### Questions

Query guru harus selalu menambahkan:

```text
questions.owner_teacher_id = current_teacher_id
```

Master soal global harus berada di modul terpisah:

```text
/teacher/master-questions
```

Guru boleh menyalin master soal ke soal pribadi. Setelah disalin, record baru memiliki `owner_teacher_id = current_teacher_id`.

### Game Room

Query guru harus selalu menambahkan:

```text
game_rooms.teacher_id = current_teacher_id
```

Endpoint control/start/pause/report harus memanggil policy:

```text
assertRoomOwner(roomUuid, currentTeacherId)
```

### Game Engine

Saat roll memilih soal, engine mengambil soal dari guru pemilik room:

```text
question.owner_teacher_id = room.teacher_id
question.status = PUBLISHED
```

Client tidak boleh mengirim `teacher_id`, `score`, `dice_value`, `position`, atau `is_correct`.

## Auth Implementation Plan

Gunakan CodeIgniter Shield penuh:

- publish config dan migration Shield,
- aktifkan CSRF session,
- group `superadmin` dan `teacher`,
- permission:
  - `teachers.view`
  - `questions.manage`
  - `rooms.manage`
  - `rooms.control`
  - `reports.view`
  - `audit.view`
- semua `/teacher/*` memakai auth filter,
- semua `/superadmin/*` memakai auth + permission filter.

## Free System Security Baseline

Karena aplikasi free, mitigasi abuse harus murah dan praktis:

1. **Rate limit per IP dan route**
   - join page,
   - join submit,
   - API state polling,
   - API mutation roll/answer/start,
   - login.

2. **CSRF**
   - wajib untuk form web guru dan superadmin,
   - API tim memakai token session tim/idempotency dan rate limit.

3. **Session**
   - regenerate session setelah login,
   - secure cookie di HTTPS,
   - HttpOnly,
   - SameSite Lax/Strict sesuai kebutuhan,
   - timeout realistis.

4. **DDoS/traffic spike**
   - aplikasi-level throttling hanya lapisan pertama,
   - production tetap perlu Cloudflare/proxy/CDN,
   - cache asset statis,
   - polling interval dibatasi,
   - endpoint state dibuat ringan,
   - batasi ukuran payload.

5. **Input validation**
   - batas panjang nama tim,
   - PIN numeric fixed length,
   - option_id harus milik question aktif,
   - file import dibatasi MIME dan ukuran.

6. **Audit**
   - login guru,
   - create room,
   - start/pause/finish,
   - score adjustment,
   - import/delete/publish soal.

7. **Disclosure control**
   - correct answer tidak dikirim ke controller sebelum answer resolved,
   - database ID internal tidak dikirim ke client kecuali ID opsi non-sensitif,
   - gunakan public UUID untuk room/team/question.

## Perubahan yang Sudah Dipasang

- CodeIgniter Shield sudah dipublish dan aktif untuk session login.
- Registration dan magic link login dinonaktifkan.
- Group Shield `superadmin` dan `teacher` sudah dibuat beserta permission awal.
- Seeder membuat akun `superadmin` dan `guru.demo`, lalu mengunci guru demo ke `auth_user_id`.
- `/teacher/*` memakai filter login + group teacher atau superadmin.
- `/superadmin/*` memakai filter login + group superadmin.
- Login redirect berbasis role: guru ke `/teacher`, superadmin ke `/superadmin`.
- `TenantContext` membaca guru aktif dan menjadi pusat ownership check; superadmin melewati ownership check.
- Dashboard guru, daftar game, dan bank soal sudah difilter ke teacher aktif.
- Superadmin yang membuka dashboard/bank soal/daftar game guru melihat semua data tanpa filter pemilik.
- Control/detail room guru memanggil ownership check.
- `GameEngine` memilih soal berdasarkan guru pemilik room.
- API start game wajib melewati ownership room.
- Controller tim dan API roll/answer wajib memakai session token tim dari proses join.
- Laporan dasar game guru sudah memakai ownership room.
- `RateLimitFilter` ditambahkan untuk login, join, teacher routes, dan API game.
- Security headers diaktifkan lewat filter `secureheaders`.
- UI tombol dadu menampilkan alasan saat belum dapat digunakan.

## Batasan Saat Ini

Superadmin baru memiliki dashboard ringkas dan daftar guru. Detail guru, room aktif lintas guru, audit log, validasi upload/import, dan checklist deployment DDoS masih menjadi tahap berikutnya.
