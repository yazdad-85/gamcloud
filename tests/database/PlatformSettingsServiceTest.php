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
        $branding = (new PlatformSettingsService())->branding(true);

        $this->assertSame('Ular Tangga Edukatif', $branding['site_name']);
        $this->assertSame('/assets/brand/logo.svg', $branding['logo_path']);
        $this->assertSame('/assets/brand/favicon.svg', $branding['favicon_path']);
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
}
