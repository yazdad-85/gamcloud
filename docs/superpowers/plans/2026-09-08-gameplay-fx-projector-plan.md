# Gameplay FX (Dadu, Pion, Perayaan, Suara) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the animasi/efek/suara layer described in `docs/superpowers/specs/2026-09-08-gameplay-fx-projector-design.md` — dadu 3D yang sinkron, pion yang benar-benar jalan (bukan teleport, termasuk saat kena mystery box), perayaan jawaban benar/salah dan tile khusus (confetti + ikon + suara), dirangkai lewat satu sequencer per giliran di layar projector.

**Architecture:** Semua presentasi (dadu 3D, confetti, banner, ikon tile, suara sintetis) hidup di file baru `public/assets/game-fx.js` + `game-fx.css`, diekspos sebagai `window.GameFx` — modul ini tidak tahu apa-apa soal state game, cuma menerima koordinat/nilai/nama dan mengembalikan `Promise` yang resolve saat animasinya selesai. `public/assets/app.js` tetap pemilik polling/state/DOM-diff dan memanggil `GameFx.*` di titik-titik yang tepat. Satu perubahan kecil di backend (`GameEngine.php`) menambah field `movement` ke event `mystery.resolved` supaya pergerakan akibat mystery box bisa dianimasikan sama seperti pergerakan biasa.

**Tech Stack:** Vanilla JS (tanpa build step, tanpa dependency baru), CSS3 (3D transform, Web Animations API), Web Audio API (oscillator, tanpa file suara), PHP/CodeIgniter 4 untuk satu perubahan backend, PHPUnit untuk tesnya.

**Catatan soal testing:** Project ini tidak punya test runner JS (tidak ada `package.json`/Jest/dsb — dikonfirmasi saat riset). Mengikuti pola yang sudah dipakai di spec sebelumnya (`2026-09-08-board-theme-visual-redesign-design.md`), setiap task JS/CSS diverifikasi lewat `node --check` (sintaks valid) + langkah manual di browser (snippet devtools console atau interaksi nyata). Task backend (Task 9) yang satu-satunya punya PHPUnit, dan itu **wajib** ikut alur TDD penuh (test gagal dulu, baru implementasi).

---

### Task 1: Scaffold `game-fx.js` / `game-fx.css` + wiring ke layout + markup baru

**Files:**
- Create: `public/assets/game-fx.js`
- Create: `public/assets/game-fx.css`
- Modify: `app/Views/layouts/projector.php`
- Modify: `app/Views/layouts/controller.php`
- Modify: `app/Views/game/projector.php`

- [ ] **Step 1: Buat skeleton `game-fx.js`**

```js
(function () {
    'use strict';

    window.GameFx = {};
})();
```

- [ ] **Step 2: Buat skeleton `game-fx.css`**

```css
.fx-sound-unlock {
    align-items: center;
    background: rgba(9, 17, 31, .92);
    color: #fff;
    display: grid;
    inset: 0;
    justify-items: center;
    place-content: center;
    position: fixed;
    text-align: center;
    z-index: 60;
}

.fx-sound-unlock.hidden {
    display: none;
}

.fx-sound-unlock button {
    background: #2563eb;
    border: none;
    border-radius: 999px;
    color: #fff;
    cursor: pointer;
    font-size: 22px;
    font-weight: 800;
    padding: 18px 34px;
}

.fx-sound-unlock p {
    font-size: 15px;
    margin: 0 0 14px;
    opacity: .85;
}
```

- [ ] **Step 3: Wire kedua file baru ke layout projector**

Modify `app/Views/layouts/projector.php` — tambahkan `<link>` untuk `game-fx.css` setelah `app.css`, dan `<script>` untuk `game-fx.js` setelah `app.js`:

```php
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'Projector Game') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/game-fx.css">
</head>
<body class="projector">
<main class="game-screen">
    <?= $this->renderSection('content') ?>
</main>
<script src="/assets/app.js"></script>
<script src="/assets/game-fx.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
```

- [ ] **Step 4: Wire kedua file baru ke layout controller**

Modify `app/Views/layouts/controller.php` dengan cara yang sama:

```php
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= esc($title ?? 'Controller Tim') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/game-fx.css">
</head>
<body class="controller-page">
<main class="game-screen">
    <?= $this->renderSection('content') ?>
</main>
<script src="/assets/app.js"></script>
<script src="/assets/game-fx.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
```

- [ ] **Step 5: Tambah markup overlay "Aktifkan Suara" + panel dadu projector**

Modify `app/Views/game/projector.php` — tambahkan overlay unlock-suara (elemen baru, sibling dari `<div class="projector-grid">`) dan panel dadu projector (di dalam `<section>`, sibling dari `.board`):

```php
<?= $this->extend('layouts/projector') ?>

<?= $this->section('content') ?>
<?php $room = $snapshot['room']; ?>
<div class="fx-sound-unlock" data-fx-sound-unlock>
    <p>Ketuk untuk mengaktifkan suara efek permainan</p>
    <button type="button" data-fx-sound-unlock-button>🔊 Aktifkan Suara</button>
</div>
<div class="projector-grid">
    <section>
        <div class="board" data-board></div>
        <div class="projector-event-overlay hidden" data-event-overlay></div>
        <div class="fx-dice-panel hidden" data-projector-dice-panel>
            <div class="fx-die-mount" data-projector-die></div>
            <p data-projector-die-label></p>
        </div>
    </section>
    <aside class="grid">
        <div class="panel">
            <h1 class="page-title"><?= esc($room['title']) ?></h1>
            <p>Mode <strong><?= esc($snapshot['mode_state']['label'] ?? 'Ular Tangga Kuis') ?></strong></p>
            <p>PIN <strong><?= esc($room['pin']) ?></strong></p>
            <p>Status <strong data-room-status><?= esc($room['status']) ?></strong></p>
            <p>Giliran <strong data-current-team>-</strong></p>
            <div class="countdown-card">
                <span>Sisa waktu</span>
                <strong data-countdown>-</strong>
                <div class="countdown-track"><span data-countdown-bar></span></div>
            </div>
        </div>
        <div class="panel">
            <h2>Leaderboard</h2>
            <div class="leaderboard" data-leaderboard></div>
        </div>
        <div class="panel">
            <h2>Event</h2>
            <div class="event-log" data-events></div>
        </div>
        <div class="alert hidden" data-error></div>
    </aside>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
UlarTangga.projector({
    roomUuid: <?= json_encode($room['uuid']) ?>,
    snapshot: <?= json_encode($snapshot, JSON_UNESCAPED_SLASHES) ?>
});

document.querySelectorAll('[data-fx-sound-unlock-button]').forEach(function (button) {
    button.addEventListener('click', function () {
        GameFx.sound.unlock();
        button.closest('[data-fx-sound-unlock]').classList.add('hidden');
    });
});
</script>
<?= $this->endSection() ?>
```

- [ ] **Step 6: Tambah CSS panel dadu projector ke `game-fx.css`**

Append ke `public/assets/game-fx.css`:

```css
.fx-dice-panel {
    align-items: center;
    display: grid;
    gap: 10px;
    justify-items: center;
    margin-top: 14px;
}

.fx-dice-panel.hidden {
    display: none;
}

.fx-dice-panel p {
    color: #f8fafc;
    font-size: 18px;
    font-weight: 800;
    margin: 0;
}
```

- [ ] **Step 7: Verifikasi sintaks**

Run: `node --check public/assets/game-fx.js`
Expected: tidak ada output (exit code 0).

Run: `node --check public/assets/app.js`
Expected: tidak ada output (exit code 0, memastikan belum ada yang rusak).

- [ ] **Step 8: Verifikasi manual**

Jalankan dev server CI4 (`php spark serve` dari root `ular-tangga/`), buka halaman projector sebuah room. Konfirmasi:
- Tidak ada error di console browser (file `game-fx.js`/`game-fx.css` termuat, `window.GameFx` berupa object kosong `{}`).
- Overlay "Aktifkan Suara" muncul menutupi layar; klik tombolnya membuat overlay hilang tanpa error console.
- Panel dadu projector (`data-projector-dice-panel`) tidak terlihat (masih `hidden`).

- [ ] **Step 9: Commit**

```bash
git add public/assets/game-fx.js public/assets/game-fx.css app/Views/layouts/projector.php app/Views/layouts/controller.php app/Views/game/projector.php
git commit -m "feat: scaffold game-fx module and wire it into projector/controller layouts"
```

---

### Task 2: Sound engine (Web Audio API, tanpa file)

**Files:**
- Modify: `public/assets/game-fx.js`

- [ ] **Step 1: Tambahkan sound engine ke `game-fx.js`**

Edit `public/assets/game-fx.js`, sisipkan sebelum baris `window.GameFx = {};`:

