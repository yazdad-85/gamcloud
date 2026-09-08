# Design: Refactor Laporan Game (Pemenang, Pagination, Grafik, PDF)

**Tanggal:** 2026-09-09  
**Status:** Draft untuk review  
**Konteks:** Halaman `/teacher/games/{uuid}/report`

---

## 1. Masalah

Laporan game saat ini menampilkan metrik + tabel leaderboard/analisis/jawaban tanpa:

1. **Pemenang eksplisit** — guru harus menyimpulkan dari posisi/skor.
2. **Pagination** — tabel analisis soal & jawaban tim bisa panjang.
3. **Visualisasi** — tidak ada grafik akurasi / performa tim.
4. **Export** — tidak ada unduhan PDF untuk arsip/analisis soal.

Keputusan produk (disepakati):

- Refactor UI laporan menjadi lebih modern dan terbaca.
- PDF = **ringkasan pemenang + tabel analisis soal saja** (bukan jawaban tim).
- PDF **server-side Dompdf** (download file `.pdf`).

---

## 2. Tujuan

1. Banner/hero **pemenang** saat room `FINISHED` (berdasarkan juara papan / finish).
2. Leaderboard final yang menandai juara papan (dan skor sebagai metrik sekunder).
3. Pagination terpisah untuk **Analisis Soal** dan **Jawaban Tim**.
4. Dua grafik ringan (Chart.js CDN).
5. Tombol **Export PDF** → unduh analisis soal (+ ringkasan pemenang).

---

## 3. Pendekatan

**Pendekatan 1 (dipilih):** UI laporan modern di browser; PDF memakai template HTML terpisah + Dompdf.

Alasan: CSS modern + Chart.js tidak dipaksa masuk PDF; kualitas PDF lebih prediktabel.

---

## 4. Struktur Halaman Laporan

```text
Header: Judul + PIN + status + tombol Kembali
Ringkasan metrik: Tim | Jawaban | Event (tetap)

Banner Pemenang
  - Jika status FINISHED dan ada winner:
      “Pemenang: {nama}” · Kotak {max}/{posisi} · Skor {score}
  - Jika belum FINISHED:
      “Belum ada pemenang” · status room

Leaderboard Final
  - Urutan: posisi DESC, lalu skor DESC (atau tetap skor dulu + highlight juara papan)
  - Badge “Juara Papan” pada tim yang mencapai finish / winner event
  - Kolom: Peringkat | Tim | Skor | Posisi

Grafik
  1. Bar: akurasi (%) per soal (dari questionStats)
  2. Bar/stacked: jumlah benar vs salah per tim

Analisis Soal (paginated)
  - 10 baris / halaman (?soal_page=)
  - Tombol “Export PDF Analisis Soal”

Jawaban Tim (paginated)
  - 15 baris / halaman (?jawab_page=)
  - Tidak ikut PDF MVP
```

### Aturan pemenang di laporan

- Sumber utama: event `game.finished` → `winner_team_uuid` jika ada.
- Fallback: tim dengan `position >= max_position` (atau posisi tertinggi jika belum finish — **tanpa** label pemenang, hanya leaderboard).
- Skor tinggi **tidak** menggantikan juara papan (konsisten dengan aturan game).

### Urutan leaderboard (disepakati untuk laporan)

1. Tim pemenang (jika ada) di atas, atau  
2. Urut `position DESC`, lalu `score DESC`.

Implementasi default yang direkomendasikan: **position DESC, score DESC**, dengan badge pemenang — agar “siapa lebih maju di papan” jelas.

---

## 5. Pagination

| Section | Param | Ukuran halaman |
|---|---|---:|
| Analisis Soal | `soal_page` | 10 |
| Jawaban Tim | `jawab_page` | 15 |

- Validasi: page ≥ 1; page > last → clamp ke last (atau empty state).
- Link pagination mempertahankan param lain (`soal_page` & `jawab_page` independen).
- Slice dilakukan di service setelah query (MVP; dataset room tunggal biasanya kecil). Optimasi SQL `LIMIT/OFFSET` boleh belakangan jika perlu.

---

## 6. Grafik

- Library: **Chart.js** (CDN), hanya di-load di halaman report.
- Data disiapkan di controller/service sebagai array JSON aman (`json_encode` + escape).
- Chart 1: labels = ringkas stem (truncate ~40 karakter), data = akurasi %.
- Chart 2: labels = nama tim, datasets = benar / salah.
- Responsif; tinggi terbatas agar tidak mendominasi halaman.
- Jika data kosong: tampilkan empty state teks, jangan render chart kosong yang membingungkan.

---

## 7. Export PDF (Dompdf)

### Dependency

```bash
composer require dompdf/dompdf
```

### Route

```text
GET /teacher/games/{uuid}/report/pdf
Filter: teacherAccess + assertRoomOwner (sama seperti report HTML)
```

### Isi PDF

1. Judul game, PIN, tanggal generate  
2. Blok pemenang (atau “Belum ada pemenang”)  
3. Tabel analisis soal **lengkap** (semua soal, tidak ter-paginasi)  
   - Kolom: Soal | Jawaban | Benar | Akurasi  

### Bukan bagian PDF MVP

- Jawaban tim  
- Grafik Chart.js  
- Daftar event  

### Teknis

- View: `app/Views/teacher/games/report_pdf.php` (HTML sederhana + CSS inline/print-friendly).
- Controller method: `pdf(string $roomUuid)` memanggil Dompdf, `stream`/`download` dengan filename:

```text
laporan-{pin}-analisis-soal.pdf
```

- Encoding UTF-8; font Dompdf default atau DejaVu Sans agar karakter Indonesia aman.

---

## 8. Perubahan File (perkiraan)

| File | Perubahan |
|---|---|
| `app/Services/Report/GameReportService.php` | Winner resolve, agregat chart, pagination helpers |
| `app/Controllers/Teacher/ReportController.php` | `show` + `pdf`; query page params |
| `app/Views/teacher/games/report.php` | Layout baru + chart mounts + pagination UI |
| `app/Views/teacher/games/report_pdf.php` | Template PDF |
| `app/Config/Routes.php` | Route PDF |
| `public/assets/app.css` | Style laporan modern |
| `composer.json` / `composer.lock` | dompdf |

Tidak mengubah game engine / aturan menang runtime.

---

## 9. Testing

- Unit/service: winner resolution dari event / fallback posisi.
- Pagination: page 1 size, page 2 offset benar.
- Feature/HTTP (jika feasible): owner dapat `report/pdf` → `Content-Type: application/pdf`; non-owner ditolak.
- Manual: buka report room FINISHED → banner pemenang, grafik, pagination, unduh PDF terbuka.

---

## 10. Out of Scope

- PDF jawaban tim / grafik  
- Export Excel  
- Redesign seluruh modul teacher selain report  
- Realtime update laporan  

---

## 11. Decisions Log

| Topik | Keputusan |
|---|---|
| Pendekatan | UI modern + PDF Dompdf terpisah |
| Isi PDF | Pemenang + analisis soal saja |
| PDF tech | Dompdf server-side download |
| Pagination | Soal 10/hal; Jawaban 15/hal |
| Grafik | Chart.js CDN, 2 chart |
| Juara | Papan (finish), skor sekunder |

---

## 12. Success Criteria

- Room FINISHED menampilkan pemenang secara eksplisit di laporan.
- Analisis soal & jawaban tim punya pagination yang bekerja.
- Dua grafik tampil saat ada data.
- Guru bisa mengunduh PDF analisis soal dari tombol di halaman laporan.
- Ownership/akses PDF sama ketatnya dengan halaman report.
