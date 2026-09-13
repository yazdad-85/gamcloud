# Teacher Join QR Link Cepat Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tampilkan QR code join di panel Link Cepat halaman detail game guru, dengan URL absolut mengikuti host/domain aktif, sementara link teks `/join/{PIN}` tetap bisa diklik.

**Architecture:** Markup QR + data path join ditambahkan di view `teacher/games/show.php`. Library `qrcode` dimuat dari CDN (pola sama Chart.js di laporan). Script kecil membangun `window.location.origin + data-join-path` lalu menggambar canvas ~180px. CSS minimal merapikan layout di panel. Feature test memverifikasi kontrak HTML (container, path, CDN, help text, link join, projector).

**Tech Stack:** CodeIgniter 4 views, PHPUnit FeatureTestTrait + Shield AuthenticationTesting, CDN `qrcode@1.5.4`, CSS di `public/assets/app.css`

**Spec:** `docs/superpowers/specs/2026-09-13-teacher-join-qr-link-cepat-design.md`

---

## File map

| File | Responsibility |
|------|----------------|
| `tests/feature/TeacherGameShowJoinQrTest.php` | Feature test kontrak markup Link Cepat + QR |
| `app/Views/teacher/games/show.php` | Markup Link Cepat + section scripts QR |
| `public/assets/app.css` | Style kecil untuk blok QR |

---

### Task 1: Feature test kontrak HTML QR join

**Files:**
- Create: `tests/feature/TeacherGameShowJoinQrTest.php`

- [ ] **Step 1: Write the failing test**

Buat file `tests/feature/TeacherGameShowJoinQrTest.php`:

```php
<?php

use App\Models\TeacherModel;
use App\Services\Game\GameEngine;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class TeacherGameShowJoinQrTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace = ['App', 'CodeIgniter\Shield', 'CodeIgniter\Settings'];
    protected $seed = App\Database\Seeds\DemoGameSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();
    }

    public function testShowPageIncludesJoinQrMarkupAndKeepsJoinLink(): void
    {
        $room = (new GameEngine())->createRoom(1, 'QR Link Cepat', [
            'game_mode' => 'QUIZ_RACE',
            'participation_mode' => 'TEAM_DEVICE',
        ])['room'];

        $result = $this->withSession($this->actingAsTeacherOwner(1))
            ->get('/teacher/games/' . $room['uuid']);

        $result->assertStatus(200);
        $body = $result->getBody();

        $this->assertStringContainsString('Link Cepat', $body);
        $this->assertStringContainsString('id="join-qr"', $body);
        $this->assertStringContainsString('data-join-path="/join/' . $room['pin'] . '"', $body);
        $this->assertStringContainsString('cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js', $body);
        $this->assertStringContainsString('window.location.origin', $body);
        $this->assertStringContainsString('Scan QR untuk join', $body);
        $this->assertStringContainsString('href="/join/' . $room['pin'] . '"', $body);
        $this->assertStringContainsString('Buka Projector', $body);
    }

    /** @return array<string, mixed> */
    private function actingAsTeacherOwner(int $teacherId): array
    {
        $users = model(UserModel::class);
        $suffix = bin2hex(random_bytes(4));
        $user = new User([
            'username' => 'test-teacher-' . $teacherId . '-' . $suffix,
            'email' => 'test-teacher-' . $teacherId . '-' . $suffix . '@example.test',
            'active' => true,
        ]);
        $user->setPassword('TestPassword123!');
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup('teacher');

        (new TeacherModel())->update($teacherId, ['auth_user_id' => $user->id]);

        $_SESSION = [];
        $this->actingAs($user);

        $session = $_SESSION;
        $_SESSION = [];

        return $session;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
./vendor/bin/phpunit tests/feature/TeacherGameShowJoinQrTest.php --filter testShowPageIncludesJoinQrMarkupAndKeepsJoinLink
```

Expected: FAIL — response 200 mungkin lolos, tapi assertion `id="join-qr"` / CDN / teks bantuan gagal karena markup belum ada.

- [ ] **Step 3: Commit failing test**

```bash
git add tests/feature/TeacherGameShowJoinQrTest.php
git commit -m "test: require join QR markup on teacher game show"
```

---

### Task 2: Markup + script QR di Link Cepat

**Files:**
- Modify: `app/Views/teacher/games/show.php`
- Test: `tests/feature/TeacherGameShowJoinQrTest.php`

