<?= $this->extend('layouts/teacher') ?>

<?= $this->section('content') ?>
<div class="topbar">
    <div>
        <h1 class="page-title">Pengaturan Platform</h1>
        <p class="muted">Branding situs: nama platform, logo, favicon, dan gambar Open Graph. Nama sebaiknya netral (mis. Edugame), bukan nama satu mode game.</p>
    </div>
    <a class="button secondary" href="/superadmin">Kembali</a>
</div>

<?php if (! empty($message)): ?>
    <div class="alert success"><?= esc($message) ?></div>
<?php endif ?>
<?php if (! empty($error)): ?>
    <div class="alert"><?= esc($error) ?></div>
<?php endif ?>

<form class="panel" method="post" action="/superadmin/settings" enctype="multipart/form-data" style="margin-top:16px">
    <?= csrf_field() ?>
    <div class="form-grid">
        <label>
            Nama situs
            <input type="text" name="site_name" value="<?= esc(old('site_name', $branding['site_name'] ?? '')) ?>" required maxlength="190">
        </label>
        <label>
            Tagline
            <input type="text" name="tagline" value="<?= esc(old('tagline', $branding['tagline'] ?? '')) ?>" maxlength="190">
        </label>
        <label class="full">
            Meta description (SEO)
            <textarea name="seo_description" rows="3" maxlength="190"><?= esc(old('seo_description', $branding['seo_description'] ?? '')) ?></textarea>
        </label>
    </div>

    <div class="brand-upload-grid" style="margin-top:16px">
        <div>
            <p class="muted">Logo website (tampil di beranda, login, daftar, sidebar)</p>
            <img src="<?= esc($branding['logo_path']) ?>" alt="Logo" style="max-height:48px;background:#fff;padding:8px;border-radius:8px">
            <label>Ganti logo (PNG/JPEG/WebP/SVG, max 2MB)
                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
            </label>
        </div>
        <div>
            <p class="muted">Favicon (ikon tab browser — sebaiknya kotak 32–256px, file kecil)</p>
            <img src="<?= esc($branding['favicon_path']) ?>" alt="Favicon" style="max-height:32px;background:#fff;padding:8px;border-radius:8px">
            <label>Ganti favicon
                <input type="file" name="favicon" accept="image/png,image/jpeg,image/webp,image/svg+xml">
            </label>
        </div>
        <div>
            <p class="muted">OG image — <strong>bukan</strong> di halaman web. Muncul saat link dibagikan (WhatsApp, Facebook, X, Telegram).</p>
            <img src="<?= esc($branding['og_image_path']) ?>" alt="OG" style="max-width:240px;border-radius:8px">
            <label>Ganti OG image (PNG/JPEG/WebP, max 3MB, ideal 1200×630)
                <input type="file" name="og_image" accept="image/png,image/jpeg,image/webp">
            </label>
        </div>
    </div>

    <div style="margin-top:16px">
        <button class="button" type="submit">Simpan Pengaturan</button>
    </div>
</form>
<?= $this->endSection() ?>
