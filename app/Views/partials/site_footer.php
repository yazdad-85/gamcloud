<?php
$settings = new \App\Services\Platform\PlatformSettingsService();
$branding = $branding ?? $settings->branding();
$siteName = (string) ($branding['site_name'] ?? 'Edugame');
$years = $settings->copyrightYears();
$footerVariant = $footerVariant ?? 'public';
?>
<footer class="site-footer site-footer-<?= esc($footerVariant) ?>" role="contentinfo">
    <p class="site-footer-copy">© <?= esc($years) ?> <?= esc($siteName) ?></p>
    <?php if ($footerVariant === 'public'): ?>
        <nav class="site-footer-nav" aria-label="Footer">
            <a href="/">Beranda</a>
            <a href="/login">Login</a>
            <a href="/join">Join</a>
        </nav>
    <?php endif ?>
</footer>