```js
(function () {
    'use strict';

    var AudioCtx = window.AudioContext || window.webkitAudioContext;
    var audioCtx = null;

    function ensureAudioCtx() {
        if (!AudioCtx) {
            return null;
        }
        if (!audioCtx) {
            audioCtx = new AudioCtx();
        }
        return audioCtx;
    }

    function unlockSound() {
        var ctx = ensureAudioCtx();
        if (ctx && ctx.state === 'suspended') {
            ctx.resume();
        }
    }

    function tone(freq, startOffset, duration, waveType, gainPeak) {
        var ctx = ensureAudioCtx();
        if (!ctx || ctx.state !== 'running') {
            return;
        }
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = waveType || 'sine';
        osc.frequency.value = freq;
        var startAt = ctx.currentTime + startOffset;
        gain.gain.setValueAtTime(0.0001, startAt);
        gain.gain.linearRampToValueAtTime(gainPeak || 0.18, startAt + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.001, startAt + duration);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(startAt);
        osc.stop(startAt + duration + 0.02);
    }

    function mysterySweep() {
        var ctx = ensureAudioCtx();
        if (!ctx || ctx.state !== 'running') {
            return;
        }
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        var startAt = ctx.currentTime;
        osc.frequency.setValueAtTime(300, startAt);
        osc.frequency.exponentialRampToValueAtTime(720, startAt + 0.5);
        gain.gain.setValueAtTime(0.0001, startAt);
        gain.gain.linearRampToValueAtTime(0.16, startAt + 0.08);
        gain.gain.exponentialRampToValueAtTime(0.001, startAt + 0.55);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(startAt);
        osc.stop(startAt + 0.6);
    }

    var SOUND_LIBRARY = {
        dice: function () {
            for (var i = 0; i < 5; i++) {
                tone(180 + Math.random() * 90, i * 0.09, 0.06, 'square', 0.08);
            }
        },
        correct: function () {
            [523.25, 659.25, 783.99].forEach(function (freq, i) {
                tone(freq, i * 0.11, 0.22, 'triangle', 0.2);
            });
        },
        wrong: function () {
            tone(160, 0, 0.35, 'sawtooth', 0.18);
        },
        bonus: function () {
            [660, 990].forEach(function (freq, i) {
                tone(freq, i * 0.09, 0.16, 'square', 0.16);
            });
        },
        trap: function () {
            [400, 260].forEach(function (freq, i) {
                tone(freq, i * 0.1, 0.22, 'sawtooth', 0.16);
            });
        },
        mystery: function () {
            mysterySweep();
        },
        safe: function () {
            tone(880, 0, 0.3, 'sine', 0.14);
        },
        winner: function () {
            [523.25, 659.25, 783.99, 1046.5].forEach(function (freq, i) {
                tone(freq, i * 0.14, 0.3, 'triangle', 0.22);
            });
        },
    };

    function playSoundSafe(name) {
        var fn = SOUND_LIBRARY[name];
        if (fn) {
            fn();
        }
    }

    window.GameFx = {
        sound: {
            unlock: unlockSound,
        },
    };
})();
```

(Ini menggantikan seluruh isi file — variabel/fungsi lain akan ditambahkan di task berikutnya sebelum `window.GameFx = {...}` yang sama, dan object `window.GameFx` akan diperluas setiap task.)

- [ ] **Step 2: Verifikasi sintaks**

Run: `node --check public/assets/game-fx.js`
Expected: tidak ada output.

- [ ] **Step 3: Verifikasi manual di browser console**

Buka halaman projector, klik tombol "Aktifkan Suara" (supaya `AudioContext` running), lalu di devtools console jalankan (fungsi belum diekspos publik selain `unlock`, jadi untuk tes manual sementara expose lewat console):

```js
GameFx.sound.unlock();
```

Expected: tidak ada error. (Fungsi individual seperti `correct()`/`wrong()` baru bisa dites lewat UI publik setelah Task 6 mengekspos `GameFx.tileEffect`/`celebrateCorrect`/dst — cukup pastikan di sini `unlock()` tidak error dan `AudioContext` tidak throw karena browser policy.)

- [ ] **Step 4: Commit**

```bash
git add public/assets/game-fx.js
git commit -m "feat: add synthesized sound engine to game-fx"
```

---

### Task 3: Dadu 3D CSS (mount, resting state, roll sequencing)

**Files:**
- Modify: `public/assets/game-fx.js`
- Modify: `public/assets/game-fx.css`

- [ ] **Step 1: Tambahkan komponen dadu 3D ke `game-fx.js`**

Edit `public/assets/game-fx.js`. Sisipkan blok berikut tepat sebelum `window.GameFx = {` (di bawah `playSoundSafe`), lalu perluas object `window.GameFx`:

```js
    var FACE_NAMES = {1: 'front', 2: 'right', 3: 'top', 4: 'bottom', 5: 'left', 6: 'back'};

    var DIE_FACE_ROTATIONS = {
        1: {x: 0, y: 0},
        2: {x: 0, y: -90},
        3: {x: -90, y: 0},
        4: {x: 90, y: 0},
        5: {x: 0, y: 90},
        6: {x: 0, y: 180},
    };

    var dieStates = new WeakMap();

    function buildDieFaceHtml(value) {
        var pips = '';
        for (var i = 0; i < 9; i++) {
            pips += '<span class="fx-die-pip"></span>';
        }
        return '<div class="fx-die-face fx-die-face-' + FACE_NAMES[value] + '" data-value="' + value + '">' + pips + '</div>';
    }

    function mountDie(containerEl) {
        if (dieStates.has(containerEl)) {
            return dieStates.get(containerEl);
        }

        containerEl.innerHTML = '<div class="fx-die-scene"><div class="fx-die-cube">' +
            [1, 2, 3, 4, 5, 6].map(buildDieFaceHtml).join('') +
            '</div></div>';

        var state = {
            cube: containerEl.querySelector('.fx-die-cube'),
            rx: 0,
            ry: 0,
            raf: null,
            rolling: false,
            currentValue: null,
        };
        dieStates.set(containerEl, state);

        return state;
    }

    function getDieState(containerEl) {
        return dieStates.get(containerEl) || mountDie(containerEl);
    }

    function tumbleTick(state) {
        state.rx = (state.rx + 41) % 360;
        state.ry = (state.ry + 29) % 360;
        state.cube.classList.remove('settling');
        state.cube.style.transform = 'rotateX(' + state.rx + 'deg) rotateY(' + state.ry + 'deg)';
        state.raf = window.requestAnimationFrame(function () {
            tumbleTick(state);
        });
    }

    function settleOnValue(state, value) {
        var target = DIE_FACE_ROTATIONS[value] || DIE_FACE_ROTATIONS[1];
        state.cube.classList.add('settling');
        state.cube.style.transform = 'rotateX(' + (target.x + 720) + 'deg) rotateY(' + (target.y + 720) + 'deg)';
        state.rx = ((target.x % 360) + 360) % 360;
        state.ry = ((target.y % 360) + 360) % 360;
        state.currentValue = value;
    }

    function setDieResting(containerEl, value) {
        var state = getDieState(containerEl);
        if (state.rolling) {
            return;
        }
        var target = value ? Number(value) : (state.currentValue || 1);
        if (state.currentValue === target) {
            return;
        }
        settleOnValue(state, target);
    }

    function rollDie(containerEl, options) {
        var state = getDieState(containerEl);
        var minDurationMs = (options && options.minDurationMs) || 1200;
        var resultPromise = (options && options.resultPromise) || Promise.resolve(state.currentValue || 1);

        state.rolling = true;
        window.cancelAnimationFrame(state.raf);
        tumbleTick(state);

        var waitMinDuration = new Promise(function (resolve) {
            window.setTimeout(resolve, minDurationMs);
        });

        return Promise.all([resultPromise.catch(function () { return null; }), waitMinDuration]).then(function (results) {
            var value = results[0];
            window.cancelAnimationFrame(state.raf);
            var finalValue = value == null ? (state.currentValue || 1) : Number(value);
            settleOnValue(state, finalValue);
            if (value != null) {
                playSoundSafe('dice');
            }
            return new Promise(function (resolve) {
                window.setTimeout(function () {
                    state.rolling = false;
                    resolve(finalValue);
                }, 560);
            });
        });
    }

    window.GameFx = {
        sound: {
            unlock: unlockSound,
        },
        mountDie: mountDie,
        setDieResting: setDieResting,
        rollDie: rollDie,
    };
```

- [ ] **Step 2: Tambahkan CSS kubus dadu ke `game-fx.css`**

Append ke `public/assets/game-fx.css`:

```css
.fx-die-scene {
    --die-size: 64px;
    height: var(--die-size);
    margin: 0 auto;
    perspective: 320px;
    width: var(--die-size);
}

.dice-face .fx-die-scene {
    --die-size: 64px;
}

[data-projector-die] .fx-die-scene {
    --die-size: 120px;
}

.fx-die-cube {
    height: 100%;
    position: relative;
    transform-style: preserve-3d;
    width: 100%;
}

.fx-die-cube.settling {
    transition: transform .5s cubic-bezier(.22, 1.4, .36, 1);
}

.fx-die-face {
    background: #ffffff;
    border: 2px solid #1f2937;
    border-radius: 14%;
    box-shadow: 0 2px 10px rgba(0, 0, 0, .25);
    box-sizing: border-box;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    grid-template-rows: repeat(3, 1fr);
    height: 100%;
    padding: 12%;
    position: absolute;
    width: 100%;
}

.fx-die-face-front {
    transform: translateZ(calc(var(--die-size) / 2));
}

.fx-die-face-back {
    transform: rotateY(180deg) translateZ(calc(var(--die-size) / 2));
}

.fx-die-face-right {
    transform: rotateY(90deg) translateZ(calc(var(--die-size) / 2));
}

.fx-die-face-left {
    transform: rotateY(-90deg) translateZ(calc(var(--die-size) / 2));
}

.fx-die-face-top {
    transform: rotateX(90deg) translateZ(calc(var(--die-size) / 2));
}

.fx-die-face-bottom {
    transform: rotateX(-90deg) translateZ(calc(var(--die-size) / 2));
}

.fx-die-pip {
    align-self: center;
    background: #1f2937;
    border-radius: 50%;
    height: 22%;
    justify-self: center;
    opacity: 0;
    width: 22%;
}

.fx-die-face[data-value="1"] .fx-die-pip:nth-child(5) {
    opacity: 1;
}

.fx-die-face[data-value="2"] .fx-die-pip:nth-child(1),
.fx-die-face[data-value="2"] .fx-die-pip:nth-child(9) {
    opacity: 1;
}

.fx-die-face[data-value="3"] .fx-die-pip:nth-child(1),
.fx-die-face[data-value="3"] .fx-die-pip:nth-child(5),
.fx-die-face[data-value="3"] .fx-die-pip:nth-child(9) {
    opacity: 1;
}

.fx-die-face[data-value="4"] .fx-die-pip:nth-child(1),
.fx-die-face[data-value="4"] .fx-die-pip:nth-child(3),
.fx-die-face[data-value="4"] .fx-die-pip:nth-child(7),
.fx-die-face[data-value="4"] .fx-die-pip:nth-child(9) {
    opacity: 1;
}

.fx-die-face[data-value="5"] .fx-die-pip:nth-child(1),
.fx-die-face[data-value="5"] .fx-die-pip:nth-child(3),
.fx-die-face[data-value="5"] .fx-die-pip:nth-child(5),
.fx-die-face[data-value="5"] .fx-die-pip:nth-child(7),
.fx-die-face[data-value="5"] .fx-die-pip:nth-child(9) {
    opacity: 1;
}

.fx-die-face[data-value="6"] .fx-die-pip:nth-child(1),
.fx-die-face[data-value="6"] .fx-die-pip:nth-child(3),
.fx-die-face[data-value="6"] .fx-die-pip:nth-child(4),
.fx-die-face[data-value="6"] .fx-die-pip:nth-child(6),
.fx-die-face[data-value="6"] .fx-die-pip:nth-child(7),
.fx-die-face[data-value="6"] .fx-die-pip:nth-child(9) {
    opacity: 1;
}
```

