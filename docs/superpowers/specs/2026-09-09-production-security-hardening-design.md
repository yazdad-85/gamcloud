# Design Spec — Production Security Hardening

**Tanggal:** 2026-09-09  
**Status:** Approved (user chose projector approach A)  
**Konteks:** Melanjutkan audit keamanan setelah XSS JSON + password seeder. Repo publik: `yazdad-85/gamcloud`.

## Out of scope

- Self-registration guru tanpa approval superadmin — **by design** (konsep produk).
- Mengubah PIN menjadi non-6-digit (UX kelas dipertahankan).
- Membuat repo private / purge history Git (ops manual).

## Goals

1. UUID room saja tidak cukup untuk membuka projector atau membaca PIN.
2. Mutasi guru lewat API session dilindungi CSRF.
3. Cookie/HTTPS aman di produksi via env.
4. Upload tidak bisa dieksekusi sebagai skrip.
5. Command `demo:*` tidak jalan di production.
6. TTL session tim benar-benar ditegakkan.

---

## 1. Projector display token (pendekatan A)

### Model data

Tambah kolom pada `game_rooms`:

| Kolom | Tipe | Keterangan |
| --- | --- | --- |
| `projector_token_hash` | `VARCHAR(64)` nullable → not null setelah backfill | SHA-256 hex dari token plain |

- Token plain: `bin2hex(random_bytes(32))` (64 hex chars).
- Hanya **hash** disimpan di DB.
- Plain token hanya muncul di URL yang ditampilkan ke guru (halaman room/control) dan di response create room jika ada.

### Generate

Saat `GameEngine::createRoom`:

1. Generate plain token.
2. Simpan `hash('sha256', $plain)`.
3. Kembalikan plain di payload room untuk guru (field `projector_token`) — **jangan** masukkan ke `publicRoom()` / snapshot publik.

Migrasi existing rooms: generate token baru + hash saat migrate (atau lazy generate on first teacher view). Prefer **migration seeder backfill** agar semua room punya hash.

### Akses projector page

`GET /game/{uuid}/projector?t={token}`

- Baca query `t`.
- Load room by UUID.
- Validasi `hash_equals(room.projector_token_hash, hash('sha256', t))`.
- Gagal / kosong → **404** (jangan bocorkan keberadaan room).
- Sukses → render projector; snapshot **boleh** menyertakan PIN (layar kelas).

### Akses API state

Dua mode:

| Caller | Auth | PIN in snapshot? |
| --- | --- | --- |
| Projector poll | `?t=` valid projector token | Ya (butuh PIN di UI) |
| Lain (UUID only) | tidak ada | **Tidak** — `room.pin` di-strip / null |
| Guru (session + owner) | optional later | Ya di halaman guru (bukan lewat state publik) |
| Tim (session tim) | team session | Tidak perlu PIN di controller; strip PIN dari snapshot untuk team/API tanpa projector token |

Aturan konkret:

```text
snapshot($roomUuid, ?string $projectorToken = null): array
```

- Jika token projector valid → `publicRoom` termasuk `pin`.
- Jika tidak → `publicRoom` **tanpa** field `pin` (atau `pin => null`).
- `RoomsController::state` membaca `t` dari query string, meneruskan ke `snapshot`.
- JS projector harus mengirim `t` saat poll (simpan dari `URLSearchParams` / bootstrap config).

Teacher pages (`/teacher/games/{uuid}`, control) tetap menampilkan PIN dari data ownership (`assertRoomOwner` + row DB), bukan dari public snapshot.

### Link UI

Ganti semua link projector menjadi:

```text
/game/{uuid}/projector?t={plainToken}
```

Plain token harus tersedia di view guru. Karena DB hanya hash:

**Opsi implementasi (wajib dipilih satu):**

**A1 (disarankan):** Simpan plain token di kolom `projector_token` terenkripsi atau plain di DB hanya untuk pemilik — trade-off: plain di DB.

**A2:** Simpan hanya hash; plain ditampilkan **sekali** saat create dan disimpan di session guru / ditampilkan di flash. Link hilang setelah itu kecuali regenerate.

**A3:** Simpan hash + plain di DB (`projector_token` text) dengan akses hanya lewat `assertRoomOwner`. Praktis untuk MVP kelas; risiko: DB leak = token leak (masih lebih baik daripada UUID-only).

