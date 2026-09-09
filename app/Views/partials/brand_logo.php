<?php
$branding = $branding ?? (new \App\Services\Platform\PlatformSettingsService())->branding();
$siteName = (string) ($branding['site_name'] ?? 'Ular Tangga Edukatif');
$logoPath = (string) ($branding['logo_path'] ?? '/assets/brand/logo.svg');
$href = $href ?? null;
$markClass = trim('brand-mark brand-mark-logo ' . (string) ($markClass ?? ''));
$tag = $href !== null && $href !== '' ? 'a' : 'div';
?>
<<?= $tag ?> class="<?= esc($markClass) ?>"<?= $href !== null && $href !== '' ? ' href="' . esc($href) . '"' : '' ?> aria-label="<?= esc($siteName) ?>">
    <img src="<?= esc($logoPath) ?>" alt="<?= esc($siteName) ?>" width="280" height="72">
</<?= $tag ?>>