- [ ] **Step 3: Verifikasi sintaks**

Run: `node --check public/assets/game-fx.js`
Expected: tidak ada output.

- [ ] **Step 4: Verifikasi manual — mount & resting state**

Di halaman projector (atau controller), devtools console:

```js
const mount = document.querySelector('[data-projector-die]');
GameFx.setDieResting(mount, 4);
```

Expected: kubus putih 3D muncul di dalam `[data-projector-die]`, menampilkan sisi dengan 4 titik (pola 4 sudut). Ganti angka 1-6 satu per satu dan screenshot/cek visual tiap nilai menampilkan jumlah titik yang benar DAN posisi pip yang benar (1=tengah, 2=diagonal dua titik, 3=diagonal tiga titik, 4=empat sudut, 5=empat sudut+tengah, 6=dua kolom tiga titik).

**PENTING — verifikasi orientasi rotasi:** karena `DIE_FACE_ROTATIONS` di atas adalah hasil perhitungan manual (belum pernah dijalankan di browser sungguhan), ada risiko realistis salah satu sisi menghadap terbalik/miring alih-alih tepat menghadap kamera. Untuk tiap nilai 1-6: pastikan wajah dadu yang tampil **rata menghadap layar** (bukan miring/terpotong). Kalau ada nilai yang tampil miring atau salah wajah, tukar tanda (`x` atau `y`) pada entri `DIE_FACE_ROTATIONS[value]` yang bersangkutan di `game-fx.js` dan uji ulang sampai keenam wajah tampil rata menghadap kamera.

- [ ] **Step 5: Verifikasi manual — roll sequencing**

```js
GameFx.rollDie(mount, {resultPromise: Promise.resolve(5), minDurationMs: 1200}).then((v) => console.log('settled on', v));
```

Expected: kubus berputar cepat/acak (~1.2 detik) lalu berhenti tepat di sisi angka 5 (empat sudut + tengah), console log `settled on 5` muncul setelah animasi berhenti (bukan sebelumnya).

- [ ] **Step 6: Commit**

```bash
git add public/assets/game-fx.js public/assets/game-fx.css
git commit -m "feat: add 3D CSS die component with tumble/settle sequencing"
```

---

### Task 4: Pasang dadu 3D di controller (perbaiki race condition)

**Files:**
- Modify: `public/assets/app.js:1039-1041` (drawDiceState)
- Modify: `public/assets/app.js:1110-1132` (roll click handler)
- Modify: `public/assets/app.js:1213-1226` (hapus `rollDiceAnimation` lama)

- [ ] **Step 1: Ganti baris yang menimpa teks dadu di `drawDiceState`**

Di `public/assets/app.js`, cari (sekitar baris 1039-1041):

```js
            if (diceDisplay && !isRolling) {
                diceDisplay.textContent = turn && turn.dice_value ? turn.dice_value : '?';
            }
```

Ganti menjadi:

```js
            if (diceDisplay && !isRolling) {
                GameFx.setDieResting(diceDisplay, turn && turn.dice_value ? Number(turn.dice_value) : null);
            }
```

- [ ] **Step 2: Ganti handler klik tombol roll**

Cari (sekitar baris 1110-1132):

```js
        if (rollButton) {
            rollButton.addEventListener('click', function () {
                if (rollButton.disabled || isRolling) {
                    return;
                }

                isRolling = true;
                rollButton.disabled = true;
                rollDiceAnimation(diceDisplay);
                drawController();
                runtime.setError('');
                jsonFetch('/api/v1/rooms/' + config.roomUuid + '/roll', {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid}),
                })
                    .then(runtime.refresh)
                    .catch((error) => runtime.setError(error.message))
                    .finally(() => {
                        isRolling = false;
                        drawController();
                    });
            });
        }
```

Ganti menjadi:

```js
        if (rollButton) {
            rollButton.addEventListener('click', function () {
                if (rollButton.disabled || isRolling) {
                    return;
                }

                isRolling = true;
                rollButton.disabled = true;
                drawController();
                runtime.setError('');

                const rollRequest = jsonFetch('/api/v1/rooms/' + config.roomUuid + '/roll', {
                    method: 'POST',
                    body: JSON.stringify({team_uuid: config.teamUuid}),
                });
                const diceValuePromise = rollRequest.then((data) => Number(data.current_turn.dice_value));
                const cubeSettled = GameFx.rollDie(diceDisplay, {resultPromise: diceValuePromise, minDurationMs: 1200});

                Promise.all([
                    rollRequest.then(runtime.refresh).catch((error) => runtime.setError(error.message)),
                    cubeSettled,
                ]).finally(() => {
                    isRolling = false;
                    drawController();
                });
            });
        }
```

- [ ] **Step 3: Hapus fungsi `rollDiceAnimation` yang sudah tidak dipakai**

Cari (sekitar baris 1213-1226):

```js
    function rollDiceAnimation(element) {
        if (!element) {
            return;
        }

        let ticks = 0;
        const interval = window.setInterval(() => {
            element.textContent = String(Math.floor(Math.random() * 6) + 1);
            ticks += 1;
            if (ticks >= 12) {
                window.clearInterval(interval);
            }
        }, 80);
    }

```

Hapus seluruh blok ini.

- [ ] **Step 4: Verifikasi sintaks**

Run: `node --check public/assets/app.js`
Expected: tidak ada output.

- [ ] **Step 5: Verifikasi manual end-to-end**

Buka dua browser tab: satu sebagai guru (start game), satu sebagai controller tim yang sedang giliran. Klik "Lempar Dadu". Konfirmasi:
- Kubus dadu berputar (bukan teks acak berkedip).
- Kubus berhenti **setelah** animasi minimum selesai (~1.2 detik), tidak lebih cepat dari itu meski request API selesai lebih cepat.
- Angka yang ditampilkan kubus (jumlah titik) sama persis dengan `turn.dice_value` yang tersimpan di state (bandingkan dengan info giliran/pertanyaan yang muncul setelahnya, yang perhitungannya berbasis dice_value yang sama).
- Setelah dadu berhenti, tombol kembali aktif/berubah sesuai state berikutnya (tidak macet).
- Reload halaman controller di tengah game (dadu belum pernah dilempar tim ini) — pastikan kubus muncul dalam keadaan diam tanpa error, tidak crash karena `turn.dice_value` null.

- [ ] **Step 6: Commit**

```bash
git add public/assets/app.js
git commit -m "fix: replace racy dice text flicker with synced 3D cube on controller"
```

---

### Task 5: Confetti, banner, dan ikon tile (primitive presentasi)

**Files:**
- Modify: `public/assets/game-fx.js`
- Modify: `public/assets/game-fx.css`

- [ ] **Step 1: Tambahkan primitive banner/confetti/ikon ke `game-fx.js`**

Sisipkan blok berikut sebelum `window.GameFx = {` (di bawah kode dadu dari Task 3), lalu perluas object export:

```js
    var CONFETTI_COLORS = ['#f97316', '#22c55e', '#facc15', '#38bdf8', '#f472b6', '#a78bfa'];

    var ICONS = {
        check: '<svg viewBox="0 0 24 24" width="40" height="40"><path fill="currentColor" d="M9 16.17 4.83 12l-1.42 1.41L9 19l12-12-1.41-1.41z"/></svg>',
        cross: '<svg viewBox="0 0 24 24" width="40" height="40"><path fill="currentColor" d="m12 10.59 4.95-4.95 1.41 1.41L13.41 12l4.95 4.95-1.41 1.41L12 13.41l-4.95 4.95-1.41-1.41L10.59 12 5.64 7.05l1.41-1.41z"/></svg>',
        shield: '<svg viewBox="0 0 24 24" width="36" height="36"><path fill="currentColor" d="M12 2 20 5v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5Z"/></svg>',
        bonus: '★',
        trap: '⚠',
        mystery: '?',
        winner: '🏆',
    };

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function fxBannerEl() {
        var el = document.querySelector('[data-fx-banner]');
        if (!el) {
            el = document.createElement('div');
            el.setAttribute('data-fx-banner', '');
            el.className = 'fx-banner hidden';
            document.body.appendChild(el);
        }
        return el;
    }

    function showFxBanner(opts) {
        var el = fxBannerEl();
        el.className = 'fx-banner fx-banner-' + opts.tone;
        el.innerHTML = '<span class="fx-banner-icon">' + opts.icon + '</span>' +
            '<strong>' + escapeHtml(opts.title) + '</strong>' +
            (opts.body ? '<span class="fx-banner-body">' + escapeHtml(opts.body) + '</span>' : '');

        return new Promise(function (resolve) {
            window.setTimeout(function () {
                el.classList.add('hidden');
                resolve();
            }, opts.durationMs || 2000);
        });
    }

    function confettiBurst(x, y, count) {
        var pieceCount = count || 32;
        for (var i = 0; i < pieceCount; i++) {
            (function () {
                var piece = document.createElement('div');
                piece.className = 'fx-confetti-piece';
                piece.style.left = x + 'px';
                piece.style.top = y + 'px';
                piece.style.background = CONFETTI_COLORS[i % CONFETTI_COLORS.length];
                document.body.appendChild(piece);

                var angle = Math.random() * Math.PI * 2;
                var distance = 90 + Math.random() * 140;
                var dx = Math.cos(angle) * distance;
                var dy = Math.sin(angle) * distance - 40;
                var rotate = Math.random() * 720 - 360;
                var duration = 900 + Math.random() * 500;

                var animation = piece.animate([
                    {transform: 'translate(-50%, -50%) translate(0, 0) rotate(0deg)', opacity: 1},
                    {transform: 'translate(-50%, -50%) translate(' + dx + 'px, ' + (dy + 180) + 'px) rotate(' + rotate + 'deg)', opacity: 0},
                ], {duration: duration, easing: 'cubic-bezier(.25,.65,.4,1)'});

                animation.finished.catch(function () { return null; }).finally(function () {
                    piece.remove();
                });
            })();
        }

        return new Promise(function (resolve) {
            window.setTimeout(resolve, 1500);
        });
    }

    function burstIcon(x, y, icon, tone) {
        var el = document.createElement('div');
        el.className = 'fx-tile-icon fx-tile-icon-' + tone;
        el.style.left = x + 'px';
        el.style.top = y + 'px';
        el.innerHTML = icon;
        document.body.appendChild(el);

        var animation = el.animate([
            {transform: 'translate(-50%, -50%) scale(.4)', opacity: 0},
            {transform: 'translate(-50%, -65%) scale(1.15)', opacity: 1, offset: .35},
            {transform: 'translate(-50%, -80%) scale(1)', opacity: 0},
        ], {duration: 1000, easing: 'ease-out'});

        return animation.finished.catch(function () { return null; }).finally(function () {
            el.remove();
        });
    }

    function flashScreen(tone) {
        var el = document.createElement('div');
        el.className = 'fx-screen-flash fx-screen-flash-' + tone;
        document.body.appendChild(el);

        var animation = el.animate([
            {opacity: 0}, {opacity: 1, offset: .15}, {opacity: 0},
        ], {duration: 500, easing: 'ease-out'});

        animation.finished.catch(function () { return null; }).finally(function () {
            el.remove();
        });
    }

    window.GameFx = {
        sound: {
            unlock: unlockSound,
        },
        mountDie: mountDie,
        setDieResting: setDieResting,
        rollDie: rollDie,
        confettiBurst: confettiBurst,
        banner: showFxBanner,
    };
```

- [ ] **Step 2: Tambahkan CSS banner/confetti/ikon/flash ke `game-fx.css`**

Append:

```css
.fx-banner {
    animation: fxBannerPop .3s ease-out;
    background: rgba(15, 23, 42, .92);
    border: 1px solid rgba(255, 255, 255, .28);
    border-radius: 12px;
    box-shadow: 0 26px 70px rgba(0, 0, 0, .4);
    color: #ffffff;
    display: grid;
    gap: 6px;
    justify-items: center;
    left: 50%;
    padding: 26px 34px;
    position: fixed;
    text-align: center;
    top: 28%;
    transform: translate(-50%, -50%);
    z-index: 45;
}

.fx-banner.hidden {
    display: none;
}

.fx-banner-icon {
    font-size: 44px;
    line-height: 1;
}

.fx-banner strong {
    font-size: clamp(28px, 4vw, 46px);
}

.fx-banner-body {
    color: #cbd5e1;
    font-size: 18px;
    font-weight: 800;
}

.fx-banner-correct {
    background: rgba(20, 83, 45, .94);
}

.fx-banner-wrong {
    background: rgba(127, 29, 29, .94);
}

.fx-banner-bonus {
    background: rgba(120, 53, 15, .94);
}

.fx-banner-trap {
    background: rgba(127, 29, 29, .94);
}

.fx-banner-mystery {
    background: rgba(88, 28, 135, .94);
}

.fx-banner-safe {
    background: rgba(30, 64, 175, .94);
}

.fx-banner-winner {
    background: rgba(120, 53, 15, .96);
}

@keyframes fxBannerPop {
    from {
        opacity: 0;
        transform: translate(-50%, -46%) scale(.94);
    }

    to {
        opacity: 1;
        transform: translate(-50%, -50%) scale(1);
    }
}

.fx-tile-icon {
    filter: drop-shadow(0 6px 10px rgba(0, 0, 0, .4));
    font-size: 40px;
    line-height: 1;
    pointer-events: none;
    position: fixed;
    z-index: 42;
}

.fx-tile-icon-bonus {
    color: #facc15;
}

.fx-tile-icon-trap {
    color: #ef4444;
}

.fx-tile-icon-mystery {
    color: #c084fc;
}

.fx-tile-icon-safe {
    color: #60a5fa;
}

.fx-screen-flash {
    inset: 0;
    pointer-events: none;
    position: fixed;
    z-index: 38;
}

.fx-screen-flash-danger {
    background: radial-gradient(circle, transparent 40%, rgba(239, 68, 68, .45) 100%);
}

.fx-confetti-piece {
    background: #f97316;
    border-radius: 2px;
    height: 12px;
    left: 0;
    pointer-events: none;
    position: fixed;
    top: 0;
    width: 8px;
    z-index: 40;
}
```

- [ ] **Step 3: Verifikasi sintaks**

Run: `node --check public/assets/game-fx.js`
Expected: tidak ada output.

- [ ] **Step 4: Verifikasi manual di browser console**

Di halaman projector (setelah suara diaktifkan):

```js
GameFx.confettiBurst(window.innerWidth / 2, window.innerHeight / 2, 30);
GameFx.banner({tone: 'correct', icon: '✓', title: 'Tes Banner', body: 'Tim Contoh', durationMs: 2000});
```

Expected: potongan confetti beterbangan dari tengah layar lalu hilang dalam ~1.5 detik; banner hijau besar muncul di tengah-atas layar lalu hilang setelah 2 detik.

- [ ] **Step 5: Commit**

```bash
git add public/assets/game-fx.js public/assets/game-fx.css
git commit -m "feat: add confetti, banner, and tile-icon presentation primitives"
```

---

### Task 6: Fungsi perayaan (correct/wrong/tile-effect/winner)

**Files:**
- Modify: `public/assets/game-fx.js`

- [ ] **Step 1: Tambahkan fungsi perayaan level-tinggi**

Sisipkan sebelum `window.GameFx = {` (di bawah kode Task 5), lalu perluas export:

```js
    var TILE_EFFECT_STYLE = {
        BONUS: {icon: ICONS.bonus, tone: 'bonus', sound: 'bonus'},
        TRAP: {icon: ICONS.trap, tone: 'trap', sound: 'trap'},
        MYSTERY: {icon: ICONS.mystery, tone: 'mystery', sound: 'mystery'},
        SAFE: {icon: ICONS.shield, tone: 'safe', sound: 'safe'},
        SAFE_BLOCK: {icon: ICONS.shield, tone: 'safe', sound: 'safe'},
    };

    function tileEffect(x, y, type, label) {
        var style = TILE_EFFECT_STYLE[String(type || '').toUpperCase()];
        if (!style) {
            return Promise.resolve();
        }
        playSoundSafe(style.sound);
        return burstIcon(x, y, style.icon, style.tone).then(function () {
            return showFxBanner({
                tone: style.tone,
                icon: style.icon,
                title: label,
                durationMs: 1400,
            });
        });
    }

    function celebrateCorrect(x, y, teamName) {
        playSoundSafe('correct');
        var confettiDone = confettiBurst(x, y, 36);
        return showFxBanner({
            tone: 'correct',
            icon: ICONS.check,
            title: 'Jawaban Benar',
            body: teamName,
            durationMs: 2200,
        }).then(function () {
            return confettiDone;
        });
    }

    function celebrateWrong(teamName) {
        playSoundSafe('wrong');
        flashScreen('danger');
        return showFxBanner({
            tone: 'wrong',
            icon: ICONS.cross,
            title: 'Belum Tepat',
            body: teamName,
            durationMs: 1500,
        });
    }

    function celebrateWinner(x, y, teamName) {
        playSoundSafe('winner');
        var confettiDone = Promise.all([
            confettiBurst(x, y, 50),
            confettiBurst(window.innerWidth / 2, window.innerHeight * 0.25, 50),
        ]);
        return showFxBanner({
            tone: 'winner',
            icon: ICONS.winner,
            title: 'Pemenang',
            body: teamName,
            durationMs: 4000,
        }).then(function () {
            return confettiDone;
        });
    }

    window.GameFx = {
        sound: {
            unlock: unlockSound,
        },
        mountDie: mountDie,
        setDieResting: setDieResting,
        rollDie: rollDie,
        confettiBurst: confettiBurst,
        banner: showFxBanner,
        tileEffect: tileEffect,
        celebrateCorrect: celebrateCorrect,
        celebrateWrong: celebrateWrong,
        celebrateWinner: celebrateWinner,
    };
```

- [ ] **Step 2: Verifikasi sintaks**

Run: `node --check public/assets/game-fx.js`
Expected: tidak ada output.

- [ ] **Step 3: Verifikasi manual di browser console**

Di halaman projector (suara sudah diaktifkan):

```js
GameFx.celebrateCorrect(window.innerWidth / 2, window.innerHeight / 2, 'Tim Roket').then(() => console.log('correct done'));
GameFx.tileEffect(window.innerWidth / 2, window.innerHeight / 2, 'TRAP', 'Trap: mundur ke 12');
GameFx.celebrateWrong('Tim Roket');
GameFx.celebrateWinner(window.innerWidth / 2, window.innerHeight / 2, 'Tim Roket');
```