**Keputusan:** **A3** — kolom `projector_token` (plain) + `projector_token_hash` untuk verifikasi cepat konsisten; endpoint/view guru saja yang membaca plain; snapshot publik tidak pernah mengembalikan `projector_token`.

Regenerate: tombol opsional “Buat ulang link projector” di halaman guru (fase 2 jika waktu kurang; MVP cukup generate sekali di create + backfill).

---

## 2. CSRF pada mutasi API guru

### Masalah

`Filters` CSRF `except: api/*`. Teacher control memanggil POST `start/pause/...` dengan session cookie tanpa CSRF token.

### Solusi

1. Hapus blanket except untuk semua `api/*` **atau** kecualikan hanya path team-token yang tidak memakai cookie guru secara berbahaya.
2. Terapkan CSRF pada POST:

   - `api/v1/rooms/*/start`
   - `pause`, `resume`, `skip-turn`, `force-timeout`

3. Mutasi tim (`roll`, `answer`, …) tetap memakai `TeamSessionService` (bukan cookie guru sebagai capability utama). CSRF untuk team boleh ditambahkan jika `jsonFetch` mudah mengirim token; **MVP:** CSRF wajib untuk endpoint guru di atas; team endpoints tetap except sementara (dilindungi token tim + rate limit).

4. View teacher layout / control: expose CSRF hash ke JS (`csrf_hash()` / meta tag), `jsonFetch` kirim header `X-CSRF-TOKEN` sesuai config Shield/CI4.

---

## 3. Cookie secure + force HTTPS

- `App::$forceGlobalSecureRequests` membaca `env('app.forceGlobalSecureRequests', false)` (atau set via `.env` yang sudah didukung CI4).
- `Cookie::$secure` membaca env / true ketika production + HTTPS.
- `.env.example` sudah berisi flag produksi; pastikan dokumentasi deploy menekankan nilai ini.

Tidak memaksa `secure=true` di development HTTP lokal.

---

## 4. Upload hardening

Tambah `public/uploads/.htaccess`:

- `Options -Indexes`
- Tolak eksekusi: `RemoveHandler .php .phtml` / `php_flag engine off` (Apache) + deny script extensions.
- `public/uploads/index.html` kosong opsional.

Validasi MIME/size yang ada di `DocxQuestionImportService` tetap.

---

## 5. Demo CLI production guard

Di `DemoResetPlayCommand` dan `DemoForceFinishCommand`:

```php
if (ENVIRONMENT === 'production') {
    CLI::error('Demo commands disabled in production.');
    return EXIT_ERROR;
}
```

---

## 6. Team session TTL

- Saat join, session payload: `{ team_uuid, token, issued_at }` (unix timestamp).
- `TeamSessionService::assertTeamSession`: jika `now - issued_at > teamSessionTtlMinutes * 60` → tolak (401/redirect join).
- Baca TTL dari `config(Game::class)->teamSessionTtlMinutes`.
- Opsional: sliding refresh `issued_at` pada assert sukses — **MVP: fixed expiry dari issued_at** (lebih sederhana).

---

## 7. Join rate limit

Ubah POST `join` filter dari `15/60` menjadi **`8/60`** per IP.

PIN tetap 6 digit.

---

## Testing

| Area | Tes |
| --- | --- |
| Projector | Tanpa `t` → 404; `t` salah → 404; `t` benar → 200 + PIN di view |
| State API | Tanpa `t` → 200 tanpa `room.pin`; dengan `t` valid → ada PIN |
| CSRF guru | POST start tanpa token → 403; dengan token → OK |
| Team TTL | Session lama melewati TTL → ditolak |
| Demo CLI | Di production env → EXIT_ERROR |
| Upload | `.htaccess` ada di repo |

## Deploy notes

1. Jalankan migrasi sebelum deploy traffic.
2. Update bookmark projector guru (URL lama tanpa `t` putus — expected).
3. Set `.env` produksi: HTTPS + seed passwords baru.

## Success criteria

- Tidak ada cara mendapatkan PIN hanya dengan menebak/memiliki UUID room.
- Mutasi kontrol guru tidak bisa di-CSRF dari origin lain dengan cookie sesi.
- Demo commands mati di production.
- Session tim kadaluarsa sesuai konfigurasi.
