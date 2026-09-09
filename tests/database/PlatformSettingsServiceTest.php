<?php

use App\Database\Seeds\DemoGameSeeder;
use App\Services\Platform\PlatformSettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class PlatformSettingsServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $seed      = DemoGameSeeder::class;

    public function testBrandingFallsBackToDefaults(): void
    {
        $service = new PlatformSettingsService();
        $branding = $service->branding(true);

        $this->assertSame('Edugame', $branding['site_name']);
        $this->assertSame('/assets/brand/logo.svg', $branding['logo_path']);
        $this->assertSame('/assets/brand/favicon.svg', $branding['favicon_path']);
        $this->assertSame('image/svg+xml', $service->faviconMimeType($branding['favicon_path']));
        $this->assertSame('image/png', $service->faviconMimeType('/uploads/brand/favicon_path-abc.png'));
        $this->assertSame('image/jpeg', $service->faviconMimeType('/uploads/brand/x.jpeg'));
    }

    public function testUpdateTextSettingsPersists(): void
    {
        $service = new PlatformSettingsService();
        $service->updateTextSettings([
            'site_name'       => 'Edugame Cloud',
            'tagline'         => 'Belajar sambil bermain',
            'seo_description' => 'Deskripsi SEO custom untuk platform.',
        ]);

        $branding = $service->branding(true);
        $this->assertSame('Edugame Cloud', $branding['site_name']);
        $this->assertSame('Belajar sambil bermain', $branding['tagline']);
        $this->assertSame('Deskripsi SEO custom untuk platform.', $branding['seo_description']);
    }

    public function testEmptySiteNameRejected(): void
    {
        $this->expectException(DomainException::class);
        (new PlatformSettingsService())->updateTextSettings(['site_name' => '   ']);
    }

    public function testSyncRootFaviconWritesIcoFromPng(): void
    {
        $publicSource = FCPATH . 'uploads/brand/test-favicon-sync.png';
        $publicDir = dirname($publicSource);
        if (! is_dir($publicDir)) {
            mkdir($publicDir, 0777, true);
        }

        $img = imagecreatetruecolor(64, 64);
        $green = imagecolorallocate($img, 14, 58, 46);
        imagefilledrectangle($img, 0, 0, 63, 63, $green);
        imagepng($img, $publicSource);
        imagedestroy($img);

        $target = FCPATH . 'favicon.ico';
        $backup = $target . '.bak-test';
        if (is_file($target)) {
            copy($target, $backup);
        }

        try {
            (new PlatformSettingsService())->syncRootFavicon('/uploads/brand/test-favicon-sync.png');
            $this->assertFileExists($target);
            $this->assertGreaterThan(100, (int) filesize($target));
        } finally {
            @unlink($publicSource);
            if (is_file($backup)) {
                rename($backup, $target);
            }
        }
    }

    public function testCopyrightYearsRange(): void
    {
        $service = new PlatformSettingsService();
        $start = (int) config('App')->appStartYear;
        $this->assertSame((string) $start, $service->copyrightYears($start));
        $this->assertSame($start . '–' . ($start + 1), $service->copyrightYears($start + 1));
        $this->assertSame($start . '–' . ($start + 4), $service->copyrightYears($start + 4));
    }
}