Expected untuk masing-masing:
- `celebrateCorrect`: suara chime naik, confetti, banner hijau "Jawaban Benar / Tim Roket", console log "correct done" muncul setelah banner+confetti selesai.
- `tileEffect` TRAP: suara turun pendek, ikon ⚠ merah membesar-mengecil lalu banner merah singkat "Trap: mundur ke 12".
- `celebrateWrong`: buzz pendek, layar berkedip merah di tepi, banner merah "Belum Tepat / Tim Roket".
- `celebrateWinner`: fanfare 4 nada, confetti dua sumber (dari titik + dari atas layar), banner besar "Pemenang / Tim Roket" ~4 detik.

- [ ] **Step 4: Commit**

```bash
git add public/assets/game-fx.js
git commit -m "feat: add correct/wrong/tile-effect/winner celebration functions"
```

---

### Task 7: Perbaiki bug pion "teleport" (sinkronisasi render posisi)

**Files:**
- Modify: `public/assets/app.js:76-128` (`renderBoard`)
- Modify: `public/assets/app.js:738-785` (`animateMovementEvent`)

- [ ] **Step 1: Tambah `displayPositions` module-level map + fungsi `effectivePosition`**

Di `public/assets/app.js`, cari baris paling atas fungsi `renderBoard` (sekitar baris 76):

```js
    function renderBoard(element, snapshot) {
        if (!element || !snapshot) {
            return;
        }
```

Tambahkan SEBELUM `function renderBoard(...)`:

```js
    const displayPositions = new Map();

    function effectivePosition(team) {
        return displayPositions.has(team.uuid) ? displayPositions.get(team.uuid) : Number(team.position || 1);
    }

    function renderBoard(element, snapshot) {
        if (!element || !snapshot) {
            return;
        }
```

- [ ] **Step 2: Pakai `effectivePosition` di `renderBoard`**

Cari (sekitar baris 96-100):

```js
        (snapshot.teams || []).forEach((team) => {
            const position = Number(team.position || 1);
            teamsByPosition[position] = teamsByPosition[position] || [];
            teamsByPosition[position].push(team);
        });
```

Ganti menjadi:

```js
        (snapshot.teams || []).forEach((team) => {
            const position = effectivePosition(team);
            teamsByPosition[position] = teamsByPosition[position] || [];
            teamsByPosition[position].push(team);
        });
```

- [ ] **Step 3: Ubah `animateMovementEvent` supaya menerima `teamUuid` eksplisit, membekukan `displayPositions` selama animasi, dan mengembalikan `Promise`**

Cari (sekitar baris 738-785):

```js
    function animateMovementEvent(event, snapshot) {
        const movement = event.payload && event.payload.movement;
        if (!movement || Number(movement.from) === Number(movement.to)) {
            return;
        }

        const board = document.querySelector('[data-board]');
        const team = (snapshot.teams || []).find((item) => item.uuid === event.payload.team_uuid);
        if (!board || !team) {
            return;
        }

        const tilePath = movementTilePath(movement, snapshot);
        const points = tilePath
            .map((tile) => viewportTileCenter(board, tile))
            .filter(Boolean);
        if (points.length < 2) {
            return;
        }

        const mover = document.createElement('div');
        mover.className = 'board-mover';
        mover.classList.add('avatar-' + avatarClass(team.avatar));
        mover.style.setProperty('--team-color', team.color);
        mover.style.setProperty('--board-accent', (snapshot.board && snapshot.board.theme && snapshot.board.theme.palette && snapshot.board.theme.palette.accent) || '#f97316');
        mover.style.background = team.color;
        mover.innerHTML = '<span>' + escapeHtml(teamInitials(team.name)) + '</span>';
        document.body.appendChild(mover);

        const keyframes = points.map((point) => ({
            left: point.x + 'px',
            top: point.y + 'px',
            transform: 'translate(-50%, -50%) scale(1)',
        }));
        const duration = Math.min(3200, Math.max(900, points.length * 220));
        const animation = mover.animate(keyframes, {
            duration,
            easing: 'cubic-bezier(.2,.72,.2,1)',
            fill: 'forwards',
        });

        addTileEffect(board, Number(movement.to), movement.special);
        animation.finished
            .catch(() => null)
            .finally(() => {
                mover.remove();
            });
    }
```

Ganti menjadi:

```js
    function animateMovementEvent(event, snapshot, teamUuid) {
        const movement = event.payload && event.payload.movement;
        if (!movement || Number(movement.from) === Number(movement.to)) {
            return Promise.resolve();
        }

        const board = document.querySelector('[data-board]');
        const team = (snapshot.teams || []).find((item) => item.uuid === teamUuid);
        if (!board || !team) {
            return Promise.resolve();
        }

        const tilePath = movementTilePath(movement, snapshot);
        const points = tilePath
            .map((tile) => viewportTileCenter(board, tile))
            .filter(Boolean);
        if (points.length < 2) {
            return Promise.resolve();
        }

        displayPositions.set(team.uuid, Number(movement.from));
        renderBoard(board, snapshot);

        const mover = document.createElement('div');
        mover.className = 'board-mover';
        mover.classList.add('avatar-' + avatarClass(team.avatar));
        mover.style.setProperty('--team-color', team.color);
        mover.style.setProperty('--board-accent', (snapshot.board && snapshot.board.theme && snapshot.board.theme.palette && snapshot.board.theme.palette.accent) || '#f97316');
        mover.style.background = team.color;
        mover.innerHTML = '<span>' + escapeHtml(teamInitials(team.name)) + '</span>';
        document.body.appendChild(mover);

        const keyframes = points.map((point) => ({
            left: point.x + 'px',
            top: point.y + 'px',
            transform: 'translate(-50%, -50%) scale(1)',
        }));
        const duration = Math.min(3200, Math.max(900, points.length * 220));
        const animation = mover.animate(keyframes, {
            duration,
            easing: 'cubic-bezier(.2,.72,.2,1)',
            fill: 'forwards',
        });

        addTileEffect(board, Number(movement.to), movement.special);

        return animation.finished
            .catch(() => null)
            .finally(() => {
                mover.remove();
                displayPositions.delete(team.uuid);
                renderBoard(board, snapshot);
            });
    }
```

- [ ] **Step 4: Update pemanggil `animateMovementEvent` di `projector()`**

Cari (sekitar baris 616-618):

```js
                    if (event.event === 'answer.resolved') {
                        animateMovementEvent(event, snapshot);
                    }
```

Ganti menjadi:

```js
                    if (event.event === 'answer.resolved') {
                        animateMovementEvent(event, snapshot, event.payload.team_uuid);
                    }
```

(Pemanggilan untuk `mystery.resolved` akan ditambahkan di Task 10 setelah backend mengirim `movement`-nya.)

- [ ] **Step 5: Verifikasi sintaks**

Run: `node --check public/assets/app.js`
Expected: tidak ada output.

- [ ] **Step 6: Verifikasi manual end-to-end**

Mainkan game di projector sampai satu tim menjawab benar dan pindah kotak (tanpa kena tile khusus dulu). Konfirmasi:
- Pion **tidak** terlihat dobel/tumpang tindih (tidak ada pion diam di kotak tujuan sementara ghost masih terbang).
- Pion "hilang" dari kotak asal begitu ghost mulai terbang, dan baru "muncul" lagi (sebagai pion statis di grid) di kotak tujuan tepat saat ghost tiba, bukan sebelumnya.
- Setelah animasi selesai, posisi akhir pion di papan cocok dengan `team.position` yang sebenarnya (tidak nyangkut di posisi lama).

- [ ] **Step 7: Commit**

```bash
git add public/assets/app.js
git commit -m "fix: hide static pawn during flight so movement animation is the single source of truth"
```

---

### Task 8: Trap berjalan mundur kotak-demi-kotak

**Files:**
- Modify: `public/assets/app.js:787-816` (`movementTilePath`)

- [ ] **Step 1: Ganti logic lompatan tunggal jadi loop mundur untuk TRAP**

Cari (sekitar baris 787-816):

```js
    function movementTilePath(movement, snapshot) {
        const from = Number(movement.from);
        const landed = Number(movement.landed || movement.to);
        const to = Number(movement.to);
        const max = Number(snapshot.room && snapshot.room.max_position || snapshot.board.tile_count || 100);
        const path = [from];

        if (movement.finish_bounced) {
            for (let tile = from + 1; tile <= max; tile++) {
                path.push(tile);
            }
            for (let tile = max - 1; tile >= landed; tile--) {
                path.push(tile);
            }
        } else if (landed >= from) {
            for (let tile = from + 1; tile <= landed; tile++) {
                path.push(tile);
            }
        } else {
            for (let tile = from - 1; tile >= landed; tile--) {
                path.push(tile);
            }
        }

        if (to !== landed) {
            path.push(to);
        }

        return path.filter((tile, index, items) => tile >= 1 && tile <= max && (index === 0 || tile !== items[index - 1]));
    }
```

Ganti menjadi:

```js
    function movementTilePath(movement, snapshot) {
        const from = Number(movement.from);
        const landed = Number(movement.landed || movement.to);
        const to = Number(movement.to);
        const max = Number(snapshot.room && snapshot.room.max_position || snapshot.board.tile_count || 100);
        const path = [from];

        if (movement.finish_bounced) {
            for (let tile = from + 1; tile <= max; tile++) {
                path.push(tile);
            }
            for (let tile = max - 1; tile >= landed; tile--) {
                path.push(tile);
            }
        } else if (landed >= from) {
            for (let tile = from + 1; tile <= landed; tile++) {
                path.push(tile);
            }
        } else {
            for (let tile = from - 1; tile >= landed; tile--) {
                path.push(tile);
            }
        }

        if (to !== landed) {
            if (to < landed) {
                for (let tile = landed - 1; tile >= to; tile--) {
                    path.push(tile);
                }
            } else {
                for (let tile = landed + 1; tile <= to; tile++) {
                    path.push(tile);
                }
            }
        }

        return path.filter((tile, index, items) => tile >= 1 && tile <= max && (index === 0 || tile !== items[index - 1]));
    }
```

- [ ] **Step 2: Verifikasi sintaks**

Run: `node --check public/assets/app.js`
Expected: tidak ada output.

- [ ] **Step 3: Verifikasi manual**