- [ ] **Step 1: Update panel Link Cepat**

Ganti blok panel Link Cepat (sekitar baris 80–84) menjadi:

```php
    <div class="panel">
        <h2>Link Cepat</h2>
        <div class="join-qr-block">
            <div id="join-qr" class="join-qr" data-join-path="/join/<?= esc($room['pin']) ?>" aria-label="QR code join"></div>
            <p class="muted">Scan QR untuk join, atau buka link di bawah.</p>
            <p><a class="button secondary" href="/join/<?= esc($room['pin']) ?>">/join/<?= esc($room['pin']) ?></a></p>
            <p><a class="button secondary" href="/game/<?= esc($room['uuid']) ?>/projector?t=<?= esc((string) ($room['projector_token'] ?? '')) ?>">Buka Projector</a></p>
        </div>
    </div>
```

- [ ] **Step 2: Add scripts section at end of file**

Setelah `<?= $this->endSection() ?>` content, tambahkan:

```php
<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js"></script>
<script>
(function () {
    var el = document.getElementById('join-qr');
    if (!el || typeof QRCode === 'undefined' || typeof QRCode.toCanvas !== 'function') {
        return;
    }
    var path = el.getAttribute('data-join-path') || '';
    if (!path) {
        return;
    }
    var url = window.location.origin + path;
    QRCode.toCanvas(url, { width: 180, margin: 1 }, function (error, canvas) {
        if (error || !canvas) {
            return;
        }
        el.appendChild(canvas);
    });
})();
</script>
<?= $this->endSection() ?>
```

Catatan API: `QRCode.toCanvas(text, options, callback)` — library `qrcode@1.5.4` browser build mengekspor global `QRCode` dengan `toCanvas`.

- [ ] **Step 3: Run test to verify it passes**

Run:

```bash
./vendor/bin/phpunit tests/feature/TeacherGameShowJoinQrTest.php --filter testShowPageIncludesJoinQrMarkupAndKeepsJoinLink
```

Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Views/teacher/games/show.php
git commit -m "feat: add join QR to teacher Link Cepat panel"
```

---

### Task 3: CSS layout QR

**Files:**
- Modify: `public/assets/app.css`
- Test: `tests/feature/TeacherGameShowJoinQrTest.php` (tetap hijau)

- [ ] **Step 1: Add join QR styles**

Tambahkan di `public/assets/app.css` dekat style `.panel` (setelah blok `.panel h2` ~baris 210):

```css
.join-qr-block {
    display: grid;
    gap: 10px;
    justify-items: start;
}

.join-qr {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: var(--radius);
    line-height: 0;
    padding: 10px;
}

.join-qr canvas {
    display: block;
    height: 180px;
    width: 180px;
}
```

- [ ] **Step 2: Re-run feature test**

Run:

```bash
./vendor/bin/phpunit tests/feature/TeacherGameShowJoinQrTest.php --filter testShowPageIncludesJoinQrMarkupAndKeepsJoinLink
```

Expected: PASS

- [ ] **Step 3: Manual smoke check**

Dengan server `php spark serve --host 127.0.0.1 --port 8090`:

1. Buka `/teacher/games/{uuid}` room LOBBY
2. Pastikan QR ~180px muncul di Link Cepat
3. Pastikan link `/join/{PIN}` dan **Buka Projector** masih ada
4. Inspect canvas / decode mental: URL harus `http://127.0.0.1:8090/join/{PIN}`

- [ ] **Step 4: Commit**

```bash
git add public/assets/app.css
git commit -m "style: layout join QR in Link Cepat"
```

---

## Spec coverage check

| Spec requirement | Task |
|------------------|------|
| QR di Link Cepat | Task 2 |
| URL = origin + `/join/{PIN}` | Task 2 script |
| Link teks tetap | Task 1 + 2 |
| Projector tidak berubah | Task 1 + 2 |
| CDN client-side, no Composer | Task 2 |
| CSS minimal | Task 3 |
| Graceful jika QR library gagal (link tetap) | Task 2 early `return` |

## Placeholder / consistency check

- Element id: `join-qr`
- Attribute: `data-join-path="/join/{PIN}"`
- CDN: `qrcode@1.5.4`
- Help text exact: `Scan QR untuk join`
- Global: `QRCode.toCanvas`
