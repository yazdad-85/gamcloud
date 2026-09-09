<?php
$settings = new \App\Services\Platform\PlatformSettingsService();
$branding = $settings->branding();
$seoTitle = (string) ($seoTitle ?? $title ?? $branding['site_name']);
$seoDescription = (string) ($seoDescription ?? $branding['seo_description']);
$seoRobots = (string) ($seoRobots ?? 'index,follow');
$seoImage = (string) ($seoImage ?? $branding['og_image_path']);
$favicon = (string) ($branding['favicon_path'] ?? '/assets/brand/favicon.svg');
$faviconType = $settings->faviconMimeType($favicon);
$base = rtrim((string) config('App')->baseURL, '/');
$path = '/' . ltrim((string) ($seoPath ?? uri_string()), '/');
if ($path === '/') {
    $canonical = $base . '/';
} else {
    $canonical = $base . $path;
}
if (str_starts_with($seoImage, 'http://') || str_starts_with($seoImage, 'https://')) {
    $ogImage = $seoImage;
} else {
    $ogImage = $base . '/' . ltrim($seoImage, '/');
}
$siteName = (string) $branding['site_name'];
?>
<title><?= esc($seoTitle) ?></title>
<meta name="description" content="<?= esc($seoDescription) ?>">
<meta name="robots" content="<?= esc($seoRobots) ?>">
<link rel="canonical" href="<?= esc($canonical) ?>">
<link rel="icon" href="<?= esc($favicon) ?>" type="<?= esc($faviconType) ?>">
<link rel="apple-touch-icon" href="<?= esc($favicon) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= esc($siteName) ?>">
<meta property="og:locale" content="id_ID">
<meta property="og:title" content="<?= esc($seoTitle) ?>">
<meta property="og:description" content="<?= esc($seoDescription) ?>">
<meta property="og:url" content="<?= esc($canonical) ?>">
<meta property="og:image" content="<?= esc($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= esc($seoTitle) ?>">
<meta name="twitter:description" content="<?= esc($seoDescription) ?>">
<meta name="twitter:image" content="<?= esc($ogImage) ?>">