Mainkan sampai satu tim menjawab benar dan mendarat di kotak TRAP. Konfirmasi ghost pion terlihat jalan maju dulu ke kotak landing, lalu **melangkah mundur kotak demi kotak** (bukan meluncur garis lurus) ke kotak tujuan trap.

- [ ] **Step 4: Commit**

```bash
git add public/assets/app.js
git commit -m "fix: animate trap tile backward movement tile-by-tile instead of a straight glide"
```

---

### Task 9: Backend — payload `movement` untuk `mystery.resolved`

**Files:**
- Modify: `app/Services/Game/GameEngine.php:691-760` (`resolveMysteryOutcome`)
- Test: `tests/database/GameEngineHardeningTest.php:462-567`

Ini satu-satunya task dengan test runner otomatis (PHPUnit) — ikuti TDD penuh: tulis assertion dulu (akan gagal), baru ubah implementasi.

- [ ] **Step 1: Tambah assertion `movement` ke 4 test yang sudah ada (harus gagal dulu)**

Di `tests/database/GameEngineHardeningTest.php`, method `testMysteryRewardSelfOnCorrectAnswer` (sekitar baris 462-483), tambahkan satu baris assertion sebelum penutup method:

```php
    public function testMysteryRewardSelfOnCorrectAnswer(): void
    {
        $engine = new GameEngine();
        $room = $engine->createRoom(1, 'Mystery Reward Test', [
            'turn_order_mode' => 'join_order',
            'scoring' => $this->noScoring(),
        ])['room'];
        $team = $engine->joinByPin($room['pin'], 'Tim Misteri')['team'];
        $this->answerCorrectWithForcedMove($engine, $room, $team, 45, 1);
        $engine->chooseMysteryTarget($room['uuid'], $team['public_uuid'], 'SELF');

        $turn = (new GameTurnModel())->where('room_id', $this->roomId($room['uuid']))->orderBy('id', 'DESC')->first();
        $optionId = $this->correctOptionId((int) $turn['question_id']);
        $snapshot = $engine->answerMystery($room['uuid'], $team['public_uuid'], $optionId);
        $updatedTeam = $this->teamFromSnapshot($snapshot, $team['public_uuid']);

        $this->assertSame(49, $updatedTeam['position']);
        $this->assertSame(180, $updatedTeam['score']);
        $this->assertSame('TURN_COMPLETED', (new GameTurnModel())->find($turn['id'])['state']);
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.resolved');
        $this->assertSame('REWARD_SELF', $lastEvent['payload']['outcome']);
        $this->assertSame(['from' => 46, 'landed' => 46, 'to' => 49], $lastEvent['payload']['movement']);
    }
```

Method `testMysteryPunishOpponentOnCorrectAnswer` (sekitar baris 485-512), tambahkan sebelum penutup:

```php
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.resolved');
        $this->assertSame('PUNISH_OPPONENT', $lastEvent['payload']['outcome']);
        $this->assertSame($opponent['public_uuid'], $lastEvent['payload']['affected_team_uuid']);
        $this->assertSame(['from' => 30, 'landed' => 30, 'to' => 26], $lastEvent['payload']['movement']);
    }
```

Method `testMysteryBoomerangsToSelfOnWrongAnswer` (sekitar baris 514-539), tambahkan sebelum penutup:

```php
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.resolved');
        $this->assertSame('BOOMERANG_SELF', $lastEvent['payload']['outcome']);
        $this->assertSame(['from' => 46, 'landed' => 46, 'to' => 42], $lastEvent['payload']['movement']);
    }
```

Method `testMysteryQuestionTimeoutBoomerangsToSelf` (sekitar baris 541-567), tambahkan sebelum penutup:

```php
        $lastEvent = $this->lastEvent($this->roomId($room['uuid']), 'mystery.resolved');
        $this->assertSame('BOOMERANG_SELF', $lastEvent['payload']['outcome']);
        $this->assertSame(['from' => 46, 'landed' => 46, 'to' => 42], $lastEvent['payload']['movement']);
    }
```

- [ ] **Step 2: Jalankan test, pastikan 4 assertion baru GAGAL**

Run: `./vendor/bin/phpunit --filter GameEngineHardeningTest`
Expected: FAIL pada `testMysteryRewardSelfOnCorrectAnswer`, `testMysteryPunishOpponentOnCorrectAnswer`, `testMysteryBoomerangsToSelfOnWrongAnswer`, `testMysteryQuestionTimeoutBoomerangsToSelf` — pesan error semacam `Failed asserting that an array has the key 'movement'` (karena field belum ada di payload).

- [ ] **Step 3: Implementasi — tambah `movement` ke payload `mystery.resolved`**

Di `app/Services/Game/GameEngine.php`, cari method `resolveMysteryOutcome` (sekitar baris 691-760):

```php
    private function resolveMysteryOutcome(array $room, array $team, array $turn, bool $isCorrect): array
    {
        $maxPosition = (int) $room['max_position'];
        $targetTeamId = $turn['mystery_target_team_id'] !== null ? (int) $turn['mystery_target_team_id'] : null;
        $finished = false;
        $finishedTeamUuid = null;

        if ($isCorrect && $targetTeamId === null) {
            $newPosition = $this->applyMysteryDeltaToTeam($room, $team, 80, 3, $maxPosition);
            $outcome = 'REWARD_SELF';
            $affectedTeamUuid = $team['public_uuid'];
            if ($newPosition >= $maxPosition) {
                $finished = true;
                $finishedTeamUuid = $team['public_uuid'];
            }
        } elseif ($isCorrect && $targetTeamId !== null) {
            $opponent = (new GameTeamModel())->find($targetTeamId);
            if ($opponent === null) {
                throw new DomainException('Tim target Kotak Misteri sudah tidak ada.');
            }
            $this->applyMysteryDeltaToTeam($room, $opponent, -60, -4, $maxPosition);
            $outcome = 'PUNISH_OPPONENT';
            $affectedTeamUuid = $opponent['public_uuid'];
        } else {
            // Wrong answer or timeout always punishes the answering team,
            // regardless of whether they had chosen SELF or an opponent.
            $this->applyMysteryDeltaToTeam($room, $team, -60, -4, $maxPosition);
            $outcome = 'BOOMERANG_SELF';
            $affectedTeamUuid = $team['public_uuid'];
        }
```

Ganti menjadi:

```php
    private function resolveMysteryOutcome(array $room, array $team, array $turn, bool $isCorrect): array
    {
        $maxPosition = (int) $room['max_position'];
        $targetTeamId = $turn['mystery_target_team_id'] !== null ? (int) $turn['mystery_target_team_id'] : null;
        $finished = false;
        $finishedTeamUuid = null;

        if ($isCorrect && $targetTeamId === null) {
            $fromPosition = (int) $team['position'];
            $toPosition = $this->applyMysteryDeltaToTeam($room, $team, 80, 3, $maxPosition);
            $outcome = 'REWARD_SELF';
            $affectedTeamUuid = $team['public_uuid'];
            if ($toPosition >= $maxPosition) {
                $finished = true;
                $finishedTeamUuid = $team['public_uuid'];
            }
        } elseif ($isCorrect && $targetTeamId !== null) {
            $opponent = (new GameTeamModel())->find($targetTeamId);
            if ($opponent === null) {
                throw new DomainException('Tim target Kotak Misteri sudah tidak ada.');
            }
            $fromPosition = (int) $opponent['position'];
            $toPosition = $this->applyMysteryDeltaToTeam($room, $opponent, -60, -4, $maxPosition);
            $outcome = 'PUNISH_OPPONENT';
            $affectedTeamUuid = $opponent['public_uuid'];
        } else {
            // Wrong answer or timeout always punishes the answering team,
            // regardless of whether they had chosen SELF or an opponent.
            $fromPosition = (int) $team['position'];
            $toPosition = $this->applyMysteryDeltaToTeam($room, $team, -60, -4, $maxPosition);
            $outcome = 'BOOMERANG_SELF';
            $affectedTeamUuid = $team['public_uuid'];
        }
```

Lalu cari pemanggilan `recordEvent` untuk `mystery.resolved` di method yang sama (sekitar baris 747-752):

```php
        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'mystery.resolved', [
            'team_uuid' => $team['public_uuid'],
            'affected_team_uuid' => $affectedTeamUuid,
            'is_correct' => $isCorrect,
            'outcome' => $outcome,
        ]);
```

Ganti menjadi:

```php
        $room = $this->roomById((int) $room['id']);
        $this->recordEvent($room, 'mystery.resolved', [
            'team_uuid' => $team['public_uuid'],
            'affected_team_uuid' => $affectedTeamUuid,
            'is_correct' => $isCorrect,
            'outcome' => $outcome,
            'movement' => ['from' => $fromPosition, 'landed' => $fromPosition, 'to' => $toPosition],
        ]);
```

- [ ] **Step 4: Jalankan test, pastikan semua lulus**

Run: `./vendor/bin/phpunit --filter GameEngineHardeningTest`
Expected: `OK (52 tests, 174 assertions)` — 52 test tetap (tidak nambah method baru, cuma nambah assertion), 174 assertion (170 + 4 assertion baru).

- [ ] **Step 5: Jalankan seluruh suite untuk pastikan tidak ada regresi**

Run: `./vendor/bin/phpunit`
Expected: semua test tetap lulus (jumlah total sama seperti sebelum perubahan, ditambah 4 assertion).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Game/GameEngine.php tests/database/GameEngineHardeningTest.php
git commit -m "feat: include movement payload on mystery.resolved so the pawn can animate"
```

---

### Task 10: Frontend — animasikan pergerakan mystery box

**Files:**
- Modify: `public/assets/app.js:616-618` (`projector()` onSnapshot)

- [ ] **Step 1: Trigger `animateMovementEvent` juga untuk `mystery.resolved`**

Cari (hasil dari Task 7 Step 4):

```js
                    if (event.event === 'answer.resolved') {
                        animateMovementEvent(event, snapshot, event.payload.team_uuid);
                    }
