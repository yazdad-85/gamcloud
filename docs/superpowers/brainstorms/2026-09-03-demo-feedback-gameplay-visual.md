# Brainstorm - Feedback Demo Ular Tangga Edukatif

**Tanggal:** 2026-09-03  
**Status:** Login shield and dice UX implemented; order-roll pending  
**Masukan utama:** Demo sudah punya engine dasar, tetapi belum terasa seperti permainan ular tangga yang menarik untuk siswa.

## 1. Ringkasan Masalah Demo

1. Belum ada superadmin untuk melihat guru yang login/aktif.
2. Guru belum benar-benar login untuk mengelola bank soal.
3. Tombol lempar dadu terasa tidak bisa digunakan.
4. Belum ada penentuan siapa jalan dulu.
5. Papan terlalu polos, karakter hanya titik, tidak terasa seperti ular tangga, belum ada ketegangan atau efek game.

## 2. Diagnosis

### Tombol dadu

Secara backend, roll hanya valid saat:

- room sudah `PLAYING`,
- tim tersebut adalah `current_team`,
- turn state adalah `ROLL_READY`.

Di UI controller sekarang, saat syarat itu belum terpenuhi tombol tetap tampil sebagai tombol utama. Ini membuat siswa merasa tombolnya rusak. Perbaikan UX:

- kalau room masih `LOBBY`, tampilkan status "Menunggu guru memulai permainan";
- kalau bukan giliran, tombol diganti panel "Menunggu Tim X";
- kalau giliran, tombol aktif besar dengan animasi dadu;
- setelah roll, tombol hilang dan pertanyaan tampil;
- setiap disable state harus punya alasan eksplisit.

### Penentuan giliran

Demo langsung memakai tim pertama yang join sebagai pemain pertama. Ini tidak seru dan bisa dianggap tidak adil. Perlu fase baru:

```text
LOBBY -> ORDER_ROLL -> PLAYING
```

Setiap tim melempar dadu pembuka. Nilai tertinggi jalan dulu. Jika seri, tim yang seri roll ulang. Ini memberi momen pembuka dan ketegangan sebelum game utama.

## 3. Role dan Akses

### Superadmin

Superadmin harus melihat:

- daftar guru,
- status guru aktif/nonaktif,
- jumlah room dibuat tiap guru,
- room yang sedang berjalan,
- jumlah soal guru,
- riwayat login terakhir,
- tombol impersonate optional untuk support.

Route awal:

```text
/superadmin
/superadmin/teachers
/superadmin/teachers/{uuid}
/superadmin/rooms
```

### Guru Login

Guru harus login untuk:

- dashboard,
- bank soal,
- create room,
- laporan,
- import/export.

Tim tetap tidak perlu akun, cukup PIN + nama tim + token session.

Implementasi autentikasi:

- pakai CodeIgniter Shield secara penuh,
- migration Shield dipublish,
- group `superadmin`, `teacher`,
- permission `questions.manage`, `rooms.manage`, `teachers.view`, `reports.view`.

## 4. Konsep Visual Baru

Papan saat ini terlalu mirip tabel. Target baru: siswa langsung paham "ini ular tangga".

### Tema Papan

Minimal sediakan 4 tema:

| Tema | Suasana | Ular | Tangga | Karakter |
| --- | --- | --- | --- | --- |
| Jungle Quest | petualangan hutan | ular besar berwarna | jembatan akar | explorer anak |
| Space Race | luar angkasa | wormhole turun | portal naik | astronaut |
| Ocean Treasure | laut/pulau | pusaran air | ombak/kapal | bajak laut kecil |
| Castle Trial | kastil/fantasi | naga kecil | tangga batu | ksatria |

Setiap tema punya:

- background board,
- warna tile,
- ilustrasi ular/tangga,
- avatar pion,
- efek suara berbeda,
- animasi special tile.

### Board Layout

Board jangan hanya kotak putih/biru. Ubah menjadi:

- tile bertekstur ringan,
- border dan nomor lebih kecil,
- jalur zig-zag jelas,
- ular berupa kurva SVG/canvas dari tile `from` ke `to`,
- tangga berupa dua garis + anak tangga dari tile `from` ke `to`,
- kotak start dan finish dibuat besar/menonjol,
- tile aktif diberi spotlight.

Baseline implementasi sebaiknya tetap 2D Canvas/SVG dulu, bukan langsung Spline. Alasannya: ular/tangga dinamis antar template lebih mudah digambar presisi. Setelah 2D bagus, Spline/3D menjadi enhancement.

## 5. Karakter dan Pion

Titik warna harus diganti dengan karakter.

MVP karakter:

- sprite 2D/emoji-style avatar di board,
- nama tim muncul saat hover/projector highlight,
- animasi melompat antar tile,
- ekspresi menang/kalah saat jawaban benar/salah.

Karakter 3D:

- tahap berikutnya gunakan Spline/Three.js sebagai projector enhancement;
- controller tetap 2D ringan;
- board 3D tidak boleh menjadi dependency utama.

Paket karakter awal:

- Explorer,
- Astronaut,
- Knight,
- Scientist,
- Robot,
- Pirate.

## 6. Ketegangan Gameplay

### Roll Sequence

Saat giliran:

1. spotlight ke tim aktif,
2. countdown "Siap lempar",
3. animasi dadu 1-2 detik,
4. angka dadu muncul besar,
5. pertanyaan muncul,
6. timer berjalan,
7. jawaban dikunci,
8. benar/salah direveal,
9. pion bergerak tile per tile,
10. jika kena tangga/ular, animasi special diputar.

### Efek Benar/Salah

Jawaban benar:

- bunyi positif,
- confetti kecil,
- pion meloncat maju,
- jika kena tangga: kamera/board highlight jalur naik.

Jawaban salah:

- bunyi pendek tegang,
- "tetap di kotak X",
- optional mode penalti: mundur 1 atau kartu tantangan.

### Ular dan Tangga

Tangga:

- "Naik tangga!" dengan animasi pion meluncur naik,
- bonus kecil +20 poin optional.

Ular:

- "Tergigit ular!" dengan efek shake,
- pion turun mengikuti kurva ular,
- redemption question optional agar bisa mengurangi penalti.

### Near Finish Tension

Saat posisi >= 85:

- musik/efek lebih tegang,
- tile finish menyala,
- pertanyaan bernilai lebih besar,
- exact roll mode optional: harus pas sampai 100.

## 7. Variasi Permainan

Mode yang bisa dipilih guru saat create room:

1. **Classic Edu**
   Jawaban benar maju sesuai dadu, salah diam.

2. **Cepat Tepat**
   Skor ditambah berdasarkan sisa waktu menjawab.

3. **Redemption Snake**
   Saat kena ular, tim mendapat pertanyaan penyelamat. Benar: turun setengah saja. Salah: turun penuh.

4. **Power Tile**
   Beberapa tile punya efek: tukar posisi, bonus skor, roll lagi, beku satu giliran.

5. **Boss Finish**
   Kotak 100 membutuhkan pertanyaan final dari kategori sulit.

Untuk MVP berikutnya, ambil 2 mode dulu: Classic Edu dan Redemption Snake.

## 8. Perubahan Data yang Dibutuhkan

Tambahan field/tabel:

```text
game_rooms.turn_order_mode
game_rooms.theme_key
game_rooms.game_mode
game_rooms.order_roll_status

game_teams.order_roll_value
game_teams.character_key

board_templates.theme_key
board_templates.special_tiles_json
```

Tambahan state:

```text
LOBBY
ORDER_ROLL
PLAYING
PAUSED
FINISHED
```

Tambahan event:

```text
order_roll.started
order_roll.rolled
order_roll.resolved
dice.roll_requested
dice.rolling
dice.rolled
question.started
answer.resolved
movement.started
movement.stepped
special_tile.triggered
game.finished
```

## 9. Rekomendasi Implementasi Berikutnya

### Sprint 1 - Betulkan Alur Dasar

- [x] Aktifkan Shield auth penuh.
- [x] Tambah superadmin dashboard.
- [x] Guru wajib login untuk bank soal dan room.
- [x] Perbaiki UI dadu disabled state.
- Tambah fase penentuan giliran.

### Sprint 2 - Papan Menjadi Game

- Buat theme selector saat create room.
- Ganti board grid polos menjadi themed SVG/canvas board.
- Ganti pawn titik menjadi karakter.
- Tambah animasi dadu dan movement queue.
- Tambah efek benar/salah/tangga/ular.

### Sprint 3 - Variasi dan Ketegangan

- Tambah game mode Classic/Redemption.
- Tambah timer visual.
- Tambah sound/haptic optional.
- Tambah near-finish tension.
- Tambah laporan hasil game.

## 10. Prinsip Penting

- Jangan langsung memaksakan 3D sebelum 2D board terasa bagus.
- 3D/Spline adalah projector enhancement, bukan fondasi state.
- Controller siswa harus tetap ringan dan jelas.
- Semua efek visual mengikuti event backend, bukan membuat state sendiri.
- Papan harus terlihat sebagai arena bermain, bukan tabel database.

## 11. Implementasi Tahap 3 - Dadu

- Controller tim sekarang punya panel dadu dengan angka, status, dan alasan kenapa tombol belum aktif.
- Saat giliran tim dan turn `ROLL_READY`, tombol berubah menjadi siap lempar.
- Saat roll dikirim, dadu menampilkan animasi kocok dan tombol dikunci sementara.
- Setelah roll sukses, panel menampilkan nilai dadu dan pertanyaan aktif.
- Saat menjawab, tombol jawaban dikunci sementara untuk mencegah double submit.
- Teacher control menonaktifkan tombol Start setelah room masuk `PLAYING`.
- Event log menampilkan nama tim, nilai dadu, dan efek ular/tangga dari payload event.