```

Ganti menjadi:

```js
                    if (event.event === 'answer.resolved') {
                        animateMovementEvent(event, snapshot, event.payload.team_uuid);
                    } else if (event.event === 'mystery.resolved') {
                        animateMovementEvent(event, snapshot, event.payload.affected_team_uuid);
                    }
```

- [ ] **Step 2: Verifikasi sintaks**

Run: `node --check public/assets/app.js`
Expected: tidak ada output.

- [ ] **Step 3: Verifikasi manual**

Mainkan sampai ada tim mendarat di kotak MYSTERY, pilih target (diri sendiri atau lawan), jawab soal HARD. Untuk ketiga outcome (jawab benar target SELF, jawab benar target lawan, jawab salah):
- **REWARD_SELF**: pion tim yang menjawab terlihat jalan maju (bukan lompat) ke posisi barunya.
- **PUNISH_OPPONENT**: pion **tim lawan** (bukan tim yang menjawab) yang terlihat jalan mundur.
- **BOOMERANG_SELF**: pion tim yang menjawab terlihat jalan mundur.

- [ ] **Step 4: Commit**

```bash
git add public/assets/app.js
git commit -m "feat: animate pawn movement for mystery box outcomes"
```

---

### Task 11: Sequencer — rangkai semua jadi satu alur per giliran di projector

**Files:**
- Modify: `public/assets/app.js:600-714` (`projector()` dan `overlayForEvent()`)

Ini task integrasi terbesar: mengganti penanganan event `dice.rolled`, `answer.resolved`, `tile.special_triggered`, `mystery.resolved`, `game.finished` di `projector()` supaya berjalan berurutan lewat `GameFx`, sementara event sederhana lain (`turn_order.selected`, `mystery.target_chosen`, `turn.timeout`, `game.paused`, `game.resumed`, `turn.skipped`) tetap lewat banner queue lama.

- [ ] **Step 1: Tambah markup label giliran dadu di panel projector (dipakai sequencer)**

(Elemen `[data-projector-die-label]` sudah dibuat di Task 1 Step 5 — tidak ada perubahan file di sini, cukup memastikan file itu ada.)

- [ ] **Step 2: Ganti fungsi `projector()`**

Cari (sekitar baris 600-625):

```js
    function projector(config) {
        const seenEvents = new Set((config.snapshot.events || []).map((event) => event.event_id));
        const overlayQueue = [];
        let overlayBusy = false;

        createRuntime(Object.assign({interval: 1600}, config, {
            onSnapshot(snapshot) {
                (snapshot.events || []).forEach((event) => {
                    if (seenEvents.has(event.event_id)) {
                        return;
                    }
                    seenEvents.add(event.event_id);
                    const item = overlayForEvent(event, snapshot);
                    if (item) {
                        overlayQueue.push(item);
                    }
                    if (event.event === 'answer.resolved') {
                        animateMovementEvent(event, snapshot, event.payload.team_uuid);
                    } else if (event.event === 'mystery.resolved') {
                        animateMovementEvent(event, snapshot, event.payload.affected_team_uuid);
                    }
                });
                playOverlayQueue(overlayQueue, () => overlayBusy, (value) => {
                    overlayBusy = value;
                });
            },
        }));
    }
```

Ganti menjadi:

```js
    const SEQUENCED_EVENTS = new Set([
        'dice.rolled',
        'answer.resolved',
        'tile.special_triggered',
        'mystery.resolved',
        'game.finished',
    ]);

    function projector(config) {
        const seenEvents = new Set((config.snapshot.events || []).map((event) => event.event_id));
        const overlayQueue = [];
        let overlayBusy = false;
        let sequenceBusy = Promise.resolve();

        createRuntime(Object.assign({interval: 1600}, config, {
            onSnapshot(snapshot) {
                (snapshot.events || []).forEach((event) => {
                    if (seenEvents.has(event.event_id)) {
                        return;
                    }
                    seenEvents.add(event.event_id);

                    if (SEQUENCED_EVENTS.has(event.event)) {
                        sequenceBusy = sequenceBusy.then(() => runSequencedEvent(event, snapshot));
                        return;
                    }

                    const item = overlayForEvent(event, snapshot);
                    if (item) {
                        overlayQueue.push(item);
                    }
                });
                playOverlayQueue(overlayQueue, () => overlayBusy, (value) => {
                    overlayBusy = value;
                });
            },
        }));
    }

    function runSequencedEvent(event, snapshot) {
        switch (event.event) {
            case 'dice.rolled':
                return runDiceRolledSequence(event, snapshot);
            case 'answer.resolved':
                return runMovementSequence(event, snapshot, event.payload.team_uuid, event.payload.is_correct);
            case 'tile.special_triggered':
                return runTileEffectSequence(event, snapshot);
            case 'mystery.resolved':
                return runMovementSequence(event, snapshot, event.payload.affected_team_uuid, null);
            case 'game.finished':
                return runWinnerSequence(event, snapshot);
            default:
                return Promise.resolve();
        }
    }

    function runDiceRolledSequence(event, snapshot) {
        const dieMount = document.querySelector('[data-projector-die]');
        const panel = document.querySelector('[data-projector-dice-panel]');
        const label = document.querySelector('[data-projector-die-label]');
        if (!dieMount || !panel) {
            return Promise.resolve();
        }

        if (label) {
            label.textContent = teamNameByUuid(event.payload.team_uuid, snapshot) + ' melempar dadu...';
        }
        panel.classList.remove('hidden');

        return GameFx.rollDie(dieMount, {
            resultPromise: Promise.resolve(event.payload.dice_value),
            minDurationMs: 1400,
        }).then(() => new Promise((resolve) => {
            window.setTimeout(() => {
                panel.classList.add('hidden');
                resolve();
            }, 700);
        }));
    }

    function runMovementSequence(event, snapshot, teamUuid, isCorrectOrNull) {
        const movement = event.payload && event.payload.movement;
        const board = document.querySelector('[data-board]');
        const team = (snapshot.teams || []).find((item) => item.uuid === teamUuid);
        const hasWalk = Boolean(movement && board && team && Number(movement.from) !== Number(movement.to));
        const walk = hasWalk ? animateMovementEvent(event, snapshot, teamUuid) : Promise.resolve();

        return walk.then(() => {
            if (!team || !board) {
                return null;
            }
            if (isCorrectOrNull === null) {
                return runMysteryBanner(event, snapshot);
            }
            const point = viewportTileCenter(board, Number(movement ? movement.to : team.position));
            if (!point) {
                return null;
            }
            return isCorrectOrNull
                ? GameFx.celebrateCorrect(point.x, point.y, team.name)
                : GameFx.celebrateWrong(team.name);
        });
    }

    function runMysteryBanner(event, snapshot) {
        const overlay = overlayForEvent(event, snapshot);
        if (!overlay) {
            return Promise.resolve();
        }
        const tone = overlay.tone === 'success' ? 'correct' : 'wrong';
        const icon = overlay.tone === 'success' ? '🎁' : '💥';
        return GameFx.banner({tone, icon, title: overlay.title, body: overlay.body, durationMs: 2000});
    }

    function runTileEffectSequence(event, snapshot) {
        const board = document.querySelector('[data-board]');
        const payload = event.payload || {};
        const effect = payload.effect || {};
        const movement = payload.movement || {};
        const targetTile = Number(effect.to != null ? effect.to : movement.to);
        if (!board || !targetTile) {
            return Promise.resolve();
        }
        const point = viewportTileCenter(board, targetTile);
        if (!point) {
            return Promise.resolve();
        }
        const type = String(effect.type || '').toUpperCase();
        const overlay = specialOverlay(effect, payload.team_uuid, snapshot);
        return GameFx.tileEffect(point.x, point.y, type, overlay.body);
    }

    function runWinnerSequence(event, snapshot) {
        const board = document.querySelector('[data-board]');
        const team = (snapshot.teams || []).find((item) => item.uuid === event.payload.winner_team_uuid);
        if (!board || !team) {
            return Promise.resolve();
        }
        const point = viewportTileCenter(board, Number(team.position));
        if (!point) {
            return Promise.resolve();
        }
        return GameFx.celebrateWinner(point.x, point.y, team.name);
    }
```

- [ ] **Step 3: Bersihkan `overlayForEvent` dari case yang sudah dipindah ke sequencer**

Cari (sekitar baris 644-714):

```js
    function overlayForEvent(event, snapshot) {
        const payload = event.payload || {};
        switch (event.event) {
            case 'turn_order.selected':
                return {
                    tone: 'info',
                    title: 'Giliran Pertama',
                    body: teamNameByUuid(payload.current_team_uuid, snapshot),
                };
            case 'dice.rolled':
                return {
                    tone: 'dice',
                    title: teamNameByUuid(payload.team_uuid, snapshot),
                    body: 'Dadu ' + payload.dice_value,
                };
            case 'answer.resolved': {
                const feedback = movementFeedbackText(payload);
                return {
                    tone: payload.is_correct ? 'success' : 'danger',
                    title: payload.is_correct ? 'Jawaban Benar' : 'Belum Tepat',
                    body: feedback ? feedback.body : teamNameByUuid(payload.team_uuid, snapshot),
                };
            }
            case 'tile.special_triggered':
                return specialOverlay(payload.effect || {}, payload.team_uuid, snapshot);
            case 'mystery.target_chosen':
                return {
                    tone: 'dice',
                    title: 'Kotak Misteri',
                    body: teamNameByUuid(payload.team_uuid, snapshot) + ' memilih ' + (payload.target === 'SELF' ? 'hadiah untuk timnya' : 'menyerang ' + teamNameByUuid(payload.target, snapshot)),
                };
            case 'mystery.resolved':
                return {
                    tone: payload.outcome === 'REWARD_SELF' ? 'success' : 'danger',
                    title: payload.outcome === 'REWARD_SELF' ? 'Misteri: Hadiah!' : (payload.outcome === 'PUNISH_OPPONENT' ? 'Misteri: Kena Serang!' : 'Misteri: Boomerang!'),
                    body: teamNameByUuid(payload.affected_team_uuid, snapshot) + (payload.outcome === 'REWARD_SELF' ? ' dapat efek positif' : ' kena efek negatif'),
                };
            case 'turn.timeout':
                return {
                    tone: 'danger',
                    title: 'Waktu Habis',
                    body: teamNameByUuid(payload.team_uuid, snapshot),
                };
            case 'game.paused':
                return {
                    tone: 'info',
                    title: 'Game Dijeda',
                    body: 'Ikuti arahan guru',
                };
            case 'game.resumed':
                return {
                    tone: 'success',
                    title: 'Game Dilanjutkan',
                    body: teamNameByUuid(snapshot.room.current_team_uuid, snapshot),
                };
            case 'turn.skipped':
                return {
                    tone: 'info',
                    title: 'Giliran Dilewati',
                    body: teamNameByUuid(payload.next_team_uuid, snapshot),
                };
            case 'game.finished':
                return {
                    tone: 'winner',
                    title: 'Pemenang',
                    body: teamNameByUuid(payload.winner_team_uuid, snapshot),
                };
            default:
                return null;
        }
    }
```

Ganti menjadi (menghapus case `dice.rolled`, `answer.resolved`, `tile.special_triggered`, `game.finished` yang sekarang ditangani sequencer — `mystery.resolved` tetap ada karena masih dipanggil manual dari `runMysteryBanner`):

```js
    function overlayForEvent(event, snapshot) {
        const payload = event.payload || {};
        switch (event.event) {
            case 'turn_order.selected':
                return {
                    tone: 'info',
                    title: 'Giliran Pertama',
                    body: teamNameByUuid(payload.current_team_uuid, snapshot),
                };
            case 'mystery.target_chosen':
                return {
                    tone: 'dice',
                    title: 'Kotak Misteri',
                    body: teamNameByUuid(payload.team_uuid, snapshot) + ' memilih ' + (payload.target === 'SELF' ? 'hadiah untuk timnya' : 'menyerang ' + teamNameByUuid(payload.target, snapshot)),
                };
            case 'mystery.resolved':
                return {
                    tone: payload.outcome === 'REWARD_SELF' ? 'success' : 'danger',
                    title: payload.outcome === 'REWARD_SELF' ? 'Misteri: Hadiah!' : (payload.outcome === 'PUNISH_OPPONENT' ? 'Misteri: Kena Serang!' : 'Misteri: Boomerang!'),
                    body: teamNameByUuid(payload.affected_team_uuid, snapshot) + (payload.outcome === 'REWARD_SELF' ? ' dapat efek positif' : ' kena efek negatif'),
                };
            case 'turn.timeout':
                return {
                    tone: 'danger',
                    title: 'Waktu Habis',
                    body: teamNameByUuid(payload.team_uuid, snapshot),
                };
            case 'game.paused':
                return {
                    tone: 'info',
                    title: 'Game Dijeda',
                    body: 'Ikuti arahan guru',
                };
            case 'game.resumed':
                return {
                    tone: 'success',
                    title: 'Game Dilanjutkan',
                    body: teamNameByUuid(snapshot.room.current_team_uuid, snapshot),
                };
            case 'turn.skipped':
                return {
                    tone: 'info',
                    title: 'Giliran Dilewati',
                    body: teamNameByUuid(payload.next_team_uuid, snapshot),
                };
            default:
                return null;
        }
    }
```

- [ ] **Step 4: Verifikasi sintaks**

Run: `node --check public/assets/app.js`
Expected: tidak ada output.

- [ ] **Step 5: Verifikasi manual — satu giliran penuh tanpa tile khusus**

Mainkan satu giliran biasa (jawab benar, tidak kena tile khusus). Urutan yang harus terlihat di projector:
1. Panel dadu projector muncul, kubus tumbling lalu berhenti di angka yang benar, panel hilang.
2. Pion jalan kotak-demi-kotak ke posisi baru.
3. Banner hijau + confetti + suara "Jawaban Benar".

- [ ] **Step 6: Verifikasi manual — giliran dengan tile BONUS**

Sama seperti di atas, tapi mendarat di kotak BONUS. Urutan: dadu → pion jalan → banner+confetti "Jawaban Benar" → ikon bintang bonus + suara + banner bonus.

- [ ] **Step 7: Verifikasi manual — jawaban salah**

Urutan: dadu → (tidak ada animasi jalan, posisi tidak berubah) → layar berkedip merah + suara buzz + banner "Belum Tepat", tanpa confetti.

- [ ] **Step 8: Verifikasi manual — game selesai**

Sampai satu tim mencapai finish. Urutan: dadu → pion jalan → banner+confetti jawaban benar → (bila ada tile khusus di kotak finish, ikon tile-nya) → confetti besar + fanfare + banner "Pemenang" ~4 detik.

- [ ] **Step 9: Commit**

```bash
git add public/assets/app.js
git commit -m "feat: sequence dice, movement, tile effects, and celebrations into one turn timeline"
```

---

### Task 12: Regresi penuh + checklist QA manual final

**Files:** (tidak ada file baru — task verifikasi menyeluruh)

- [ ] **Step 1: Jalankan seluruh suite PHPUnit**

Run: `./vendor/bin/phpunit`
Expected: semua test lulus (jumlah sama seperti baseline sebelum plan ini + 4 assertion baru dari Task 9, tidak ada test yang gagal).

- [ ] **Step 2: Validasi sintaks semua file JS yang disentuh**

Run: `node --check public/assets/app.js && node --check public/assets/game-fx.js && echo OK`
Expected: `OK`.

- [ ] **Step 3: Checklist QA manual (dari spec, jalankan di browser dengan minimal 2 tim)**

Buka projector + 2 controller tim, mainkan sampai mencakup semua skenario berikut, tandai tiap butir:

- [ ] Roll dadu di controller → kubus 3D tumbling lalu berhenti pas di angka yang benar (cocok dengan `dice_value` di state), tidak ada kedipan angka duluan.
- [ ] Roll dadu di projector (event `dice.rolled`) → kubus 3D projector tumbling lalu berhenti di angka yang sama dengan yang dilihat tim di controllernya.
- [ ] Jawab benar tanpa tile khusus → pion jalan kotak-demi-kotak (bukan snap/dobel), lalu confetti + banner + suara.
- [ ] Jawab benar kena BONUS → ikon bintang + suara bonus muncul pas pion tiba, lalu (atau sebelum, sesuai urutan Task 11) banner jawaban benar.
- [ ] Jawab benar kena TRAP → pion jalan maju ke kotak landing, lalu terlihat jalan **mundur** kotak-demi-kotak (bukan meluncur lurus) ke kotak trap, ikon+suara trap muncul.
- [ ] Jawab salah → layar berkedip merah + ikon silang + suara buzz, tanpa confetti, tanpa pion bergerak.
- [ ] Kena MYSTERY, pilih SELF, jawab benar → pion tim sendiri jalan maju, banner "Misteri: Hadiah!".
- [ ] Kena MYSTERY, pilih lawan, jawab benar → pion **tim lawan** (bukan tim yang menjawab) jalan mundur, banner "Misteri: Kena Serang!".
- [ ] Kena MYSTERY, jawab salah (atau timeout) → pion tim sendiri jalan mundur, banner "Misteri: Boomerang!".
- [ ] Game selesai (satu tim mencapai finish) → confetti besar + fanfare ~4 detik, tidak terpotong oleh poll berikutnya.
- [ ] Reload halaman projector di tengah game → overlay "Aktifkan Suara" muncul lagi, visual (dadu/pion/banner) tetap jalan normal sebelum diklik, suara baru terdengar setelah diklik.
- [ ] Tidak ada error di browser console sepanjang sesi manual test ini.

- [ ] **Step 4: Catat dan perbaiki temuan (jika ada)**

Kalau ada butir yang gagal di Step 3 (terutama kemungkinan orientasi dadu 3D yang salah, lihat catatan di Task 3 Step 4), perbaiki file terkait, ulangi `node --check`, lalu ulangi butir checklist yang relevan sampai lulus.

- [ ] **Step 5: Commit perbaikan (jika ada perubahan dari Step 4)**

```bash
git add -A
git commit -m "fix: address issues found during manual gameplay fx QA pass"
```

(Kalau tidak ada perubahan di Step 4, task ini selesai tanpa commit baru — seluruh pekerjaan sudah terccommit di task-task sebelumnya.)

---

## Self-Review

**Cakupan spec:**
- Bagian A (dadu 3D) → Task 3, 4, 11 (projector).
- Bagian B (fix teleport) → Task 7.
- Bagian C (movement payload mystery) → Task 9.
- Bagian D (perayaan benar/salah) → Task 6, 11.
- Bagian E (ikon tile khusus) → Task 6, 11.
- Bagian F (sequencer) → Task 11.
- Bagian G (sound engine + unlock overlay) → Task 2, 1 (overlay markup).
- Trap tile-by-tile (perbaikan tambahan disebut di Bagian B spec) → Task 8.
- Testing/Verifikasi spec → Task 12.

Semua bagian lingkup "in scope" di spec punya task yang mengimplementasikannya.

**Placeholder scan:** tidak ada "TBD"/"implement later"/instruksi tanpa kode konkret — setiap step berisi kode lengkap yang bisa langsung ditempel, atau perintah persis dengan expected output.

**Konsistensi tipe/nama:** `window.GameFx` diperluas secara konsisten task demi task (`sound.unlock` → `+mountDie/setDieResting/rollDie` → `+confettiBurst/banner` → `+tileEffect/celebrateCorrect/celebrateWrong/celebrateWinner`); `animateMovementEvent(event, snapshot, teamUuid)` dipakai dengan signature yang sama persis di Task 7, 10, 11; `displayPositions`/`effectivePosition` didefinisikan sekali di Task 7 dan dipakai di tempat yang sama tanpa nama berbeda di task lain.
